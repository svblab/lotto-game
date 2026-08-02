<?php

declare(strict_types=1);

namespace Lotto\Core;

/**
 * EPIC-11.3 — Economy audit instrumentation.
 *
 * Opt-in via LOTTO_ECONOMY_AUDIT=1. Writes structured financial events to
 * logs/economy_audit.log (separate from server.log for replay analysis).
 *
 * Optional log path override:
 *   - constructor ?string $logFilePath
 *   - env LOTTO_ECONOMY_AUDIT_LOG
 *
 * Amount convention (signed delta applied to user balance on replay):
 *   stake / apartment — negative (coins debited from user)
 *   prize / refund    — positive (coins credited to user)
 *   burn              — positive amount destroyed from room bank (user_id=0)
 *
 * Integrity invariant:
 *   sum(initial_user_coins) = sum(final_user_coins) + sum(burned) + sum(active_room_banks)
 */
final class EconomyAudit
{
    private const DEFAULT_LOG_FILENAME = 'economy_audit.log';

    /** @var string[] */
    public const OPERATIONS = [
        'stake',
        'prize',
        'apartment',
        'refund',
        'burn',
    ];

    private Logger $logger;
    private string $logFile;
    private int $txCounter = 0;

    public function __construct(Logger $logger, ?string $logFilePath = null)
    {
        $this->logger = $logger;
        $this->logFile = self::resolveLogPath($logFilePath);
    }

    public static function resolveLogPath(?string $logFilePath = null): string
    {
        if ($logFilePath !== null && $logFilePath !== '') {
            return $logFilePath;
        }

        $envPath = getenv('LOTTO_ECONOMY_AUDIT_LOG');
        if (is_string($envPath) && $envPath !== '') {
            return $envPath;
        }

        return dirname(__DIR__, 2) . '/logs/' . self::DEFAULT_LOG_FILENAME;
    }

    public static function isEnabled(): bool
    {
        $flag = getenv('LOTTO_ECONOMY_AUDIT');
        return $flag === '1' || $flag === 'true';
    }

    /**
     * Record a financial event. No-op when audit is disabled.
     *
     * @param array<string, scalar|null> $context  room_id, game_id, note, etc.
     */
    public function record(string $operation, int $userId, int $amount, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (!in_array($operation, self::OPERATIONS, true)) {
            $this->logger->warning("EconomyAudit: unknown operation '{$operation}'");
        }

        $this->txCounter++;
        $txId = sprintf('tx-%s-%04d', date('YmdHis'), $this->txCounter);

        $parts = [
            'tx_id=' . $txId,
            'op=' . $operation,
            'user_id=' . $userId,
            'amount=' . $amount,
            'ts_us=' . (string) (int) (microtime(true) * 1_000_000),
        ];

        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = "{$key}=" . (is_scalar($value) ? $value : json_encode($value));
        }

        $this->writeLine(implode(' ', $parts));
    }

    /**
     * Parse economy audit log into structured events.
     *
     * @return list<array{tx_id: string, op: string, user_id: int, amount: int, room_id: int|null, game_id: int|null, ts_us: int|null, line: string}>
     */
    public static function parseLog(string $logPath): array
    {
        if (!is_file($logPath)) {
            return [];
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $events = [];
        foreach ($lines as $line) {
            if (!preg_match('/\[ECONOMY\]\s+(.+)$/', $line, $m)) {
                continue;
            }

            $fields = [];
            foreach (preg_split('/\s+/', trim($m[1])) as $pair) {
                if (!str_contains($pair, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $pair, 2);
                $fields[$k] = $v;
            }

            if (!isset($fields['tx_id'], $fields['op'], $fields['amount'])) {
                continue;
            }

            $events[] = [
                'tx_id'   => $fields['tx_id'],
                'op'      => $fields['op'],
                'user_id' => isset($fields['user_id']) ? (int) $fields['user_id'] : 0,
                'amount'  => (int) $fields['amount'],
                'room_id' => isset($fields['room_id']) ? (int) $fields['room_id'] : null,
                'game_id' => isset($fields['game_id']) ? (int) $fields['game_id'] : null,
                'ts_us'   => isset($fields['ts_us']) ? (int) $fields['ts_us'] : null,
                'line'    => $line,
            ];
        }

        return $events;
    }

    /**
     * Replay log events against initial balances.
     *
     * @param  array<int, int> $initialBalances  user_id => coins
     * @return array{
     *   balances: array<int, int>,
     *   burned: int,
     *   room_banks: array<int, int>,
     *   ops: array<string, int>
     * }
     */
    public static function replay(array $events, array $initialBalances = []): array
    {
        $balances = $initialBalances;
        $burned = 0;
        $roomBanks = [];
        $ops = array_fill_keys(self::OPERATIONS, 0);

        foreach ($events as $event) {
            $op = $event['op'];
            $amount = $event['amount'];
            $userId = $event['user_id'];
            $roomId = $event['room_id'];

            if (isset($ops[$op])) {
                $ops[$op]++;
            }

            if ($op === 'burn') {
                $burned += abs($amount);
                if ($roomId !== null) {
                    $roomBanks[$roomId] = max(0, ($roomBanks[$roomId] ?? 0) - abs($amount));
                }
                continue;
            }

            if ($op === 'stake' || $op === 'apartment') {
                $debit = abs($amount);
                $balances[$userId] = ($balances[$userId] ?? 0) - $debit;
                if ($roomId !== null) {
                    $roomBanks[$roomId] = ($roomBanks[$roomId] ?? 0) + $debit;
                }
                continue;
            }

            if ($op === 'prize') {
                $credit = abs($amount);
                $balances[$userId] = ($balances[$userId] ?? 0) + $credit;
                if ($roomId !== null) {
                    $roomBanks[$roomId] = max(0, ($roomBanks[$roomId] ?? 0) - $credit);
                }
                continue;
            }

            if ($op === 'refund') {
                $credit = abs($amount);
                $balances[$userId] = ($balances[$userId] ?? 0) + $credit;
                if ($roomId !== null) {
                    $roomBanks[$roomId] = max(0, ($roomBanks[$roomId] ?? 0) - $credit);
                }
            }
        }

        return [
            'balances'   => $balances,
            'burned'     => $burned,
            'room_banks' => $roomBanks,
            'ops'        => $ops,
        ];
    }

    /**
     * Verify economic conservation: initial total = final user coins + burned + room banks.
     *
     * @param  array<int, int> $initialBalances
     * @param  array<int, int> $finalBalances
     * @param  array<int, int> $activeRoomBanks
     */
    public static function verifyConservation(
        array $initialBalances,
        array $finalBalances,
        int $burned = 0,
        array $activeRoomBanks = []
    ): bool {
        $initialTotal = array_sum($initialBalances);
        $finalTotal = array_sum($finalBalances) + $burned + array_sum($activeRoomBanks);

        return $initialTotal === $finalTotal;
    }

    /**
     * @return array{events: int, ops: array<string, int>}
     */
    public static function parseLogStats(string $logPath): array
    {
        $events = self::parseLog($logPath);
        $ops = array_fill_keys(self::OPERATIONS, 0);

        foreach ($events as $event) {
            if (isset($ops[$event['op']])) {
                $ops[$event['op']]++;
            }
        }

        return ['events' => count($events), 'ops' => $ops];
    }

    private function writeLine(string $message): void
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] [ECONOMY] ' . $message . PHP_EOL;
        $result = file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            $this->logger->error('EconomyAudit: failed to write to log file ' . $this->logFile);
        }
    }
}
