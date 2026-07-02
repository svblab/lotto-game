<?php

declare(strict_types=1);

namespace Lotto\Game;

use function Lotto\Core\sendError;

/**
 * ApartmentService — EPIC-7.0 / 7.1 / 7.2 / 7.3 / 7.4 / 7.5
 *
 * Ответственности:
 *   - Line detection (pure math)
 *   - Apartment trigger, state, voting, payment, timeout
 *   - Player removal with reason 'refuse'
 *
 * Forbidden: victory logic, authentication.
 *
 * Зависимости через конструктор — нужны для оркестрации (db, sockets).
 * GameService передаёт себя как $gameService для вызова finishGame().
 */
final class ApartmentService
{
    private object $db;
    private object $stmts;
    private object $logger;

    public function __construct(object $db, object $stmts, object $logger)
    {
        $this->db     = $db;
        $this->stmts  = $stmts;
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // EPIC-7.0  Line detection (pure math)
    // -------------------------------------------------------------------------

    /**
     * Проверить есть ли у игрока хотя бы одна закрытая строка на любой карте.
     *
     * @param  array $player
     * @return bool
     */
    public function hasLine(array $player): bool
    {
        $cards = $player['cards'] ?? [];
        $masks = $player['masks'] ?? [];

        foreach ($cards as $cardIdx => $card) {
            $mask = $masks[$cardIdx] ?? [];
            for ($row = 0; $row < 3; $row++) {
                if ($this->isRowComplete($card, $mask, $row)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Проверить всех активных игроков — есть ли хотя бы один с закрытой строкой.
     *
     * @param  array $room
     * @return bool
     */
    public function shouldTrigger(array $room): bool
    {
        if ($room['apartment_fired']) {
            return false;
        }
        foreach ($room['players'] as $player) {
            if ($player['status'] !== 'active') continue;
            if ($this->hasLine($player)) return true;
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // EPIC-7.1  Apartment trigger state
    // -------------------------------------------------------------------------

    /**
     * Подготовить комнату к апартаментному голосованию.
     *
     * @param  array &$room
     * @return array<int, bool>  connId → required
     */
    public function prepareApartment(array &$room): array
    {
        $room['status']              = 'apartment';
        $room['apartment_fired']     = true;
        $room['apartment_responses'] = [];

        $participants = [];
        foreach ($room['players'] as $connId => $player) {
            if ($player['status'] !== 'active') continue;
            $participants[$connId] = !$player['immune'];
        }
        return $participants;
    }

    // -------------------------------------------------------------------------
    // EPIC-7.2 / 7.3  Apartment voting
    // -------------------------------------------------------------------------

    /**
     * Записать ответ игрока.
     */
    public function recordResponse(array &$room, int $connId, string $choice): void
    {
        $room['apartment_responses'][$connId] = $choice;
    }

    /**
     * Проверить получены ли все обязательные ответы.
     *
     * @param  array $room
     * @param  array $participants  connId → required
     * @return bool
     */
    public function allRequiredAnswered(array $room, array $participants): bool
    {
        foreach ($participants as $connId => $required) {
            if (!$required) continue;
            if (!isset($room['apartment_responses'][$connId])) {
                if (isset($room['players'][$connId]) &&
                    $room['players'][$connId]['status'] === 'active') {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Получить pending required игроков.
     *
     * @return int[]
     */
    public function getPendingRequired(array $room, array $participants): array
    {
        $pending = [];
        foreach ($participants as $connId => $required) {
            if (!$required) continue;
            if (!isset($room['apartment_responses'][$connId])) {
                if (isset($room['players'][$connId]) &&
                    $room['players'][$connId]['status'] === 'active') {
                    $pending[] = $connId;
                }
            }
        }
        return $pending;
    }

    /**
     * Список игроков ответивших 'agree'.
     *
     * @return int[]
     */
    public function getAgreeList(array $room, array $participants): array
    {
        $agreed = [];
        foreach ($participants as $connId => $required) {
            if (!$required) continue;
            if (($room['apartment_responses'][$connId] ?? '') === 'agree') {
                $agreed[] = $connId;
            }
        }
        return $agreed;
    }

    // -------------------------------------------------------------------------
    // EPIC-7.2 / 7.5  Orchestration (trigger, timeout, finish)
    // -------------------------------------------------------------------------

    /**
     * Запустить апартаментное голосование.
     * Вызывается из GameService::handleDrawBarrel() когда shouldTrigger() = true.
     *
     * @param array   &$room
     * @param int     $roomId
     * @param object  $worker
     * @param object  $gameService  GameService — нужен для finishGame()
     */
    public function triggerApartment(
        array &$room,
        int $roomId,
        object $worker,
        object $gameService
    ): void {
        $participants = $this->prepareApartment($room);
        $room['_apartment_participants'] = $participants;

        $this->logger->info("Room {$roomId}: apartment triggered");

        // Broadcast apartment_alert
        foreach ($room['players'] as $connId => $player) {
            if ($player['status'] !== 'active') continue;
            $required = $participants[$connId] ?? false;
            $player['connection']->send(json_encode([
                'type'      => 'apartment_alert',
                'required'  => $required,
                'time_left' => 10,
            ]));
        }

        // Apartment timer — 10s single-shot (ANCHOR_CORE Part 5)
        $self = $this;
        $room['apartment_timer_id'] = \Workerman\Timer::add(
            10,
            function() use (&$room, $roomId, $worker, $gameService, $self) {
                if (!isset($worker->rooms[$roomId])) return;
                $self->onApartmentTimeout($room, $roomId, $worker, $gameService);
            },
            [],
            false
        );
    }

    /**
     * Обработать ответ игрока {"action": "apartment_choice"}.
     *
     * @param object $connection
     * @param object $worker
     * @param string $choice      'agree' | 'refuse'
     * @param object $gameService GameService — нужен для finishGame()
     */
    public function handleApartmentChoice(
        object $connection,
        object $worker,
        string $choice,
        object $gameService
    ): void {
        if (empty($connection->userId)) {
            sendError($connection, 'error.auth_required');
            return;
        }

        $connId = $connection->id;

        $roomId = null;
        foreach ($worker->rooms as $rid => $r) {
            if (isset($r['players'][$connId])) { $roomId = $rid; break; }
        }
        if ($roomId === null) {
            sendError($connection, 'error.room_not_found');
            return;
        }

        $room = &$worker->rooms[$roomId];

        if ($room['status'] !== 'apartment') {
            sendError($connection, 'error.not_your_turn', 'No apartment in progress');
            return;
        }

        $participants = $room['_apartment_participants'] ?? [];

        if (!isset($participants[$connId]) || !$participants[$connId]) {
            return; // immune — молча игнорируем
        }

        if (isset($room['apartment_responses'][$connId])) {
            return; // уже ответил
        }

        $this->recordResponse($room, $connId, $choice);

        if ($choice === 'refuse') {
            $this->removePlayerFromApartment($room, $roomId, $connId, 'refuse', $worker);
            if (!isset($worker->rooms[$roomId])) return;
        }

        if ($this->allRequiredAnswered($room, $participants)) {
            $this->finishApartment($room, $roomId, $worker, $gameService);
        }
    }

    /**
     * Таймаут — неответившие required игроки = refuse.
     */
    public function onApartmentTimeout(
        array &$room,
        int $roomId,
        object $worker,
        object $gameService
    ): void {
        $room['apartment_timer_id'] = null;
        $participants = $room['_apartment_participants'] ?? [];

        foreach ($this->getPendingRequired($room, $participants) as $connId) {
            $this->recordResponse($room, $connId, 'refuse');
            $this->removePlayerFromApartment($room, $roomId, $connId, 'refuse', $worker);
            if (!isset($worker->rooms[$roomId])) return;
        }

        $this->finishApartment($room, $roomId, $worker, $gameService);
    }

    // -------------------------------------------------------------------------
    // EPIC-7.4  Apartment payment + finish
    // -------------------------------------------------------------------------

    /**
     * Завершить апартамент: оплата → resume playing / last_survivor / no_survivors.
     */
    public function finishApartment(
        array &$room,
        int $roomId,
        object $worker,
        object $gameService
    ): void {
        // Остановить таймер
        if (!empty($room['apartment_timer_id'])) {
            \Workerman\Timer::del($room['apartment_timer_id']);
            $room['apartment_timer_id'] = null;
        }

        $participants = $room['_apartment_participants'] ?? [];
        $agreed       = $this->getAgreeList($room, $participants);

        // Транзакционная оплата (ANCHOR_CORE § Apartment Payment)
        if (!empty($agreed)) {
            $pdo = $this->db->getPdo();
            try {
                $pdo->beginTransaction();
                foreach ($agreed as $connId) {
                    if (!isset($room['players'][$connId])) continue;
                    $userId = $room['players'][$connId]['user_id'];
                    $stmt = $this->stmts->get('user_by_id');
                    $stmt->execute([$userId]);
                    $row = $stmt->fetch();
                    if ($row === false) continue;

                    $newCoins = max(0, (int)$row['coins'] - 5);
                    $upd = $this->stmts->get('update_user_coins');
                    $upd->execute([$newCoins, $userId]);

                    $room['bank']                           += 5;
                    $room['players'][$connId]['total_paid'] += 5;
                    $room['players'][$connId]['immune']      = true;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $this->logger->error("finishApartment: payment failed: " . $e->getMessage());
            }
        }

        unset($room['_apartment_participants']);

        $active = array_filter($room['players'], fn($p) => $p['status'] === 'active');

        if (count($active) === 0) {
            $this->handleNoSurvivors($room, $roomId, $worker);
            return;
        }

        if (count($active) === 1) {
            $survivorConnId = array_key_first($active);
            $gameService->finishGame(
                $room, $roomId,
                [$survivorConnId => 1],
                [$survivorConnId => $room['bank']],
                $worker,
                'last_survivor'
            );
            return;
        }

        // Продолжаем игру
        $room['status'] = 'playing';
        $this->logger->info("Room {$roomId}: apartment finished, game resumes");
        $gameService->sendYourTurn($room);
    }

    /**
     * Удалить игрока из комнаты в состоянии apartment (reason: 'refuse').
     */
    public function removePlayerFromApartment(
        array &$room,
        int $roomId,
        int $connId,
        string $reason,
        object $worker
    ): void {
        if (!isset($room['players'][$connId])) return;

        $player = $room['players'][$connId];

        $room['all_players_history'][] = [
            'conn_id'    => $connId,
            'username'   => $player['username'],
            'total_paid' => $player['total_paid'],
        ];

        unset($room['players'][$connId]);

        $room['drawer_order'] = array_values(
            array_filter($room['drawer_order'], fn($id) => $id !== $connId)
        );

        foreach ($room['players'] as $p) {
            if ($p['status'] === 'active') {
                $p['connection']->send(json_encode([
                    'type'     => 'player_left',
                    'username' => $player['username'],
                    'reason'   => $reason,
                ]));
            }
        }

        $this->logger->info(
            "Room {$roomId}: player {$player['username']} removed (reason: {$reason})"
        );

        if (empty($room['players'])) {
            unset($worker->rooms[$roomId]);
        }
    }

    // -------------------------------------------------------------------------
    // EPIC-7.x  No survivors
    // -------------------------------------------------------------------------

    /**
     * Нет выживших — возврат монет всем участникам (ANCHOR_CORE § No Survivors).
     */
    public function handleNoSurvivors(array &$room, int $roomId, object $worker): void
    {
        $pdo = $this->db->getPdo();
        try {
            $pdo->beginTransaction();
            foreach ($room['all_players_history'] as $hist) {
                $uid = $hist['user_id'] ?? 0;
                if (!$uid) continue;
                $stmt = $this->stmts->get('user_by_id');
                $stmt->execute([$uid]);
                $row = $stmt->fetch();
                if ($row === false) continue;
                $upd = $this->stmts->get('update_user_coins');
                $upd->execute([(int)$row['coins'] + $hist['total_paid'], $uid]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->logger->error("handleNoSurvivors: refund failed: " . $e->getMessage());
        }

        $this->logger->info("Room {$roomId}: no survivors, refunds issued");
        unset($worker->rooms[$roomId]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function isRowComplete(array $card, array $mask, int $row): bool
    {
        $filledCount = 0;
        for ($col = 0; $col < 9; $col++) {
            $cell = $card[$row][$col] ?? null;
            if ($cell === null) continue;
            if (empty($mask[$row][$col])) return false;
            $filledCount++;
        }
        return $filledCount >= 1;
    }
}
