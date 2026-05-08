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
