<?php

namespace Lotto\Chat;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use function Lotto\Core\lottoTimerAdd;
use function Lotto\Core\lottoTimerDel;
use function Lotto\Core\sendError;
use function Lotto\Core\sendJson;
use function Lotto\Core\serverLog;

/**
 * FileTransferService — ADR-030
 *
 * Consent-based 1-to-1 file relay with room transfer lock.
 * Bytes are never written to SQLite/disk and never buffered before accept.
 */
final class FileTransferService
{
    public function __construct(
        private RoomManager $roomManager,
        private ChatService $chatService,
        private Logger $logger
    ) {
    }

    public function handleFileOffer(array $data, object $connection, object $worker): void
    {
        if (!$this->consumeFileRateLimit($connection)) {
            sendError($connection, 'error.file_rate_limited', 'File transfer rate limit exceeded');
            return;
        }

        $resolved = $this->chatService->resolvePasswordRoom($worker, $connection);
        if ($resolved === null) {
            sendError($connection, 'error.chat_unavailable', 'File transfer is only available in password-protected rooms');
            return;
        }

        $roomId = $resolved['room_id'];
        $room = &$worker->rooms[$roomId];
        $senderConnId = (int) $connection->id;

        if (!empty($room['file_transfer'])) {
            sendError($connection, 'error.file_transfer_busy', 'A file transfer is already in progress in this room');
            return;
        }

        $toUsername = $data['to_username'] ?? null;
        $filenameRaw = $data['filename'] ?? null;
        $sizeBytes = $data['size_bytes'] ?? null;

        if (!is_string($toUsername) || $toUsername === '') {
            sendError($connection, 'error.file_recipient_invalid', 'Invalid recipient');
            return;
        }

        if (!is_int($sizeBytes) && !(is_string($sizeBytes) && ctype_digit($sizeBytes))) {
            sendError($connection, 'error.file_too_large', 'Invalid file size');
            return;
        }
        $sizeBytes = (int) $sizeBytes;
        if ($sizeBytes < 1 || $sizeBytes > Constants::FILE_MAX_BYTES) {
            sendError($connection, 'error.file_too_large', 'File exceeds maximum size');
            return;
        }

        if (!is_string($filenameRaw) || $filenameRaw === '') {
            sendError($connection, 'error.file_invalid_payload', 'Invalid filename');
            return;
        }
        $filename = $this->sanitizeFilename($filenameRaw);
        if ($filename === '') {
            sendError($connection, 'error.file_invalid_payload', 'Invalid filename');
            return;
        }

        $senderUsername = (string) ($room['players'][$senderConnId]['username'] ?? '');
        if ($toUsername === $senderUsername) {
            sendError($connection, 'error.file_recipient_invalid', 'Cannot send a file to yourself');
            return;
        }

        $recipientConnId = $this->findActiveConnIdByUsername($room, $toUsername);
        if ($recipientConnId === null) {
            sendError($connection, 'error.file_recipient_invalid', 'Recipient is not available in this room');
            return;
        }

        $offerId = bin2hex(random_bytes(8));
        $recipientConn = $room['players'][$recipientConnId]['connection'];

        $room['file_transfer'] = [
            'state'              => 'offer_pending',
            'offer_id'           => $offerId,
            'sender_conn_id'     => $senderConnId,
            'recipient_conn_id'  => $recipientConnId,
            'sender_username'    => $senderUsername,
            'recipient_username' => $toUsername,
            'filename'           => $filename,
            'size_bytes'         => $sizeBytes,
            'timer_id'           => null,
        ];

        $timerId = lottoTimerAdd(
            (float) Constants::FILE_OFFER_TIMEOUT,
            function () use ($worker, $roomId, $offerId): void {
                $this->onOfferTimeout($worker, $roomId, $offerId);
            },
            [],
            false,
            'file_offer',
            ['room_id' => $roomId, 'offer_id' => $offerId]
        );
        $room['file_transfer']['timer_id'] = $timerId;

        sendJson($recipientConn, [
            'type'       => 'file_offer',
            'offer_id'   => $offerId,
            'from'       => $senderUsername,
            'filename'   => $filename,
            'size_bytes' => $sizeBytes,
        ]);

        serverLog($this->logger, 'INFO', "File offer room_id={$roomId} offer_id={$offerId} from={$senderUsername} to={$toUsername} size={$sizeBytes}");
    }

    public function handleFileAccept(array $data, object $connection, object $worker): void
    {
        $ft = $this->requireOfferActor($data, $connection, $worker, 'recipient', 'offer_pending');
        if ($ft === null) {
            return;
        }

        [$roomId, $offer] = $ft;
        $room = &$worker->rooms[$roomId];
        $this->cancelTransferTimer($room);

        $room['file_transfer']['state'] = 'relay_pending';
        $room['file_transfer']['timer_id'] = null;

        $timerId = lottoTimerAdd(
            (float) Constants::FILE_RELAY_TIMEOUT,
            function () use ($worker, $roomId, $offer): void {
                $this->onOfferTimeout($worker, $roomId, $offer['offer_id']);
            },
            [],
            false,
            'file_offer',
            ['room_id' => $roomId, 'offer_id' => $offer['offer_id']]
        );
        $room['file_transfer']['timer_id'] = $timerId;

        $senderConn = $room['players'][$offer['sender_conn_id']]['connection'] ?? null;
        if ($senderConn !== null) {
            sendJson($senderConn, [
                'type'     => 'file_accepted',
                'offer_id' => $offer['offer_id'],
            ]);
        }

        serverLog($this->logger, 'INFO', "File accept room_id={$roomId} offer_id={$offer['offer_id']}");
    }

    public function handleFileReject(array $data, object $connection, object $worker): void
    {
        $ft = $this->requireOfferActor($data, $connection, $worker, 'recipient', 'offer_pending');
        if ($ft === null) {
            return;
        }

        [$roomId, $offer] = $ft;
        $room = &$worker->rooms[$roomId];
        $this->cancelTransferTimer($room);
        $room['file_transfer'] = null;

        $senderConn = $room['players'][$offer['sender_conn_id']]['connection'] ?? null;
        if ($senderConn !== null && ($room['players'][$offer['sender_conn_id']]['status'] ?? '') === 'active') {
            sendJson($senderConn, [
                'type'     => 'file_rejected',
                'offer_id' => $offer['offer_id'],
                'reason'   => 'declined',
            ]);
        }

        serverLog($this->logger, 'INFO', "File reject room_id={$roomId} offer_id={$offer['offer_id']}");
    }

    public function handleFileData(array $data, object $connection, object $worker): void
    {
        if (!$this->consumeFileRateLimit($connection)) {
            sendError($connection, 'error.file_rate_limited', 'File transfer rate limit exceeded');
            return;
        }

        $ft = $this->requireOfferActor($data, $connection, $worker, 'sender', 'relay_pending');
        if ($ft === null) {
            return;
        }

        [$roomId, $offer] = $ft;
        $room = &$worker->rooms[$roomId];

        $b64 = $data['data'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            $this->failRelay($room, $connection, 'error.file_invalid_payload', 'Missing file data');
            return;
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            $this->failRelay($room, $connection, 'error.file_invalid_payload', 'Invalid base64 payload');
            return;
        }

        $decodedLen = strlen($decoded);
        if ($decodedLen > Constants::FILE_MAX_BYTES) {
            $this->failRelay($room, $connection, 'error.file_too_large', 'Decoded file exceeds maximum size');
            return;
        }

        if ($decodedLen !== (int) $offer['size_bytes']) {
            $this->failRelay($room, $connection, 'error.file_invalid_payload', 'Decoded size does not match offer');
            return;
        }

        unset($decoded);

        $this->cancelTransferTimer($room);
        $room['file_transfer'] = null;

        $recipient = $room['players'][$offer['recipient_conn_id']] ?? null;
        if (
            is_array($recipient)
            && ($recipient['status'] ?? '') === 'active'
            && isset($recipient['connection'])
        ) {
            sendJson($recipient['connection'], [
                'type'     => 'file_data',
                'offer_id' => $offer['offer_id'],
                'from'     => $offer['sender_username'],
                'filename' => $offer['filename'],
                'data'     => $b64,
            ]);
        }

        serverLog($this->logger, 'INFO', "File relayed room_id={$roomId} offer_id={$offer['offer_id']} bytes={$decodedLen}");
    }

    /**
     * Release lock if conn is sender or recipient (disconnect / leave / remove).
     */
    public function releaseForConn(object $worker, int $connId): void
    {
        if (empty($worker->rooms) || !is_array($worker->rooms)) {
            return;
        }

        foreach ($worker->rooms as $roomId => $room) {
            $ft = $room['file_transfer'] ?? null;
            if (!is_array($ft)) {
                continue;
            }
            if ((int) $ft['sender_conn_id'] !== $connId && (int) $ft['recipient_conn_id'] !== $connId) {
                continue;
            }
            $this->expireTransfer($worker, (int) $roomId, (string) $ft['offer_id'], $connId);
        }
    }

    /**
     * Cancel timer on room destroy (no packets — room is gone).
     */
    public function releaseForRoom(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }
        $this->cancelTransferTimer($worker->rooms[$roomId]);
        $worker->rooms[$roomId]['file_transfer'] = null;
    }

    private function onOfferTimeout(object $worker, int $roomId, string $offerId): void
    {
        $this->expireTransfer($worker, $roomId, $offerId, null);
    }

    private function expireTransfer(object $worker, int $roomId, string $offerId, ?int $excludeConnId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];
        $ft = $room['file_transfer'] ?? null;
        if (!is_array($ft) || ($ft['offer_id'] ?? '') !== $offerId) {
            return;
        }

        $this->cancelTransferTimer($room);
        $room['file_transfer'] = null;

        foreach (['sender_conn_id', 'recipient_conn_id'] as $key) {
            $cid = (int) $ft[$key];
            if ($excludeConnId !== null && $cid === $excludeConnId) {
                continue;
            }
            $player = $room['players'][$cid] ?? null;
            if (
                is_array($player)
                && ($player['status'] ?? '') === 'active'
                && isset($player['connection'])
            ) {
                sendJson($player['connection'], [
                    'type'     => 'file_offer_expired',
                    'offer_id' => $offerId,
                ]);
            }
        }

        serverLog($this->logger, 'INFO', "File transfer released room_id={$roomId} offer_id={$offerId}");
    }

    /**
     * @return array{0: int, 1: array}|null
     */
    private function requireOfferActor(
        array $data,
        object $connection,
        object $worker,
        string $role,
        string $expectedState
    ): ?array {
        $resolved = $this->chatService->resolvePasswordRoom($worker, $connection);
        if ($resolved === null) {
            sendError($connection, 'error.chat_unavailable', 'File transfer is only available in password-protected rooms');
            return null;
        }

        $roomId = $resolved['room_id'];
        $room = $worker->rooms[$roomId];
        $ft = $room['file_transfer'] ?? null;
        $offerId = $data['offer_id'] ?? null;

        if (!is_array($ft) || !is_string($offerId) || $offerId === '' || ($ft['offer_id'] ?? '') !== $offerId) {
            sendError($connection, 'error.file_offer_invalid', 'Invalid or unknown file offer');
            return null;
        }

        if (($ft['state'] ?? '') !== $expectedState) {
            sendError($connection, 'error.file_offer_invalid', 'File offer is not in the expected state');
            return null;
        }

        $connId = (int) $connection->id;
        $expectedConn = $role === 'sender'
            ? (int) $ft['sender_conn_id']
            : (int) $ft['recipient_conn_id'];

        if ($connId !== $expectedConn) {
            sendError($connection, 'error.file_offer_invalid', 'Not authorized for this file offer');
            return null;
        }

        return [$roomId, $ft];
    }

    private function failRelay(array &$room, object $connection, string $code, string $message): void
    {
        $this->cancelTransferTimer($room);
        $room['file_transfer'] = null;
        sendError($connection, $code, $message);
    }

    private function cancelTransferTimer(array &$room): void
    {
        $ft = $room['file_transfer'] ?? null;
        if (!is_array($ft) || empty($ft['timer_id'])) {
            return;
        }
        lottoTimerDel((int) $ft['timer_id'], 'file_offer', [
            'room_id'  => $room['room_id'] ?? null,
            'offer_id' => $ft['offer_id'] ?? null,
        ]);
        $room['file_transfer']['timer_id'] = null;
    }

    private function findActiveConnIdByUsername(array $room, string $username): ?int
    {
        foreach ($room['players'] as $connId => $player) {
            if (
                ($player['username'] ?? '') === $username
                && ($player['status'] ?? '') === 'active'
                && isset($player['connection'])
            ) {
                return (int) $connId;
            }
        }

        return null;
    }

    private function sanitizeFilename(string $raw): string
    {
        $base = basename(str_replace(["\0", '\\'], ['', '/'], $raw));
        $base = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $base) ?? '';
        $base = trim($base, ". \t\n\r\0\x0B");
        if ($base === '' || $base === '.' || $base === '..') {
            return 'file';
        }
        if (strlen($base) > 120) {
            $base = substr($base, 0, 120);
        }

        return $base;
    }

    private function consumeFileRateLimit(object $connection): bool
    {
        $now = time();
        if (!isset($connection->fileActionWindowStart)) {
            $connection->fileActionWindowStart = $now;
            $connection->fileActionCount = 0;
        }

        if (($now - (int) $connection->fileActionWindowStart) >= Constants::FILE_RATE_LIMIT_WINDOW_SECONDS) {
            $connection->fileActionWindowStart = $now;
            $connection->fileActionCount = 0;
        }

        if ((int) $connection->fileActionCount >= Constants::FILE_RATE_LIMIT_MAX) {
            return false;
        }

        $connection->fileActionCount = (int) $connection->fileActionCount + 1;

        return true;
    }
}
