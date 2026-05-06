<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

interface SnapshotStoreInterface
{
    public function load(string $aggregateId): ?Snapshot;
    public function save(Snapshot $snapshot): void;
}
