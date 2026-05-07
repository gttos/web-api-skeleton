<?php

declare(strict_types=1);

namespace App\Domain;

abstract class IntegrationEvent
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly string $causationId,
        public readonly string $occurredAt,
        public readonly string $sourceContext,
    ) {}
}
