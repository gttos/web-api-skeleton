# Fase 07 — Inyección de Errores, Seeding y Event Upcasting

## Prerequisito

Fases 01–06 completadas: flujo completo funcionando con dead letters y observabilidad.

## Contexto

Implementar los tres sistemas que hacen el laboratorio realmente útil para practicar:
1. **Error Injection**: permite provocar errores controlados (temporales, permanentes, payload inválido, slow consumer, concurrencia) usando flags en Redis
2. **Seeding**: genera volúmenes configurables de pedidos con tasas de error y duplicados
3. **Event Upcasting**: demuestra cómo evolucionar el esquema de eventos sin romper compatibilidad histórica

## Tareas a implementar

### 7.1 — Error Injection: value objects e interfaz

**`src/Infrastructure/ErrorInjection/ErrorFlag.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorInjection;

final class ErrorFlag
{
    // Tipos de error soportados
    public const TEMP_FAILURE         = 'TEMP_FAILURE';
    public const PERM_FAILURE         = 'PERM_FAILURE';
    public const INVALID_PAYLOAD      = 'INVALID_PAYLOAD';
    public const SLOW_CONSUMER        = 'SLOW_CONSUMER';
    public const CONCURRENCY_CONFLICT = 'CONCURRENCY_CONFLICT';

    public function __construct(
        public readonly string $type,
        public readonly string $correlationId,
        public readonly array $config = [],
        // Para TEMP_FAILURE: cuántas veces debe fallar antes de tener éxito
        // Para SLOW_CONSUMER: delay_ms
    ) {}
}
```

**`src/Infrastructure/ErrorInjection/ErrorFlagStoreInterface.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorInjection;

interface ErrorFlagStoreInterface
{
    public function setFlag(string $correlationId, string $errorType, array $config = []): void;
    public function getFlag(string $correlationId): ?ErrorFlag;
    public function clearFlag(string $correlationId): void;
    /** Incrementa el contador de intentos fallidos para TEMP_FAILURE */
    public function incrementFailureCount(string $correlationId): int;
    public function getFailureCount(string $correlationId): int;
}
```

### 7.2 — Error Injection: implementación con Redis

**`src/Infrastructure/ErrorInjection/RedisErrorFlagStore.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorInjection;

use Symfony\Component\Cache\Adapter\RedisAdapter;

final class RedisErrorFlagStore implements ErrorFlagStoreInterface
{
    private const TTL = 300; // 5 minutos

    public function __construct(private readonly \Redis $redis) {}

    public function setFlag(string $correlationId, string $errorType, array $config = []): void
    {
        $key = $this->flagKey($correlationId);
        $data = json_encode(['type' => $errorType, 'config' => $config, 'correlation_id' => $correlationId]);
        $this->redis->setex($key, self::TTL, $data);
    }

    public function getFlag(string $correlationId): ?ErrorFlag
    {
        $data = $this->redis->get($this->flagKey($correlationId));
        if ($data === false) {
            return null;
        }
        $decoded = json_decode($data, true);
        return new ErrorFlag(
            type: $decoded['type'],
            correlationId: $decoded['correlation_id'],
            config: $decoded['config'] ?? [],
        );
    }

    public function clearFlag(string $correlationId): void
    {
        $this->redis->del($this->flagKey($correlationId));
        $this->redis->del($this->counterKey($correlationId));
    }

    public function incrementFailureCount(string $correlationId): int
    {
        $key = $this->counterKey($correlationId);
        $count = $this->redis->incr($key);
        $this->redis->expire($key, self::TTL);
        return $count;
    }

    public function getFailureCount(string $correlationId): int
    {
        return (int) ($this->redis->get($this->counterKey($correlationId)) ?: 0);
    }

    private function flagKey(string $correlationId): string
    {
        return "es_lab:error_flag:{$correlationId}";
    }

    private function counterKey(string $correlationId): string
    {
        return "es_lab:failure_count:{$correlationId}";
    }
}
```

Registrar en `config/services.yaml`:
```yaml
    App\Infrastructure\ErrorInjection\ErrorFlagStoreInterface:
        alias: App\Infrastructure\ErrorInjection\RedisErrorFlagStore
        public: true

    App\Infrastructure\ErrorInjection\RedisErrorFlagStore:
        arguments:
            $redis: '@snc_redis.default'
        # Si no hay snc_redis, usar:
        # $redis: !service { class: Redis, calls: [[connect, ['%env(REDIS_HOST)%', 6379]]] }
```

**Nota**: Si el proyecto no tiene `snc/redis-bundle`, crear el servicio Redis directamente:

```yaml
    redis.client:
        class: Redis
        calls:
            - [connect, ['%env(REDIS_HOST)%', '%env(int:REDIS_PORT)%']]

    App\Infrastructure\ErrorInjection\RedisErrorFlagStore:
        arguments:
            $redis: '@redis.client'
```

Y añadir en `.env`:
```
REDIS_HOST=app-redis
REDIS_PORT=6379
```

### 7.3 — Error Injection Middleware

**`src/Infrastructure/ErrorInjection/ErrorInjectionMiddleware.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorInjection;

use App\Infrastructure\Messaging\CorrelationIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class ErrorInjectionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ErrorFlagStoreInterface $flagStore) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $correlationId = $this->extractCorrelationId($envelope);

        if ($correlationId === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $flag = $this->flagStore->getFlag($correlationId);

        if ($flag === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        match ($flag->type) {
            ErrorFlag::TEMP_FAILURE => $this->handleTempFailure($flag),
            ErrorFlag::PERM_FAILURE => throw new \RuntimeException(
                "Simulated permanent failure for correlation_id: {$correlationId}"
            ),
            ErrorFlag::INVALID_PAYLOAD => throw new \InvalidArgumentException(
                "Simulated invalid payload for correlation_id: {$correlationId}"
            ),
            ErrorFlag::SLOW_CONSUMER => $this->handleSlowConsumer($flag),
            default => null,
        };

        return $stack->next()->handle($envelope, $stack);
    }

    private function handleTempFailure(ErrorFlag $flag): void
    {
        $maxFailures = (int) ($flag->config['max_failures'] ?? 2);
        $count = $this->flagStore->incrementFailureCount($flag->correlationId);

        if ($count <= $maxFailures) {
            throw new \RuntimeException(
                "Simulated temporary failure #{$count} for correlation_id: {$flag->correlationId}"
            );
        }

        // Después de max_failures intentos, limpiar el flag y continuar normalmente
        $this->flagStore->clearFlag($flag->correlationId);
    }

    private function handleSlowConsumer(ErrorFlag $flag): void
    {
        $delayMs = (int) ($flag->config['delay_ms'] ?? 2000);
        usleep($delayMs * 1000);
    }

    private function extractCorrelationId(Envelope $envelope): ?string
    {
        $stamp = $envelope->last(CorrelationIdStamp::class);
        if ($stamp) {
            return $stamp->correlationId;
        }
        $message = $envelope->getMessage();
        if (property_exists($message, 'correlationId')) {
            return $message->correlationId;
        }
        return null;
    }
}
```

Registrar el middleware en los buses de consumers en `config/packages/messenger.yaml`:

```yaml
        buses:
            event.bus:
                middleware:
                    - App\Infrastructure\Messaging\CorrelationIdMiddleware
                    - App\Infrastructure\ErrorInjection\ErrorInjectionMiddleware
```

### 7.4 — Comando `seed:orders`

**`src/Delivery/Console/SeedOrdersCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Console;

use App\Domain\Order\Commands\CreateOrder;
use App\Infrastructure\ErrorInjection\ErrorFlag;
use App\Infrastructure\ErrorInjection\ErrorFlagStoreInterface;
use Faker\Factory;
use Somnambulist\Components\Commands\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'seed:orders', description: 'Genera pedidos de prueba en volumen')]
final class SeedOrdersCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly ErrorFlagStoreInterface $flagStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Número de pedidos a crear', 100)
            ->addOption('error-rate', 'e', InputOption::VALUE_OPTIONAL, 'Porcentaje de pedidos con error (0-100)', 0)
            ->addOption('with-duplicates', 'd', InputOption::VALUE_NONE, 'Reenviar ~10% de mensajes como duplicados');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count         = (int) $input->getOption('count');
        $errorRate     = (int) $input->getOption('error-rate');
        $withDuplicates = $input->getOption('with-duplicates');
        $faker         = Factory::create();

        $output->writeln(sprintf(
            'Generando <info>%d</info> pedidos (error-rate: %d%%, duplicates: %s)...',
            $count,
            $errorRate,
            $withDuplicates ? 'sí' : 'no'
        ));

        $createdOrders = [];

        for ($i = 1; $i <= $count; $i++) {
            $orderId       = Uuid::v4()->toRfc4122();
            $correlationId = Uuid::v4()->toRfc4122();

            // Inyectar error si corresponde
            if ($errorRate > 0 && rand(1, 100) <= $errorRate) {
                $errorType = $this->randomErrorType();
                $this->flagStore->setFlag($correlationId, $errorType, $this->errorConfig($errorType));
                $output->writeln(sprintf(
                    '  [%d/%d] <comment>ERROR(%s)</comment> Order %s (correlation: %s)',
                    $i, $count, $errorType, $orderId, $correlationId
                ));
            } else {
                $output->writeln(sprintf(
                    '  [%d/%d] Order %s (correlation: %s)',
                    $i, $count, $orderId, $correlationId
                ));
            }

            $command = new CreateOrder(
                orderId: $orderId,
                customerId: Uuid::v4()->toRfc4122(),
                items: $this->randomItems($faker),
                correlationId: $correlationId,
                currency: $faker->randomElement(['EUR', 'USD', 'GBP']),
            );

            $this->commandBus->execute($command);
            $createdOrders[] = ['orderId' => $orderId, 'correlationId' => $correlationId];
        }

        // Enviar duplicados (~10%)
        if ($withDuplicates && !empty($createdOrders)) {
            $duplicateCount = max(1, (int) round(count($createdOrders) * 0.1));
            $output->writeln(sprintf('Enviando <comment>%d</comment> mensajes duplicados...', $duplicateCount));

            $selected = array_rand($createdOrders, min($duplicateCount, count($createdOrders)));
            if (!is_array($selected)) {
                $selected = [$selected];
            }

            foreach ($selected as $idx) {
                $original = $createdOrders[$idx];
                // Reenviar el mismo comando con el mismo correlationId (simula duplicado)
                $duplicate = new CreateOrder(
                    orderId: Uuid::v4()->toRfc4122(), // Nuevo orderId para no violar PK
                    customerId: Uuid::v4()->toRfc4122(),
                    items: $this->randomItems($faker),
                    correlationId: $original['correlationId'], // Mismo correlationId
                );
                $this->commandBus->execute($duplicate);
                $output->writeln(sprintf(
                    '  <comment>[DUP]</comment> Duplicate for correlation: %s',
                    $original['correlationId']
                ));
            }
        }

        $output->writeln(sprintf('<info>Done. %d pedidos creados.</info>', $count));

        return Command::SUCCESS;
    }

    private function randomItems(\Faker\Generator $faker): array
    {
        $count = rand(1, 5);
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'sku'        => strtoupper($faker->bothify('??###')),
                'name'       => $faker->words(3, true),
                'quantity'   => rand(1, 10),
                'unit_price' => round($faker->randomFloat(2, 5, 500), 2),
            ];
        }
        return $items;
    }

    private function randomErrorType(): string
    {
        return $faker = [
            ErrorFlag::TEMP_FAILURE,
            ErrorFlag::PERM_FAILURE,
            ErrorFlag::INVALID_PAYLOAD,
            ErrorFlag::SLOW_CONSUMER,
        ][array_rand([
            ErrorFlag::TEMP_FAILURE,
            ErrorFlag::PERM_FAILURE,
            ErrorFlag::INVALID_PAYLOAD,
            ErrorFlag::SLOW_CONSUMER,
        ])];
    }

    private function errorConfig(string $errorType): array
    {
        return match ($errorType) {
            ErrorFlag::TEMP_FAILURE    => ['max_failures' => rand(1, 2)],
            ErrorFlag::SLOW_CONSUMER   => ['delay_ms' => rand(1000, 5000)],
            default                    => [],
        };
    }
}
```

### 7.5 — Event Upcasting

**`src/Infrastructure/EventStore/Upcasting/UpcasterInterface.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore\Upcasting;

interface UpcasterInterface
{
    /** Retorna true si este upcaster puede transformar el evento dado */
    public function canUpcast(string $eventType, int $fromVersion): bool;

    /** Transforma el payload de la versión fromVersion a targetVersion() */
    public function upcast(array $payload, int $fromVersion): array;

    /** Versión de destino después de aplicar este upcaster */
    public function targetVersion(): int;
}
```

**`src/Infrastructure/EventStore/Upcasting/UpcasterChain.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore\Upcasting;

final class UpcasterChain
{
    /** @param UpcasterInterface[] $upcasters */
    public function __construct(private readonly array $upcasters = []) {}

    /**
     * Aplica la cadena de upcasters al payload del evento.
     * El Event Store original NO se modifica; esto se aplica solo en tiempo de lectura.
     */
    public function upcast(string $eventType, array $payload, int $eventVersion): array
    {
        $currentVersion = $eventVersion;
        $currentPayload = $payload;

        foreach ($this->upcasters as $upcaster) {
            if ($upcaster->canUpcast($eventType, $currentVersion)) {
                $currentPayload = $upcaster->upcast($currentPayload, $currentVersion);
                $currentVersion = $upcaster->targetVersion();
            }
        }

        return $currentPayload;
    }
}
```

**`src/Infrastructure/EventStore/Upcasting/Upcasters/OrderCreatedV1ToV2Upcaster.php`**

Ejemplo concreto: la versión 1 de `OrderCreated` no tenía el campo `currency`. La versión 2 lo añade con valor por defecto `EUR` para eventos históricos.

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore\Upcasting\Upcasters;

use App\Infrastructure\EventStore\Upcasting\UpcasterInterface;

final class OrderCreatedV1ToV2Upcaster implements UpcasterInterface
{
    public function canUpcast(string $eventType, int $fromVersion): bool
    {
        return $eventType === 'OrderCreated' && $fromVersion === 1;
    }

    public function upcast(array $payload, int $fromVersion): array
    {
        // Añadir campo currency con valor por defecto para eventos históricos
        if (!isset($payload['currency'])) {
            $payload['currency'] = 'EUR';
        }
        return $payload;
    }

    public function targetVersion(): int
    {
        return 2;
    }
}
```

### 7.6 — Integrar UpcasterChain en DbalEventStore

Modificar `DbalEventStore` para aplicar upcasting en tiempo de lectura:

```php
// En DbalEventStore, añadir constructor argument:
public function __construct(
    private readonly Connection $connection,
    private readonly UpcasterChain $upcasterChain = new UpcasterChain(),
) {}

// En loadStream() y loadAllFromSequence(), aplicar upcasting al mapear:
fn(array $row) => StoredEvent::fromRow(array_merge($row, [
    'payload' => json_encode(
        $this->upcasterChain->upcast(
            $row['event_type'],
            json_decode($row['payload'], true),
            (int) $row['event_version']
        )
    ),
]))
```

Registrar en `config/services.yaml`:
```yaml
    App\Infrastructure\EventStore\Upcasting\UpcasterChain:
        arguments:
            $upcasters:
                - '@App\Infrastructure\EventStore\Upcasting\Upcasters\OrderCreatedV1ToV2Upcaster'

    App\Infrastructure\EventStore\DbalEventStore:
        arguments:
            $connection: '@doctrine.dbal.default_connection'
            $upcasterChain: '@App\Infrastructure\EventStore\Upcasting\UpcasterChain'
```

## Verificación

Al terminar esta fase:

1. **Error injection funciona**:
   ```bash
   # Crear un pedido con error permanente
   php bin/console seed:orders --count=1 --error-rate=100
   # Consumir mensajes y ver que va a dead letter
   php bin/console messenger:consume order_events --limit=5
   # Ver el dead letter
   php bin/console dead-letter:list
   ```

2. **Seeding funciona**:
   ```bash
   php bin/console seed:orders --count=50 --error-rate=20 --with-duplicates
   # Verificar en la DB:
   # SELECT COUNT(*) FROM order_ctx.event_store;  → debe ser ~50
   # SELECT COUNT(*) FROM order_ctx.outbox WHERE published_at IS NULL;  → mensajes pendientes
   ```

3. **Upcasting funciona**:
   - Insertar manualmente un evento `OrderCreated` con `event_version=1` y sin campo `currency` en el payload
   - Cargar el agregado y verificar que `$order->currency()` retorna `'EUR'`
   - Verificar que el registro original en la DB sigue sin el campo `currency`

4. **Slow consumer funciona**:
   ```bash
   php bin/console seed:orders --count=1 --error-rate=100
   # Configurar SLOW_CONSUMER con delay_ms=3000
   # Consumir y verificar que tarda ~3 segundos pero procesa correctamente
   ```
