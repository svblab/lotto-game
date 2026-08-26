<?php

declare(strict_types=1);

namespace Lotto\Core;

/**
 * EPIC-11.4 — State machine audit instrumentation.
 *
 * Opt-in via LOTTO_STATE_AUDIT=1. Writes structured events to
 * logs/state_machine_audit.log for transition replay and validation.
 *
 * Room states and transitions follow ANCHOR_CORE.md Part 4.
 *
 * Optional log path override:
 *   - constructor ?string $logFilePath
 *   - env LOTTO_STATE_AUDIT_LOG
 */
final class StateMachineAudit
{
    private const DEFAULT_LOG_FILENAME = 'state_machine_audit.log';

    /** @var list<string> */
    public const ROOM_STATES = ['waiting', 'playing', 'apartment', 'finished'];

    /** @var list<string> */
    public const PLAYER_STATES = ['active', 'disconnected'];

    /**
     * Allowed room transitions: from => [to => [triggers...]]
     *
     * @var array<string, array<string, list<string>>>
     */
    public const ROOM_TRANSITIONS = [
        'created' => [
            'waiting' => ['room_created'],
        ],
        'waiting' => [
            'playing'   => ['start_game', 'play_vs_bot'],
            'destroyed' => ['no_players', 'admin_close', 'room_destroyed'],
        ],
        'playing' => [
            'apartment' => ['apartment_detected'],
            'finished'  => ['victory', 'last_survivor'],
            'destroyed' => ['admin_close', 'no_active_players', 'room_destroyed'],
        ],
        'apartment' => [
            'playing'   => ['apartment_complete', 'apartment_timeout'],
            'finished'  => ['victory', 'last_survivor'],
            'destroyed' => ['admin_close', 'room_destroyed'],
        ],
        'finished' => [
            'destroyed' => ['game_over_cleanup'],
        ],
    ];

    /**
     * Allowed actions per room state (ANCHOR_CORE.md Part 4).
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_ACTIONS = [
        'waiting' => ['room_list', 'join_room', 'leave_room', 'start_game', 'play_vs_bot', 'reconnect', 'ping'],
        'playing' => ['draw_barrel', 'leave_room', 'ping', 'reconnect', 'turn_ready', 'nudge_turn'],
        'apartment' => ['apartment_choice', 'ping'],
        'finished' => [],
    ];

    /**
     * Allowed player transitions: from => [to => [triggers...]]
     *
     * @var array<string, array<string, list<string>>>
     */
    public const PLAYER_TRANSITIONS = [
        'active' => [
            'disconnected' => ['connection_lost'],
        ],
        'disconnected' => [
            'active' => ['reconnect'],
        ],
    ];

    private Logger $logger;
    private string $logFile;

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

        $envPath = getenv('LOTTO_STATE_AUDIT_LOG');
        if (is_string($envPath) && $envPath !== '') {
            return $envPath;
        }

        return dirname(__DIR__, 2) . '/logs/' . self::DEFAULT_LOG_FILENAME;
    }

    public static function isEnabled(): bool
    {
        $flag = getenv('LOTTO_STATE_AUDIT');
        return $flag === '1' || $flag === 'true';
    }

    public static function isRoomTransitionAllowed(string $from, string $to, string $trigger): bool
    {
        return in_array($trigger, self::ROOM_TRANSITIONS[$from][$to] ?? [], true);
    }

    public static function isActionAllowed(string $roomState, string $action): bool
    {
        return in_array($action, self::ALLOWED_ACTIONS[$roomState] ?? [], true);
    }

    public static function isPlayerTransitionAllowed(string $from, string $to, string $trigger): bool
    {
        return in_array($trigger, self::PLAYER_TRANSITIONS[$from][$to] ?? [], true);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function recordTransition(int $roomId, string $from, string $to, string $trigger, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (!self::isRoomTransitionAllowed($from, $to, $trigger)) {
            $this->logger->warning(
                "StateMachineAudit: unexpected room transition room_id={$roomId} {$from}→{$to} trigger={$trigger}"
            );
        }

        $this->writeEvent('transition', [
            'room_id' => $roomId,
            'from'    => $from,
            'to'      => $to,
            'trigger' => $trigger,
        ] + $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function recordRejection(int $roomId, string $state, string $action, string $code, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $this->writeEvent('reject', [
            'room_id' => $roomId,
            'state'   => $state,
            'action'  => $action,
            'code'    => $code,
        ] + $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function recordPlayerTransition(
        int $roomId,
        int $connId,
        string $from,
        string $to,
        string $trigger,
        array $context = []
    ): void {
        if (!self::isEnabled()) {
            return;
        }

        if (!self::isPlayerTransitionAllowed($from, $to, $trigger)) {
            $this->logger->warning(
                "StateMachineAudit: unexpected player transition room_id={$roomId} conn_id={$connId} {$from}→{$to} trigger={$trigger}"
            );
        }

        $this->writeEvent('player_transition', [
            'room_id' => $roomId,
            'conn_id' => $connId,
            'from'    => $from,
            'to'      => $to,
            'trigger' => $trigger,
        ] + $context);
    }

    /**
     * @return list<array<string, scalar|null>>
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
            if (!preg_match('/\[STATE\]\s+(.+)$/', $line, $m)) {
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

            if (!isset($fields['event'])) {
                continue;
            }

            $events[] = $fields;
        }

        return $events;
    }

    /**
     * Replay a parsed log and verify every transition/rejection conforms to spec.
     *
     * @param list<array<string, scalar|null>> $events
     * @return list<string> failure messages (empty = pass)
     */
    public static function validateLog(array $events): array
    {
        $failures = [];
        $roomStates = [];

        foreach ($events as $i => $event) {
            $line = $i + 1;
            $type = (string) ($event['event'] ?? '');

            if ($type === 'transition') {
                $roomId = (int) ($event['room_id'] ?? 0);
                $from = (string) ($event['from'] ?? '');
                $to = (string) ($event['to'] ?? '');
                $trigger = (string) ($event['trigger'] ?? '');

                if (!self::isRoomTransitionAllowed($from, $to, $trigger)) {
                    $failures[] = "line {$line}: invalid room transition {$from}→{$to} trigger={$trigger}";
                }

                if (isset($roomStates[$roomId]) && $roomStates[$roomId] !== $from) {
                    $failures[] = "line {$line}: room {$roomId} expected from={$roomStates[$roomId]}, got {$from}";
                }

                $roomStates[$roomId] = $to === 'destroyed' ? null : $to;
                if ($to === 'destroyed') {
                    unset($roomStates[$roomId]);
                }
            } elseif ($type === 'reject') {
                $state = (string) ($event['state'] ?? '');
                $action = (string) ($event['action'] ?? '');
                if (self::isActionAllowed($state, $action)) {
                    $failures[] = "line {$line}: reject logged but action {$action} is allowed in {$state}";
                }
            } elseif ($type === 'player_transition') {
                $from = (string) ($event['from'] ?? '');
                $to = (string) ($event['to'] ?? '');
                $trigger = (string) ($event['trigger'] ?? '');
                if (!self::isPlayerTransitionAllowed($from, $to, $trigger)) {
                    $failures[] = "line {$line}: invalid player transition {$from}→{$to} trigger={$trigger}";
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<string, scalar|null> $fields
     */
    private function writeEvent(string $event, array $fields): void
    {
        $parts = ['event=' . $event, 'ts_us=' . (string) (int) (microtime(true) * 1_000_000)];

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = "{$key}=" . (is_scalar($value) ? $value : json_encode($value));
        }

        $this->writeLine(implode(' ', $parts));
    }

    private function writeLine(string $message): void
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] [STATE] ' . $message . PHP_EOL;
        $result = file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            $this->logger->error('StateMachineAudit: failed to write to log file ' . $this->logFile);
        }
    }
}
