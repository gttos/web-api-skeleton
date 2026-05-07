<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

final class OrderPaymentReceived
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $paymentId,
        public readonly float $amount,
    ) {}

    public function eventType(): string { return 'OrderPaymentReceived'; }

    public function toArray(): array
    {
        return [
            'order_id'   => $this->orderId,
            'payment_id' => $this->paymentId,
            'amount'     => $this->amount,
        ];
    }
}
