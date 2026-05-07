<?php

declare(strict_types=1);

namespace App\Domain\Notification\Events;

use App\Domain\IntegrationEvent;

final class NotificationFailed extends IntegrationEvent
{
    public function __construct(
        string $messageId,
        string $correlationId,
        string $causationId,
        string $occurredAt,
        public readonly string $notificationId,
        public readonly string $orderId,
        public readonly string $reason,
    ) {
        parent::__construct($messageId, $correlationId, $causationId, $occurredAt, 'notification');
    }
}
