<?php

declare(strict_types=1);

namespace App\Infrastructure\DeadLetter;

use Doctrine\DBAL\Connection;

final class DeadLetterStore implements DeadLetterStoreInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function store(DeadLetterEntry $entry): void
    {
        $this->connection->executeStatement(
            'INSERT INTO shared.dead_letter_store
             (message_id, event_type, consumer_name, payload, metadata, error_reason, stack_trace,
              correlation_id, causation_id, original_transport, attempts, failed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (message_id) DO UPDATE SET
                attempts = shared.dead_letter_store.attempts + 1,
                last_retry_at = NOW(),
                error_reason = EXCLUDED.error_reason,
                stack_trace = EXCLUDED.stack_trace',
            [
                $entry->messageId,
                $entry->eventType,
                $entry->consumerName,
                json_encode($entry->payload),
                json_encode($entry->metadata),
                $entry->errorReason,
                $entry->stackTrace,
                $entry->correlationId,
                $entry->causationId,
                $entry->originalTransport,
                $entry->attempts,
                $entry->failedAt->format('Y-m-d H:i:s.u P'),
            ]
        );
    }

    public function findAll(?string $consumerName = null, ?string $eventType = null): array
    {
        $sql = 'SELECT * FROM shared.dead_letter_store WHERE 1=1';
        $params = [];

        if ($consumerName !== null) {
            $sql .= ' AND consumer_name = ?';
            $params[] = $consumerName;
        }

        if ($eventType !== null) {
            $sql .= ' AND event_type = ?';
            $params[] = $eventType;
        }

        $sql .= ' ORDER BY failed_at DESC';

        $rows = $this->connection->fetchAllAssociative($sql, $params);
        return array_map(fn(array $row) => DeadLetterEntry::fromRow($row), $rows);
    }

    public function findById(string $messageId): ?DeadLetterEntry
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM shared.dead_letter_store WHERE message_id = ?',
            [$messageId]
        );

        return $row ? DeadLetterEntry::fromRow($row) : null;
    }

    public function remove(string $messageId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM shared.dead_letter_store WHERE message_id = ?',
            [$messageId]
        );
    }

    public function incrementAttempts(string $messageId): void
    {
        $this->connection->executeStatement(
            'UPDATE shared.dead_letter_store SET attempts = attempts + 1, last_retry_at = NOW() WHERE message_id = ?',
            [$messageId]
        );
    }
}
