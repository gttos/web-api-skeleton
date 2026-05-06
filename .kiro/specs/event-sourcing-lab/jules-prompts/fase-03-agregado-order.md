# Fase 03 — Agregado Order y Dominio

## Prerequisito

Fases 01 y 02 completadas: infraestructura lista, Event Store implementado.

## Contexto

Implementar el dominio completo del Order Context: el agregado `Order` con Event Sourcing, sus eventos de dominio, el repositorio y los primeros command handlers. Este es el núcleo del laboratorio.

**Principio clave**: El agregado `Order` NO usa Doctrine ORM. Su estado se reconstruye exclusivamente aplicando eventos del Event Store. No hay `@Entity`, no hay mappings XML para Order.

## Tareas a implementar

### 3.1 — Enum `OrderStatus` y value object `OrderItem`

**`src/Domain/Order/Model/OrderStatus.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Model;

enum OrderStatus: string
{
    case Draft          = 'draft';
    case PendingPayment = 'pending_payment';
    case PendingStock   = 'pending_stock';
    case Confirmed      = 'confirmed';
    case Cancelled      = 'cancelled';
}
```

**`src/Domain/Order/Model/OrderItem.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Model;

final class OrderItem
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $quantity,
        public readonly float $unitPrice,
    ) {}

    public function lineTotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }

    public function toArray(): array
    {
        return [
            'sku'        => $this->sku,
            'name'       => $this->name,
            'quantity'   => $this->quantity,
            'unit_price' => $this->unitPrice,
            'line_total' => $this->lineTotal(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            sku: $data['sku'],
            name: $data['name'],
            quantity: (int) $data['quantity'],
            unitPrice: (float) $data['unit_price'],
        );
    }
}
```

### 3.2 — Eventos de dominio del Order Context

Todos los eventos de dominio deben implementar dos métodos: `eventType(): string` y `toArray(): array`.

**`src/Domain/Order/Events/OrderCreated.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\Order\Model\OrderItem;

final class OrderCreated
{
    /** @param OrderItem[] $items */
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly array $items,
        public readonly float $total,
        public readonly string $currency = 'EUR',
    ) {}

    public function eventType(): string
    {
        return 'OrderCreated';
    }

    public function toArray(): array
    {
        return [
            'order_id'    => $this->orderId,
            'customer_id' => $this->customerId,
            'items'       => array_map(fn(OrderItem $i) => $i->toArray(), $this->items),
            'total'       => $this->total,
            'currency'    => $this->currency,
        ];
    }
}
```

**`src/Domain/Order/Events/OrderItemAdded.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\Order\Model\OrderItem;

final class OrderItemAdded
{
    public function __construct(
        public readonly string $orderId,
        public readonly OrderItem $item,
        public readonly float $newTotal,
    ) {}

    public function eventType(): string { return 'OrderItemAdded'; }

    public function toArray(): array
    {
        return [
            'order_id'  => $this->orderId,
            'item'      => $this->item->toArray(),
            'new_total' => $this->newTotal,
        ];
    }
}
```

**`src/Domain/Order/Events/OrderPaymentReceived.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

final class OrderPaymentReceived
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $paymentId,
        public readonly float $amount,
    ) {}

    public function eventType(): string { return 'OrderPaymentReceived'; }

    public function toArray(): array
    {
        return [
            'order_id'   => $this->orderId,
            'payment_id' => $this->paymentId,
            'amount'     => $this->amount,
        ];
    }
}
```

**`src/Domain/Order/Events/OrderStockReserved.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

final class OrderStockReserved
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $reservationId,
    ) {}

    public function eventType(): string { return 'OrderStockReserved'; }

    public function toArray(): array
    {
        return [
            'order_id'       => $this->orderId,
            'reservation_id' => $this->reservationId,
        ];
    }
}
```

**`src/Domain/Order/Events/OrderConfirmed.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

final class OrderConfirmed
{
    public function __construct(
        public readonly string $orderId,
        public readonly \DateTimeImmutable $confirmedAt,
    ) {}

    public function eventType(): string { return 'OrderConfirmed'; }

    public function toArray(): array
    {
        return [
            'order_id'     => $this->orderId,
            'confirmed_at' => $this->confirmedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

**`src/Domain/Order/Events/OrderCancelled.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

final class OrderCancelled
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $cancelledAt,
    ) {}

    public function eventType(): string { return 'OrderCancelled'; }

    public function toArray(): array
    {
        return [
            'order_id'     => $this->orderId,
            'reason'       => $this->reason,
            'cancelled_at' => $this->cancelledAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

### 3.3 — Agregado `Order`

**`src/Domain/Order/Model/Order.php`**

El agregado tiene constructor privado. El estado se reconstruye aplicando eventos. Mantiene una lista de `$uncommittedEvents` que el repositorio persiste.

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Model;

use App\Domain\Exceptions\InvalidStateTransitionException;
use App\Domain\Order\Events\OrderCancelled;
use App\Domain\Order\Events\OrderConfirmed;
use App\Domain\Order\Events\OrderCreated;
use App\Domain\Order\Events\OrderItemAdded;
use App\Domain\Order\Events\OrderPaymentReceived;
use App\Domain\Order\Events\OrderStockReserved;
use App\Infrastructure\EventStore\EventStream;
use App\Infrastructure\EventStore\Snapshot;

final class Order
{
    private string $orderId;
    private string $customerId;
    private OrderStatus $status;
    /** @var OrderItem[] */
    private array $items = [];
    private float $total = 0.0;
    private string $currency = 'EUR';
    private int $version = 0;
    /** @var object[] */
    private array $uncommittedEvents = [];

    private function __construct() {}

    // =========================================================
    // Factory methods
    // =========================================================

    /** @param OrderItem[] $items */
    public static function create(string $orderId, string $customerId, array $items, string $currency = 'EUR'): self
    {
        $order = new self();
        $total = array_sum(array_map(fn(OrderItem $i) => $i->lineTotal(), $items));
        $order->recordAndApply(new OrderCreated($orderId, $customerId, $items, $total, $currency));
        return $order;
    }

    public static function reconstitute(EventStream $stream): self
    {
        $order = new self();
        foreach ($stream as $storedEvent) {
            $order->applyStoredEvent($storedEvent);
        }
        return $order;
    }

    public static function reconstituteFromSnapshot(Snapshot $snapshot, EventStream $remainingEvents): self
    {
        $order = new self();
        $order->restoreFromSnapshot($snapshot);
        foreach ($remainingEvents as $storedEvent) {
            $order->applyStoredEvent($storedEvent);
        }
        return $order;
    }

    // =========================================================
    // Business methods
    // =========================================================

    public function addItem(OrderItem $item): void
    {
        $this->guardNotCancelled('addItem');
        $this->recordAndApply(new OrderItemAdded($this->orderId, $item, $this->total + $item->lineTotal()));
    }

    public function markPaymentReceived(string $paymentId, float $amount): void
    {
        if ($this->status !== OrderStatus::PendingPayment) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, 'markPaymentReceived');
        }
        $this->recordAndApply(new OrderPaymentReceived($this->orderId, $paymentId, $amount));
    }

    public function markStockReserved(string $reservationId): void
    {
        if ($this->status !== OrderStatus::PendingStock) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, 'markStockReserved');
        }
        $this->recordAndApply(new OrderStockReserved($this->orderId, $reservationId));
    }

    public function cancel(string $reason): void
    {
        if (in_array($this->status, [OrderStatus::Confirmed, OrderStatus::Cancelled], true)) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, 'cancel');
        }
        $this->recordAndApply(new OrderCancelled($this->orderId, $reason, new \DateTimeImmutable()));
    }

    public function confirm(): void
    {
        if ($this->status !== OrderStatus::PendingStock) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, 'confirm');
        }
        $this->recordAndApply(new OrderConfirmed($this->orderId, new \DateTimeImmutable()));
    }

    // =========================================================
    // Getters (read-only)
    // =========================================================

    public function id(): string { return $this->orderId; }
    public function customerId(): string { return $this->customerId; }
    public function status(): OrderStatus { return $this->status; }
    public function total(): float { return $this->total; }
    public function currency(): string { return $this->currency; }
    public function version(): int { return $this->version; }
    /** @return OrderItem[] */
    public function items(): array { return $this->items; }
    /** @return object[] */
    public function uncommittedEvents(): array { return $this->uncommittedEvents; }

    public function clearUncommittedEvents(): void
    {
        $this->uncommittedEvents = [];
    }

    // =========================================================
    // Snapshot support
    // =========================================================

    public function toSnapshot(): Snapshot
    {
        return new Snapshot(
            aggregateId: $this->orderId,
            aggregateType: 'Order',
            version: $this->version,
            state: [
                'order_id'    => $this->orderId,
                'customer_id' => $this->customerId,
                'status'      => $this->status->value,
                'items'       => array_map(fn(OrderItem $i) => $i->toArray(), $this->items),
                'total'       => $this->total,
                'currency'    => $this->currency,
            ],
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function restoreFromSnapshot(Snapshot $snapshot): void
    {
        $state = $snapshot->state;
        $this->orderId    = $state['order_id'];
        $this->customerId = $state['customer_id'];
        $this->status     = OrderStatus::from($state['status']);
        $this->items      = array_map(fn(array $i) => OrderItem::fromArray($i), $state['items']);
        $this->total      = (float) $state['total'];
        $this->currency   = $state['currency'];
        $this->version    = $snapshot->version;
    }

    // =========================================================
    // Event application (private)
    // =========================================================

    private function recordAndApply(object $event): void
    {
        $this->applyDomainEvent($event);
        $this->uncommittedEvents[] = $event;
    }

    private function applyStoredEvent(\App\Infrastructure\EventStore\StoredEvent $storedEvent): void
    {
        // Reconstruir el evento de dominio desde el payload del StoredEvent
        $event = $this->deserializeEvent($storedEvent->eventType, $storedEvent->payload);
        $this->applyDomainEvent($event);
    }

    private function applyDomainEvent(object $event): void
    {
        match (true) {
            $event instanceof OrderCreated         => $this->applyOrderCreated($event),
            $event instanceof OrderItemAdded       => $this->applyOrderItemAdded($event),
            $event instanceof OrderPaymentReceived => $this->applyOrderPaymentReceived($event),
            $event instanceof OrderStockReserved   => $this->applyOrderStockReserved($event),
            $event instanceof OrderConfirmed       => $this->applyOrderConfirmed($event),
            $event instanceof OrderCancelled       => $this->applyOrderCancelled($event),
            default => throw new \LogicException('Unknown event type: ' . get_class($event)),
        };
        $this->version++;
    }

    private function applyOrderCreated(OrderCreated $event): void
    {
        $this->orderId    = $event->orderId;
        $this->customerId = $event->customerId;
        $this->items      = $event->items;
        $this->total      = $event->total;
        $this->currency   = $event->currency;
        $this->status     = OrderStatus::Draft;
    }

    private function applyOrderItemAdded(OrderItemAdded $event): void
    {
        $this->items[] = $event->item;
        $this->total   = $event->newTotal;
    }

    private function applyOrderPaymentReceived(OrderPaymentReceived $event): void
    {
        $this->status = OrderStatus::PendingStock;
    }

    private function applyOrderStockReserved(OrderStockReserved $event): void
    {
        $this->status = OrderStatus::Confirmed;
    }

    private function applyOrderConfirmed(OrderConfirmed $event): void
    {
        $this->status = OrderStatus::Confirmed;
    }

    private function applyOrderCancelled(OrderCancelled $event): void
    {
        $this->status = OrderStatus::Cancelled;
    }

    private function deserializeEvent(string $eventType, array $payload): object
    {
        return match ($eventType) {
            'OrderCreated' => new OrderCreated(
                orderId: $payload['order_id'],
                customerId: $payload['customer_id'],
                items: array_map(fn(array $i) => OrderItem::fromArray($i), $payload['items']),
                total: (float) $payload['total'],
                currency: $payload['currency'] ?? 'EUR',
            ),
            'OrderItemAdded' => new OrderItemAdded(
                orderId: $payload['order_id'],
                item: OrderItem::fromArray($payload['item']),
                newTotal: (float) $payload['new_total'],
            ),
            'OrderPaymentReceived' => new OrderPaymentReceived(
                orderId: $payload['order_id'],
                paymentId: $payload['payment_id'],
                amount: (float) $payload['amount'],
            ),
            'OrderStockReserved' => new OrderStockReserved(
                orderId: $payload['order_id'],
                reservationId: $payload['reservation_id'],
            ),
            'OrderConfirmed' => new OrderConfirmed(
                orderId: $payload['order_id'],
                confirmedAt: new \DateTimeImmutable($payload['confirmed_at']),
            ),
            'OrderCancelled' => new OrderCancelled(
                orderId: $payload['order_id'],
                reason: $payload['reason'],
                cancelledAt: new \DateTimeImmutable($payload['cancelled_at']),
            ),
            default => throw new \LogicException("Cannot deserialize unknown event type: {$eventType}"),
        };
    }

    // =========================================================
    // Guards
    // =========================================================

    private function guardNotCancelled(string $action): void
    {
        if ($this->status === OrderStatus::Cancelled) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, $action);
        }
    }
}
```

### 3.4 — Repositorio `DbalOrderRepository`

**`src/Infrastructure/Persistence/Order/DbalOrderRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Order;

use App\Domain\Order\Model\Order;
use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Infrastructure\EventStore\EventStoreInterface;
use App\Infrastructure\EventStore\SnapshotStoreInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use Doctrine\ORM\EntityNotFoundException;

final class DbalOrderRepository implements OrderRepositoryInterface
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly SnapshotStoreInterface $snapshotStore,
    ) {}

    public function findById(string $orderId): Order
    {
        $snapshot = $this->snapshotStore->load($orderId);

        if ($snapshot !== null) {
            $stream = $this->eventStore->loadStream($orderId, $snapshot->version + 1);
            return Order::reconstituteFromSnapshot($snapshot, $stream);
        }

        $stream = $this->eventStore->loadStream($orderId);

        if ($stream->isEmpty()) {
            throw new EntityNotFoundException("Order with id {$orderId} not found");
        }

        return Order::reconstitute($stream);
    }

    public function save(Order $order, CorrelationContext $context): void
    {
        $uncommitted = $order->uncommittedEvents();

        if (empty($uncommitted)) {
            return;
        }

        $expectedVersion = $order->version() - count($uncommitted);

        $this->eventStore->append(
            aggregateId: $order->id(),
            aggregateType: 'Order',
            events: $uncommitted,
            expectedVersion: $expectedVersion,
            context: $context,
        );

        $order->clearUncommittedEvents();
    }
}
```

**Nota**: La firma de `save()` incluye `CorrelationContext` como segundo parámetro. Actualizar la interfaz `OrderRepositoryInterface` para que coincida:

```php
public function save(Order $order, CorrelationContext $context): void;
```

### 3.5 — Comandos y handlers del Order Context

**`src/Domain/Order/Commands/CreateOrder.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Commands;

final class CreateOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly array $items,   // array de arrays con keys: sku, name, quantity, unit_price
        public readonly string $correlationId,
        public readonly string $currency = 'EUR',
    ) {}
}
```

**`src/Domain/Order/Commands/CancelOrder.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Commands;

final class CancelOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $reason,
        public readonly string $correlationId,
    ) {}
}
```

**`src/Application/Order/CommandHandlers/CreateOrderHandler.php`**

Este handler crea el agregado y lo persiste. La integración con el Outbox se añadirá en la Fase 04.

```php
<?php

declare(strict_types=1);

namespace App\Application\Order\CommandHandlers;

use App\Domain\Order\Commands\CreateOrder;
use App\Domain\Order\Model\Order;
use App\Domain\Order\Model\OrderItem;
use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class CreateOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $repository,
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

        $this->repository->save($order, $context);

        $this->logger->info('Order created', [
            'order_id'       => $command->orderId,
            'customer_id'    => $command->customerId,
            'total'          => $order->total(),
            'correlation_id' => $context->correlationId,
        ]);
    }
}
```

**`src/Application/Order/CommandHandlers/CancelOrderHandler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Application\Order\CommandHandlers;

use App\Domain\Order\Commands\CancelOrder;
use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class CancelOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CancelOrder $command): void
    {
        $context = new CorrelationContext(
            correlationId: $command->correlationId,
            causationId: Uuid::v4()->toRfc4122(),
        );

        $order = $this->repository->findById($command->orderId);
        $order->cancel($command->reason);
        $this->repository->save($order, $context);

        $this->logger->info('Order cancelled', [
            'order_id'       => $command->orderId,
            'reason'         => $command->reason,
            'correlation_id' => $context->correlationId,
        ]);
    }
}
```

### 3.6 — Registrar alias en `config/services.yaml`

```yaml
    App\Domain\Order\Services\OrderRepositoryInterface:
        alias: App\Infrastructure\Persistence\Order\DbalOrderRepository
        public: true

    App\Infrastructure\Persistence\Order\DbalOrderRepository:
        arguments:
            $eventStore: '@App\Infrastructure\EventStore\EventStoreInterface'
            $snapshotStore: '@App\Infrastructure\EventStore\SnapshotStoreInterface'
```

### 3.7 — Tests del agregado (opcional pero recomendado)

Crear `tests/Unit/Domain/Order/OrderAggregateTest.php` con tests que verifiquen:
- `Order::create()` genera un `OrderCreated` en `uncommittedEvents`
- `Order::reconstitute()` reconstruye el estado correctamente desde un stream de eventos
- `cancel()` lanza `InvalidStateTransitionException` si el pedido ya está cancelado
- `markPaymentReceived()` lanza excepción si el status no es `PendingPayment`
- El `version` se incrementa correctamente con cada evento aplicado

## Verificación

Al terminar esta fase:
1. `php bin/console cache:clear` sin errores
2. Tests del agregado pasan: `php bin/phpunit tests/Unit/Domain/Order/`
3. Puedes crear un pedido manualmente desde un test de integración y verificar que aparece en `order_ctx.event_store`
