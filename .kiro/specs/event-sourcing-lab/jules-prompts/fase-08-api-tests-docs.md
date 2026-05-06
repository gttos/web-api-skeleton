# Fase 08 — API REST, Tests y Documentación

## Prerequisito

Fases 01–07 completadas: laboratorio completo funcionando.

## Contexto

Fase final: exponer el laboratorio vía API REST, escribir la suite de tests y crear la documentación de referencia para entrevistas técnicas.

## Tareas a implementar

### 8.1 — API REST: endpoints de Orders

**`src/Delivery/Api/V1/Orders/CreateOrderController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Api\V1\Orders;

use App\Domain\Order\Commands\CreateOrder;
use Somnambulist\Components\Commands\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/orders', methods: ['POST'])]
final class CreateOrderController extends AbstractController
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['customer_id']) || empty($data['items'])) {
            return new JsonResponse(['error' => 'customer_id and items are required'], Response::HTTP_BAD_REQUEST);
        }

        $orderId       = Uuid::v4()->toRfc4122();
        $correlationId = $request->headers->get('X-Correlation-Id', Uuid::v4()->toRfc4122());

        $this->commandBus->execute(new CreateOrder(
            orderId: $orderId,
            customerId: $data['customer_id'],
            items: $data['items'],
            correlationId: $correlationId,
            currency: $data['currency'] ?? 'EUR',
        ));

        return new JsonResponse(
            ['order_id' => $orderId, 'correlation_id' => $correlationId],
            Response::HTTP_ACCEPTED
        );
    }
}
```

**`src/Delivery/Api/V1/Orders/GetOrderController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Api\V1\Orders;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1/orders/{orderId}', methods: ['GET'])]
final class GetOrderController extends AbstractController
{
    public function __construct(private readonly Connection $connection) {}

    public function __invoke(string $orderId): JsonResponse
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.order_projections WHERE order_id = ?',
            [$orderId]
        );

        if ($row === false) {
            return new JsonResponse(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'order_id'    => $row['order_id'],
            'status'      => $row['status'],
            'customer_id' => $row['customer_id'],
            'items'       => json_decode($row['items'], true),
            'total'       => (float) $row['total'],
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ]);
    }
}
```

**`src/Delivery/Api/V1/Orders/ListOrdersController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Api\V1\Orders;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1/orders', methods: ['GET'])]
final class ListOrdersController extends AbstractController
{
    public function __construct(private readonly Connection $connection) {}

    public function __invoke(Request $request): JsonResponse
    {
        $status  = $request->query->get('status');
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));
        $offset  = ($page - 1) * $perPage;

        $sql    = 'SELECT * FROM order_ctx.order_projections WHERE 1=1';
        $params = [];

        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $total = (int) $this->connection->fetchOne(
            str_replace('SELECT *', 'SELECT COUNT(*)', $sql),
            $params
        );

        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return new JsonResponse([
            'data' => array_map(fn(array $row) => [
                'order_id'    => $row['order_id'],
                'status'      => $row['status'],
                'customer_id' => $row['customer_id'],
                'total'       => (float) $row['total'],
                'created_at'  => $row['created_at'],
            ], $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }
}
```

**`src/Delivery/Api/V1/Orders/CancelOrderController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Delivery\Api\V1\Orders;

use App\Domain\Order\Commands\CancelOrder;
use Somnambulist\Components\Commands\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/orders/{orderId}', methods: ['DELETE'])]
final class CancelOrderController extends AbstractController
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function __invoke(string $orderId, Request $request): JsonResponse
    {
        $data   = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Cancelled by user';
        $correlationId = $request->headers->get('X-Correlation-Id', Uuid::v4()->toRfc4122());

        $this->commandBus->execute(new CancelOrder(
            orderId: $orderId,
            reason: $reason,
            correlationId: $correlationId,
        ));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

Registrar rutas en `config/routes.yaml`:
```yaml
app_api_v1_orders_create:
    path: /api/v1/orders
    controller: App\Delivery\Api\V1\Orders\CreateOrderController
    methods: [POST]

app_api_v1_orders_list:
    path: /api/v1/orders
    controller: App\Delivery\Api\V1\Orders\ListOrdersController
    methods: [GET]

app_api_v1_orders_get:
    path: /api/v1/orders/{orderId}
    controller: App\Delivery\Api\V1\Orders\GetOrderController
    methods: [GET]

app_api_v1_orders_cancel:
    path: /api/v1/orders/{orderId}
    controller: App\Delivery\Api\V1\Orders\CancelOrderController
    methods: [DELETE]
```

### 8.2 — Tests unitarios de infraestructura

**`tests/Unit/Infrastructure/EventStore/DbalEventStoreTest.php`**

Tests que verifican:
- `append()` persiste eventos con todos los campos correctos (aggregate_id, event_type, correlation_id, etc.)
- `loadStream()` retorna eventos ordenados por `event_version` ASC
- `append()` lanza `ConcurrencyException` cuando `expectedVersion` no coincide con la versión actual
- `loadAllFromSequence()` retorna solo eventos desde el sequence_number indicado
- Dos inserciones consecutivas para el mismo agregado incrementan correctamente el `event_version`

Usar DAMA Doctrine Test Bundle para rollback automático entre tests.

**`tests/Unit/Domain/Order/OrderAggregateTest.php`**

Tests que verifican:
- `Order::create()` genera exactamente un `OrderCreated` en `uncommittedEvents`
- `Order::reconstitute()` reconstruye el estado correctamente desde un stream de eventos
- `cancel()` lanza `InvalidStateTransitionException` si el pedido ya está cancelado
- `markPaymentReceived()` lanza excepción si el status no es `PendingPayment`
- El `version` se incrementa correctamente con cada evento aplicado
- `Order::reconstituteFromSnapshot()` produce el mismo estado que `Order::reconstitute()` con todos los eventos

**`tests/Unit/Infrastructure/Projection/OrderSummaryProjectorTest.php`**

Tests que verifican:
- `handle(OrderCreated)` inserta una fila en `order_ctx.order_projections`
- `handle(OrderCancelled)` actualiza el status a `cancelled`
- `reset()` vacía la tabla `order_ctx.order_projections`
- Procesar el mismo evento dos veces no duplica la fila (idempotencia del projector)

**`tests/Unit/Infrastructure/Messaging/IdempotentMessageHandlerTest.php`**

Tests que verifican:
- El mismo mensaje procesado dos veces solo ejecuta `handle()` una vez
- El segundo procesamiento loguea "Duplicate message ignored" y retorna sin efectos
- Un mensaje nuevo se procesa y se registra en `processed_messages`

### 8.3 — Tests de integración del flujo completo

**`tests/Integration/FullOrderFlowTest.php`**

Test que verifica el flujo completo:
1. Crear un pedido vía `CreateOrderHandler`
2. Verificar que aparece en `order_ctx.event_store` con `event_type = 'OrderCreated'`
3. Verificar que aparece en `order_ctx.outbox` con `published_at IS NULL`
4. Verificar que aparece en `order_ctx.order_projections` con `status = 'draft'`
5. Ejecutar el outbox relay
6. Verificar que `published_at` ya no es NULL en el outbox
7. Simular que el Payment handler procesa el mensaje
8. Verificar que aparece en `payment_ctx.payments`
9. Verificar que el Order Context actualiza el status a `pending_stock`
10. Verificar que el Audit Context registra todos los eventos con el mismo `correlation_id`

**`tests/Integration/DeadLetterFlowTest.php`**

Test que verifica:
1. Configurar un flag `PERM_FAILURE` para un `correlationId`
2. Crear un pedido con ese `correlationId`
3. Consumir el mensaje (debe fallar)
4. Verificar que tras 3 reintentos el mensaje aparece en `shared.dead_letter_store`
5. Ejecutar `dead-letter:retry <message-id>`
6. Verificar que el contador de `attempts` se incrementa

**`tests/Integration/IdempotencyTest.php`**

Test que verifica:
1. Enviar el mismo `OrderCreatedIntegration` al Payment handler dos veces
2. Verificar que solo existe un registro en `payment_ctx.payments`
3. Verificar que solo existe un registro en `payment_ctx.processed_messages`
4. Verificar que el segundo procesamiento no lanza excepción

### 8.4 — Documentación del laboratorio

Crear **`docs/event-sourcing-lab.md`** con las siguientes secciones:

```markdown
# Event Sourcing Lab — Guía de Referencia

## Bounded Contexts

| Contexto | Schema | Responsabilidad |
|----------|--------|-----------------|
| Order | order_ctx | Ciclo de vida de pedidos (Event Sourcing) |
| Payment | payment_ctx | Procesamiento de pagos |
| Stock | stock_ctx | Reservas de inventario |
| Notification | notification_ctx | Envío de notificaciones |
| Audit | audit_ctx | Registro de auditoría |

## Tablas por contexto

[Listar todas las tablas con sus campos principales]

## Catálogo de eventos

### Eventos de dominio (internos al Order Context)
- OrderCreated, OrderItemAdded, OrderPaymentReceived, OrderStockReserved, OrderConfirmed, OrderCancelled

### Integration Events (publicados vía RabbitMQ)
[Tabla con evento, origen, consumidores, payload]

## Comandos de consola disponibles

| Comando | Descripción | Ejemplo |
|---------|-------------|---------|
| seed:orders | Genera pedidos en volumen | php bin/console seed:orders --count=100 --error-rate=10 |
| outbox:relay | Publica mensajes del outbox | php bin/console outbox:relay --once |
| projection:rebuild | Reconstruye proyecciones | php bin/console projection:rebuild |
| dead-letter:list | Lista mensajes fallidos | php bin/console dead-letter:list |
| dead-letter:show | Detalle de un mensaje | php bin/console dead-letter:show <uuid> |
| dead-letter:retry | Reintenta un mensaje | php bin/console dead-letter:retry <uuid> |
| dead-letter:retry-all | Reintenta todos | php bin/console dead-letter:retry-all |

## Flujos de eventos

### Flujo exitoso
OrderCreated → [outbox relay] → OrderCreatedIntegration → PaymentSucceeded → StockReserved → NotificationSent

### Flujo con fallo de pago
OrderCreated → [outbox relay] → OrderCreatedIntegration → PaymentFailed → OrderCancelled → NotificationSent

## Patrones de comunicación implementados

1. **Síncrono**: Payment Context llama directamente a OrderService.markPaymentReceived()
2. **Async Integration Events**: Order → Payment, Stock, Notification, Audit vía RabbitMQ fanout
3. **Async Commands**: Order → Stock vía comando asíncrono (stock_commands transport)
4. **Internal vs Integration Events**: OrderCreated (interno) vs OrderCreatedIntegration (externo)
5. **Shared DB**: Order Context y sus proyecciones comparten schema order_ctx

## Cómo generar errores

### Error temporal (falla N veces, luego éxito)
php bin/console seed:orders --count=1 --error-rate=100
# El seeder configura TEMP_FAILURE automáticamente

### Error permanente (va a dead letter)
# Configurar manualmente en Redis:
# SET es_lab:error_flag:<correlation_id> '{"type":"PERM_FAILURE","config":{},"correlation_id":"<uuid>"}'

### Slow consumer
# SET es_lab:error_flag:<correlation_id> '{"type":"SLOW_CONSUMER","config":{"delay_ms":3000},"correlation_id":"<uuid>"}'

## Cómo ver dead letters

php bin/console dead-letter:list
php bin/console dead-letter:show <message-id>

## Cómo reprocesar dead letters

php bin/console dead-letter:retry <message-id>
php bin/console dead-letter:retry-all

## Cómo reconstruir proyecciones

# Rebuild completo
php bin/console projection:rebuild

# Rebuild desde secuencia específica
php bin/console projection:rebuild --from-sequence=1000

## Cómo buscar por correlation_id

# En logs (formato JSON)
cat var/log/dev.log | python3 -c "import sys,json; [print(l) for l in sys.stdin if json.loads(l).get('extra',{}).get('correlation_id')=='<uuid>']"

# En base de datos
SELECT * FROM order_ctx.event_store WHERE correlation_id = '<uuid>';
SELECT * FROM audit_ctx.audit_log WHERE correlation_id = '<uuid>' ORDER BY occurred_at;
SELECT * FROM shared.dead_letter_store WHERE correlation_id = '<uuid>';

## Cómo generar volumen de datos

php bin/console seed:orders --count=1000
php bin/console seed:orders --count=2000 --error-rate=10
php bin/console seed:orders --count=5000 --with-duplicates

## 12 Escenarios de práctica para entrevistas

### Escenario 1: Crear una orden y ver todos sus eventos
```bash
# Crear pedido
curl -X POST http://localhost:8080/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"customer_id":"uuid","items":[{"sku":"ABC","name":"Product","quantity":2,"unit_price":10.00}]}'

# Ver eventos en el Event Store
SELECT * FROM order_ctx.event_store WHERE aggregate_id = '<order_id>' ORDER BY event_version;
```

### Escenario 2: Reconstruir estado desde el Event Store
```bash
# Ver todos los eventos del agregado
SELECT event_type, event_version, payload FROM order_ctx.event_store 
WHERE aggregate_id = '<order_id>' ORDER BY event_version;

# El estado se reconstruye aplicando cada evento en orden
```

### Escenario 3: Ver cómo se actualiza una proyección
```bash
# Antes de crear el pedido
SELECT * FROM order_ctx.order_projections WHERE order_id = '<order_id>';  -- vacío

# Crear pedido y ejecutar outbox relay
php bin/console outbox:relay --once

# Después
SELECT * FROM order_ctx.order_projections WHERE order_id = '<order_id>';  -- tiene datos
```

### Escenario 4: Comunicación síncrona entre contextos
```
Ver HandleOrderCreatedHandler.php → llama a OrderService::markPaymentReceived()
Esto es un service call directo dentro del mismo proceso PHP.
Ventaja: consistencia inmediata. Desventaja: acoplamiento temporal.
```

### Escenario 5: Comunicación por eventos entre contextos
```
Ver el flujo: Order → outbox → RabbitMQ → Payment/Stock/Notification/Audit
Cada contexto tiene su propia cola y procesa de forma independiente.
```

### Escenario 6: Comando asíncrono entre contextos
```
Ver stock_commands transport en messenger.yaml
Un comando tiene un destinatario específico (Stock), a diferencia de un evento que es broadcast.
```

### Escenario 7: Error temporal y retries
```bash
php bin/console seed:orders --count=1 --error-rate=100
php bin/console messenger:consume order_events --limit=5 -vv
# Ver los reintentos con backoff exponencial en los logs
```

### Escenario 8: Error permanente y Dead Letter
```bash
php bin/console seed:orders --count=1 --error-rate=100
php bin/console messenger:consume order_events --limit=10
php bin/console dead-letter:list
```

### Escenario 9: Reprocesar un Dead Letter
```bash
php bin/console dead-letter:list
php bin/console dead-letter:retry <message-id>
php bin/console messenger:consume order_events --limit=5
```

### Escenario 10: Idempotencia con mensajes duplicados
```bash
php bin/console seed:orders --count=10 --with-duplicates
# Ver que processed_messages tiene una sola entrada por (message_id, consumer_name)
SELECT * FROM payment_ctx.processed_messages;
```

### Escenario 11: Volumen y búsqueda por correlation_id
```bash
php bin/console seed:orders --count=1000 --error-rate=5
# Tomar un correlation_id de la salida del comando
# Buscar en todos los sistemas
SELECT * FROM order_ctx.event_store WHERE correlation_id = '<uuid>';
SELECT * FROM audit_ctx.audit_log WHERE correlation_id = '<uuid>';
SELECT * FROM shared.dead_letter_store WHERE correlation_id = '<uuid>';
```

### Escenario 12: Comparar bases compartidas vs separadas
```
Shared DB (mismo schema): order_ctx.event_store y order_ctx.order_projections
  → Transacciones ACID, sin latencia, acoplamiento alto

Schemas separados (payment_ctx, stock_ctx, etc.):
  → Aislamiento lógico, comunicación vía mensajería, eventual consistency

Bases de datos separadas (no implementado, solo documentado):
  → Máximo aislamiento, sin transacciones distribuidas, complejidad operacional alta
```

## Conceptos clave para explicar en entrevistas

- **Event Sourcing**: el estado se deriva de eventos, no se almacena directamente
- **CQRS**: separación entre modelo de escritura (agregado) y lectura (proyección)
- **Outbox Pattern**: garantía de publicación atómica sin two-phase commit
- **Idempotencia**: el mismo mensaje procesado N veces produce el mismo resultado
- **Correlation ID**: trazabilidad de un flujo completo a través de múltiples sistemas
- **Causation ID**: causalidad directa entre eventos (quién causó a quién)
- **Dead Letter**: mensajes que no pudieron procesarse tras agotar reintentos
- **Event Upcasting**: evolución de esquemas sin modificar eventos históricos
- **Snapshot**: optimización de reconstrucción de agregados con muchos eventos
- **Bounded Context**: límite lógico con su propio modelo y lenguaje
```

## Verificación final

Al terminar esta fase:

1. **API funciona**:
   ```bash
   curl -X POST http://localhost:8080/api/v1/orders \
     -H "Content-Type: application/json" \
     -d '{"customer_id":"test-uuid","items":[{"sku":"ABC","name":"Test","quantity":1,"unit_price":10}]}'
   # Debe retornar 202 con order_id y correlation_id

   curl http://localhost:8080/api/v1/orders
   # Debe retornar lista paginada
   ```

2. **Tests pasan**:
   ```bash
   php bin/phpunit tests/Unit/
   php bin/phpunit tests/Integration/
   ```

3. **Documentación existe**: `docs/event-sourcing-lab.md` con los 12 escenarios

4. **Flujo completo de extremo a extremo**:
   ```bash
   # 1. Crear pedido
   php bin/console seed:orders --count=5

   # 2. Publicar al broker
   php bin/console outbox:relay --once

   # 3. Consumir mensajes
   php bin/console messenger:consume order_events --limit=20

   # 4. Ver resultados
   SELECT COUNT(*) FROM order_ctx.event_store;
   SELECT COUNT(*) FROM order_ctx.order_projections;
   SELECT COUNT(*) FROM audit_ctx.audit_log;
   SELECT COUNT(*) FROM payment_ctx.payments;
   ```
