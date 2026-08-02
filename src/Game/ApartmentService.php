<?php

declare(strict_types=1);

namespace Lotto\Game;

use Lotto\Core\Constants;

use function Lotto\Core\sendError;
use function Lotto\Core\sendJson;
use function Lotto\Core\lottoTimerAdd;
use function Lotto\Core\lottoTimerDel;
use function Lotto\Core\lottoEconomyRecord;
use function Lotto\Core\lottoStateTransition;
use function Lotto\Core\lottoStateReject;

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
    private ?object $gameService = null;
    private const APARTMENT_PAYMENT = 5;

    public function __construct(object $db, object $stmts, object $logger)
    {
        $this->db     = $db;
        $this->stmts  = $stmts;
        $this->logger = $logger;
    }

    /**
     * Post-construction wiring for early-finish after admin removal (EPIC-13.5).
     */
    public function bindGameService(object $gameService): void
    {
        $this->gameService = $gameService;
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
     * @return array<int, bool> connId → required
     */
    public function prepareApartment(array &$room): array
    {
        $roomId = (int) ($room['room_id'] ?? 0);
        lottoStateTransition($roomId, 'playing', 'apartment', 'apartment_detected');
        $room['status']              = 'apartment';
        $room['apartment_fired']     = true;
        $room['apartment_responses'] = [];

        // Closed-row players earned immunity (triggered the event); others must pay.
        // Persist onto player['immune'] so getParticipants() stays consistent mid-round.
        $participants = [];
        foreach ($room['players'] as $connId => $player) {
            if (($player['status'] ?? '') !== 'active') {
                continue;
            }
            $playerHasLine = $this->hasLine($player);
            $room['players'][$connId]['immune'] = $playerHasLine;
            $participants[$connId] = !$playerHasLine;
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
     * EPIC-13.5: after any apartment removal, finish early if all required answered.
     * Reuses refuse-path logic from handleApartmentChoice().
     */
    public function maybeFinishApartmentEarly(array &$room, int $roomId, object $worker): void
    {
        if ($this->gameService === null || ($room['status'] ?? null) !== 'apartment') {
            return;
        }
        $participants = $this->getParticipants($room);
        if ($this->allRequiredAnswered($room, $participants)) {
            $this->finishApartment($room, $roomId, $worker, $this->gameService);
        }
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

    /**
     * Получить участников apartment фазы.
     *
     * Совместимость:
     * - Unit tests Phase 7 могут подсовывать $room['_apartment_participants'].
     *   Мы НЕ создаём этот ключ, но если он уже есть — используем его.
     *
     * @return array<int, bool> connId → required
     */
    private function getParticipants(array $room): array
    {
        if (isset($room['_apartment_participants']) && is_array($room['_apartment_participants'])) {
            return $room['_apartment_participants'];
        }

        // Matches prepareApartment(): immune (closed row) → required=false; others pay.
        $participants = [];
        foreach ($room['players'] as $connId => $player) {
            if (($player['status'] ?? '') !== 'active') {
                continue;
            }
            $participants[$connId] = empty($player['immune']);
        }
        return $participants;
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

        if (!empty($room['game_afk_timer_id'])) {
            lottoTimerDel((int) $room['game_afk_timer_id'], 'game_afk', ['room_id' => $roomId]);
            $room['game_afk_timer_id'] = null;
        }

        $this->logger->info("Room {$roomId}: apartment triggered");

        // Broadcast apartment_alert
        foreach ($room['players'] as $connId => $player) {
            if ($player['status'] !== 'active') continue;
            $required = $participants[$connId] ?? false;
            $player['connection']->send(json_encode([
                'type'      => 'apartment_alert',
                'required'  => $required,
                'time_left' => Constants::apartmentTimeout(),
            ]));
        }

        // Apartment timer — 10s single-shot (ANCHOR_CORE Part 5)
        $self = $this;
        $room['apartment_timer_id'] = lottoTimerAdd(
            (float) Constants::apartmentTimeout(),
            function() use (&$room, $roomId, $worker, $gameService, $self) {
                if (!isset($worker->rooms[$roomId])) return;
                $self->onApartmentTimeout($room, $roomId, $worker, $gameService);
            },
            [],
            false,
            'apartment',
            ['room_id' => $roomId]
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
            lottoStateReject($roomId, $room['status'], 'apartment_choice', 'error.not_your_turn');
            sendError($connection, 'error.not_your_turn', 'No apartment in progress');
            return;
        }

        $participants = $this->getParticipants($room);

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
        $participants = $this->getParticipants($room);

        foreach ($this->getPendingRequired($room, $participants) as $connId) {
            $this->recordResponse($room, $connId, 'refuse');
            $this->removePlayerFromApartment($room, $roomId, $connId, 'refuse', $worker);
            if (!isset($worker->rooms[$roomId])) return;
        }

        $this->finishApartment($room, $roomId, $worker, $gameService, 'apartment_timeout');
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
        object $gameService,
        string $resumeTrigger = 'apartment_complete'
    ): void {
        // Остановить таймер
        if (!empty($room['apartment_timer_id'])) {
            lottoTimerDel((int) $room['apartment_timer_id'], 'apartment', ['room_id' => $roomId]);
            $room['apartment_timer_id'] = null;
        }

        $participants = $this->getParticipants($room);
        $agreed       = $this->getAgreeList($room, $participants);

        // Предусловия оплаты: если игрок согласился, но не может оплатить — это refuse.
        // Удаляем таких игроков до транзакции (они не участвуют в оплате).
        if (!empty($agreed)) {
            foreach ($agreed as $connId) {
                if (!isset($room['players'][$connId])) {
                    continue;
                }
                $userId = (int)($room['players'][$connId]['user_id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }
                $stmt = $this->stmts->get('user_by_id');
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if ($row === false) {
                    continue;
                }
                if ((int)$row['coins'] < self::APARTMENT_PAYMENT) {
                    // Не хватает монет — трактуем как refuse (контракт: иначе экономика ломается)
                    $this->recordResponse($room, $connId, 'refuse');
                    $this->removePlayerFromApartment($room, $roomId, $connId, 'refuse', $worker);
                    if (!isset($worker->rooms[$roomId])) {
                        return;
                    }
                }
            }
        }

        // Пересчитать участников/agree после возможных удалений
        $participants = $this->getParticipants($room);
        $agreed       = $this->getAgreeList($room, $participants);

        // Транзакционная оплата (ANCHOR_CORE § Apartment Payment): банк + coins строго вместе
        if (!empty($agreed)) {
            $pdo = $this->db->getPdo();
            try {
                $pdo->beginTransaction();
                foreach ($agreed as $connId) {
                    if (!isset($room['players'][$connId])) {
                        continue;
                    }
                    $userId = (int)($room['players'][$connId]['user_id'] ?? 0);
                    if ($userId <= 0) {
                        continue;
                    }

                    $stmt = $this->stmts->get('user_by_id');
                    $stmt->execute([$userId]);
                    $row = $stmt->fetch();
                    if ($row === false) {
                        continue;
                    }

                    if ((int)$row['coins'] < self::APARTMENT_PAYMENT) {
                        // Защита от гонок: если баланс изменился между проверкой и транзакцией — игрок вылетает
                        $pdo->rollBack();
                        $this->recordResponse($room, $connId, 'refuse');
                        $this->removePlayerFromApartment($room, $roomId, $connId, 'refuse', $worker);
                        if (!isset($worker->rooms[$roomId])) {
                            return;
                        }
                        // Стартуем транзакцию заново для оставшихся
                        $pdo->beginTransaction();
                        continue;
                    }

                    $newCoins = (int)$row['coins'] - self::APARTMENT_PAYMENT;
                    $upd = $this->stmts->get('update_user_coins');
                    $upd->execute([$newCoins, $userId]);

                    $room['bank']                           += self::APARTMENT_PAYMENT;
                    $room['players'][$connId]['total_paid'] += self::APARTMENT_PAYMENT;
                    $room['players'][$connId]['immune']      = true;

                    lottoEconomyRecord('apartment', $userId, -self::APARTMENT_PAYMENT, [
                        'room_id' => $roomId,
                    ]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $this->logger->error("finishApartment: payment failed: " . $e->getMessage());
            }
        }

        $active = array_filter($room['players'], fn($p) => $p['status'] === 'active');

        if (count($active) === 0) {
            $this->gameService->handleNoSurvivors($room, $roomId, $worker);
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
        lottoStateTransition($roomId, 'apartment', 'playing', $resumeTrigger);
        $room['status'] = 'playing';
        $this->logger->info("Room {$roomId}: apartment finished, game resumes");
        $gameService->startTurn($room, $worker, $roomId, true);
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

        $wasHost = ($room['host_conn_id'] ?? null) === $connId;
        $player = $room['players'][$connId];
        $userId = (int)($player['user_id'] ?? 0);

        // FIX-6: защитная отмена reconnect_timer (ANCHOR_CORE.md Part 5 §
        // Timer Integrity Rules). В apartment-состоянии reconnect запрещён
        // и таймер по спецификации не должен существовать, но правило
        // абсолютное ("A destroyed owner keeps no timers") — на случай
        // рассогласования состояния.
        if (!empty($player['reconnect_timer'])) {
            lottoTimerDel((int) $player['reconnect_timer'], 'reconnect', [
                'room_id' => $roomId,
                'conn_id' => $connId,
            ]);
        }

        // История должна содержать user_id для возвратов (ANCHOR_CORE Part 2 § No Survivors/Admin Close Room)
        $room['all_players_history'][$connId] = [
            'user_id'    => $userId,
            'username'   => $player['username'],
            'total_paid' => $player['total_paid'],
        ];

        if (($player['status'] ?? null) === 'active' && isset($player['connection'])) {
            sendJson($player['connection'], [
                'type'     => 'player_left',
                'username' => $player['username'],
                'reason'   => $reason,
            ]);
        }

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

        $active = array_filter(
            $room['players'],
            fn($p) => ($p['status'] ?? null) === 'active'
        );
        if (count($active) === 0) {
            $notifyConn = (($player['status'] ?? null) === 'active' && isset($player['connection']))
                ? $player['connection']
                : null;
            $this->gameService->handleNoSurvivors($room, $roomId, $worker, $notifyConn);
            return;
        }

        if ($wasHost) {
            foreach ($room['drawer_order'] as $candidateConnId) {
                if (
                    isset($room['players'][$candidateConnId]) &&
                    ($room['players'][$candidateConnId]['status'] ?? null) === 'active'
                ) {
                    $room['host_conn_id'] = $candidateConnId;
                    break;
                }
            }
            $this->broadcastHostChanged($room);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function broadcastHostChanged(array $room): void
    {
        $hostUsername = $this->resolveHostUsername($room);
        if ($hostUsername === '') {
            return;
        }

        $packet = [
            'type' => 'host_changed',
            'host' => $hostUsername,
        ];

        foreach ($room['players'] as $player) {
            if (($player['status'] ?? null) === 'active' && isset($player['connection'])) {
                sendJson($player['connection'], $packet);
            }
        }
    }

    private function resolveHostUsername(array $room): string
    {
        if (count($room['players']) < 2) {
            return '';
        }

        $hostConnId = $room['host_conn_id'] ?? null;
        if ($hostConnId === null || !isset($room['players'][$hostConnId])) {
            return '';
        }

        return (string) $room['players'][$hostConnId]['username'];
    }

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

    /**
     * Уничтожение комнаты согласно ANCHOR_CORE Part 5 (таймеры + reconnect_timer).
     * RoomManager::destroyRoom() здесь недоступен по DI, поэтому повторяем контракт строго.
     */
    private function destroyRoom(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = $worker->rooms[$roomId];

        if (!empty($room['lobby_afk_timer_id'])) {
            lottoTimerDel((int) $room['lobby_afk_timer_id'], 'lobby_afk', ['room_id' => $roomId]);
        }
        if (!empty($room['game_afk_timer_id'])) {
            lottoTimerDel((int) $room['game_afk_timer_id'], 'game_afk', ['room_id' => $roomId]);
        }
        if (!empty($room['apartment_timer_id'])) {
            lottoTimerDel((int) $room['apartment_timer_id'], 'apartment', ['room_id' => $roomId]);
        }

        foreach (($room['players'] ?? []) as $playerConnId => $player) {
            if (!empty($player['reconnect_timer'])) {
                lottoTimerDel((int) $player['reconnect_timer'], 'reconnect', [
                    'room_id' => $roomId,
                    'conn_id' => $playerConnId,
                ]);
            }
        }

        unset($worker->rooms[$roomId]);
    }
}
