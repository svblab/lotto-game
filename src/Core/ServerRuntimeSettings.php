<?php

declare(strict_types=1);

namespace Lotto\Core;

/**
 * RAM-only server settings adjustable by admin at runtime (worker scope).
 * Defaults mirror Constants / env; lost on worker restart.
 */
final class ServerRuntimeSettings
{
    public static function initOnWorker(object $worker): void
    {
        $worker->serverSettings = [
            'max_accounts_per_ip' => Constants::maxAccountsPerIp(),
            'bet_per_card'        => Constants::BET_PER_CARD,
            'apartment_payment'   => Constants::APARTMENT_PAYMENT,
        ];
    }

    /**
     * @return array{max_accounts_per_ip:int,bet_per_card:int,apartment_payment:int}
     */
    public static function snapshot(object $worker): array
    {
        self::ensureInit($worker);

        return [
            'max_accounts_per_ip' => (int) $worker->serverSettings['max_accounts_per_ip'],
            'bet_per_card'        => (int) $worker->serverSettings['bet_per_card'],
            'apartment_payment'   => (int) $worker->serverSettings['apartment_payment'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return string|null error message when invalid
     */
    public static function apply(object $worker, array $data): ?string
    {
        self::ensureInit($worker);

        if (array_key_exists('max_accounts_per_ip', $data)) {
            $value = (int) $data['max_accounts_per_ip'];
            if ($value < 1 || $value > 9999) {
                return 'max_accounts_per_ip must be between 1 and 9999';
            }
            $worker->serverSettings['max_accounts_per_ip'] = $value;
        }

        if (array_key_exists('bet_per_card', $data)) {
            $value = (int) $data['bet_per_card'];
            if ($value < 1 || $value > 1000) {
                return 'bet_per_card must be between 1 and 1000';
            }
            $worker->serverSettings['bet_per_card'] = $value;
            foreach ($worker->rooms ?? [] as &$room) {
                if (($room['status'] ?? '') === 'waiting') {
                    $room['bet_per_card'] = $value;
                }
            }
            unset($room);
        }

        if (array_key_exists('apartment_payment', $data)) {
            $value = (int) $data['apartment_payment'];
            if ($value < 0 || $value > 1000) {
                return 'apartment_payment must be between 0 and 1000';
            }
            $worker->serverSettings['apartment_payment'] = $value;
        }

        return null;
    }

    public static function maxAccountsPerIp(object $worker): int
    {
        self::ensureInit($worker);

        return (int) $worker->serverSettings['max_accounts_per_ip'];
    }

    public static function betPerCard(object $worker, ?array $room = null): int
    {
        if ($room !== null && isset($room['bet_per_card'])) {
            return max(1, (int) $room['bet_per_card']);
        }

        self::ensureInit($worker);

        return (int) $worker->serverSettings['bet_per_card'];
    }

    public static function apartmentPayment(object $worker): int
    {
        self::ensureInit($worker);

        return (int) $worker->serverSettings['apartment_payment'];
    }

    private static function ensureInit(object $worker): void
    {
        if (!isset($worker->serverSettings) || !is_array($worker->serverSettings)) {
            self::initOnWorker($worker);
        }
    }
}
