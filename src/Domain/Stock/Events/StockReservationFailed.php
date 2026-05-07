<?php

declare(strict_types=1);

namespace App\Domain\Stock\Events;

use App\Domain\IntegrationEvent;

final class StockReservationFailed extends IntegrationEvent
{
    public function __construct(
        string $messageId,
        string $correlationId,
        string $causationId,
        string $occurredAt,
        public readonly string $reservationId,
        public readonly string $orderId,
        public readonly string $reason,
    ) {
        parent::__construct($messageId, $correlationId, $causationId, $occurredAt, 'stock');
    }
}
