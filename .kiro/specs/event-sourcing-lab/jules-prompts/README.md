# Prompts para Jules — Event Sourcing Lab

Cada archivo en esta carpeta es un prompt autocontenido para Jules. Dáselo en orden y Jules podrá implementar el laboratorio fase por fase.

## Orden de ejecución

| Archivo | Fase | Puede paralelizarse |
|---------|------|---------------------|
| `fase-01-infraestructura.md` | Infraestructura, Docker, migraciones, config | No (base de todo) |
| `fase-02-event-store.md` | DbalEventStore, SnapshotStore, value objects | Después de fase 01 |
| `fase-03-agregado-order.md` | Dominio Order, eventos, agregado, repositorio | Después de fase 02 |
| `fase-04-outbox-proyecciones.md` | Outbox Pattern + Projection Engine | Después de fase 03 |
| `fase-05-consumers-idempotencia.md` | Integration Events, consumers, idempotencia | Después de fase 04 |
| `fase-06-dead-letters-observabilidad.md` | Dead Letters, comandos de gestión, correlation IDs | Después de fase 05 |
| `fase-07-errores-seeding-upcasting.md` | Error injection, seed:orders, event upcasting | Después de fase 06 |
| `fase-08-api-tests-docs.md` | API REST, suite de tests completa, documentación | Después de fase 07 |

## Notas
- Cada prompt incluye todo el contexto necesario: stack, estructura de carpetas, schemas SQL, interfaces.
- Las tareas marcadas con `*` en tasks.md son opcionales (property-based tests). Jules puede omitirlas si querés ir más rápido.
- Los checkpoints intermedios (tareas 3, 7, 12, 18 del tasks.md) son puntos donde conviene verificar antes de continuar.
