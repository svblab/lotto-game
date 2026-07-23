<?php

namespace Lotto\Lobby;

/**
 * LobbyHandler — EPIC-10.4
 *
 * Обрабатывает WebSocket-пакеты лобби: room_list, create_room, join_room,
 * leave_room. Транслирует входящие пакеты ANCHOR_PROTOCOL.md в вызовы
 * LobbyService (EPIC-2.x — бизнес-логика уже реализована).
 *
 * Контракты worker-памяти (инициализируются в server.php, EPIC-10.4):
 *   $worker->rooms — array<roomId, room> (управляется RoomManager/LobbyService)
 *
 * Зависимости:
 *   LobbyService — бизнес-логика создания/входа/выхода из комнаты
 */
final class LobbyHandler
{
    private LobbyService $lobbyService;

    public function __construct(LobbyService $lobbyService)
    {
        $this->lobbyService = $lobbyService;
    }

    public function handleRoomList(object $connection, object $worker): void
    {
        $this->lobbyService->handleRoomList($connection, $worker);
    }

    public function handleCreateRoom(array $data, object $connection, object $worker): void
    {
        $this->lobbyService->handleCreateRoom($data, $connection, $worker);
    }

    public function handleJoinRoom(array $data, object $connection, object $worker): void
    {
        $this->lobbyService->handleJoinRoom($data, $connection, $worker);
    }

    public function handleLeaveRoom(object $connection, object $worker): void
    {
        $this->lobbyService->handleLeaveRoom($connection, $worker);
    }
}
