<?php

declare(strict_types=1);

namespace App\Application\Audit\CommandHandlers;

use App\Infrastructure\Messaging\IdempotentMessageHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class AuditEventHandler extends IdempotentMessageHandler
{
    public static function consumerName(): string { return 'audit.handle_all_events'; }
    public static function schema(): string { return 'audit_ctx'; }

    public function __invoke(\App\Domain\IntegrationEvent $message): void
    {
        $this->processIdempotently($message);
    }

    protected function handle(object $message): void
    {
        $this->connection->insert('audit_ctx.audit_log', [
            'event_type'     => get_class($message),
            'aggregate_id'   => $message->orderId ?? null,
            'payload'        => json_encode((array) $message),
            'correlation_id' => $message->correlationId ?? '',
            'causation_id'   => $message->causationId ?? '',
            'source_context' => $message->sourceContext ?? 'unknown',
            'occurred_at'    => $message->occurredAt ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
