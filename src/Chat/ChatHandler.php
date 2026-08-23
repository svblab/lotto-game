<?php

namespace Lotto\Chat;

/**
 * ChatHandler — ADR-030
 *
 * Routes room chat and file-transfer actions to ChatService /
 * FileTransferService. No business logic beyond dispatch.
 */
final class ChatHandler
{
    public function __construct(
        private ChatService $chatService,
        private FileTransferService $fileTransferService
    ) {
    }

    public function handleRoomMessage(array $data, object $connection, object $worker): void
    {
        $this->chatService->handleRoomMessage($data, $connection, $worker);
    }

    public function handleFileOffer(array $data, object $connection, object $worker): void
    {
        $this->fileTransferService->handleFileOffer($data, $connection, $worker);
    }

    public function handleFileAccept(array $data, object $connection, object $worker): void
    {
        $this->fileTransferService->handleFileAccept($data, $connection, $worker);
    }

    public function handleFileReject(array $data, object $connection, object $worker): void
    {
        $this->fileTransferService->handleFileReject($data, $connection, $worker);
    }

    public function handleFileData(array $data, object $connection, object $worker): void
    {
        $this->fileTransferService->handleFileData($data, $connection, $worker);
    }
}
