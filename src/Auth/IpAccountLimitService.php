<?php

namespace Lotto\Auth;

use Lotto\Core\Constants;
use Lotto\Core\Logger;

use function Lotto\Core\lottoRuntimeEnv;

/**
 * ADR-031: live per-client-IP distinct account cap at login.
 * Stateless — counts via $worker->userConnections + $connection->clientRemoteIp.
 */
final class IpAccountLimitService
{
    /** Shared bucket when trusted proxy peer sends no resolvable client IP. */
    public const TRUSTED_PROXY_UNRESOLVED_BUCKET = '__trusted_proxy_unresolved__';

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function attachClientRemoteIp(object $connection, ?object $request = null): void
    {
        $connection->clientRemoteIp = $this->resolveClientRemoteIp($connection, $request);
    }

    public function resolveClientRemoteIp(object $connection, ?object $request = null): string
    {
        $peerIp = $this->getConnectionPeerIp($connection);

        if ($this->isTrustedProxyIp($peerIp)) {
            $fromHeader = $this->extractClientIpFromRequest($request);
            if ($fromHeader !== null) {
                return $fromHeader;
            }

            $connId = $connection->id ?? 'null';
            $this->logger->write(
                'WARNING',
                'Trusted proxy peer without resolvable client IP: peer=' . $peerIp
                . ' conn_id=' . $connId
            );

            return self::TRUSTED_PROXY_UNRESOLVED_BUCKET;
        }

        return $peerIp !== '' ? $peerIp : '0.0.0.0';
    }

    public function wouldRejectNewAuth(object $worker, object $connection, int $userId): bool
    {
        $clientIp = $this->getClientRemoteIp($connection);
        $distinct = $this->distinctUserIdsAtClientIp($worker, $clientIp);

        if (isset($distinct[$userId])) {
            return false;
        }

        return count($distinct) >= Constants::MAX_ACCOUNTS_PER_IP;
    }

    private function getClientRemoteIp(object $connection): string
    {
        if (
            isset($connection->clientRemoteIp)
            && is_string($connection->clientRemoteIp)
            && $connection->clientRemoteIp !== ''
        ) {
            return $connection->clientRemoteIp;
        }

        return $this->resolveClientRemoteIp($connection, null);
    }

    /**
     * @return array<int, true>
     */
    private function distinctUserIdsAtClientIp(object $worker, string $clientIp): array
    {
        $distinct = [];
        $userConnections = $worker->userConnections ?? [];

        foreach ($userConnections as $uid => $conn) {
            $userId = (int) $uid;
            if ($this->getClientRemoteIp($conn) === $clientIp) {
                $distinct[$userId] = true;
            }
        }

        return $distinct;
    }

    private function getConnectionPeerIp(object $connection): string
    {
        if (method_exists($connection, 'getRemoteIp')) {
            $ip = $connection->getRemoteIp();
            return is_string($ip) ? $ip : '';
        }

        if (isset($connection->remoteIp) && is_string($connection->remoteIp)) {
            return $connection->remoteIp;
        }

        return '';
    }

    private function isTrustedProxyIp(string $peerIp): bool
    {
        if ($peerIp === '') {
            return false;
        }

        return in_array($peerIp, $this->getTrustedProxyIps(), true);
    }

    /**
     * @return list<string>
     */
    private function getTrustedProxyIps(): array
    {
        $raw = lottoRuntimeEnv('LOTTO_TRUSTED_PROXY_IPS');
        if ($raw === null || $raw === '') {
            return ['127.0.0.1', '::1'];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $part): bool => $part !== ''
        ));
    }

    private function extractClientIpFromRequest(?object $request): ?string
    {
        if ($request === null || !is_object($request) || !method_exists($request, 'header')) {
            return null;
        }

        $xff = $request->header('x-forwarded-for');
        if (is_string($xff) && $xff !== '') {
            $parts = explode(',', $xff);
            $first = trim($parts[0]);
            if ($this->isValidIp($first)) {
                return $first;
            }
        }

        $realIp = $request->header('x-real-ip');
        if (is_string($realIp) && $realIp !== '') {
            $trimmed = trim($realIp);
            if ($this->isValidIp($trimmed)) {
                return $trimmed;
            }
        }

        return null;
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
