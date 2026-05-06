# Fase 06 — Dead Letters, Comandos de Gestión y Observabilidad

## Prerequisito

Fases 01–05 completadas: flujo completo de mensajería funcionando con consumers idempotentes.

## Contexto

Implementar dos sistemas transversales:
1. **Dead Letter Store**: captura mensajes que fallaron definitivamente, con comandos para inspeccionarlos y reintentarlos
2. **Observabilidad**: correlation/causation IDs propagados automáticamente en todos los buses, logs estructurados

## Tareas a implementar

### 6.1 — Dead Letter: value objects e implementación

**`src/Infrastructure/DeadLetter/DeadLetterEntry.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\DeadLetter;

final class DeadLetterEntry
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $eventType,
        public readonly string $consumerName,
        public readonly array $payload,
        public readonly array $metadata,
        public readonly string $errorReason,
        public readonly ?string $stackTrace,
        public readonly ?string $correlationId,
        public readonly ?string $causationId,
        public readonly string $originalTransport,
        public readonly int $attempts,
        public readonly \DateTimeImmutable $failedAt,
        public readonly ?\DateTimeImmutable $lastRetryAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            messageId: $row['message_id'],
            eventType: $row['event_type'],
            consumerName: $row['consumer_name'],
            payload: json_decode($row['payload'], true),
            metadata: json_decode($row['metadata'], true),
            errorReason: $row['error_reason'],
            stackTrace: $row['stack_trace'] ?? null,
            correlationId: $row['correlation_id'] ?? null,
            causationId: $row['causation_id'] ?? null,
            originalTransport: $row['original_transport'],
            attempts: (int) $row['attempts'],
            failedAt: new \DateTimeImmutable($row['failed_at']),
            lastRetryAt: isset($row['last_retry_at']) ? new \DateTimeImmutable($row['last_retry_at']) : null,
        );
    }
}
```

**`src/Infrastructure/DeadLetter/DeadLetterStore.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\DeadLetter;

use Doctrine\DBAL\Connection;

final class DeadLetterStore implements DeadLetterStoreInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function store(DeadLetterEntry $entry): void
    {
        $this->connection->executeStatement(
            'INSERT INTO shared.dead_letter_store
             (message_id, event_type, consumer_name, payload, metadata, error_reason, stack_trace,
              correlation_id, causation_id, original_transport, attempts, failed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (message_id) DO UPDATE SET
                attempts = shared.dead_letter_store.attempts + 1,
                last_retry_at = NOW(),
                error_reason = EXCLUDED.error_reason,
                stack_trace = EXCLUDED.stack_trace',
            [
                $entry->messageId,
                $entry->eventType,
                $entry->consumerName,
                json_encode($entry->payload),
                json_encode($entry->metadata),
                $entry->errorReason,
                $entry->stackTrace,
                $entry->correlationId,
                $entry->causationId,
                $entry->originalTransport,
                $entry->attempts,
                $entry->failedAt->format('Y-m-d H:i:s.u P'),
            ]
        );
    }

    public function findAll(?string $consumerName = null, ?string $eventType = null): array
    {
        $sql = 'SELECT * FROM shared.dead_letter_store WHERE 1=1';
        $params = [];

        if ($consumerName !== null) {
            $sql .= ' AND consumer_name = ?';
            $params[] = $consumerName;
        }

        if ($eventType !== null) {
            $sql .= ' AND event_type = ?';
            $params[] = $eventType;
        }

        $sql .= ' ORDER BY failed_at DESC';

        $rows = $this->connection->fetchAllAssociative($sql, $params);
        return array_map(fn(array $row) => DeadLetterEntry::fromRow($row), $rows);
    }

    public function findById(string $messageId): ?DeadLetterEntry
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM shared.dead_letter_store WHERE message_id = ?',
            [$messageId]
        );

        return $row ? DeadLetterEntry::fromRow($row) : null;
    }

    public function remove(string $messageId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM shared.dead_letter_store WHERE message_id = ?',
            [$messageId]
        );
    }

    public function incrementAttempts(string $messageId): void
    {
        $this->connection->executeStatement(
            'UPDATE shared.dead_letter_store SET attempts = attempts + 1, last_retry_at = NOW() WHERE message_id = ?',
            [$messageId]
        );
    }
}
```

### 6.2 — Integrar Dead Letter Store con el failure transport de Messenger

Crear un event subscriber que capture mensajes del transport `failed` y los persista en `DeadLetterStore`:

**`src/Infrastructure/DeadLetter/DeadLetterSubscriber.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\DeadLetter;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;

final class DeadLetterSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly DeadLetterStoreInterface $deadLetterStore) {}

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        // Solo capturar cuando ya no se va a reintentar (willRetry = false)
        if ($event->willRetry()) {
            return;
        }

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $throwable = $event->getThrowable();

        $messageId = $this->extractMessageId($envelope);
        $correlationId = $this->extractCorrelationId($message);
        $causationId = $this->extractCausationId($message);

        $entry = new DeadLetterEntry(
            messageId: $messageId,
            eventType: get_class($message),
            consumerName: 'messenger_worker',
            payload: $this->extractPayload($message),
            metadata: [],
            errorReason: $throwable->getMessage(),
            stackTrace: $throwable->getTraceAsString(),
            correlationId: $correlationId,
            causationId: $causationId,
            originalTransport: 'order_events',
            attempts: 1,
            failedAt: new \DateTimeImmutable(),
            lastRetryAt: null,
        );

        $this->deadLetterStore->store($entry);
    }

    private function extractMessageId(\Symfony\Component\Messenger\Envelope $envelope): string
    {
        $stamp = $envelope->last(TransportMessageIdStamp::class);
        if ($stamp) {
            return (string) $stamp->getId();
        }
        $message = $envelope->getMessage();
        if (property_exists($message, 'messageId')) {
            return $message->messageId;
        }
        return Uuid::v4()->toRfc4122();
    }

    private function extractCorrelationId(object $message): ?string
    {
        return property_exists($message, 'correlationId') ? $message->correlationId : null;
    }

    private function extractCausationId(object $message): ?string
    {
        return property_exists($message, 'causationId') ? $message->causationId : null;
    }

    private function extractPayload(object $message): array
    {
        return json_decode(json_encode($message), true) ?? [];
    }
}
```

Registrar en `config/services.yaml`:
```yaml
    App\Infrastructure\DeadLetter\DeadLetterStoreInterface:
        alias: App\Infrastructure\DeadLetter\DeadLetterStore
        public: true

    App\Infrastructure\DeadLetter\DeadLetterSubscriber:
        tags:
            - { name: kernel.event_subscriber }
```

### 6.3 — Comandos de gestión de dead letters

**`src/Delivery/Console/DeadLetter/ListCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Console\DeadLetter;

use App\Infrastructure\DeadLetter\DeadLetterStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dead-letter:list', description: 'Lista los mensajes en el Dead Letter Store')]
final class ListCommand extends Command
{
    public function __construct(private readonly DeadLetterStoreInterface $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('consumer', null, InputOption::VALUE_OPTIONAL, 'Filtrar por consumer_name')
            ->addOption('event-type', null, InputOption::VALUE_OPTIONAL, 'Filtrar por event_type');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->store->findAll(
            $input->getOption('consumer'),
            $input->getOption('event-type')
        );

        if (empty($entries)) {
            $output->writeln('<info>No hay mensajes en el Dead Letter Store.</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['message_id', 'event_type', 'consumer_name', 'error_reason', 'correlation_id', 'attempts', 'failed_at']);

        foreach ($entries as $entry) {
            $table->addRow([
                substr($entry->messageId, 0, 8) . '...',
                $entry->eventType,
                $entry->consumerName,
                substr($entry->errorReason, 0, 50),
                $entry->correlationId ? substr($entry->correlationId, 0, 8) . '...' : '-',
                $entry->attempts,
                $entry->failedAt->format('Y-m-d H:i:s'),
            ]);
        }

        $table->render();
        $output->writeln(sprintf('<comment>Total: %d mensajes</comment>', count($entries)));

        return Command::SUCCESS;
    }
}
```

**`src/Delivery/Console/DeadLetter/ShowCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Console\DeadLetter;

use App\Infrastructure\DeadLetter\DeadLetterStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dead-letter:show', description: 'Muestra el detalle de un mensaje en el Dead Letter Store')]
final class ShowCommand extends Command
{
    public function __construct(private readonly DeadLetterStoreInterface $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('message-id', InputArgument::REQUIRED, 'UUID del mensaje');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entry = $this->store->findById($input->getArgument('message-id'));

        if ($entry === null) {
            $output->writeln('<error>Mensaje no encontrado.</error>');
            return Command::FAILURE;
        }

        $output->writeln("<info>message_id:</info>      {$entry->messageId}");
        $output->writeln("<info>event_type:</info>      {$entry->eventType}");
        $output->writeln("<info>consumer_name:</info>   {$entry->consumerName}");
        $output->writeln("<info>correlation_id:</info>  {$entry->correlationId}");
        $output->writeln("<info>causation_id:</info>    {$entry->causationId}");
        $output->writeln("<info>attempts:</info>        {$entry->attempts}");
        $output->writeln("<info>failed_at:</info>       {$entry->failedAt->format('Y-m-d H:i:s')}");
        $output->writeln("<info>error_reason:</info>    {$entry->errorReason}");
        $output->writeln('');
        $output->writeln('<info>payload:</info>');
        $output->writeln(json_encode($entry->payload, JSON_PRETTY_PRINT));
        $output->writeln('');

        if ($entry->stackTrace) {
            $output->writeln('<info>stack_trace:</info>');
            $output->writeln($entry->stackTrace);
        }

        return Command::SUCCESS;
    }
}
```

**`src/Delivery/Console/DeadLetter/RetryCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Console\DeadLetter;

use App\Infrastructure\DeadLetter\DeadLetterStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'dead-letter:retry', description: 'Reenvía un mensaje del Dead Letter Store al transport original')]
final class RetryCommand extends Command
{
    public function __construct(
        private readonly DeadLetterStoreInterface $store,
        private readonly MessageBusInterface $eventBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('message-id', InputArgument::REQUIRED, 'UUID del mensaje a reintentar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $messageId = $input->getArgument('message-id');
        $entry = $this->store->findById($messageId);

        if ($entry === null) {
            $output->writeln('<error>Mensaje no encontrado.</error>');
            return Command::FAILURE;
        }

        $this->store->incrementAttempts($messageId);

        // Reconstruir el mensaje y reenviarlo
        // En una implementación real, se deserializaría el mensaje original
        // Aquí usamos OutboxIntegrationMessage como proxy genérico
        $message = new \App\Infrastructure\Outbox\OutboxIntegrationMessage(
            messageId: $entry->messageId,
            eventType: $entry->eventType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            correlationId: $entry->correlationId ?? '',
            causationId: $entry->causationId ?? '',
        );

        try {
            $this->eventBus->dispatch($message);
            $this->store->remove($messageId);
            $output->writeln("<info>Mensaje {$messageId} reenviado exitosamente.</info>");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>Error al reenviar: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
```

**`src/Delivery/Console/DeadLetter/RetryAllCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Console\DeadLetter;

use App\Infrastructure\DeadLetter\DeadLetterStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dead-letter:retry-all', description: 'Reenvía todos los mensajes del Dead Letter Store')]
final class RetryAllCommand extends Command
{
    public function __construct(
        private readonly DeadLetterStoreInterface $store,
        private readonly RetryCommand $retryCommand,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->store->findAll();

        if (empty($entries)) {
            $output->writeln('<info>No hay mensajes para reintentar.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Reintentando %d mensajes...', count($entries)));

        foreach ($entries as $entry) {
            $output->write("  {$entry->messageId}: ");
            // Delegar al RetryCommand individual
            $result = $this->retryCommand->run(
                new \Symfony\Component\Console\Input\ArrayInput(['message-id' => $entry->messageId]),
                $output
            );
            if ($result !== Command::SUCCESS) {
                $output->writeln('<error>FAILED</error>');
            }
        }

        return Command::SUCCESS;
    }
}
```

### 6.4 — Observabilidad: Correlation ID Stamps y Middleware

**`src/Infrastructure/Messaging/CorrelationIdStamp.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Symfony\Component\Messenger\Stamp\StampInterface;

final class CorrelationIdStamp implements StampInterface
{
    public function __construct(
        public readonly string $correlationId,
        public readonly string $causationId,
    ) {}
}
```

**`src/Infrastructure/Messaging/CorrelationIdMiddleware.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(CorrelationIdStamp::class);

        if ($stamp === null) {
            // Extraer del mensaje si tiene los campos directamente
            $message = $envelope->getMessage();
            $correlationId = property_exists($message, 'correlationId')
                ? $message->correlationId
                : Uuid::v4()->toRfc4122();
            $causationId = property_exists($message, 'causationId')
                ? $message->causationId
                : Uuid::v4()->toRfc4122();

            $stamp = new CorrelationIdStamp($correlationId, $causationId);
            $envelope = $envelope->with($stamp);
        }

        // Inyectar en el contexto del logger para que todos los logs del handler incluyan estos IDs
        $this->logger->pushProcessor(function (array $record) use ($stamp): array {
            $record['extra']['correlation_id'] = $stamp->correlationId;
            $record['extra']['causation_id']   = $stamp->causationId;
            return $record;
        });

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->logger->popProcessor();
        }
    }
}
```

Registrar el middleware en todos los buses en `config/packages/messenger.yaml`:

```yaml
        buses:
            command.bus:
                middleware:
                    - doctrine_transaction
                    - App\Infrastructure\Messaging\CorrelationIdMiddleware
            query.bus:
                middleware:
                    - App\Infrastructure\Messaging\CorrelationIdMiddleware
            event.bus:
                middleware:
                    - App\Infrastructure\Messaging\CorrelationIdMiddleware
            job.queue:
                middleware:
                    - App\Infrastructure\Messaging\CorrelationIdMiddleware
```

### 6.5 — Structured Logger

**`src/Infrastructure/Observability/StructuredLogger.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

final class StructuredLogger implements LoggerInterface
{
    use LoggerTrait;

    private array $defaultContext = [];

    public function __construct(private readonly LoggerInterface $inner) {}

    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->defaultContext = array_merge($this->defaultContext, $context);
        return $clone;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, array_merge($this->defaultContext, $context));
    }
}
```

Actualizar `config/packages/monolog.yaml` para añadir un handler con formato JSON en el entorno de desarrollo (útil para buscar por correlation_id con `jq`):

```yaml
monolog:
    handlers:
        main:
            type: stream
            path: "%kernel.logs_dir%/%kernel.environment%.log"
            level: debug
            channels: ["!event"]
            formatter: monolog.formatter.json

when@dev:
    monolog:
        handlers:
            main:
                type: stream
                path: "%kernel.logs_dir%/%kernel.environment%.log"
                level: debug
                formatter: monolog.formatter.json
```

Añadir el formatter JSON en `config/services.yaml`:

```yaml
    monolog.formatter.json:
        class: Monolog\Formatter\JsonFormatter
```

## Verificación

Al terminar esta fase:
1. `php bin/console dead-letter:list` ejecuta sin errores (muestra "No hay mensajes" si el store está vacío)
2. Forzar un error en un consumer (lanzar excepción en `handle()`) y verificar que el mensaje aparece en `shared.dead_letter_store` tras agotar reintentos
3. `php bin/console dead-letter:retry <message-id>` reenvía el mensaje
4. Los logs en `var/log/dev.log` tienen formato JSON con `correlation_id` en el campo `extra`
5. Buscar por correlation_id: `cat var/log/dev.log | grep '<uuid>'` muestra todos los logs del flujo
