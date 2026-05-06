<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

final class StoredEvent
{
    public function __construct(
        public readonly int $sequenceNumber,
        public readonly string $aggregateId,
        public readonly string $aggregateType,
        public readonly string $eventType,
        public readonly int $eventVersion,
        public readonly array $payload,
        public readonly array $metadata,
        public readonly string $correlationId,
        public readonly string $causationId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            sequenceNumber: (int) $row['sequence_number'],
            aggregateId: $row['aggregate_id'],
            aggregateType: $row['aggregate_type'],
            eventType: $row['event_type'],
            eventVersion: (int) $row['event_version'],
            payload: json_decode($row['payload'], true),
            metadata: json_decode($row['metadata'], true),
            correlationId: $row['correlation_id'],
            causationId: $row['causation_id'],
            occurredAt: new \DateTimeImmutable($row['occurred_at']),
        );
    }
}
