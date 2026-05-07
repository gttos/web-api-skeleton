<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\IntegrationEvent;

final class OrderCreatedIntegration extends IntegrationEvent
{
    public function __construct(
        string $messageId,
        string $correlationId,
        string $causationId,
        string $occurredAt,
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly array $items,
        public readonly float $total,
        public readonly string $currency,
    ) {
        parent::__construct($messageId, $correlationId, $causationId, $occurredAt, 'order');
    }
}
