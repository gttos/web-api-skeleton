<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

use App\Domain\Exceptions\ConcurrencyException;
use App\Infrastructure\Messaging\CorrelationContext;
use Doctrine\DBAL\Connection;

final class DbalEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly \App\Infrastructure\EventStore\Upcasting\UpcasterChain $upcasterChain = new \App\Infrastructure\EventStore\Upcasting\UpcasterChain(),
    ) {}

    public function append(
        string $aggregateId,
        string $aggregateType,
        array $events,
        int $expectedVersion,
        CorrelationContext $context
    ): void {
        if (empty($events)) {
            return;
        }

        $this->connection->transactional(function () use (
            $aggregateId, $aggregateType, $events, $expectedVersion, $context
        ): void {
            // Control de concurrencia optimista
            $currentVersion = (int) $this->connection->fetchOne(
                'SELECT COALESCE(MAX(event_version), 0) FROM order_ctx.event_store WHERE aggregate_id = ? FOR UPDATE',
                [$aggregateId]
            );

            if ($currentVersion !== $expectedVersion) {
                throw new ConcurrencyException($aggregateId, $expectedVersion, $currentVersion);
            }

            $version = $expectedVersion;
            foreach ($events as $event) {
                $version++;
                $this->connection->insert('order_ctx.event_store', [
                    'aggregate_id'   => $aggregateId,
                    'aggregate_type' => $aggregateType,
                    'event_type'     => $event->eventType(),
                    'event_version'  => $version,
                    'payload'        => json_encode($event->toArray()),
                    'metadata'       => json_encode(['source' => 'command']),
                    'correlation_id' => $context->correlationId,
                    'causation_id'   => $context->causationId,
                    'occurred_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u P'),
                ]);
            }
        });
    }

    public function loadStream(string $aggregateId, int $fromVersion = 0): EventStream
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM order_ctx.event_store WHERE aggregate_id = ? AND event_version >= ? ORDER BY event_version ASC',
            [$aggregateId, $fromVersion]
        );

        return EventStream::fromStoredEvents(array_map(
            fn(array $row) => StoredEvent::fromRow(array_merge($row, [
                'payload' => json_encode(
                    $this->upcasterChain->upcast(
                        $row['event_type'],
                        json_decode($row['payload'], true),
                        (int) $row['event_version']
                    )
                ),
            ])),
            $rows
        ));
    }

    public function loadAllFromSequence(int $fromSequence = 0): EventStream
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM order_ctx.event_store WHERE sequence_number >= ? ORDER BY sequence_number ASC',
            [$fromSequence]
        );

        return EventStream::fromStoredEvents(array_map(
            fn(array $row) => StoredEvent::fromRow(array_merge($row, [
                'payload' => json_encode(
                    $this->upcasterChain->upcast(
                        $row['event_type'],
                        json_decode($row['payload'], true),
                        (int) $row['event_version']
                    )
                ),
            ])),
            $rows
        ));
    }
}
