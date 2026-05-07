<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

final class OutboxIntegrationMessage
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly array $metadata,
        public readonly string $correlationId,
        public readonly string $causationId,
    ) {}
}
