<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

final class OrderStockReserved
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $reservationId,
    ) {}

    public function eventType(): string { return 'OrderStockReserved'; }

    public function toArray(): array
    {
        return [
            'order_id'       => $this->orderId,
            'reservation_id' => $this->reservationId,
        ];
    }
}
