<?php

declare(strict_types=1);

namespace Lotto\Game;

use function Lotto\Core\sendError;

/**
 * GameHandler — EPIC-10.5
 *
 * Обрабатывает WebSocket-пакеты игрового цикла: start_game, draw_barrel,
 * apartment_choice. Транслирует входящие пакеты ANCHOR_PROTOCOL.md в вызовы
 * GameService (Phase 4-7 — бизнес-логика уже реализована). Никакой новой
 * бизнес-логики здесь нет — только маршрутизация и разбор полей payload,
 * что соответствует прецеденту EPIC-10.3/10.4 (AuthHandler/LobbyHandler).
 *
 * Контракты worker-памяти (уже инициализируются в server.php с EPIC-10.4):
 *   $worker->rooms — array<roomId, room>
 *
 * Зависимости:
 *   GameService — бизнес-логика start_game/draw_barrel/apartment_choice
 *                 (внутри делегирует VictoryService/ApartmentService/
 *                 GameFinishService — уже собраны на уровне server.php).
 */
final class GameHandler
{
    private GameService $gameService;

    public function __construct(GameService $gameService)
    {
        $this->gameService = $gameService;
    }

    /**
     * {"action": "start_game"} — только хост, ANCHOR_PROTOCOL.md § Game Start.
     */
    public function handleStartGame(object $connection, object $worker): void
    {
        $this->gameService->handleStartGame($connection, $worker);
    }

    /**
     * {"action": "draw_barrel"} — только текущий active_drawer_conn_id.
     */
    public function handleDrawBarrel(object $connection, object $worker): void
    {
        $this->gameService->handleDrawBarrel($connection, $worker);
    }

    /**
     * {"action": "apartment_choice", "choice": "agree"|"refuse"}.
     *
     * Разбор поля `choice` — единственная новая проверка этого Epic'а
     * (роутинг-уровень, не бизнес-логика): GameService/ApartmentService
     * ожидают уже провалидированную строку. Собственно значения choice
     * ('agree'/'refuse' vs что-то иное) валидируются внутри
     * ApartmentService::handleApartmentChoice() — здесь только гарантия
     * того, что поле вообще присутствует и является строкой.
     */
    public function handleApartmentChoice(array $data, object $connection, object $worker): void
    {
        $choice = $data['choice'] ?? null;

        if (!is_string($choice) || $choice === '') {
            sendError($connection, 'error.invalid_json', 'Missing or invalid choice field');
            return;
        }

        $this->gameService->handleApartmentChoice($connection, $worker, $choice);
    }
}
