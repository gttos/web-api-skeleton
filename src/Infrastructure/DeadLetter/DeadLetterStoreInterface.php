<?php

declare(strict_types=1);

namespace App\Infrastructure\DeadLetter;

interface DeadLetterStoreInterface
{
    public function store(DeadLetterEntry $entry): void;

    /** @return DeadLetterEntry[] */
    public function findAll(?string $consumerName = null, ?string $eventType = null): array;

    public function findById(string $messageId): ?DeadLetterEntry;

    public function remove(string $messageId): void;

    public function incrementAttempts(string $messageId): void;
}
