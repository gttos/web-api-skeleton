# Documento de Requisitos — Event Sourcing Lab

## Introducción

Este proyecto transforma un esqueleto de microservicio Symfony existente en un laboratorio práctico y didáctico para estudiar Event Sourcing, arquitectura dirigida por eventos, comunicación entre Bounded Contexts, manejo controlado de errores, dead letters, idempotencia, latencia, correlation IDs y observabilidad.

No es una aplicación de producción. Es un proyecto realista diseñado para comprender, practicar y explicar estos conceptos en entrevistas técnicas.

El dominio elegido es un sistema simple de Pedidos (Orders) dividido en cinco Bounded Contexts que se comunican mediante distintos patrones.

## Glosario

- **Event_Store**: Tabla de persistencia que almacena todos los eventos de dominio como fuente de verdad para los agregados que usan Event Sourcing. Contiene aggregate_id, aggregate_type, event_type, event_version, payload, metadata, correlation_id, causation_id y occurred_at.
- **Agregado**: Entidad raíz de un cluster de objetos de dominio cuyo estado se reconstruye a partir de eventos almacenados en el Event_Store.
- **Proyección**: Modelo de lectura (read model) derivado de los eventos del Event_Store, optimizado para consultas.
- **Bounded_Context**: Límite lógico que encapsula un subdominio con su propio modelo, lenguaje y reglas de negocio.
- **Order_Context**: Bounded Context responsable del ciclo de vida de pedidos. Usa Event Sourcing como mecanismo de persistencia.
- **Payment_Context**: Bounded Context responsable de procesar pagos asociados a pedidos.
- **Stock_Context**: Bounded Context responsable de gestionar reservas de inventario.
- **Notification_Context**: Bounded Context responsable de enviar notificaciones sobre el estado de los pedidos.
- **Audit_Context**: Bounded Context responsable de registrar y reportar la actividad del sistema con fines de auditoría.
- **Dead_Letter_Store**: Almacén de mensajes que no pudieron ser procesados tras agotar los reintentos configurados.
- **Processed_Messages_Table**: Tabla que registra mensajes ya procesados para garantizar idempotencia (message_id, event_id, consumer_name, processed_at, correlation_id).
- **Correlation_ID**: Identificador único que agrupa todos los eventos y mensajes originados por una misma acción del usuario, permitiendo trazabilidad completa del flujo.
- **Causation_ID**: Identificador del evento o comando que causó directamente la emisión de otro evento.
- **Integration_Event**: Evento publicado externamente por un Bounded Context para comunicar hechos relevantes a otros contextos.
- **Internal_Event**: Evento de dominio que permanece dentro de los límites de su Bounded Context.
- **Consumer**: Handler de Symfony Messenger que procesa mensajes de un transporte asíncrono.
- **Replay**: Proceso de reconstruir una proyección reprocesando todos los eventos del Event_Store desde el inicio o desde un punto específico.
- **Outbox_Pattern**: Patrón que garantiza la publicación atómica de eventos almacenándolos en una tabla transaccional antes de enviarlos al broker.
- **Snapshot**: Captura del estado de un agregado en un punto específico para optimizar la reconstrucción evitando reprocesar todos los eventos.
- **Seeder**: Comando de consola que genera datos de prueba (pedidos, eventos, errores) en volumen configurable.
- **Sistema**: El laboratorio Event Sourcing Lab en su conjunto, incluyendo todos los Bounded Contexts y su infraestructura.

## Requisitos

### Requisito 1: Event Store y Persistencia de Eventos

**User Story:** Como desarrollador estudiando Event Sourcing, quiero un Event Store que persista todos los eventos de dominio del Order_Context, para poder reconstruir el estado de los agregados y entender cómo funciona la persistencia basada en eventos.

#### Criterios de Aceptación

1. THE Event_Store SHALL persistir cada evento con los campos: aggregate_id (UUID), aggregate_type (string), event_type (string), event_version (integer), payload (JSON), metadata (JSON), correlation_id (UUID), causation_id (UUID) y occurred_at (timestamp con microsegundos).
2. WHEN un evento es almacenado en el Event_Store, THE Sistema SHALL asignar un número de secuencia global auto-incremental único al evento.
3. WHEN dos escrituras concurrentes intentan almacenar eventos para el mismo agregado con la misma versión esperada, THE Event_Store SHALL rechazar la segunda escritura con un error de conflicto de concurrencia.
4. WHEN se solicitan los eventos de un agregado, THE Event_Store SHALL retornarlos ordenados por event_version de forma ascendente.
5. THE Event_Store SHALL almacenar el correlation_id y causation_id proporcionados en los metadatos de cada evento para permitir trazabilidad completa.

---

### Requisito 2: Reconstrucción de Estado del Agregado

**User Story:** Como desarrollador estudiando Event Sourcing, quiero reconstruir el estado de un agregado Order a partir de sus eventos, para comprender cómo el estado se deriva exclusivamente de la secuencia de eventos.

#### Criterios de Aceptación

1. WHEN se solicita un agregado Order por su ID, THE Order_Context SHALL reconstruir el estado completo del agregado aplicando todos sus eventos almacenados en el Event_Store en orden secuencial.
2. WHEN un agregado Order tiene más de 50 eventos almacenados, THE Order_Context SHALL soportar la creación de un Snapshot para optimizar la reconstrucción.
3. WHEN existe un Snapshot para un agregado, THE Order_Context SHALL reconstruir el estado aplicando el Snapshot y luego solo los eventos posteriores a dicho Snapshot.
4. FOR ALL agregados Order reconstruidos, aplicar todos los eventos desde el inicio SHALL producir un estado equivalente al obtenido mediante Snapshot más eventos posteriores (propiedad round-trip).

---

### Requisito 3: Proyecciones y Read Models

**User Story:** Como desarrollador estudiando Event Sourcing, quiero proyecciones que se actualicen a partir de los eventos del Event_Store, para entender la separación entre modelo de escritura y modelo de lectura.

#### Criterios de Aceptación

1. WHEN un evento de dominio es almacenado en el Event_Store, THE Sistema SHALL actualizar las proyecciones asociadas de forma asíncrona.
2. THE Sistema SHALL mantener al menos una proyección de lectura para el Order_Context que refleje el estado actual de los pedidos (order_id, status, total, created_at, updated_at).
3. WHEN se ejecuta el comando `projection:rebuild`, THE Sistema SHALL reconstruir la proyección completa reprocesando todos los eventos del Event_Store desde el inicio.
4. WHEN se ejecuta el comando `projection:rebuild --from-sequence=N`, THE Sistema SHALL reconstruir la proyección reprocesando solo los eventos con secuencia mayor o igual a N.
5. FOR ALL proyecciones reconstruidas mediante Replay, el estado final SHALL ser equivalente al estado obtenido por procesamiento incremental de eventos (propiedad de idempotencia del replay).

---

### Requisito 4: Flujo de Eventos entre Bounded Contexts

**User Story:** Como desarrollador estudiando arquitectura dirigida por eventos, quiero un flujo completo de eventos entre los cinco Bounded Contexts, para entender cómo se orquesta un proceso de negocio distribuido.

#### Criterios de Aceptación

1. WHEN se crea un pedido en el Order_Context, THE Order_Context SHALL emitir un Integration_Event OrderCreated con order_id, items, total y correlation_id.
2. WHEN el Payment_Context recibe un OrderCreated, THE Payment_Context SHALL emitir PaymentRequested y posteriormente PaymentSucceeded o PaymentFailed.
3. WHEN el Stock_Context recibe un PaymentSucceeded, THE Stock_Context SHALL emitir StockReservationRequested y posteriormente StockReserved o StockReservationFailed.
4. WHEN el Notification_Context recibe un StockReserved o un evento terminal (PaymentFailed, StockReservationFailed), THE Notification_Context SHALL emitir NotificationRequested y posteriormente NotificationSent o NotificationFailed.
5. WHEN el Audit_Context recibe cualquier Integration_Event, THE Audit_Context SHALL registrar el evento con su correlation_id, causation_id, timestamp y payload completo.
6. THE Sistema SHALL propagar el correlation_id original a través de toda la cadena de eventos entre Bounded Contexts.

---

### Requisito 5: Patrones de Comunicación entre Bounded Contexts

**User Story:** Como desarrollador estudiando comunicación entre contextos, quiero implementar y comparar cinco patrones distintos de comunicación, para entender las ventajas y desventajas de cada uno.

#### Criterios de Aceptación

1. THE Sistema SHALL implementar comunicación síncrona directa (service call) entre al menos dos Bounded Contexts, donde un contexto invoca directamente un servicio de otro contexto dentro del mismo proceso.
2. THE Sistema SHALL implementar comunicación asíncrona mediante Integration_Events publicados en RabbitMQ, donde un contexto emite un evento y otros contextos lo consumen de forma independiente.
3. THE Sistema SHALL implementar comunicación basada en comandos asíncronos, donde un Bounded Context envía un comando a otro contexto solicitando una acción específica.
4. THE Sistema SHALL diferenciar explícitamente entre Internal_Events (que permanecen dentro de un contexto) e Integration_Events (que se publican para consumo externo).
5. THE Sistema SHALL documentar y demostrar con ejemplos la diferencia entre Bounded Contexts que comparten base de datos y Bounded Contexts con bases de datos separadas (schemas PostgreSQL distintos).

---

### Requisito 6: Manejo de Errores Controlados

**User Story:** Como desarrollador estudiando resiliencia en sistemas distribuidos, quiero escenarios de error controlados y reproducibles, para entender cómo se comporta el sistema ante fallos temporales, permanentes y de concurrencia.

#### Criterios de Aceptación

1. WHEN un Consumer experimenta un fallo temporal configurable, THE Sistema SHALL reintentar el procesamiento del mensaje según la política de reintentos configurada y completar exitosamente en un intento posterior.
2. WHEN un Consumer experimenta un fallo permanente (tras agotar todos los reintentos), THE Sistema SHALL mover el mensaje al Dead_Letter_Store con el motivo del fallo, correlation_id y metadata original.
3. WHEN un Consumer recibe un mensaje con payload inválido, THE Sistema SHALL rechazar el mensaje, registrar el error con detalles de validación y mover el mensaje al Dead_Letter_Store.
4. WHEN un Consumer simula latencia elevada (slow consumer), THE Sistema SHALL procesar el mensaje tras el retraso configurado sin perder el mensaje ni bloquear otros consumers.
5. WHEN dos comandos concurrentes intentan modificar el mismo agregado Order, THE Sistema SHALL detectar el conflicto de versión y rechazar la operación conflictiva con un error descriptivo.
6. THE Sistema SHALL permitir activar cada escenario de error mediante flags de configuración o parámetros en los comandos de seeding.

---

### Requisito 7: Dead Letter Management

**User Story:** Como desarrollador estudiando dead letters, quiero comandos para inspeccionar, reintentar y gestionar mensajes fallidos, para entender el ciclo de vida completo de un mensaje que no pudo ser procesado.

#### Criterios de Aceptación

1. WHEN se ejecuta el comando `dead-letter:list`, THE Sistema SHALL mostrar todos los mensajes en el Dead_Letter_Store con: message_id, event_type, consumer_name, error_reason, correlation_id y failed_at.
2. WHEN se ejecuta el comando `dead-letter:show {message_id}`, THE Sistema SHALL mostrar el detalle completo del mensaje incluyendo payload, metadata, stack trace del error y número de intentos realizados.
3. WHEN se ejecuta el comando `dead-letter:retry {message_id}`, THE Sistema SHALL reenviar el mensaje al transporte original para reprocesamiento y registrar el intento de reenvío.
4. WHEN se ejecuta el comando `dead-letter:retry-all`, THE Sistema SHALL reenviar todos los mensajes del Dead_Letter_Store a sus transportes originales.
5. IF un mensaje reenviado desde el Dead_Letter_Store falla nuevamente, THEN THE Sistema SHALL retornarlo al Dead_Letter_Store incrementando el contador de intentos.

---

### Requisito 8: Idempotencia de Consumers

**User Story:** Como desarrollador estudiando idempotencia, quiero que cada Consumer demuestre procesamiento idempotente, para entender cómo evitar efectos duplicados ante mensajes repetidos.

#### Criterios de Aceptación

1. THE Sistema SHALL mantener una Processed_Messages_Table con los campos: message_id (UUID), event_id (UUID), consumer_name (string), processed_at (timestamp) y correlation_id (UUID).
2. WHEN un Consumer recibe un mensaje, THE Consumer SHALL verificar en la Processed_Messages_Table si el message_id ya fue procesado por ese consumer_name antes de ejecutar la lógica de negocio.
3. WHEN un Consumer detecta un mensaje duplicado (message_id ya registrado), THE Consumer SHALL ignorar el mensaje, registrar un log informativo y confirmar el mensaje como procesado exitosamente sin ejecutar efectos secundarios.
4. WHEN un Consumer procesa un mensaje nuevo exitosamente, THE Consumer SHALL registrar el message_id en la Processed_Messages_Table dentro de la misma transacción que los cambios de negocio.
5. FOR ALL mensajes duplicados enviados al Sistema, el estado final del sistema SHALL ser idéntico al estado producido por una única entrega del mensaje (propiedad de idempotencia).

---

### Requisito 9: Observabilidad y Trazabilidad

**User Story:** Como desarrollador estudiando observabilidad, quiero trazabilidad completa de cada flujo mediante correlation_id y logs estructurados, para poder reconstruir la historia completa de cualquier operación.

#### Criterios de Aceptación

1. THE Sistema SHALL incluir en cada log estructurado los campos: correlation_id, causation_id, message_id, event_id, aggregate_id y consumer_name cuando estén disponibles en el contexto.
2. WHEN se busca por un correlation_id específico, THE Sistema SHALL permitir recuperar todos los eventos, logs y mensajes asociados a ese flujo completo.
3. THE Sistema SHALL generar un correlation_id único al inicio de cada flujo (creación de pedido) y propagarlo automáticamente a todos los eventos, comandos y mensajes derivados.
4. THE Sistema SHALL generar un causation_id en cada evento que referencia al evento o comando que lo originó directamente.
5. WHERE se configura observabilidad avanzada, THE Sistema SHALL exponer métricas compatibles con Prometheus (mensajes procesados, errores, latencia por consumer, dead letters pendientes).

---

### Requisito 10: Comandos de Generación de Volumen (Seeding)

**User Story:** Como desarrollador estudiando comportamiento bajo carga, quiero comandos que generen volúmenes configurables de pedidos y eventos, para observar el sistema bajo distintas condiciones de carga y tasa de error.

#### Criterios de Aceptación

1. WHEN se ejecuta `seed:orders --count=N`, THE Seeder SHALL crear N pedidos con datos aleatorios realistas y disparar el flujo completo de eventos para cada uno.
2. WHEN se ejecuta `seed:orders --count=N --error-rate=P`, THE Seeder SHALL crear N pedidos donde aproximadamente P por ciento de los mensajes generarán errores controlados (temporales y permanentes distribuidos aleatoriamente).
3. WHEN se ejecuta `seed:orders --count=N --with-duplicates`, THE Seeder SHALL crear N pedidos y reenviar aproximadamente el 10 por ciento de los mensajes como duplicados para ejercitar la idempotencia.
4. WHEN se ejecuta `seed:orders` sin parámetro --count, THE Seeder SHALL usar un valor por defecto de 100 pedidos.
5. THE Seeder SHALL asignar un correlation_id único a cada pedido generado y mostrarlo en la salida del comando para facilitar la trazabilidad posterior.

---

### Requisito 11: Outbox Pattern

**User Story:** Como desarrollador estudiando consistencia eventual, quiero una implementación del Outbox Pattern, para entender cómo garantizar la publicación atómica de eventos sin perder mensajes ante fallos del broker.

#### Criterios de Aceptación

1. WHEN un agregado del Order_Context persiste nuevos eventos, THE Sistema SHALL almacenar los Integration_Events pendientes de publicación en una tabla outbox dentro de la misma transacción de base de datos.
2. THE Sistema SHALL incluir un proceso (relay/poller) que lea los mensajes de la tabla outbox y los publique en RabbitMQ.
3. WHEN un mensaje de la tabla outbox es publicado exitosamente en RabbitMQ, THE Sistema SHALL marcar el registro como publicado.
4. IF el broker RabbitMQ no está disponible al momento de publicar, THEN THE Sistema SHALL mantener el mensaje en la tabla outbox y reintentar la publicación en el siguiente ciclo del relay.
5. FOR ALL eventos almacenados en el Event_Store, el Outbox_Pattern SHALL garantizar que eventualmente se publique un Integration_Event correspondiente (propiedad de consistencia eventual).

---

### Requisito 12: Event Upcasting

**User Story:** Como desarrollador estudiando evolución de esquemas, quiero un mecanismo de event upcasting, para entender cómo manejar cambios en la estructura de eventos sin perder compatibilidad con eventos históricos.

#### Criterios de Aceptación

1. WHEN el Sistema carga un evento con event_version anterior a la versión actual del esquema, THE Sistema SHALL aplicar los upcasters registrados para transformar el payload a la versión actual.
2. THE Sistema SHALL mantener un registro de upcasters ordenados por versión que transforman eventos de la versión N a la versión N+1 de forma encadenada.
3. WHEN se ejecuta un Replay de proyecciones, THE Sistema SHALL aplicar los upcasters correspondientes a cada evento antes de procesarlo en la proyección.
4. THE Event_Store SHALL preservar el evento original sin modificar; el upcasting se aplica solo en tiempo de lectura.
5. FOR ALL eventos históricos con versiones anteriores, aplicar la cadena de upcasters SHALL producir un payload compatible con la versión actual del esquema (propiedad de compatibilidad hacia adelante).

---

### Requisito 13: Tests Automatizados

**User Story:** Como desarrollador, quiero una suite de tests que verifique todos los comportamientos del laboratorio, para poder experimentar con confianza y detectar regresiones.

#### Criterios de Aceptación

1. THE Sistema SHALL incluir tests que verifiquen que un agregado Order se reconstruye correctamente a partir de sus eventos almacenados.
2. THE Sistema SHALL incluir tests que verifiquen que los eventos se almacenan correctamente en el Event_Store con todos los campos requeridos.
3. THE Sistema SHALL incluir tests que verifiquen que las proyecciones se actualizan correctamente al procesar eventos.
4. THE Sistema SHALL incluir tests que verifiquen que una proyección reconstruida mediante Replay produce el mismo estado que el procesamiento incremental.
5. THE Sistema SHALL incluir tests que verifiquen el comportamiento de los consumers ante: procesamiento exitoso, reintentos por fallo temporal, envío a dead letter por fallo permanente, e idempotencia ante mensajes duplicados.
6. THE Sistema SHALL incluir tests que verifiquen que un mensaje con payload inválido es rechazado y enviado al Dead_Letter_Store.
7. THE Sistema SHALL incluir tests que verifiquen la detección de conflictos de concurrencia al modificar el mismo agregado simultáneamente.
8. THE Sistema SHALL incluir tests que verifiquen la comunicación entre Bounded Contexts mediante los distintos patrones implementados.

---

### Requisito 14: Documentación del Laboratorio

**User Story:** Como desarrollador que estudia para entrevistas técnicas, quiero documentación completa del laboratorio, para poder repasar conceptos, flujos y comandos disponibles.

#### Criterios de Aceptación

1. THE Sistema SHALL incluir un archivo `/docs/event-sourcing-lab.md` que documente: todos los Bounded Contexts y sus responsabilidades, las tablas del Event Store y proyecciones, todos los eventos del sistema con su estructura, todos los comandos de consola disponibles, los flujos de eventos entre contextos, los mecanismos de generación de errores, el funcionamiento de dead letters, las proyecciones y su reconstrucción, la trazabilidad mediante correlation_id, la generación de volumen y los casos de práctica para entrevistas.
2. THE Sistema SHALL incluir en la documentación al menos 12 escenarios de práctica que cubran: crear un pedido y ver todos los eventos generados, reconstruir estado desde el Event Store, ver actualización de proyección, comunicación síncrona entre contextos, comunicación por eventos entre contextos, comunicación por comandos asíncronos, provocar error temporal y ver reintentos, provocar error permanente y ver dead letter, reprocesar un dead letter, enviar mensajes duplicados y verificar idempotencia, generar volumen y buscar errores por correlation_id, y comparar bases de datos compartidas vs separadas.
3. THE Sistema SHALL incluir diagramas o descripciones textuales de los flujos de eventos que muestren la secuencia completa desde OrderCreated hasta NotificationSent.

---

### Requisito 15: Compatibilidad con Stack Existente

**User Story:** Como desarrollador, quiero que el laboratorio se integre con el stack existente del microservicio Symfony, para no tener que aprender herramientas nuevas y aprovechar la infraestructura ya configurada.

#### Criterios de Aceptación

1. THE Sistema SHALL utilizar Symfony 6.4, PHP 8.2+, PostgreSQL 15, Symfony Messenger, RabbitMQ y Redis como stack tecnológico base.
2. THE Sistema SHALL utilizar los buses ya configurados (command.bus, query.bus, event.bus, job.queue) de somnambulist/domain para el despacho de comandos, queries y eventos.
3. THE Sistema SHALL utilizar Doctrine ORM con mappings XML para la persistencia de entidades y el Event Store.
4. THE Sistema SHALL utilizar Symfony Messenger con transporte AMQP (RabbitMQ) para la comunicación asíncrona entre Bounded Contexts y el transporte doctrine para el failure transport.
5. THE Sistema SHALL mantener la estructura de carpetas existente (Application, Delivery, Domain, Infrastructure) adaptándola para acomodar los múltiples Bounded Contexts.
6. THE Sistema SHALL utilizar PHPUnit como framework de testing, aprovechando DAMA doctrine test bundle y Liip test fixtures ya configurados.
