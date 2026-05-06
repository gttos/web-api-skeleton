# Plan de Implementación: Event Sourcing Lab

## Resumen

Implementación incremental del laboratorio de Event Sourcing sobre el microservicio Symfony 6.4 existente. Las tareas están organizadas en 15 fases que construyen progresivamente desde la infraestructura base hasta la documentación final. Cada fase produce código funcional e integrado; no hay código huérfano.

## Tareas

- [ ] 1. Infraestructura y Fundamentos
  - [ ] 1.1 Actualizar `docker-compose.yml` para incluir RabbitMQ con management plugin en el entorno de desarrollo
    - Añadir servicio `rabbitmq` con imagen `rabbitmq:3.11-management-alpine` y variables de entorno
    - Exponer puertos 5672 (AMQP) y 15672 (management UI)
    - Actualizar `.env` con `MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f`
    - Añadir variable `REDIS_URL` si no existe para el sistema de inyección de errores
    - _Requisitos: 15.1, 15.4_

  - [ ] 1.2 Crear migración de base de datos para todos los schemas PostgreSQL
    - Crear archivo de migración Doctrine que ejecute `CREATE SCHEMA IF NOT EXISTS` para: `order_ctx`, `payment_ctx`, `stock_ctx`, `notification_ctx`, `audit_ctx`, `shared`
    - Habilitar extensión `uuid-ossp` con `CREATE EXTENSION IF NOT EXISTS "uuid-ossp"`
    - Crear todas las tablas definidas en el diseño: `order_ctx.event_store`, `order_ctx.snapshots`, `order_ctx.outbox`, `order_ctx.order_projections`
    - Crear tablas de contextos consumidores: `payment_ctx.payments`, `payment_ctx.processed_messages`, `stock_ctx.stock_reservations`, `stock_ctx.processed_messages`
    - Crear tablas de notificación y auditoría: `notification_ctx.notification_log`, `notification_ctx.processed_messages`, `audit_ctx.audit_log`, `audit_ctx.processed_messages`
    - Crear tabla compartida: `shared.dead_letter_store`
    - Incluir todos los índices definidos en el diseño
    - _Requisitos: 1.1, 1.2, 15.3_

  - [ ] 1.3 Actualizar `config/packages/messenger.yaml` con los transportes del laboratorio
    - Añadir transport `order_events` con exchange fanout y colas para cada consumer (`payment_consumer`, `stock_consumer`, `notification_consumer`, `audit_consumer`)
    - Añadir transport `stock_commands` para comandos asíncronos al Stock Context
    - Configurar política de reintentos: `max_retries: 3`, delay exponencial (1s, 3s, 9s), `max_delay: 30000`
    - Mantener `failed: doctrine://default?queue_name=failed` como failure transport
    - _Requisitos: 15.4, 5.2, 6.1_

  - [ ] 1.4 Actualizar `config/services.yaml` para registrar los nuevos servicios del laboratorio
    - Añadir recursos de autowiring para `App\Application\Order\`, `App\Application\Payment\`, `App\Application\Stock\`, `App\Application\Notification\`, `App\Application\Audit\`
    - Registrar handlers de comandos de cada contexto con el tag `messenger.message_handler` y su bus correspondiente
    - Registrar consumers de integration events con el tag `messenger.message_handler` y bus `event.bus`
    - Añadir alias de interfaces a implementaciones concretas (EventStoreInterface, OutboxStoreInterface, etc.)
    - _Requisitos: 15.2, 15.5_

  - [ ] 1.5 Crear las clases base e interfaces del dominio compartido
    - Crear `src/Infrastructure/EventStore/EventStoreInterface.php` con métodos `append()`, `loadStream()`, `loadAllFromSequence()`
    - Crear `src/Infrastructure/EventStore/SnapshotStoreInterface.php` con métodos `load()`, `save()`
    - Crear `src/Infrastructure/Outbox/OutboxStoreInterface.php` con métodos `store()`, `fetchPending()`, `markPublished()`
    - Crear `src/Infrastructure/DeadLetter/DeadLetterStoreInterface.php` con métodos `store()`, `findAll()`, `findById()`, `remove()`, `incrementAttempts()`
    - Crear `src/Infrastructure/Messaging/CorrelationContext.php` con métodos `initiate()` y `causedBy()`
    - Crear `src/Domain/Order/Services/OrderRepositoryInterface.php`
    - _Requisitos: 15.5_


- [ ] 2. Event Store
  - [ ] 2.1 Implementar `DbalEventStore` con control de concurrencia optimista
    - Crear `src/Infrastructure/EventStore/DbalEventStore.php` implementando `EventStoreInterface`
    - Implementar `append()`: insertar eventos en `order_ctx.event_store` usando DBAL (no ORM), verificar `expected_version` con `SELECT MAX(event_version)` dentro de la misma transacción
    - Lanzar `ConcurrencyException` si la versión actual no coincide con `expected_version`
    - Implementar `loadStream()`: SELECT ordenado por `event_version ASC` con filtro por `aggregate_id` y `from_version`
    - Implementar `loadAllFromSequence()`: SELECT ordenado por `sequence_number ASC` desde un número de secuencia dado
    - Crear `src/Infrastructure/EventStore/StoredEvent.php` como value object con todos los campos de la tabla
    - Crear `src/Infrastructure/EventStore/EventStream.php` como iterable de `StoredEvent`
    - Crear `src/Domain/Exceptions/ConcurrencyException.php`
    - _Requisitos: 1.1, 1.2, 1.3, 1.4, 1.5_

  - [ ]* 2.2 Escribir test de propiedad: Round-trip de persistencia del Event Store
    - Crear `tests/Property/EventStoreRoundTripPropertyTest.php`
    - Crear `tests/Support/Generators/OrderEventGenerator.php` usando Faker
    - Crear `tests/Support/Behaviours/PropertyBasedTesting.php` con el trait `forAll()` (100 iteraciones mínimo)
    - **Propiedad 1: Round-trip de persistencia del Event Store**
    - **Valida: Requisitos 1.1, 1.5**

  - [ ]* 2.3 Escribir test de propiedad: Secuencia global estrictamente creciente
    - Añadir test en `tests/Property/EventStoreRoundTripPropertyTest.php`
    - Verificar que los `sequence_number` asignados son estrictamente crecientes para N eventos insertados
    - **Propiedad 2: Secuencia global estrictamente creciente**
    - **Valida: Requisitos 1.2**

  - [ ]* 2.4 Escribir test de propiedad: Control de concurrencia optimista
    - Crear `tests/Property/ConcurrencyControlPropertyTest.php`
    - Simular dos escrituras concurrentes con el mismo `expected_version` y verificar que exactamente una tiene éxito
    - **Propiedad 3: Control de concurrencia optimista**
    - **Valida: Requisitos 1.3, 6.5**

  - [ ]* 2.5 Escribir test de propiedad: Ordenamiento de eventos por versión
    - Añadir test en `tests/Property/EventStoreRoundTripPropertyTest.php`
    - Verificar que `loadStream()` retorna eventos con `event_version` estrictamente ascendente
    - **Propiedad 4: Ordenamiento de eventos por versión**
    - **Valida: Requisitos 1.4**

  - [ ] 2.6 Implementar `SnapshotStore`
    - Crear `src/Infrastructure/EventStore/SnapshotStore.php` implementando `SnapshotStoreInterface`
    - Implementar `load()`: SELECT por `aggregate_id` en `order_ctx.snapshots`, retornar `null` si no existe
    - Implementar `save()`: UPSERT en `order_ctx.snapshots` con el estado serializado como JSONB
    - Crear `src/Infrastructure/EventStore/Snapshot.php` como value object
    - _Requisitos: 2.2, 2.3_

- [ ] 3. Checkpoint — Verificar Event Store
  - Ejecutar `php bin/console doctrine:migrations:migrate` y confirmar que todos los schemas y tablas se crean correctamente.
  - Ejecutar los tests de propiedad del Event Store y verificar que pasan. Consultar al usuario si surgen dudas.

- [ ] 4. Agregado Order y Dominio
  - [ ] 4.1 Implementar el enum `OrderStatus` y el value object `OrderItem`
    - Crear `src/Domain/Order/Model/OrderStatus.php` como PHP 8.1 enum con casos: `Draft`, `PendingPayment`, `PendingStock`, `Confirmed`, `Cancelled`
    - Crear `src/Domain/Order/Model/OrderItem.php` como value object con `sku`, `name`, `qty`, `price` y método `lineTotal()`
    - _Requisitos: 2.1_

  - [ ] 4.2 Implementar los eventos de dominio del Order Context
    - Crear `src/Domain/Order/Events/OrderCreated.php` con payload: `order_id`, `customer_id`, `items`, `total`, `currency`
    - Crear `src/Domain/Order/Events/OrderItemAdded.php` con payload: `order_id`, `item`, `new_total`
    - Crear `src/Domain/Order/Events/OrderConfirmed.php` con payload: `order_id`, `confirmed_at`
    - Crear `src/Domain/Order/Events/OrderCancelled.php` con payload: `order_id`, `reason`, `cancelled_at`
    - Crear `src/Domain/Order/Events/OrderPaymentReceived.php` con payload: `order_id`, `payment_id`, `amount`
    - Crear `src/Domain/Order/Events/OrderStockReserved.php` con payload: `order_id`, `reservation_id`
    - _Requisitos: 2.1, 4.1_

  - [ ] 4.3 Implementar el agregado `Order` con reconstitución desde eventos
    - Crear `src/Domain/Order/Model/Order.php` con constructor privado
    - Implementar factory `Order::create()` que aplica `OrderCreated`
    - Implementar métodos de negocio: `addItem()`, `markPaymentReceived()`, `markStockReserved()`, `cancel()`
    - Implementar método privado `apply()` que aplica el evento al estado y lo añade a `$uncommittedEvents`
    - Implementar `applyEvent()` con `match` para cada tipo de evento (actualiza estado interno + incrementa `$version`)
    - Implementar `Order::reconstitute(EventStream $stream)` estático
    - Implementar `Order::reconstituteFromSnapshot(Snapshot $snapshot, EventStream $remainingEvents)` estático
    - Implementar guards: `guardNotCancelled()`, `guardStatus()`, `guardNotTerminal()`
    - Crear `src/Domain/Exceptions/InvalidStateTransitionException.php`
    - _Requisitos: 2.1, 2.2, 2.3_

  - [ ]* 4.4 Escribir tests unitarios del agregado Order
    - Crear `tests/Unit/Domain/Order/OrderAggregateTest.php`
    - Testear creación, adición de items, transiciones de estado válidas e inválidas
    - Testear que `uncommittedEvents` contiene los eventos correctos tras cada operación
    - _Requisitos: 2.1, 13.1_

  - [ ]* 4.5 Escribir test de propiedad: Equivalencia de reconstrucción (Snapshot round-trip)
    - Crear `tests/Property/SnapshotEquivalencePropertyTest.php`
    - Crear `tests/Support/Generators/OrderEventSequenceGenerator.php`
    - Generar secuencias aleatorias de eventos válidos, tomar snapshot en punto aleatorio, verificar equivalencia de estado
    - **Propiedad 5: Equivalencia de reconstrucción (Snapshot round-trip)**
    - **Valida: Requisitos 2.1, 2.3, 2.4**

  - [ ] 4.6 Implementar `DbalOrderRepository`
    - Crear `src/Infrastructure/Persistence/Order/DbalOrderRepository.php` implementando `OrderRepositoryInterface`
    - Implementar `findById()`: cargar snapshot (si existe) + eventos posteriores, reconstituir agregado
    - Implementar `save()`: llamar a `EventStore::append()` con los `uncommittedEvents` del agregado
    - Registrar alias en `config/services.yaml`
    - _Requisitos: 2.1, 2.2, 2.3_

  - [ ] 4.7 Implementar los comandos y handlers del Order Context
    - Crear `src/Domain/Order/Commands/CreateOrder.php` con `order_id`, `customer_id`, `items`, `correlation_id`
    - Crear `src/Domain/Order/Commands/ConfirmOrder.php` con `order_id`, `correlation_id`
    - Crear `src/Application/Order/CommandHandlers/CreateOrderHandler.php`: instanciar `Order::create()`, guardar con repositorio, almacenar en outbox (dentro de transacción)
    - Crear `src/Application/Order/CommandHandlers/ConfirmOrderHandler.php`
    - _Requisitos: 4.1, 11.1_


- [ ] 5. Outbox Pattern
  - [ ] 5.1 Implementar `OutboxStore` y `OutboxMessage`
    - Crear `src/Infrastructure/Outbox/OutboxMessage.php` como value object con: `id` (UUID), `aggregate_id`, `event_type`, `payload`, `metadata`, `correlation_id`, `causation_id`
    - Crear `src/Infrastructure/Outbox/OutboxStore.php` implementando `OutboxStoreInterface`
    - Implementar `store()`: INSERT en `order_ctx.outbox` usando DBAL
    - Implementar `fetchPending()`: SELECT WHERE `published_at IS NULL` ORDER BY `created_at` con LIMIT
    - Implementar `markPublished()`: UPDATE `published_at = NOW()` por `id`
    - _Requisitos: 11.1, 11.2, 11.3_

  - [ ] 5.2 Integrar el Outbox en el `CreateOrderHandler` dentro de la misma transacción
    - Modificar `CreateOrderHandler` para envolver la persistencia de eventos y el `outboxStore->store()` en `$connection->transactional()`
    - Construir el payload del `OrderCreatedIntegration` event a partir del agregado Order
    - Verificar que si la transacción falla, ningún registro persiste (ni en event_store ni en outbox)
    - _Requisitos: 11.1, 12.1_

  - [ ] 5.3 Implementar `OutboxRelay` y el comando `outbox:relay`
    - Crear `src/Infrastructure/Outbox/OutboxRelay.php` con método `execute(int $batchSize = 100): int`
    - El relay lee mensajes pendientes, los despacha al bus de mensajería y marca como publicados
    - Manejar excepciones del broker: log warning y continuar (el mensaje se reintentará en el siguiente ciclo)
    - Crear `src/Delivery/Console/OutboxRelayCommand.php` que invoca `OutboxRelay::execute()` en bucle con `--once` flag opcional
    - _Requisitos: 11.2, 11.4_

  - [ ]* 5.4 Escribir test de propiedad: Atomicidad del Outbox Pattern
    - Crear `tests/Property/OutboxAtomicityPropertyTest.php`
    - Verificar que si la transacción falla, ni eventos ni entradas de outbox persisten
    - Verificar que para cada evento en el Event Store existe una entrada correspondiente en outbox
    - **Propiedad 12: Atomicidad del Outbox Pattern**
    - **Propiedad 13: Consistencia eventual del Outbox**
    - **Valida: Requisitos 11.1, 11.3, 11.5**

- [ ] 6. Proyecciones
  - [ ] 6.1 Implementar `ProjectionEngine` y la interfaz `ProjectorInterface`
    - Crear `src/Infrastructure/Projection/ProjectorInterface.php` con métodos `handle()`, `reset()`, `supportedEvents()`
    - Crear `src/Infrastructure/Projection/ProjectionEngine.php` con métodos `projectEvent()` y `rebuild()`
    - `rebuild()` debe: llamar `reset()` en el projector, leer todos los eventos desde `loadAllFromSequence()`, aplicar upcasting, invocar `handle()` por cada evento
    - Reportar progreso cada 1000 eventos mediante logger
    - _Requisitos: 3.1, 3.3, 3.4_

  - [ ] 6.2 Implementar `OrderSummaryProjector`
    - Crear `src/Infrastructure/Projection/OrderSummaryProjector.php` implementando `ProjectorInterface`
    - Implementar `supportedEvents()` retornando los 5 eventos de dominio del Order Context
    - Implementar `handle()` con `match` para cada tipo de evento: UPSERT en `order_ctx.order_projections`
    - Implementar `reset()`: `TRUNCATE order_ctx.order_projections`
    - _Requisitos: 3.2_

  - [ ] 6.3 Crear el comando `projection:rebuild`
    - Crear `src/Delivery/Console/ProjectionRebuildCommand.php`
    - Aceptar opción `--from-sequence=N` para rebuild parcial
    - Invocar `ProjectionEngine::rebuild()` con el parámetro correspondiente
    - _Requisitos: 3.3, 3.4_

  - [ ]* 6.4 Escribir test de propiedad: Idempotencia del Replay de proyecciones
    - Crear `tests/Property/ProjectionReplayIdempotencePropertyTest.php`
    - Generar secuencias aleatorias de eventos, procesar incrementalmente, luego hacer rebuild y comparar estado final
    - **Propiedad 6: Idempotencia del Replay de proyecciones**
    - **Valida: Requisitos 3.5**

- [ ] 7. Checkpoint — Verificar Event Store, Agregado y Proyecciones
  - Ejecutar todos los tests hasta este punto y verificar que pasan.
  - Verificar manualmente que `CreateOrder` persiste eventos en `order_ctx.event_store` y entradas en `order_ctx.outbox`.
  - Consultar al usuario si surgen dudas.

- [ ] 8. Eventos de Integración y Consumers
  - [ ] 8.1 Crear la clase base `IntegrationEvent` y todos los integration events
    - Crear `src/Domain/IntegrationEvent.php` como clase abstracta con: `messageId`, `correlationId`, `causationId`, `occurredAt`, `sourceContext`
    - Crear integration events del Order Context: `src/Domain/Order/Events/OrderCreatedIntegration.php`
    - Crear integration events del Payment Context: `src/Domain/Payment/Events/PaymentRequested.php`, `PaymentSucceeded.php`, `PaymentFailed.php`
    - Crear integration events del Stock Context: `src/Domain/Stock/Events/StockReservationRequested.php`, `StockReserved.php`, `StockReservationFailed.php`
    - Crear integration events del Notification Context: `src/Domain/Notification/Events/NotificationRequested.php`, `NotificationSent.php`, `NotificationFailed.php`
    - _Requisitos: 4.1, 4.2, 4.3, 4.4, 5.4_

  - [ ] 8.2 Implementar el handler del Payment Context (Patrón 1: Service Call Síncrono + Patrón 2: Integration Events)
    - Crear `src/Application/Payment/CommandHandlers/HandleOrderCreatedHandler.php` extendiendo `IdempotentMessageHandler`
    - Al recibir `OrderCreatedIntegration`: insertar en `payment_ctx.payments` con status `pending`, emitir `PaymentRequested`
    - Simular procesamiento de pago: emitir `PaymentSucceeded` o `PaymentFailed` según flags de error
    - Para `PaymentSucceeded`: invocar directamente `OrderServiceInterface::markPaymentReceived()` (Patrón 1 síncrono)
    - Emitir el integration event correspondiente al bus para que llegue a otros contextos
    - _Requisitos: 4.2, 5.1, 5.2_

  - [ ] 8.3 Implementar el handler del Stock Context
    - Crear `src/Application/Stock/CommandHandlers/HandlePaymentSucceededHandler.php` extendiendo `IdempotentMessageHandler`
    - Al recibir `PaymentSucceeded`: insertar en `stock_ctx.stock_reservations` con status `pending`, emitir `StockReservationRequested`
    - Simular reserva de stock: emitir `StockReserved` o `StockReservationFailed` según flags de error
    - Para `StockReserved`: emitir integration event para que Order Context actualice el agregado
    - _Requisitos: 4.3, 5.2, 5.3_

  - [ ] 8.4 Implementar el handler del Notification Context
    - Crear `src/Application/Notification/CommandHandlers/HandleNotificationTriggerHandler.php` extendiendo `IdempotentMessageHandler`
    - Suscribirse a: `StockReserved`, `PaymentFailed`, `StockReservationFailed`
    - Al recibir cualquiera de estos eventos: insertar en `notification_ctx.notification_log`, emitir `NotificationRequested` y luego `NotificationSent`
    - _Requisitos: 4.4_

  - [ ] 8.5 Implementar el handler del Audit Context
    - Crear `src/Application/Audit/CommandHandlers/AuditEventHandler.php` extendiendo `IdempotentMessageHandler`
    - Suscribirse a todos los integration events del sistema
    - Al recibir cualquier integration event: insertar en `audit_ctx.audit_log` con todos los campos requeridos
    - _Requisitos: 4.5, 4.6_

  - [ ] 8.6 Actualizar el routing de Messenger para los integration events
    - Añadir routing en `config/packages/messenger.yaml` para cada integration event hacia el transport `order_events`
    - Configurar qué handlers consumen de qué colas
    - _Requisitos: 5.2, 15.4_


- [ ] 9. Idempotencia
  - [ ] 9.1 Implementar `ProcessedMessageStore` e `IdempotentMessageHandler`
    - Crear `src/Infrastructure/Messaging/ProcessedMessageStore.php` con métodos `wasProcessed(string $messageId, string $consumerName): bool` y `markProcessed(string $messageId, string $consumerName, string $correlationId): void`
    - Implementar usando DBAL: INSERT en la tabla `processed_messages` del schema correspondiente al consumer
    - Crear `src/Infrastructure/Messaging/IdempotentMessageHandler.php` como clase abstracta
    - Implementar `__invoke(Envelope $envelope)`: verificar duplicado → si ya procesado, log info y return; si nuevo, ejecutar `handle()` + `markProcessed()` dentro de transacción
    - Definir métodos abstractos `handle(Envelope $envelope): void` y `consumerName(): string`
    - _Requisitos: 8.1, 8.2, 8.3, 8.4_

  - [ ]* 9.2 Escribir test de propiedad: Idempotencia de consumers
    - Crear `tests/Property/ConsumerIdempotencyPropertyTest.php`
    - Enviar el mismo mensaje N veces (N aleatorio entre 2 y 10) y verificar que el estado final es idéntico al de una única entrega
    - Verificar que solo existe un registro en `processed_messages` por `(message_id, consumer_name)`
    - **Propiedad 9: Idempotencia de consumers**
    - **Valida: Requisitos 8.2, 8.3, 8.4, 8.5**

  - [ ]* 9.3 Escribir test de propiedad: Rechazo de payloads inválidos al Dead Letter Store
    - Añadir test en `tests/Property/ConsumerIdempotencyPropertyTest.php`
    - Crear `tests/Support/Generators/InvalidPayloadGenerator.php`
    - Verificar que mensajes con payload inválido son rechazados y almacenados en `shared.dead_letter_store`
    - **Propiedad 10: Rechazo de payloads inválidos al Dead Letter Store**
    - **Valida: Requisitos 6.3**

- [ ] 10. Dead Letters
  - [ ] 10.1 Implementar `DeadLetterStore` y `DeadLetterEntry`
    - Crear `src/Infrastructure/DeadLetter/DeadLetterEntry.php` como value object con todos los campos de `shared.dead_letter_store`
    - Crear `src/Infrastructure/DeadLetter/DeadLetterStore.php` implementando `DeadLetterStoreInterface`
    - Implementar `store()`: INSERT en `shared.dead_letter_store`
    - Implementar `findAll()`: SELECT con filtros opcionales por `consumer_name` y `event_type`
    - Implementar `findById()`: SELECT por `message_id`
    - Implementar `remove()`: DELETE por `message_id`
    - Implementar `incrementAttempts()`: UPDATE `attempts = attempts + 1`, `last_retry_at = NOW()`
    - _Requisitos: 6.2, 7.1, 7.2_

  - [ ] 10.2 Integrar el `DeadLetterStore` con el failure transport de Messenger
    - Crear un event subscriber o middleware que capture mensajes del transport `failed` y los persista en `DeadLetterStore`
    - Incluir: `error_reason`, `stack_trace`, `correlation_id`, `causation_id`, `original_transport`, `attempts = 1`
    - _Requisitos: 6.2, 6.3_

  - [ ] 10.3 Crear los comandos de gestión de dead letters
    - Crear `src/Delivery/Console/DeadLetter/ListCommand.php`: muestra tabla con `message_id`, `event_type`, `consumer_name`, `error_reason`, `correlation_id`, `failed_at`; soporta filtros `--consumer` y `--event-type`
    - Crear `src/Delivery/Console/DeadLetter/ShowCommand.php`: muestra detalle completo incluyendo payload, metadata, stack trace y número de intentos
    - Crear `src/Delivery/Console/DeadLetter/RetryCommand.php`: reenvía un mensaje al transport original, llama `incrementAttempts()`, si falla nuevamente retorna al dead letter store
    - Crear `src/Delivery/Console/DeadLetter/RetryAllCommand.php`: itera todos los mensajes y llama a `RetryCommand` para cada uno
    - _Requisitos: 7.1, 7.2, 7.3, 7.4, 7.5_

  - [ ]* 10.4 Escribir test de propiedad: Incremento de intentos en dead letters re-fallidos
    - Añadir test en `tests/Integration/DeadLetterFlowTest.php`
    - Verificar que tras N reintentos fallidos, el contador de `attempts` es N+1
    - **Propiedad 11: Incremento de intentos en dead letters re-fallidos**
    - **Valida: Requisitos 7.5**

- [ ] 11. Observabilidad
  - [ ] 11.1 Implementar `CorrelationIdStamp`, `CausationIdStamp` y `CorrelationIdMiddleware`
    - Crear `src/Infrastructure/Messaging/CorrelationIdStamp.php` implementando `StampInterface` con `correlationId` y `causationId`
    - Crear `src/Infrastructure/Messaging/CausationIdStamp.php` implementando `StampInterface`
    - Crear `src/Infrastructure/Messaging/CorrelationIdMiddleware.php` implementando `MiddlewareInterface`
    - El middleware: extrae el stamp si existe, o genera uno nuevo con `Uuid::uuid4()`; inyecta los IDs en el contexto del logger mediante `pushProcessor`
    - Registrar el middleware en todos los buses en `config/packages/messenger.yaml`
    - _Requisitos: 9.3, 9.4_

  - [ ] 11.2 Implementar `StructuredLogger` y actualizar la configuración de Monolog
    - Crear `src/Infrastructure/Observability/StructuredLogger.php` que envuelve el logger de Symfony y añade automáticamente `correlation_id`, `causation_id`, `consumer_name` al contexto
    - Actualizar `config/packages/monolog.yaml` para usar formato JSON en el handler de producción
    - _Requisitos: 9.1, 9.2_

  - [ ]* 11.3 Escribir test de propiedad: Propagación de correlation_id en la cadena de eventos
    - Crear `tests/Property/CorrelationPropagationPropertyTest.php`
    - Verificar que todos los eventos derivados de un flujo contienen el mismo `correlation_id` original
    - Verificar que cada evento tiene un `causation_id` que referencia al evento/comando anterior
    - **Propiedad 7: Propagación de correlation_id en la cadena de eventos**
    - **Propiedad 16: Causalidad correcta en la cadena de eventos**
    - **Valida: Requisitos 4.6, 9.3, 9.4**

  - [ ]* 11.4 Implementar métricas Prometheus opcionales
    - Crear `src/Infrastructure/Observability/PrometheusMetrics.php` con contadores y gauges definidos en el diseño
    - Exponer endpoint `/metrics` en el controlador de entrega
    - Registrar el servicio como opcional (solo si la librería está disponible)
    - _Requisitos: 9.5_

- [ ] 12. Checkpoint — Verificar flujo completo de mensajería
  - Ejecutar todos los tests hasta este punto y verificar que pasan.
  - Verificar que el flujo completo Order → Payment → Stock → Notification → Audit funciona con el outbox relay activo.
  - Consultar al usuario si surgen dudas.


- [ ] 13. Inyección de Errores
  - [ ] 13.1 Implementar `ErrorFlagStore` con Redis
    - Crear `src/Infrastructure/ErrorInjection/ErrorFlag.php` como value object con `type`, `config` (array), `correlationId`
    - Crear `src/Infrastructure/ErrorInjection/ErrorFlagStore.php` implementando la interfaz `ErrorFlagStoreInterface`
    - Implementar usando Redis (`symfony/redis-messenger` ya disponible): `setFlag()` con TTL de 300 segundos, `getFlag()`, `clearFlag()`
    - Soportar los tipos: `TEMP_FAILURE`, `PERM_FAILURE`, `INVALID_PAYLOAD`, `SLOW_CONSUMER`, `CONCURRENCY_CONFLICT`
    - _Requisitos: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6_

  - [ ] 13.2 Implementar `ErrorInjectionMiddleware`
    - Crear `src/Infrastructure/ErrorInjection/ErrorInjectionMiddleware.php` implementando `MiddlewareInterface`
    - El middleware extrae el `correlationId` del stamp, consulta `ErrorFlagStore`, y actúa según el tipo de flag:
      - `TEMP_FAILURE`: lanzar excepción las primeras N veces (contador en Redis), luego continuar
      - `PERM_FAILURE`: lanzar `\RuntimeException('Simulated permanent failure')`
      - `INVALID_PAYLOAD`: lanzar `InvalidMessagePayloadException`
      - `SLOW_CONSUMER`: `usleep($flag->config['delay_ms'] * 1000)`
    - Registrar el middleware en los buses de consumers en `config/packages/messenger.yaml`
    - _Requisitos: 6.1, 6.2, 6.3, 6.4_

- [ ] 14. Seeding
  - [ ] 14.1 Implementar el comando `seed:orders`
    - Crear `src/Delivery/Console/SeedOrdersCommand.php`
    - Opciones: `--count=N` (default 100), `--error-rate=P` (0-100), `--with-duplicates`
    - Para cada pedido: generar datos aleatorios con Faker, crear `CorrelationContext::initiate()`, despachar `CreateOrder` command
    - Si `--error-rate > 0`: llamar `ErrorFlagStore::setFlag()` con tipo aleatorio para el `correlationId` del pedido
    - Si `--with-duplicates`: reenviar ~10% de los mensajes como duplicados al bus
    - Mostrar en output: `[N/total] Order {id} created (correlation: {uuid})`
    - _Requisitos: 10.1, 10.2, 10.3, 10.4, 10.5_

  - [ ]* 14.2 Escribir test de propiedad: Distribución correcta del seeder
    - Crear `tests/Property/SeederDistributionPropertyTest.php`
    - Verificar que con `--count=N` se crean exactamente N pedidos
    - Verificar que con `--error-rate=P` la proporción de errores está dentro de ±10 puntos porcentuales para N ≥ 50
    - **Propiedad 18: Generación de volumen con distribución correcta**
    - **Valida: Requisitos 10.1, 10.2**

- [ ] 15. Event Upcasting
  - [ ] 15.1 Implementar `UpcasterInterface`, `UpcasterChain` y el primer upcaster de ejemplo
    - Crear `src/Infrastructure/EventStore/Upcasting/UpcasterInterface.php` con métodos `canUpcast()`, `upcast()`, `targetVersion()`
    - Crear `src/Infrastructure/EventStore/Upcasting/UpcasterChain.php` que itera upcasters ordenados y aplica los aplicables
    - Crear `src/Infrastructure/EventStore/Upcasting/Upcasters/OrderCreatedV1ToV2Upcaster.php` como ejemplo concreto (añade campo `currency` con valor por defecto `EUR`)
    - Integrar `UpcasterChain` en `DbalEventStore::loadStream()` y `loadAllFromSequence()` para aplicar upcasting en tiempo de lectura
    - Verificar que el evento original en la base de datos NO se modifica
    - _Requisitos: 12.1, 12.2, 12.3, 12.4_

  - [ ]* 15.2 Escribir test de propiedad: Compatibilidad hacia adelante del Upcasting
    - Crear `tests/Property/UpcastingCompatibilityPropertyTest.php`
    - Verificar que eventos con versión anterior producen payload compatible con la versión actual tras upcasting
    - Verificar que el registro original en la base de datos no se modifica tras múltiples lecturas
    - **Propiedad 14: Compatibilidad hacia adelante del Upcasting**
    - **Propiedad 15: Inmutabilidad del Event Store ante upcasting**
    - **Valida: Requisitos 12.4, 12.5**

- [ ] 16. API REST
  - [ ] 16.1 Crear los endpoints de gestión de pedidos
    - Crear `src/Delivery/Api/V1/Orders/CreateOrderController.php`: POST `/api/v1/orders` → despacha `CreateOrder` command, retorna 202 con `order_id` y `correlation_id`
    - Crear `src/Delivery/Api/V1/Orders/GetOrderController.php`: GET `/api/v1/orders/{orderId}` → consulta `order_ctx.order_projections`, retorna 200 con el read model o 404
    - Crear `src/Delivery/Api/V1/Orders/ListOrdersController.php`: GET `/api/v1/orders` → consulta `order_ctx.order_projections` con paginación y filtro por `status`
    - Crear `src/Delivery/Api/V1/Orders/CancelOrderController.php`: DELETE `/api/v1/orders/{orderId}` → despacha `CancelOrder` command
    - Registrar rutas en `config/routes.yaml`
    - _Requisitos: 15.5_

  - [ ] 16.2 Crear los schemas OpenAPI para los endpoints de Orders
    - Crear `config/openapi/schemas/Order.yaml` con el schema del read model de Order
    - Crear `config/openapi/schemas/CreateOrderRequest.yaml` con el schema de la petición
    - _Requisitos: 15.5_

  - [ ]* 16.3 Escribir tests de integración para los endpoints de la API
    - Crear `tests/Integration/Api/OrderApiTest.php`
    - Testear: crear pedido (202), obtener pedido (200/404), listar pedidos con paginación, cancelar pedido
    - _Requisitos: 13.8_

- [ ] 17. Suite de Tests Completa
  - [ ] 17.1 Crear tests unitarios de infraestructura
    - Crear `tests/Unit/Infrastructure/EventStore/DbalEventStoreTest.php`: testear append, loadStream, concurrencia
    - Crear `tests/Unit/Infrastructure/EventStore/UpcasterChainTest.php`: testear cadena de upcasters con múltiples versiones
    - Crear `tests/Unit/Infrastructure/Projection/OrderSummaryProjectorTest.php`: testear cada handler de evento
    - Crear `tests/Unit/Infrastructure/Messaging/IdempotentMessageHandlerTest.php`: testear deduplicación
    - Crear `tests/Unit/Infrastructure/Outbox/OutboxStoreTest.php`: testear store, fetchPending, markPublished
    - _Requisitos: 13.2, 13.3_

  - [ ] 17.2 Crear tests de integración del flujo completo
    - Crear `tests/Integration/FullOrderFlowTest.php`: flujo completo desde `CreateOrder` hasta `NotificationSent`, verificar todos los eventos en audit log
    - Crear `tests/Integration/AsyncCommunicationTest.php`: verificar comunicación entre contextos vía integration events
    - Crear `tests/Integration/DeadLetterFlowTest.php`: verificar flujo completo de dead letter (fallo → dead letter → retry → éxito/re-fallo)
    - Crear `tests/Integration/RetryBehaviorTest.php`: verificar política de reintentos con backoff exponencial
    - _Requisitos: 13.5, 13.6, 13.7, 13.8_

  - [ ]* 17.3 Escribir test de propiedad: Respuesta correcta de handlers ante integration events
    - Añadir tests en `tests/Integration/FullOrderFlowTest.php`
    - Verificar que cada handler produce los eventos de respuesta esperados preservando `correlation_id` y estableciendo `causation_id`
    - Verificar que los logs estructurados incluyen todos los campos de contexto disponibles
    - **Propiedad 8: Respuesta correcta de handlers ante eventos de integración**
    - **Propiedad 17: Completitud del log estructurado**
    - **Valida: Requisitos 4.2, 4.3, 4.4, 4.5, 9.1**

- [ ] 18. Checkpoint Final — Verificar suite completa de tests
  - Ejecutar `php bin/phpunit` y verificar que todos los tests pasan.
  - Verificar que los tests de propiedad ejecutan al menos 100 iteraciones cada uno.
  - Consultar al usuario si surgen dudas antes de proceder a la documentación.

- [ ] 19. Documentación
  - [ ] 19.1 Crear el archivo de documentación del laboratorio
    - Crear `docs/event-sourcing-lab.md` con las secciones definidas en el requisito 14.1:
      - Bounded Contexts y sus responsabilidades
      - Tablas del Event Store y proyecciones (con schemas SQL)
      - Catálogo completo de eventos con estructura de payload
      - Todos los comandos de consola disponibles con ejemplos
      - Flujos de eventos entre contextos (diagramas de secuencia en texto)
      - Mecanismos de generación de errores y flags disponibles
      - Funcionamiento de dead letters y comandos de gestión
      - Proyecciones y su reconstrucción mediante replay
      - Trazabilidad mediante correlation_id y causation_id
      - Generación de volumen con el seeder
    - _Requisitos: 14.1_

  - [ ] 19.2 Añadir los 12 escenarios de práctica para entrevistas
    - Añadir sección "Escenarios de Práctica" en `docs/event-sourcing-lab.md`
    - Incluir los 12 escenarios definidos en el requisito 14.2 con comandos exactos a ejecutar y qué observar en cada uno
    - _Requisitos: 14.2, 14.3_

## Notas

- Las tareas marcadas con `*` son opcionales y pueden omitirse para una implementación MVP más rápida.
- Cada tarea referencia los requisitos específicos que implementa para trazabilidad completa.
- Los checkpoints garantizan validación incremental y permiten detectar problemas temprano.
- Los tests de propiedad ejecutan un mínimo de 100 iteraciones con datos generados aleatoriamente mediante Faker.
- Los tests unitarios y de propiedad son complementarios: los unitarios verifican ejemplos específicos, los de propiedad verifican invariantes universales.
- El Event Store usa DBAL directamente (no ORM); no crear entidades Doctrine para los eventos.
- Todos los schemas PostgreSQL deben crearse en una única migración Doctrine para facilitar el setup del entorno.
- El upcasting se aplica exclusivamente en tiempo de lectura; el Event Store nunca modifica eventos almacenados.
