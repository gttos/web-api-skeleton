# Fase 05 — Integration Events, Consumers e Idempotencia

## Prerequisito

Fases 01–04 completadas: Event Store, agregado Order, Outbox y Proyecciones funcionando.

## Contexto

Implementar los cinco Bounded Contexts consumidores y el mecanismo de idempotencia. Cada consumer extiende `IdempotentMessageHandler` para garantizar que mensajes duplicados no produzcan efectos secundarios.

**Patrones de comunicación que se implementan aquí:**
- **Patrón 1 (Síncrono)**: Payment Context llama directamente a un servicio del Order Context
- **Patrón 2 (Async Integration Events)**: Order → Payment, Stock, Notification, Audit vía RabbitMQ
- **Patrón 3 (Async Commands)**: Order → Stock vía comando asíncrono
- **Patrón 4 (Internal vs Integration Events)**: Los eventos internos del Order Context NO se publican; solo los Integration Events derivados

## Tareas a implementar

### 5.1 — Clase base `IntegrationEvent`

**`src/Domain/IntegrationEvent.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain;

abstract class IntegrationEvent
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly string $causationId,
        public readonly string $occurredAt,
        public readonly string $sourceContext,
    ) {}
}
```

### 5.2 — Integration Events de cada contexto

**`src/Domain/Order/Events/OrderCreatedIntegration.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\IntegrationEvent;

final class OrderCreatedIntegration extends IntegrationEvent
{
    public function __construct(
        string $messageId,
        string $correlationId,
        string $causationId,
        string $occurredAt,
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly array $items,
        public readonly float $total,
        public readonly string $currency,
    ) {
        parent::__construct($messageId, $correlationId, $causationId, $occurredAt, 'order');
    }
}
```

**`src/Domain/Payment/Events/PaymentSucceeded.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Payment\Events;

use App\Domain\IntegrationEvent;

final class PaymentSucceeded extends IntegrationEvent
{
    public function __construct(
        string $messageId,
        string $correlationId,
        string $causationId,
        string $occurredAt,
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly float $amount,
    ) {
        parent::__construct($messageId, $correlationId, $causationId, $occurredAt, 'payment');
    }
}
```

**`src/Domain/Payment/Events/PaymentFailed.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Payment\Events;

use App\Domain\IntegrationEvent;

final class PaymentFailed extends IntegrationEvent
{
    public function __construct(
        string $messageId,
        string $correlationId,
        string $causationId,
        string $occurredAt,
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly string $reason,
    ) {
        parent::__construct($messageId, $correlationId, $causationId, $occurredAt, 'payment');
    }
}
```

Crear de forma similar:
- `src/Domain/Stock/Events/StockReserved.php` — con `reservationId`, `orderId`, `items`
- `src/Domain/Stock/Events/StockReservationFailed.php` — con `reservationId`, `orderId`, `reason`
- `src/Domain/Notification/Events/NotificationSent.php` — con `notificationId`, `orderId`, `channel`
- `src/Domain/Notification/Events/NotificationFailed.php` — con `notificationId`, `orderId`, `reason`

### 5.3 — `ProcessedMessageStore` e `IdempotentMessageHandler`

**`src/Infrastructure/Messaging/ProcessedMessageStore.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class ProcessedMessageStore
{
    public function __construct(private readonly Connection $connection) {}

    public function wasProcessed(string $messageId, string $consumerName, string $schema): bool
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$schema}.processed_messages WHERE message_id = ? AND consumer_name = ?",
            [$messageId, $consumerName]
        );

        return (int) $count > 0;
    }

    public function markProcessed(
        string $messageId,
        string $consumerName,
        string $schema,
        ?string $correlationId = null,
        ?string $eventId = null,
    ): void {
        try {
            $this->connection->insert("{$schema}.processed_messages", [
                'message_id'     => $messageId,
                'event_id'       => $eventId,
                'consumer_name'  => $consumerName,
                'processed_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u P'),
                'correlation_id' => $correlationId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Ya fue procesado por una carrera de condición — ignorar silenciosamente
        }
    }
}
```

**`src/Infrastructure/Messaging/IdempotentMessageHandler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

abstract class IdempotentMessageHandler
{
    public function __construct(
        protected readonly ProcessedMessageStore $processedStore,
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
    ) {}

    final public function __invoke(object $message): void
    {
        // Extraer message_id del mensaje (debe implementar getMessageId())
        $messageId = $this->extractMessageId($message);
        $consumerName = static::consumerName();
        $schema = static::schema();

        if ($this->processedStore->wasProcessed($messageId, $consumerName, $schema)) {
            $this->logger->info('Duplicate message ignored', [
                'message_id'    => $messageId,
                'consumer_name' => $consumerName,
            ]);
            return;
        }

        $this->connection->transactional(function () use ($message, $messageId, $consumerName, $schema): void {
            $this->handle($message);
            $this->processedStore->markProcessed(
                messageId: $messageId,
                consumerName: $consumerName,
                schema: $schema,
                correlationId: $this->extractCorrelationId($message),
            );
        });
    }

    abstract protected function handle(object $message): void;

    /** Nombre único del consumer, usado como clave en processed_messages */
    abstract public static function consumerName(): string;

    /** Schema PostgreSQL donde está la tabla processed_messages de este contexto */
    abstract public static function schema(): string;

    protected function extractMessageId(object $message): string
    {
        if (method_exists($message, 'getMessageId')) {
            return $message->getMessageId();
        }
        if (property_exists($message, 'messageId')) {
            return $message->messageId;
        }
        throw new \LogicException('Message must have a messageId property or getMessageId() method');
    }

    protected function extractCorrelationId(object $message): ?string
    {
        if (property_exists($message, 'correlationId')) {
            return $message->correlationId;
        }
        return null;
    }
}
```

### 5.4 — Handler del Payment Context

**`src/Application/Payment/CommandHandlers/HandleOrderCreatedHandler.php`**

Este handler demuestra el **Patrón 1 (síncrono)** y el **Patrón 2 (async)**:
- Recibe `OrderCreatedIntegration` vía RabbitMQ (async)
- Simula el procesamiento del pago
- Llama directamente al servicio del Order Context para notificar el resultado (sync)
- Emite `PaymentSucceeded` o `PaymentFailed` al bus

```php
<?php

declare(strict_types=1);

namespace App\Application\Payment\CommandHandlers;

use App\Domain\Order\Events\OrderCreatedIntegration;
use App\Domain\Order\Services\OrderServiceInterface;
use App\Domain\Payment\Events\PaymentFailed;
use App\Domain\Payment\Events\PaymentSucceeded;
use App\Infrastructure\Messaging\IdempotentMessageHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class HandleOrderCreatedHandler extends IdempotentMessageHandler
{
    public function __construct(
        private readonly MessageBusInterface $eventBus,
        private readonly OrderServiceInterface $orderService,  // Patrón 1: service call síncrono
        \App\Infrastructure\Messaging\ProcessedMessageStore $processedStore,
        Connection $connection,
        LoggerInterface $logger,
    ) {
        parent::__construct($processedStore, $connection, $logger);
    }

    public static function consumerName(): string { return 'payment.handle_order_created'; }
    public static function schema(): string { return 'payment_ctx'; }

    protected function handle(object $message): void
    {
        /** @var OrderCreatedIntegration $message */
        $paymentId = Uuid::v4()->toRfc4122();

        // Registrar el pago en payment_ctx.payments
        $this->connection->insert('payment_ctx.payments', [
            'payment_id'     => $paymentId,
            'order_id'       => $message->orderId,
            'amount'         => $message->total,
            'status'         => 'processing',
            'correlation_id' => $message->correlationId,
        ]);

        // Simular procesamiento de pago (siempre exitoso en el happy path)
        // En Fase 07 se añadirá la inyección de errores
        $succeeded = true;

        if ($succeeded) {
            // Patrón 1: Service call síncrono al Order Context
            $this->orderService->markPaymentReceived($message->orderId, $paymentId, $message->total);

            // Actualizar estado del pago
            $this->connection->executeStatement(
                'UPDATE payment_ctx.payments SET status = ? WHERE payment_id = ?',
                ['succeeded', $paymentId]
            );

            // Emitir integration event
            $this->eventBus->dispatch(new PaymentSucceeded(
                messageId: Uuid::v4()->toRfc4122(),
                correlationId: $message->correlationId,
                causationId: $message->messageId,
                occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                paymentId: $paymentId,
                orderId: $message->orderId,
                amount: $message->total,
            ));
        } else {
            $this->connection->executeStatement(
                'UPDATE payment_ctx.payments SET status = ? WHERE payment_id = ?',
                ['failed', $paymentId]
            );

            $this->eventBus->dispatch(new PaymentFailed(
                messageId: Uuid::v4()->toRfc4122(),
                correlationId: $message->correlationId,
                causationId: $message->messageId,
                occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                paymentId: $paymentId,
                orderId: $message->orderId,
                reason: 'Payment declined',
            ));
        }
    }
}
```

**`src/Domain/Order/Services/OrderServiceInterface.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

interface OrderServiceInterface
{
    public function markPaymentReceived(string $orderId, string $paymentId, float $amount): void;
    public function markStockReserved(string $orderId, string $reservationId): void;
    public function cancelOrder(string $orderId, string $reason): void;
}
```

**`src/Application/Order/OrderService.php`**

Implementación del servicio síncrono del Order Context:

```php
<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Domain\Order\Services\OrderServiceInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use Symfony\Component\Uid\Uuid;

final class OrderService implements OrderServiceInterface
{
    public function __construct(private readonly OrderRepositoryInterface $repository) {}

    public function markPaymentReceived(string $orderId, string $paymentId, float $amount): void
    {
        $context = CorrelationContext::initiate();
        $order = $this->repository->findById($orderId);
        $order->markPaymentReceived($paymentId, $amount);
        $this->repository->save($order, $context);
    }

    public function markStockReserved(string $orderId, string $reservationId): void
    {
        $context = CorrelationContext::initiate();
        $order = $this->repository->findById($orderId);
        $order->markStockReserved($reservationId);
        $this->repository->save($order, $context);
    }

    public function cancelOrder(string $orderId, string $reason): void
    {
        $context = CorrelationContext::initiate();
        $order = $this->repository->findById($orderId);
        $order->cancel($reason);
        $this->repository->save($order, $context);
    }
}
```

### 5.5 — Handler del Stock Context

**`src/Application/Stock/CommandHandlers/HandlePaymentSucceededHandler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Application\Stock\CommandHandlers;

use App\Domain\Order\Services\OrderServiceInterface;
use App\Domain\Payment\Events\PaymentSucceeded;
use App\Domain\Stock\Events\StockReservationFailed;
use App\Domain\Stock\Events\StockReserved;
use App\Infrastructure\Messaging\IdempotentMessageHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class HandlePaymentSucceededHandler extends IdempotentMessageHandler
{
    public function __construct(
        private readonly MessageBusInterface $eventBus,
        private readonly OrderServiceInterface $orderService,
        \App\Infrastructure\Messaging\ProcessedMessageStore $processedStore,
        Connection $connection,
        LoggerInterface $logger,
    ) {
        parent::__construct($processedStore, $connection, $logger);
    }

    public static function consumerName(): string { return 'stock.handle_payment_succeeded'; }
    public static function schema(): string { return 'stock_ctx'; }

    protected function handle(object $message): void
    {
        /** @var PaymentSucceeded $message */
        $reservationId = Uuid::v4()->toRfc4122();

        $this->connection->insert('stock_ctx.stock_reservations', [
            'reservation_id' => $reservationId,
            'order_id'       => $message->orderId,
            'items'          => json_encode([]),  // En producción vendría del Order
            'status'         => 'reserved',
            'correlation_id' => $message->correlationId,
        ]);

        // Notificar al Order Context (síncrono)
        $this->orderService->markStockReserved($message->orderId, $reservationId);

        // Emitir integration event
        $this->eventBus->dispatch(new StockReserved(
            messageId: Uuid::v4()->toRfc4122(),
            correlationId: $message->correlationId,
            causationId: $message->messageId,
            occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            reservationId: $reservationId,
            orderId: $message->orderId,
            items: [],
        ));
    }
}
```

### 5.6 — Handler del Notification Context

**`src/Application/Notification/CommandHandlers/HandleNotificationTriggerHandler.php`**

Este handler se suscribe a `StockReserved`, `PaymentFailed` y `StockReservationFailed`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Notification\CommandHandlers;

use App\Infrastructure\Messaging\IdempotentMessageHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class HandleNotificationTriggerHandler extends IdempotentMessageHandler
{
    public static function consumerName(): string { return 'notification.handle_trigger'; }
    public static function schema(): string { return 'notification_ctx'; }

    protected function handle(object $message): void
    {
        $notificationId = Uuid::v4()->toRfc4122();
        $orderId = $message->orderId ?? 'unknown';
        $type = match (true) {
            property_exists($message, 'reservationId') => 'order_confirmed',
            property_exists($message, 'reason')        => 'order_failed',
            default                                     => 'order_update',
        };

        $this->connection->insert('notification_ctx.notification_log', [
            'notification_id' => $notificationId,
            'order_id'        => $orderId,
            'type'            => $type,
            'channel'         => 'email',
            'status'          => 'sent',
            'correlation_id'  => $message->correlationId ?? null,
        ]);

        $this->logger->info('Notification sent', [
            'notification_id' => $notificationId,
            'order_id'        => $orderId,
            'type'            => $type,
            'correlation_id'  => $message->correlationId ?? null,
        ]);
    }
}
```

### 5.7 — Handler del Audit Context

**`src/Application/Audit/CommandHandlers/AuditEventHandler.php`**

Se suscribe a TODOS los integration events:

```php
<?php

declare(strict_types=1);

namespace App\Application\Audit\CommandHandlers;

use App\Infrastructure\Messaging\IdempotentMessageHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class AuditEventHandler extends IdempotentMessageHandler
{
    public static function consumerName(): string { return 'audit.handle_all_events'; }
    public static function schema(): string { return 'audit_ctx'; }

    protected function handle(object $message): void
    {
        $this->connection->insert('audit_ctx.audit_log', [
            'event_type'     => get_class($message),
            'aggregate_id'   => $message->orderId ?? null,
            'payload'        => json_encode((array) $message),
            'correlation_id' => $message->correlationId ?? '',
            'causation_id'   => $message->causationId ?? '',
            'source_context' => $message->sourceContext ?? 'unknown',
            'occurred_at'    => $message->occurredAt ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
```

### 5.8 — Actualizar routing en `config/packages/messenger.yaml`

Añadir el routing de los integration events y la configuración de qué handlers consumen de qué colas:

```yaml
        routing:
            # ... routing existente ...
            App\Domain\Order\Events\OrderCreatedIntegration: order_events
            App\Domain\Payment\Events\PaymentSucceeded: order_events
            App\Domain\Payment\Events\PaymentFailed: order_events
            App\Domain\Stock\Events\StockReserved: order_events
            App\Domain\Stock\Events\StockReservationFailed: order_events
            App\Domain\Notification\Events\NotificationSent: order_events
            App\Domain\Notification\Events\NotificationFailed: order_events
```

### 5.9 — Registrar alias en `config/services.yaml`

```yaml
    App\Domain\Order\Services\OrderServiceInterface:
        alias: App\Application\Order\OrderService
        public: true
```

## Verificación

Al terminar esta fase:
1. El flujo completo funciona: crear un pedido → outbox relay → Payment handler → Stock handler → Notification handler → Audit handler
2. Enviar el mismo mensaje dos veces al Payment handler no crea dos registros en `payment_ctx.payments`
3. `SELECT * FROM audit_ctx.audit_log WHERE correlation_id = '<uuid>'` muestra todos los eventos del flujo
4. `php bin/console messenger:consume order_events --limit=10` procesa mensajes sin errores
