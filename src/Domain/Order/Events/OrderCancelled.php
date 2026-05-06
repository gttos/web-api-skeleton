<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

final class OrderCancelled
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $cancelledAt,
    ) {}

    public function eventType(): string { return 'OrderCancelled'; }

    public function toArray(): array
    {
        return [
            'order_id'     => $this->orderId,
            'reason'       => $this->reason,
            'cancelled_at' => $this->cancelledAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
