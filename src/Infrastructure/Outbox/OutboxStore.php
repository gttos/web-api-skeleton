<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use Doctrine\DBAL\Connection;

final class OutboxStore implements OutboxStoreInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function store(OutboxMessage $message): void
    {
        $this->connection->insert('order_ctx.outbox', [
            'id'             => $message->id,
            'aggregate_id'   => $message->aggregateId,
            'event_type'     => $message->eventType,
            'payload'        => json_encode($message->payload),
            'metadata'       => json_encode($message->metadata),
            'correlation_id' => $message->correlationId,
            'causation_id'   => $message->causationId,
            'created_at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u P'),
        ]);
    }

    public function fetchPending(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM order_ctx.outbox WHERE published_at IS NULL ORDER BY created_at ASC LIMIT ?',
            [$limit]
        );

        return array_map(fn(array $row) => new OutboxMessage(
            id: $row['id'],
            aggregateId: $row['aggregate_id'],
            eventType: $row['event_type'],
            payload: json_decode($row['payload'], true),
            metadata: json_decode($row['metadata'], true),
            correlationId: $row['correlation_id'],
            causationId: $row['causation_id'],
        ), $rows);
    }

    public function markPublished(string $messageId): void
    {
        $this->connection->executeStatement(
            'UPDATE order_ctx.outbox SET published_at = NOW() WHERE id = ?',
            [$messageId]
        );
    }
}
