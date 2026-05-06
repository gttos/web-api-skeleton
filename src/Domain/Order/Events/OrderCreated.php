<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\Order\Model\OrderItem;

final class OrderCreated
{
    /** @param OrderItem[] $items */
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly array $items,
        public readonly float $total,
        public readonly string $currency = 'EUR',
    ) {}

    public function eventType(): string
    {
        return 'OrderCreated';
    }

    public function toArray(): array
    {
        return [
            'order_id'    => $this->orderId,
            'customer_id' => $this->customerId,
            'items'       => array_map(fn(OrderItem $i) => $i->toArray(), $this->items),
            'total'       => $this->total,
            'currency'    => $this->currency,
        ];
    }
}
