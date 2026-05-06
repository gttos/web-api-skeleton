<?php

declare(strict_types=1);

namespace App\Domain\Order\Commands;

final class CreateOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly array $items,   // array de arrays con keys: sku, name, quantity, unit_price
        public readonly string $correlationId,
        public readonly string $currency = 'EUR',
    ) {}
}
