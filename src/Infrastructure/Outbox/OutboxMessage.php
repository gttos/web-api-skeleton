<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

final class OutboxMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $aggregateId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly array $metadata,
        public readonly string $correlationId,
        public readonly string $causationId,
    ) {}
}
