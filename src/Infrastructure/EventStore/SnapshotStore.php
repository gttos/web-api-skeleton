<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

use Doctrine\DBAL\Connection;

final class SnapshotStore implements SnapshotStoreInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function load(string $aggregateId): ?Snapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.snapshots WHERE aggregate_id = ?',
            [$aggregateId]
        );

        return $row ? Snapshot::fromRow($row) : null;
    }

    public function save(Snapshot $snapshot): void
    {
        $this->connection->executeStatement(
            'INSERT INTO order_ctx.snapshots (aggregate_id, aggregate_type, version, state, created_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (aggregate_id) DO UPDATE SET
                version = EXCLUDED.version,
                state = EXCLUDED.state,
                created_at = EXCLUDED.created_at',
            [
                $snapshot->aggregateId,
                $snapshot->aggregateType,
                $snapshot->version,
                json_encode($snapshot->state),
                $snapshot->createdAt->format('Y-m-d H:i:s.u P'),
            ]
        );
    }
}
