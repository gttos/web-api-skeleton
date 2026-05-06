<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

interface OutboxStoreInterface
{
    public function store(OutboxMessage $message): void;

    /** @return OutboxMessage[] */
    public function fetchPending(int $limit = 100): array;

    public function markPublished(string $messageId): void;
}
