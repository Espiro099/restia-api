# Módulo Reservations: decisiones del contrato

**Estado:** APROBADAS (R-1 a R-12)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en D-14, D-18 y las secciones 10 y 11 del documento maestro: reserva separada de la sesión, `start_at` y `end_at` obligatorios, confirmación con revalidación, `ARRIVED_WAITING` sin sesión ficticia, prioridad de espera, sin desalojar a clientes sentados y la IA como simple interfaz de la API.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **R-1** | Qué aparta una reserva | Solo `CONFIRMED` y `ARRIVED_WAITING` apartan mesas concretas; `PENDING` no aparta nada | Capacidad total por horario, sin mesas concretas |
| **R-2** | Mesas compatibles (D-14) | Mesas del mismo `combinationGroup`, hasta 3; se elige la opción con menos asientos sobrantes | Lista explícita de combinaciones |
| **R-3** | Horario reservable | Nuevo `reservationHours` por sucursal; sin horario no se aceptan reservas | Sin validación de horario |
| **R-4** | Mesa ocupada | No disponible para reservas que inicien en los próximos 90 min | Duración promedio por sucursal |
| **R-5** | Mesa con reserva próxima | Abrirla dentro de los 60 min previos responde `409 TABLE_RESERVED_SOON`, salvo confirmación explícita y auditada | Bloqueo total, o solo aviso |
| **R-6** | `NO_SHOW` | Automático o manual, 15 min después de `start_at`, configurable por sucursal | Solo manual |
| **R-7** | `PENDING` sin confirmar | Pasa a `CANCELLED` (`EXPIRED`) al llegar `start_at` | Vencimiento más corto |
| **R-8** | Prioridad de espera | Se hace cumplir; saltarla exige `reservations.override_priority` y motivo | Solo informativa |
| **R-9** | `COMPLETED` | Automático cuando la sesión pasa a `PENDING_CLEANING`; si la sesión se cancela, la reserva vuelve a `ARRIVED_WAITING` | Marcarlo manualmente |
| **R-10** | Permisos e IA | Matriz de la sección 4 | Ajustar por rol |
| **R-11** | Datos del cliente | Copia de nombre y teléfono en la reserva, con `customerId` opcional | Exigir cliente registrado |
| **R-12** | Protección de transiciones | `Idempotency-Key` e `If-Match` en todas | Solo `If-Match` |

### Cambios a contratos ya aprobados

Estas decisiones modificaron módulos aprobados antes; los cambios ya están en el contrato:

| Módulo | Cambio | Origen |
|---|---|---|
| Branches | Nuevos `reservationHours` y `reservationNoShowToleranceMinutes` | R-3, R-6 |
| Tables | Nuevo `combinationGroup` | R-2 |
| Table Sessions | Nuevo `acknowledgeReservation` y error `TABLE_RESERVED_SOON` al abrir | R-5 |
| Table Sessions | El cierre y la cancelación actualizan la reserva vinculada | R-9 |
| Abrir sesión y agregar mesas | Nueva respuesta 503 si se agotan los reintentos | Sección 5 |

## 2. Máquina de estados

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear | Ninguno | Datos e intervalo válidos; sucursal activa | `reservations`, auditoría, outbox | `PENDING` | `BRANCH_INACTIVE`, `IDEMPOTENCY_KEY_REUSED` |
| Confirmar | `PENDING` | Horario y mesas disponibles, revalidados en la transacción | Reserva (`planned_table_ids`), `branches.operations_epoch`, auditoría, outbox | `CONFIRMED` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE`, `RESERVATION_NOT_AVAILABLE` |
| Editar horario o personas | `PENDING`, `CONFIRMED` | Si está `CONFIRMED`, revalida; si falla, conserva lo anterior | Reserva, `operations_epoch`, auditoría, outbox | Sin cambio | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE`, `RESERVATION_NOT_AVAILABLE` |
| Registrar llegada | `CONFIRMED` | Cliente presente | Reserva (`arrival_registered_at`), auditoría, outbox | `ARRIVED_WAITING` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE` |
| Sentar | `CONFIRMED`, `ARRIVED_WAITING` | Mesas disponibles y compatibles; prioridad (R-8) | Reserva, `table_sessions`, `table_assignments`, `operations_epoch`, auditoría, outbox | `CHECKED_IN` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE`, `NO_TABLE_AVAILABLE`, `TABLE_ALREADY_ASSIGNED`, `RESERVATION_PRIORITY_CONFLICT` |
| Cancelar | `PENDING`, `CONFIRMED`, `ARRIVED_WAITING` | Motivo | Reserva, auditoría, outbox | `CANCELLED` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE` |
| Marcar `NO_SHOW` | `CONFIRMED` | Pasó la tolerancia; sin llegada registrada | Reserva, auditoría, outbox | `NO_SHOW` | `VERSION_CONFLICT`, `RESERVATION_INVALID_STATE` |
| Vencer (sistema) | `PENDING` | Llegó `start_at` | Reserva, auditoría, outbox | `CANCELLED` | Ninguno |
| Sesión pasa a `PENDING_CLEANING` | `CHECKED_IN` | Sesión vinculada | Reserva, auditoría, outbox | `COMPLETED` | Ninguno |
| Sesión cancelada | `CHECKED_IN` | Sesión vinculada | Reserva, auditoría, outbox | `ARRIVED_WAITING` | Ninguno |

`ARRIVED_WAITING` nunca pasa a `NO_SHOW` (D-18). Para un cliente que llega después de un `NO_SHOW`, se abre una sesión normal o se crea otra reserva.

## 3. Disponibilidad

El endpoint de consulta orienta, pero no aparta mesas. Una opción de mesas es válida si cumple todo lo siguiente:

1. El intervalo cabe en `reservationHours`.
2. Las mesas están en `ACTIVE`, y son una sola o varias del mismo `combinationGroup` (máximo 3).
3. Su capacidad total alcanza para el grupo.
4. Ninguna está planeada en otra reserva `CONFIRMED` o `ARRIVED_WAITING` con un intervalo traslapado.
5. Ninguna está ocupada, si la reserva inicia dentro de los próximos 90 min.

Una reserva `CONFIRMED` cuyas mesas dejan de servir, por ejemplo por mantenimiento o un walk-in aceptado, se replanifica en la misma transacción. Si no hay alternativa, se marca con `planConflict = true` para que el personal la resuelva. Nunca se cancela de forma automática.

## 4. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN | `reservation-ai` |
|---|---|---|---|---|---|
| `reservations.read` | Sí | Sí | Sí | No | No |
| `reservations.availability` | Sí | Sí | Sí | No | Sí |
| `reservations.lookup` | Sí | Sí | Sí | No | Sí |
| `reservations.create` | Sí | Sí | Sí | No | Sí |
| `reservations.update` | Sí | Sí | Sí | No | No |
| `reservations.confirm` | Sí | Sí | Sí | No | Sí |
| `reservations.arrive` | Sí | Sí | Sí | No | No |
| `reservations.check_in` | Sí | Sí | Sí | No | No |
| `reservations.cancel` | Sí | Sí | Sí | No | Sí |
| `reservations.mark_no_show` | Sí | Sí | Sí | No | No |
| `reservations.override_priority` | Sí | Sí | No | No | No |

La IA puede pedir una confirmación o una cancelación, pero la API decide. Para consultar o cancelar, primero debe usar `lookup` con el código de confirmación y el teléfono. Esa consulta va por `POST` para que el teléfono no aparezca en URLs ni en logs de acceso.

**Actualización (IA-4):** este límite quedó cerrado, porque `confirm` y `cancel` ahora le exigen código y teléfono a `reservation-ai`.

**Límite conocido:** la API no puede comprobar que la IA hizo esa consulta antes de cancelar. Un `reservation-ai` comprometido podría cancelar cualquier reserva de sus sucursales. Se mitiga con auditoría y con la revocación de su token. Si se quiere una protección más fuerte, `cancel` tendría que exigirle también el código de confirmación.

## 5. Concurrencia

- **Doble confirmación de la misma mesa:** no hay índice que impida traslapes. Confirmar, editar y sentar escriben primero en `branches.operations_epoch` y luego revisan la disponibilidad. Dos transacciones así chocan, y la que reintenta ya ve el resultado de la otra.
- **Punto común:** es el mismo campo de T-9, así que también se ordenan respecto a abrir sesiones y a mantenimiento, sin un mecanismo adicional.
- **Límite de reintentos:** si se agotan, responde `503 SERVICE_BUSY`. El cliente puede reintentar con la misma `Idempotency-Key`.
- **Liberar es seguro:** cancelar y `NO_SHOW` no escriben en ese campo porque solo liberan capacidad.
- **Costo:** esto ordena estas operaciones por sucursal. Si un bot genera ráfagas de confirmaciones, puede haber más reintentos, algo que falta medir en la tarea 37.

## 6. Cambios al modelo físico

- **`reservations`:** `uuid`, `branch_id`, `status`, `start_at`, `end_at`, `party_size`, `customer_id`, `customer_snapshot`, `notes`, `source`, `confirmation_code`, `planned_table_ids`, `plan_conflict`, `arrival_registered_at`, `session_id`, `cancel_reason`, `version`.
- **`tables`:** `combination_group`.
- **`branches`:** `reservation_hours`, `reservation_no_show_tolerance_minutes`.
- **Índices nuevos:** `unique(uuid)`, `unique(branch_id, confirmation_code)`, `(branch_id, status, arrival_registered_at)`.
- **Índice existente:** `unique(branch_id, idempotency_key)` cubre la creación. Las transiciones usan `idempotency_records`, definida en CON-1 (Revisión de consistencia).
- **Códigos de error nuevos:** `RESERVATION_PRIORITY_CONFLICT`, `TABLE_RESERVED_SOON`, `SERVICE_BUSY`.

## 7. Datos personales

El nombre y el teléfono del cliente son datos personales, y `customer_snapshot` los duplica. Su retención y su manejo se deben definir con la revisión de datos personales (tarea 32) antes de implementar.
