<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class ProcessedMessageStore
{
    public function __construct(private readonly Connection $connection) {}

    public function wasProcessed(string $messageId, string $consumerName, string $schema): bool
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$schema}.processed_messages WHERE message_id = ? AND consumer_name = ?",
            [$messageId, $consumerName]
        );

        return (int) $count > 0;
    }

    public function markProcessed(
        string $messageId,
        string $consumerName,
        string $schema,
        ?string $correlationId = null,
        ?string $eventId = null,
    ): void {
        try {
            $this->connection->insert("{$schema}.processed_messages", [
                'message_id'     => $messageId,
                'event_id'       => $eventId,
                'consumer_name'  => $consumerName,
                'processed_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u P'),
                'correlation_id' => $correlationId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Ya fue procesado por una carrera de condición — ignorar silenciosamente
        }
    }
}
