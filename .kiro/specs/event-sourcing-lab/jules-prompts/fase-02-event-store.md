# Fase 02 — Event Store

## Prerequisito

La Fase 01 debe estar completada: schemas PostgreSQL creados, interfaces base existentes, Docker con RabbitMQ.

## Contexto

Implementar el Event Store usando **Doctrine DBAL directamente** (no ORM). Los eventos son inmutables y append-only; no necesitan identity map ni change tracking. El Event Store es el corazón del laboratorio.

Stack: Symfony 6.4, PHP 8.2+, PostgreSQL 15, Doctrine DBAL.

Namespaces relevantes:
- `App\Infrastructure\EventStore\` → implementaciones del Event Store
- `App\Domain\Exceptions\` → excepciones de dominio

## Tareas a implementar

### 2.1 — Value objects del Event Store

**`src/Infrastructure/EventStore/StoredEvent.php`**

Value object inmutable que representa un evento leído del Event Store:

```php
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
```

**`src/Infrastructure/EventStore/EventStream.php`**

Iterable de `StoredEvent`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

final class EventStream implements \IteratorAggregate, \Countable
{
    /** @param StoredEvent[] $events */
    public function __construct(private readonly array $events) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromStoredEvents(array $events): self
    {
        return new self($events);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->events);
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function isEmpty(): bool
    {
        return empty($this->events);
    }

    /** @return StoredEvent[] */
    public function toArray(): array
    {
        return $this->events;
    }
}
```

**`src/Infrastructure/EventStore/Snapshot.php`**

Value object para snapshots:

```php
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
```

### 2.2 — Excepción de concurrencia

**`src/Domain/Exceptions/ConcurrencyException.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class ConcurrencyException extends \RuntimeException
{
    public function __construct(string $aggregateId, int $expectedVersion, int $actualVersion)
    {
        parent::__construct(sprintf(
            'Concurrency conflict for aggregate %s: expected version %d, actual version %d',
            $aggregateId,
            $expectedVersion,
            $actualVersion
        ));
    }
}
```

**`src/Domain/Exceptions/InvalidStateTransitionException.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class InvalidStateTransitionException extends \DomainException
{
    public function __construct(string $aggregateId, string $currentStatus, string $attemptedAction)
    {
        parent::__construct(sprintf(
            'Cannot perform "%s" on order %s in status "%s"',
            $attemptedAction,
            $aggregateId,
            $currentStatus
        ));
    }
}
```

### 2.3 — Implementar `DbalEventStore`

**`src/Infrastructure/EventStore/DbalEventStore.php`**

Implementa `EventStoreInterface`. Usa `Doctrine\DBAL\Connection` directamente.

Lógica de `append()`:
1. Dentro de una transacción, hacer `SELECT MAX(event_version) FROM order_ctx.event_store WHERE aggregate_id = ?` con `FOR UPDATE`
2. Si el resultado no coincide con `$expectedVersion`, lanzar `ConcurrencyException`
3. Insertar cada evento con `INSERT INTO order_ctx.event_store (...) VALUES (...)`
4. El `sequence_number` es BIGSERIAL (auto-incremental), no se pasa en el INSERT
5. El `event_version` se calcula como `$expectedVersion + $index + 1` para cada evento del array

Lógica de `loadStream()`:
- `SELECT * FROM order_ctx.event_store WHERE aggregate_id = ? AND event_version >= ? ORDER BY event_version ASC`
- Mapear cada fila a `StoredEvent::fromRow()`

Lógica de `loadAllFromSequence()`:
- `SELECT * FROM order_ctx.event_store WHERE sequence_number >= ? ORDER BY sequence_number ASC`
- Mapear cada fila a `StoredEvent::fromRow()`

Los eventos del array `$events` son objetos de dominio (clases PHP). Para serializarlos a JSONB, usar `json_encode($event->toArray())` o similar. Cada evento de dominio debe tener un método `toArray(): array` que retorne su payload.

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

use App\Domain\Exceptions\ConcurrencyException;
use App\Infrastructure\Messaging\CorrelationContext;
use Doctrine\DBAL\Connection;

final class DbalEventStore implements EventStoreInterface
{
    public function __construct(private readonly Connection $connection) {}

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
            fn(array $row) => StoredEvent::fromRow($row),
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
            fn(array $row) => StoredEvent::fromRow($row),
            $rows
        ));
    }
}
```

### 2.4 — Implementar `SnapshotStore`

**`src/Infrastructure/EventStore/SnapshotStore.php`**

```php
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
```

### 2.5 — Registrar alias en `config/services.yaml`

Añadir los alias de interfaces a implementaciones concretas:

```yaml
    App\Infrastructure\EventStore\EventStoreInterface:
        alias: App\Infrastructure\EventStore\DbalEventStore
        public: true

    App\Infrastructure\EventStore\SnapshotStoreInterface:
        alias: App\Infrastructure\EventStore\SnapshotStore
        public: true
```

### 2.6 — Tests del Event Store (opcional pero recomendado)

Crear `tests/Unit/Infrastructure/EventStore/DbalEventStoreTest.php` con tests que verifiquen:
- `append()` persiste eventos con todos los campos correctos
- `loadStream()` retorna eventos ordenados por `event_version` ASC
- `append()` lanza `ConcurrencyException` cuando `expectedVersion` no coincide
- `loadAllFromSequence()` retorna eventos desde el sequence_number indicado

Usar DAMA Doctrine Test Bundle para rollback automático entre tests (ya configurado en `phpunit.xml.dist`).

## Verificación

Al terminar esta fase:
1. `php bin/console cache:clear` sin errores
2. Los tests del Event Store pasan: `php bin/phpunit tests/Unit/Infrastructure/EventStore/`
3. Puedes instanciar `DbalEventStore` desde el contenedor: `php bin/console debug:container App\\Infrastructure\\EventStore\\DbalEventStore`
