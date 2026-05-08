<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Symfony\Component\Messenger\Stamp\StampInterface;

final class CorrelationIdStamp implements StampInterface
{
    public function __construct(
        public readonly string $correlationId,
        public readonly string $causationId,
    ) {}
}
