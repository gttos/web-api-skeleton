# Fase 01 — Infraestructura y Fundamentos

## Contexto del proyecto

Estás trabajando en un microservicio Symfony 6.4 / PHP 8.2+ que vamos a convertir en un laboratorio de Event Sourcing. El proyecto ya tiene configurado: Doctrine, Symfony Messenger, somnambulist/domain (buses de comandos/queries/eventos), RabbitMQ (ext-amqp disponible), PostgreSQL 15, Redis.

Estructura de carpetas existente:
```
src/
├── Application/CommandHandlers/   ← handlers de comandos
├── Application/QueryHandlers/     ← handlers de queries
├── Delivery/Api/                  ← controllers
├── Delivery/Console/              ← comandos de consola
├── Domain/Commands/               ← comandos de dominio
├── Domain/Events/                 ← eventos de dominio
├── Domain/Models/                 ← modelos
├── Domain/Queries/                ← queries
├── Domain/Services/Repositories/  ← interfaces de repositorios
├── Infrastructure/Persistence/    ← implementaciones de repositorios
└── Kernel.php
```

Archivos de configuración clave ya existentes:
- `config/packages/messenger.yaml` — buses y transportes de Messenger
- `config/packages/doctrine.yaml` — ORM con mappings XML
- `config/services.yaml` — autowiring de servicios
- `docker-compose.yml` — servicios Docker (actualmente: app + redis, SIN RabbitMQ)
- `.env` — variables de entorno

## Objetivo de esta fase

Preparar toda la infraestructura base antes de escribir código de dominio. Al terminar esta fase el proyecto debe poder arrancar con RabbitMQ disponible y la base de datos con todos los schemas y tablas creados.

## Tareas a implementar

### 1.1 — Actualizar `docker-compose.yml`

Añadir el servicio RabbitMQ al entorno de desarrollo:

```yaml
rabbitmq:
  image: rabbitmq:3.11-management-alpine
  environment:
    RABBITMQ_ERLANG_COOKIE: "event-sourcing-lab-cookie"
    RABBITMQ_DEFAULT_USER: guest
    RABBITMQ_DEFAULT_PASS: guest
  ports:
    - "5672:5672"
    - "15672:15672"   # Management UI
  networks:
    - mycompany_network_backend
```

### 1.2 — Actualizar `.env`

Cambiar el transport DSN para usar AMQP en lugar de Doctrine:
```
MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f
REDIS_URL=redis://app-redis:6379
```

### 1.3 — Actualizar `config/packages/messenger.yaml`

Reemplazar la configuración de transportes con la siguiente (mantener los buses existentes, solo cambiar/añadir transportes y routing):

```yaml
framework:
    messenger:
        failure_transport: failed
        default_bus: command.bus

        serializer:
            default_serializer: messenger.transport.symfony_serializer
            symfony_serializer:
                format: json
                context: { }

        buses:
            command.bus:
                middleware:
                    - doctrine_transaction
            query.bus:
                middleware: []
            event.bus:
                middleware: []
            job.queue:
                middleware: []

        transports:
            # Transport para integration events del Order Context
            order_events:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    exchange:
                        name: order_events
                        type: fanout
                    queues:
                        payment_consumer: { binding_keys: [] }
                        stock_consumer: { binding_keys: [] }
                        notification_consumer: { binding_keys: [] }
                        audit_consumer: { binding_keys: [] }
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 3
                    max_delay: 30000

            # Transport para comandos asíncronos al Stock Context
            stock_commands:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    exchange:
                        name: stock_commands
                        type: direct
                    queues:
                        stock_command_queue: { binding_keys: ['stock'] }
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 3
                    max_delay: 30000

            # Domain events (mantener el existente)
            domain_events:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    exchange:
                        name: domain_events
                        type: fanout

            # Job queue (mantener el existente)
            job_queue:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    exchange:
                        name: job_queue
                        type: fanout

            # Failure transport (doctrine, para dead letters)
            failed: 'doctrine://default?queue_name=failed'
            sync: 'sync://'

        routing:
            Somnambulist\Components\Events\AbstractEvent: domain_events
            Somnambulist\Components\Jobs\AbstractJob: job_queue
            Somnambulist\Components\Commands\AbstractCommand: sync
            Somnambulist\Components\Queries\AbstractQuery: sync
            # Los integration events se añadirán en fases posteriores
```

### 1.4 — Actualizar `config/services.yaml`

Añadir los recursos de autowiring para los nuevos namespaces de bounded contexts. Agregar al final del bloque `services:` (antes del bloque `when@dev`):

```yaml
    # Event Sourcing Lab — Bounded Contexts
    App\Application\Order\:
        resource: '../src/Application/Order'
        
    App\Application\Payment\:
        resource: '../src/Application/Payment'
        
    App\Application\Stock\:
        resource: '../src/Application/Stock'
        
    App\Application\Notification\:
        resource: '../src/Application/Notification'
        
    App\Application\Audit\:
        resource: '../src/Application/Audit'

    App\Infrastructure\EventStore\:
        resource: '../src/Infrastructure/EventStore'
        
    App\Infrastructure\Outbox\:
        resource: '../src/Infrastructure/Outbox'
        
    App\Infrastructure\Projection\:
        resource: '../src/Infrastructure/Projection'
        
    App\Infrastructure\Messaging\:
        resource: '../src/Infrastructure/Messaging'
        
    App\Infrastructure\DeadLetter\:
        resource: '../src/Infrastructure/DeadLetter'
        
    App\Infrastructure\Observability\:
        resource: '../src/Infrastructure/Observability'
        
    App\Infrastructure\ErrorInjection\:
        resource: '../src/Infrastructure/ErrorInjection'
```

### 1.5 — Crear las interfaces base

Crear los siguientes archivos con las interfaces exactas indicadas:

**`src/Infrastructure/EventStore/EventStoreInterface.php`**
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

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
```

**`src/Infrastructure/EventStore/SnapshotStoreInterface.php`**
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

interface SnapshotStoreInterface
{
    public function load(string $aggregateId): ?Snapshot;
    public function save(Snapshot $snapshot): void;
}
```

**`src/Infrastructure/Outbox/OutboxStoreInterface.php`**
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

interface OutboxStoreInterface
{
    public function store(OutboxMessage $message): void;

    /** @return OutboxMessage[] */
    public function fetchPending(int $limit = 100): array;

    public function markPublished(string $messageId): void;
}
```

**`src/Infrastructure/DeadLetter/DeadLetterStoreInterface.php`**
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\DeadLetter;

interface DeadLetterStoreInterface
{
    public function store(DeadLetterEntry $entry): void;

    /** @return DeadLetterEntry[] */
    public function findAll(?string $consumerName = null, ?string $eventType = null): array;

    public function findById(string $messageId): ?DeadLetterEntry;

    public function remove(string $messageId): void;

    public function incrementAttempts(string $messageId): void;
}
```

**`src/Infrastructure/Messaging/CorrelationContext.php`**
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Symfony\Component\Uid\Uuid;

final class CorrelationContext
{
    public function __construct(
        public readonly string $correlationId,
        public readonly string $causationId,
    ) {}

    /** Inicia un nuevo flujo generando correlation_id y causation_id frescos */
    public static function initiate(): self
    {
        $id = Uuid::v4()->toRfc4122();
        return new self($id, $id);
    }

    /** Crea un nuevo contexto con el mismo correlation_id pero nuevo causation_id */
    public function causedBy(string $newCausationId): self
    {
        return new self($this->correlationId, $newCausationId);
    }
}
```

**`src/Domain/Order/Services/OrderRepositoryInterface.php`**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

use App\Domain\Order\Model\Order;

interface OrderRepositoryInterface
{
    public function findById(string $orderId): Order;
    public function save(Order $order): void;
}
```

### 1.6 — Crear la migración de base de datos

Crear el archivo `migrations/Version20260101000001.php` con la siguiente migración que crea todos los schemas y tablas del laboratorio:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event Sourcing Lab — Crear todos los schemas y tablas';
    }

    public function up(Schema $schema): void
    {
        // Extensión UUID
        $this->addSql('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        // =============================================
        // SCHEMAS
        // =============================================
        $this->addSql('CREATE SCHEMA IF NOT EXISTS order_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS payment_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS stock_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS notification_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS audit_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS shared');

        // =============================================
        // ORDER CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE order_ctx.event_store (
                sequence_number BIGSERIAL PRIMARY KEY,
                aggregate_id    UUID NOT NULL,
                aggregate_type  VARCHAR(255) NOT NULL,
                event_type      VARCHAR(255) NOT NULL,
                event_version   INTEGER NOT NULL,
                payload         JSONB NOT NULL,
                metadata        JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                correlation_id  UUID NOT NULL,
                causation_id    UUID NOT NULL,
                occurred_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_aggregate_version UNIQUE (aggregate_id, event_version)
            )
        ');
        $this->addSql('CREATE INDEX idx_event_store_aggregate ON order_ctx.event_store (aggregate_id, event_version)');
        $this->addSql('CREATE INDEX idx_event_store_correlation ON order_ctx.event_store (correlation_id)');
        $this->addSql('CREATE INDEX idx_event_store_type ON order_ctx.event_store (event_type)');

        $this->addSql('
            CREATE TABLE order_ctx.snapshots (
                aggregate_id   UUID PRIMARY KEY,
                aggregate_type VARCHAR(255) NOT NULL,
                version        INTEGER NOT NULL,
                state          JSONB NOT NULL,
                created_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('
            CREATE TABLE order_ctx.outbox (
                id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                aggregate_id   UUID NOT NULL,
                event_type     VARCHAR(255) NOT NULL,
                payload        JSONB NOT NULL,
                metadata       JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                correlation_id UUID NOT NULL,
                causation_id   UUID NOT NULL,
                created_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                published_at   TIMESTAMP(6) WITH TIME ZONE NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_outbox_unpublished ON order_ctx.outbox (created_at) WHERE published_at IS NULL');

        $this->addSql('
            CREATE TABLE order_ctx.order_projections (
                order_id    UUID PRIMARY KEY,
                status      VARCHAR(50) NOT NULL,
                customer_id UUID NOT NULL,
                items       JSONB NOT NULL,
                total       DECIMAL(12,2) NOT NULL,
                created_at  TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(6) WITH TIME ZONE NOT NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_order_proj_status ON order_ctx.order_projections (status)');
        $this->addSql('CREATE INDEX idx_order_proj_customer ON order_ctx.order_projections (customer_id)');

        // =============================================
        // PAYMENT CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE payment_ctx.payments (
                payment_id     UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                order_id       UUID NOT NULL,
                amount         DECIMAL(12,2) NOT NULL,
                status         VARCHAR(50) NOT NULL,
                correlation_id UUID NOT NULL,
                created_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('
            CREATE TABLE payment_ctx.processed_messages (
                message_id     UUID NOT NULL,
                event_id       UUID,
                consumer_name  VARCHAR(255) NOT NULL,
                processed_at   TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                correlation_id UUID,
                PRIMARY KEY (message_id, consumer_name)
            )
        ');

        // =============================================
        // STOCK CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE stock_ctx.stock_reservations (
                reservation_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                order_id       UUID NOT NULL,
                items          JSONB NOT NULL,
                status         VARCHAR(50) NOT NULL,
                correlation_id UUID NOT NULL,
                created_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('
            CREATE TABLE stock_ctx.processed_messages (
                message_id     UUID NOT NULL,
                event_id       UUID,
                consumer_name  VARCHAR(255) NOT NULL,
                processed_at   TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                correlation_id UUID,
                PRIMARY KEY (message_id, consumer_name)
            )
        ');

        // =============================================
        // NOTIFICATION CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE notification_ctx.notification_log (
                notification_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                order_id        UUID NOT NULL,
                type            VARCHAR(100) NOT NULL,
                channel         VARCHAR(50) NOT NULL,
                status          VARCHAR(50) NOT NULL,
                correlation_id  UUID NOT NULL,
                created_at      TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('
            CREATE TABLE notification_ctx.processed_messages (
                message_id     UUID NOT NULL,
                event_id       UUID,
                consumer_name  VARCHAR(255) NOT NULL,
                processed_at   TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                correlation_id UUID,
                PRIMARY KEY (message_id, consumer_name)
            )
        ');

        // =============================================
        // AUDIT CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE audit_ctx.audit_log (
                id             BIGSERIAL PRIMARY KEY,
                event_type     VARCHAR(255) NOT NULL,
                aggregate_id   UUID,
                payload        JSONB NOT NULL,
                correlation_id UUID NOT NULL,
                causation_id   UUID NOT NULL,
                source_context VARCHAR(100) NOT NULL,
                occurred_at    TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                recorded_at    TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');
        $this->addSql('CREATE INDEX idx_audit_correlation ON audit_ctx.audit_log (correlation_id)');
        $this->addSql('CREATE INDEX idx_audit_event_type ON audit_ctx.audit_log (event_type)');
        $this->addSql('CREATE INDEX idx_audit_aggregate ON audit_ctx.audit_log (aggregate_id)');

        $this->addSql('
            CREATE TABLE audit_ctx.processed_messages (
                message_id     UUID NOT NULL,
                event_id       UUID,
                consumer_name  VARCHAR(255) NOT NULL,
                processed_at   TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                correlation_id UUID,
                PRIMARY KEY (message_id, consumer_name)
            )
        ');

        // =============================================
        // SHARED
        // =============================================

        $this->addSql('
            CREATE TABLE shared.dead_letter_store (
                message_id          UUID PRIMARY KEY,
                event_type          VARCHAR(255) NOT NULL,
                consumer_name       VARCHAR(255) NOT NULL,
                payload             JSONB NOT NULL,
                metadata            JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                error_reason        TEXT NOT NULL,
                stack_trace         TEXT,
                correlation_id      UUID,
                causation_id        UUID,
                original_transport  VARCHAR(100) NOT NULL,
                attempts            INTEGER NOT NULL DEFAULT 1,
                failed_at           TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                last_retry_at       TIMESTAMP(6) WITH TIME ZONE NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_dead_letter_correlation ON shared.dead_letter_store (correlation_id)');
        $this->addSql('CREATE INDEX idx_dead_letter_consumer ON shared.dead_letter_store (consumer_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA IF EXISTS order_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS payment_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS stock_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS notification_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS audit_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS shared CASCADE');
    }
}
```

## Verificación

Al terminar esta fase:
1. `docker-compose up -d` debe arrancar sin errores con RabbitMQ disponible en `localhost:15672`
2. `php bin/console doctrine:migrations:migrate` debe crear todos los schemas y tablas sin errores
3. `php bin/console debug:container | grep EventStore` debe mostrar los servicios registrados
4. No debe haber errores de compilación del contenedor de Symfony (`php bin/console cache:clear`)
