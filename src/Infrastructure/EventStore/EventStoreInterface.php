<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

use App\Infrastructure\Messaging\CorrelationContext;

interface EventStoreInterface
{
    /**
     * Persiste eventos nuevos con control de concurrencia optimista.
     * Lanza ConcurrencyException si expectedVersion no coincide con la versión actual.
     */
    public function append(
        string $aggregateId,
        string $aggregateType,
        array $events,
        int $expectedVersion,
        CorrelationContext $context
    ): void;

    /**
     * Carga todos los eventos de un agregado desde una versión dada.
     */
    public function loadStream(string $aggregateId, int $fromVersion = 0): EventStream;

    /**
     * Carga todos los eventos del store desde un número de secuencia global.
     */
    public function loadAllFromSequence(int $fromSequence = 0): EventStream;
}
