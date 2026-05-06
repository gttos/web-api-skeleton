<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

final class Snapshot
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly string $aggregateType,
        public readonly int $version,
        public readonly array $state,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            aggregateId: $row['aggregate_id'],
            aggregateType: $row['aggregate_type'],
            version: (int) $row['version'],
            state: json_decode($row['state'], true),
            createdAt: new \DateTimeImmutable($row['created_at']),
        );
    }
}
