<?php

declare(strict_types=1);

namespace App\Domain\Payment\Events;

use App\Domain\IntegrationEvent;

final class PaymentSucceeded extends IntegrationEvent
{
    public function __construct(
        string $messageId,
        string $correlationId,
        string $causationId,
        string $occurredAt,
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly float $amount,
    ) {
        parent::__construct($messageId, $correlationId, $causationId, $occurredAt, 'payment');
    }
}
