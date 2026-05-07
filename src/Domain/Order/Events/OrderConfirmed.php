<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

final class OrderConfirmed
{
    public function __construct(
        public readonly string $orderId,
        public readonly \DateTimeImmutable $confirmedAt,
    ) {}

    public function eventType(): string { return 'OrderConfirmed'; }

    public function toArray(): array
    {
        return [
            'order_id'     => $this->orderId,
            'confirmed_at' => $this->confirmedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
