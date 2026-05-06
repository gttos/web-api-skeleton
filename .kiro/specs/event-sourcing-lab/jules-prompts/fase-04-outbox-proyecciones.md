# Fase 04 — Outbox Pattern y Proyecciones

## Prerequisito

Fases 01, 02 y 03 completadas: Event Store funcionando, agregado Order implementado.

## Contexto

Implementar dos sistemas que trabajan juntos:
1. **Outbox Pattern**: garantiza que los Integration Events se publiquen atómicamente junto con la persistencia de eventos del agregado. Sin Outbox, si el broker falla después de persistir pero antes de publicar, el mensaje se pierde.
2. **Projection Engine**: lee eventos del Event Store y construye read models optimizados para consultas.

Ambos usan DBAL directamente (no ORM).

## Tareas a implementar

### 4.1 — Outbox: value objects

**`src/Infrastructure/Outbox/OutboxMessage.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

final class OutboxMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $aggregateId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly array $metadata,
        public readonly string $correlationId,
        public readonly string $causationId,
    ) {}
}
```

### 4.2 — Outbox: implementar `OutboxStore`

**`src/Infrastructure/Outbox/OutboxStore.php`**

```php
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
```

### 4.3 — Integrar Outbox en `CreateOrderHandler`

Modificar `src/Application/Order/CommandHandlers/CreateOrderHandler.php` para que la persistencia de eventos y el almacenamiento en outbox ocurran en la **misma transacción**:

```php
<?php

declare(strict_types=1);

namespace App\Application\Order\CommandHandlers;

use App\Domain\Order\Commands\CreateOrder;
use App\Domain\Order\Model\Order;
use App\Domain\Order\Model\OrderItem;
use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use App\Infrastructure\Outbox\OutboxMessage;
use App\Infrastructure\Outbox\OutboxStoreInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class CreateOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $repository,
        private readonly OutboxStoreInterface $outboxStore,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CreateOrder $command): void
    {
        $context = new CorrelationContext(
            correlationId: $command->correlationId,
            causationId: Uuid::v4()->toRfc4122(),
        );

        $items = array_map(
            fn(array $item) => new OrderItem(
                sku: $item['sku'],
                name: $item['name'],
                quantity: (int) $item['quantity'],
                unitPrice: (float) $item['unit_price'],
            ),
            $command->items
        );

        $order = Order::create($command->orderId, $command->customerId, $items, $command->currency);

        // Transacción atómica: Event Store + Outbox
        $this->connection->transactional(function () use ($order, $context, $command): void {
            $this->repository->save($order, $context);

            // Almacenar Integration Event en Outbox (misma transacción)
            $this->outboxStore->store(new OutboxMessage(
                id: Uuid::v4()->toRfc4122(),
                aggregateId: $order->id(),
                eventType: 'OrderCreatedIntegration',
                payload: [
                    'order_id'    => $order->id(),
                    'customer_id' => $order->customerId(),
                    'items'       => array_map(fn($i) => $i->toArray(), $order->items()),
                    'total'       => $order->total(),
                    'currency'    => $order->currency(),
                ],
                metadata: ['source_context' => 'order'],
                correlationId: $context->correlationId,
                causationId: $context->causationId,
            ));
        });

        $this->logger->info('Order created', [
            'order_id'       => $command->orderId,
            'correlation_id' => $context->correlationId,
        ]);
    }
}
```

### 4.4 — Outbox Relay

**`src/Infrastructure/Outbox/OutboxRelay.php`**

El relay lee mensajes pendientes del outbox y los publica en RabbitMQ vía Symfony Messenger. Si el broker no está disponible, loguea el error y continúa (el mensaje se reintentará en el siguiente ciclo).

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class OutboxRelay
{
    public function __construct(
        private readonly OutboxStoreInterface $outboxStore,
        private readonly MessageBusInterface $eventBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(int $batchSize = 100): int
    {
        $pending = $this->outboxStore->fetchPending($batchSize);
        $published = 0;

        foreach ($pending as $message) {
            try {
                // Crear un mensaje genérico que Messenger pueda enrutar al transport order_events
                $integrationMessage = new OutboxIntegrationMessage(
                    messageId: $message->id,
                    eventType: $message->eventType,
                    payload: $message->payload,
                    metadata: $message->metadata,
                    correlationId: $message->correlationId,
                    causationId: $message->causationId,
                );

                $envelope = new Envelope($integrationMessage, [
                    new TransportNamesStamp(['order_events']),
                ]);

                $this->eventBus->dispatch($envelope);
                $this->outboxStore->markPublished($message->id);
                $published++;

                $this->logger->debug('Outbox message published', [
                    'message_id'     => $message->id,
                    'event_type'     => $message->eventType,
                    'correlation_id' => $message->correlationId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Outbox relay failed for message', [
                    'message_id' => $message->id,
                    'event_type' => $message->eventType,
                    'error'      => $e->getMessage(),
                ]);
                // No lanzar excepción: el mensaje se reintentará en el siguiente ciclo
            }
        }

        return $published;
    }
}
```

**`src/Infrastructure/Outbox/OutboxIntegrationMessage.php`**

Mensaje genérico que el relay despacha al bus:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

final class OutboxIntegrationMessage
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly array $metadata,
        public readonly string $correlationId,
        public readonly string $causationId,
    ) {}
}
```

Añadir routing en `config/packages/messenger.yaml`:
```yaml
        routing:
            # ... routing existente ...
            App\Infrastructure\Outbox\OutboxIntegrationMessage: order_events
```

### 4.5 — Comando `outbox:relay`

**`src/Delivery/Console/OutboxRelayCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Console;

use App\Infrastructure\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'outbox:relay', description: 'Publica mensajes pendientes del outbox en RabbitMQ')]
final class OutboxRelayCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Ejecutar una sola vez y salir (sin bucle)')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Mensajes por ciclo', 100)
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL, 'Segundos entre ciclos', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $once      = $input->getOption('once');
        $batchSize = (int) $input->getOption('batch-size');
        $sleep     = (int) $input->getOption('sleep');

        do {
            $published = $this->relay->execute($batchSize);

            if ($published > 0) {
                $output->writeln(sprintf('[%s] Published %d messages', date('H:i:s'), $published));
            }

            if (!$once) {
                sleep($sleep);
            }
        } while (!$once);

        return Command::SUCCESS;
    }
}
```

### 4.6 — Projection Engine

**`src/Infrastructure/Projection/ProjectorInterface.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Infrastructure\EventStore\StoredEvent;

interface ProjectorInterface
{
    public function handle(StoredEvent $event): void;
    public function reset(): void;
    /** @return string[] Lista de eventType que este projector maneja */
    public function supportedEvents(): array;
}
```

**`src/Infrastructure/Projection/ProjectionEngine.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Infrastructure\EventStore\EventStoreInterface;
use Psr\Log\LoggerInterface;

final class ProjectionEngine
{
    /** @param ProjectorInterface[] $projectors */
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly array $projectors,
        private readonly LoggerInterface $logger,
    ) {}

    public function projectEvent(\App\Infrastructure\EventStore\StoredEvent $event): void
    {
        foreach ($this->projectors as $projector) {
            if (in_array($event->eventType, $projector->supportedEvents(), true)) {
                $projector->handle($event);
            }
        }
    }

    public function rebuild(?int $fromSequence = null): void
    {
        $this->logger->info('Starting projection rebuild', ['from_sequence' => $fromSequence]);

        // Reset all projectors
        foreach ($this->projectors as $projector) {
            $projector->reset();
        }

        $stream = $this->eventStore->loadAllFromSequence($fromSequence ?? 0);
        $count = 0;

        foreach ($stream as $event) {
            $this->projectEvent($event);
            $count++;

            if ($count % 1000 === 0) {
                $this->logger->info('Projection rebuild progress', [
                    'events_processed' => $count,
                    'last_sequence'    => $event->sequenceNumber,
                ]);
            }
        }

        $this->logger->info('Projection rebuild completed', ['total_events' => $count]);
    }
}
```

### 4.7 — `OrderSummaryProjector`

**`src/Infrastructure/Projection/OrderSummaryProjector.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Infrastructure\EventStore\StoredEvent;
use Doctrine\DBAL\Connection;

final class OrderSummaryProjector implements ProjectorInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function supportedEvents(): array
    {
        return [
            'OrderCreated',
            'OrderItemAdded',
            'OrderPaymentReceived',
            'OrderStockReserved',
            'OrderConfirmed',
            'OrderCancelled',
        ];
    }

    public function handle(StoredEvent $event): void
    {
        match ($event->eventType) {
            'OrderCreated'         => $this->onOrderCreated($event),
            'OrderItemAdded'       => $this->onOrderItemAdded($event),
            'OrderPaymentReceived' => $this->onOrderPaymentReceived($event),
            'OrderStockReserved',
            'OrderConfirmed'       => $this->onOrderConfirmed($event),
            'OrderCancelled'       => $this->onOrderCancelled($event),
            default                => null,
        };
    }

    public function reset(): void
    {
        $this->connection->executeStatement('TRUNCATE order_ctx.order_projections');
    }

    private function onOrderCreated(StoredEvent $event): void
    {
        $p = $event->payload;
        $this->connection->executeStatement(
            'INSERT INTO order_ctx.order_projections (order_id, status, customer_id, items, total, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (order_id) DO NOTHING',
            [
                $p['order_id'],
                'draft',
                $p['customer_id'],
                json_encode($p['items']),
                $p['total'],
                $event->occurredAt->format('Y-m-d H:i:s.u P'),
                $event->occurredAt->format('Y-m-d H:i:s.u P'),
            ]
        );
    }

    private function onOrderItemAdded(StoredEvent $event): void
    {
        $p = $event->payload;
        // Actualizar total y añadir item al array JSONB
        $this->connection->executeStatement(
            'UPDATE order_ctx.order_projections
             SET total = ?, items = items || ?::jsonb, updated_at = ?
             WHERE order_id = ?',
            [
                $p['new_total'],
                json_encode([$p['item']]),
                $event->occurredAt->format('Y-m-d H:i:s.u P'),
                $p['order_id'],
            ]
        );
    }

    private function onOrderPaymentReceived(StoredEvent $event): void
    {
        $this->connection->executeStatement(
            'UPDATE order_ctx.order_projections SET status = ?, updated_at = ? WHERE order_id = ?',
            ['pending_stock', $event->occurredAt->format('Y-m-d H:i:s.u P'), $event->payload['order_id']]
        );
    }

    private function onOrderConfirmed(StoredEvent $event): void
    {
        $this->connection->executeStatement(
            'UPDATE order_ctx.order_projections SET status = ?, updated_at = ? WHERE order_id = ?',
            ['confirmed', $event->occurredAt->format('Y-m-d H:i:s.u P'), $event->payload['order_id']]
        );
    }

    private function onOrderCancelled(StoredEvent $event): void
    {
        $this->connection->executeStatement(
            'UPDATE order_ctx.order_projections SET status = ?, updated_at = ? WHERE order_id = ?',
            ['cancelled', $event->occurredAt->format('Y-m-d H:i:s.u P'), $event->payload['order_id']]
        );
    }
}
```

### 4.8 — Comando `projection:rebuild`

**`src/Delivery/Console/ProjectionRebuildCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Console;

use App\Infrastructure\Projection\ProjectionEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'projection:rebuild', description: 'Reconstruye las proyecciones reprocesando eventos del Event Store')]
final class ProjectionRebuildCommand extends Command
{
    public function __construct(private readonly ProjectionEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'from-sequence',
            null,
            InputOption::VALUE_OPTIONAL,
            'Reconstruir solo desde este número de secuencia',
            null
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fromSequence = $input->getOption('from-sequence') !== null
            ? (int) $input->getOption('from-sequence')
            : null;

        $output->writeln(sprintf(
            'Rebuilding projections%s...',
            $fromSequence !== null ? " from sequence {$fromSequence}" : ' (full rebuild)'
        ));

        $this->engine->rebuild($fromSequence);

        $output->writeln('Done.');

        return Command::SUCCESS;
    }
}
```

### 4.9 — Registrar servicios en `config/services.yaml`

```yaml
    App\Infrastructure\Outbox\OutboxStoreInterface:
        alias: App\Infrastructure\Outbox\OutboxStore
        public: true

    App\Infrastructure\Projection\ProjectionEngine:
        arguments:
            $projectors:
                - '@App\Infrastructure\Projection\OrderSummaryProjector'
```

## Verificación

Al terminar esta fase:
1. `php bin/console outbox:relay --once` debe ejecutar sin errores (aunque no haya mensajes pendientes)
2. `php bin/console projection:rebuild` debe ejecutar sin errores
3. Crear un pedido vía `CreateOrderHandler` debe:
   - Insertar eventos en `order_ctx.event_store`
   - Insertar una entrada en `order_ctx.outbox` (con `published_at = NULL`)
   - Insertar una fila en `order_ctx.order_projections` (si el projector está activo)
4. Ejecutar `outbox:relay --once` debe marcar la entrada del outbox como publicada (`published_at` no nulo)
