<?php

declare(strict_types=1);

namespace App\Domain\Order\Commands;

final class CancelOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $reason,
        public readonly string $correlationId,
    ) {}
}
