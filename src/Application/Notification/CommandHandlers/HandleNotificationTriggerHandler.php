<?php

declare(strict_types=1);

namespace App\Application\Notification\CommandHandlers;

use App\Infrastructure\Messaging\IdempotentMessageHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class HandleNotificationTriggerHandler extends IdempotentMessageHandler
{
    public static function consumerName(): string { return 'notification.handle_trigger'; }
    public static function schema(): string { return 'notification_ctx'; }

    public function __invoke(\App\Domain\IntegrationEvent $message): void
    {
        $this->processIdempotently($message);
    }

    protected function handle(object $message): void
    {
        $notificationId = Uuid::v4()->toRfc4122();
        $orderId = $message->orderId ?? 'unknown';
        $type = match (true) {
            property_exists($message, 'reservationId') => 'order_confirmed',
            property_exists($message, 'reason')        => 'order_failed',
            default                                     => 'order_update',
        };

        $this->connection->insert('notification_ctx.notification_log', [
            'notification_id' => $notificationId,
            'order_id'        => $orderId,
            'type'            => $type,
            'channel'         => 'email',
            'status'          => 'sent',
            'correlation_id'  => $message->correlationId ?? null,
        ]);

        $this->logger->info('Notification sent', [
            'notification_id' => $notificationId,
            'order_id'        => $orderId,
            'type'            => $type,
            'correlation_id'  => $message->correlationId ?? null,
        ]);
    }
}
