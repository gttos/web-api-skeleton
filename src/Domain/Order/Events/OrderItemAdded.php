<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\Order\Model\OrderItem;

final class OrderItemAdded
{
    public function __construct(
        public readonly string $orderId,
        public readonly OrderItem $item,
        public readonly float $newTotal,
    ) {}

    public function eventType(): string { return 'OrderItemAdded'; }

    public function toArray(): array
    {
        return [
            'order_id'  => $this->orderId,
            'item'      => $this->item->toArray(),
            'new_total' => $this->newTotal,
        ];
    }
}
