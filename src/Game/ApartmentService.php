<?php

declare(strict_types=1);

namespace Lotto\Game;

/**
 * ApartmentService — EPIC-7.0 / 7.1 / 7.2 / 7.3
 *
 * Чистая логика апартамента. Forbidden: socket sending, db access, timers, victory logic.
 *
 * Механика (ANCHOR_CORE.md Part 2 & Part 4):
 *   - Триггер: хотя бы у одного активного игрока закрыта целая строка (5 чисел).
 *   - Срабатывает at most once per game (apartment_fired флаг).
 *   - Victory > Apartment: если в том же ходу есть победитель — апартамент игнорируется.
 *   - required: игрок должен ответить (не immune).
 *   - agree: bank += 5; player.total_paid += 5.
 *   - refuse / timeout: игрок удаляется (reason 'refuse').
 *   - immune: игрок видит alert (required=false), не обязан отвечать.
 */
final class ApartmentService
{
    // -------------------------------------------------------------------------
    // EPIC-7.0  Line detection
    // -------------------------------------------------------------------------

    /**
     * Проверить есть ли у игрока хотя бы одна закрытая строка на любой карте.
     *
     * Строка закрыта если все 5 чисел в ней закрыты маской.
     * Пустые ячейки (null) не учитываются.
     *
     * @param  array $player  Player structure
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
            return false; // at most once per game
        }

        foreach ($room['players'] as $player) {
            if ($player['status'] !== 'active') {
                continue;
            }
            if ($this->hasLine($player)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // EPIC-7.1  Apartment trigger state
    // -------------------------------------------------------------------------

    /**
     * Подготовить комнату к апартаментному голосованию.
     *
     * Устанавливает:
     *   - status = 'apartment'
     *   - apartment_fired = true
     *   - apartment_responses = []
     *
     * Возвращает массив игроков с флагом required:
     *   required=true  → не immune, должен ответить
     *   required=false → immune, только уведомляется
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
            if ($player['status'] !== 'active') {
                continue;
            }
            $required                = !$player['immune'];
            $participants[$connId]   = $required;
        }

        return $participants;
    }

    // -------------------------------------------------------------------------
    // EPIC-7.2  Apartment voting state tracking
    // -------------------------------------------------------------------------

    /**
     * Записать ответ игрока.
     *
     * @param  array  &$room
     * @param  int    $connId
     * @param  string $choice  'agree' | 'refuse'
     */
    public function recordResponse(array &$room, int $connId, string $choice): void
    {
        $room['apartment_responses'][$connId] = $choice;
    }

    /**
     * Проверить получены ли все обязательные ответы.
     *
     * @param  array $room
     * @param  array $participants  connId → required (из prepareApartment)
     * @return bool
     */
    public function allRequiredAnswered(array $room, array $participants): bool
    {
        foreach ($participants as $connId => $required) {
            if (!$required) {
                continue;
            }
            if (!isset($room['apartment_responses'][$connId])) {
                // Проверить что игрок ещё активен
                if (isset($room['players'][$connId]) &&
                    $room['players'][$connId]['status'] === 'active') {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Получить список required игроков которые ещё не ответили.
     *
     * @param  array $room
     * @param  array $participants  connId → required
     * @return int[]  connIds
     */
    public function getPendingRequired(array $room, array $participants): array
    {
        $pending = [];
        foreach ($participants as $connId => $required) {
            if (!$required) {
                continue;
            }
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
     * Получить список игроков которые ответили 'agree'.
     *
     * @param  array $room
     * @param  array $participants  connId → required
     * @return int[]  connIds
     */
    public function getAgreeList(array $room, array $participants): array
    {
        $agreed = [];
        foreach ($participants as $connId => $required) {
            if (!$required) {
                continue; // immune не платит
            }
            if (($room['apartment_responses'][$connId] ?? '') === 'agree') {
                $agreed[] = $connId;
            }
        }
        return $agreed;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Проверить закрыта ли строка $row на карте.
     */
    private function isRowComplete(array $card, array $mask, int $row): bool
    {
        $filledCount = 0;
        for ($col = 0; $col < 9; $col++) {
            $cell = $card[$row][$col] ?? null;
            if ($cell === null) {
                continue;
            }
            if (empty($mask[$row][$col])) {
                return false; // число есть, не закрыто
            }
            $filledCount++;
        }
        // Строка должна содержать хотя бы 1 число
        return $filledCount >= 1;
    }
}
