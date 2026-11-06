# Módulo Tables / Sessions: decisiones del contrato

**Estado:** APROBADAS (T-1 a T-9)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en la sección 6 del documento maestro: el estado operativo es derivado, `tables.status` es administrativo, abrir una sesión es transaccional, separar mesas no separa cuentas, la presencia no es ocupación y la limpieza usa `session_membership_version`. El documento no define los estados de `table_sessions` ni de la limpieza, así que aquí se proponen.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **T-1** | Estados de `table_sessions` | `ACTIVE`, `PENDING_CLEANING`, `CLOSED`, `CANCELLED` | Sin `CANCELLED`; una apertura errónea se cierra normal |
| **T-2** | Limpieza | Por mesa; la mesa sigue bloqueada hasta confirmarla | Por sesión completa |
| **T-3** | Separar una mesa | El empleado indica si necesita limpieza | Siempre exige limpieza |
| **T-4** | Requisitos para cerrar | Cuentas en `PAID` o `CANCELLED` y ninguna unidad `PENDING`, `PREPARING` o `READY` | Permitir cierre con autorización |
| **T-5** | Derivación del estado operativo | Reglas de la sección 3 | Otro orden de prioridad |
| **T-6** | Ventana de presencia | 10 min para `POSIBLE_OCUPACION` | Otro valor tras probar en piso |
| **T-7** | Apertura de sesión | `Idempotency-Key` obligatoria | Solo el índice único, con 409 al reintentar |
| **T-8** | Permisos por rol | Matriz de la sección 4 | Ajustar por rol |
| **T-9** | Carrera con la desactivación de sucursal | Campo `operations_epoch` (sección 6) | Aceptar el riesgo |

### Fuera de v1, salvo que indiques lo contrario

- **Mover clientes de mesa:** se resuelve agregando la mesa nueva y separando la anterior.
- **Unir dos sesiones activas:** se rechaza con `TABLE_ALREADY_ASSIGNED`. Unirlas implica combinar comandas y cuentas, lo que requiere reglas propias.

### Se decide en otros módulos

- **Mesa con reservación próxima:** se define en Reservations, junto con las "mesas compatibles" de D-14, cuyo criterio aún no existe.
- **Token QR:** no se expone en `Table`. Su consulta y rotación se definen en Public QR.
- **Señales de presencia:** las escribe Public QR; aquí solo se leen.

## 2. Máquina de estados de la sesión

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Abrir | Ninguno | Sucursal activa; mesas `ACTIVE` sin asignación activa | `table_sessions`, `table_assignments`, `branches.operations_epoch`, `audit_log`, `outbox_events` | `ACTIVE` | `BRANCH_INACTIVE`, `TABLE_NOT_AVAILABLE`, `TABLE_ALREADY_ASSIGNED`, `IDEMPOTENCY_KEY_REUSED` |
| Agregar mesa | `ACTIVE` | `If-Match`; mesa disponible | Sesión (`membership_version` +1), asignación, `branches.operations_epoch`, auditoría, outbox | `ACTIVE` | `VERSION_CONFLICT`, `SESSION_NOT_ACTIVE`, `TABLE_ALREADY_ASSIGNED`, `TABLE_NOT_AVAILABLE` |
| Separar mesa | `ACTIVE` | `If-Match`; no es la última mesa | Sesión (`membership_version` +1), asignación, limpieza si aplica, auditoría, outbox | `ACTIVE` | `VERSION_CONFLICT`, `SESSION_NOT_ACTIVE`, `SESSION_LAST_TABLE` |
| Cerrar | `ACTIVE` | `If-Match`; requisitos de T-4 | Sesión, asignaciones (`phase = CLEANING`), limpieza `PENDING` por mesa, auditoría, outbox | `PENDING_CLEANING` | `VERSION_CONFLICT`, `SESSION_NOT_ACTIVE`, `SESSION_HAS_OPEN_CHECKS`, `SESSION_HAS_PENDING_UNITS` |
| Confirmar limpieza | `ACTIVE` o `PENDING_CLEANING` | Existe limpieza `PENDING` con esa versión | Limpieza `CONFIRMED`, asignación (`active = false`), sesión (`version` +1), auditoría, outbox | `CLOSED` si no quedan mesas; si no, sin cambio | `CLEANING_CONFIRMATION_STALE` |
| Cancelar | `ACTIVE` | `If-Match`; motivo; sin unidades, pagos ni mesas en limpieza | Sesión, asignaciones (`active = false`), auditoría, outbox | `CANCELLED` | `VERSION_CONFLICT`, `SESSION_NOT_ACTIVE`, `SESSION_HAS_ACTIVITY` |

Todas las operaciones son transaccionales.

Reglas de limpieza:

- Solo agregar y separar incrementan `membership_version`. Confirmar una limpieza no lo hace, para no invalidar las de otras mesas.
- La versión se compara con la de la limpieza pendiente de esa mesa. Así, una limpieza de una mesa separada sigue siendo válida aunque la sesión cambie después.
- Repetir una confirmación ya hecha responde 200 sin cambios.
- La confirmación no usa `If-Match`. Un cambio ajeno en la sesión no debe obligar a repetirla.
- La sesión pasa a `CLOSED` cuando no le quedan asignaciones activas. Si dos confirmaciones finales coinciden, ambas escriben en la sesión; una reintenta y ve el resultado de la otra.

## 3. Estado operativo derivado

Se calcula en cada lectura y se aplica la primera regla que coincida:

| # | Condición | Estado |
|---|---|---|
| 1 | `tables.status` distinto de `ACTIVE` | `null` |
| 2 | Asignación activa en `phase = CLEANING` | `POR_LIMPIAR` |
| 3 | Sin asignación, con presencia en los últimos 10 min | `POSIBLE_OCUPACION` |
| 4 | Sin asignación | `LIBRE` |
| 5 | Alguna cuenta en `REQUESTED` o `PARTIALLY_PAID` | `CUENTA_SOLICITADA` |
| 6 | Alguna unidad en `PENDING`, `PREPARING` o `READY` | `ESPERANDO_COMIDA` |
| 7 | Al menos una unidad `DELIVERED` | `CONSUMIENDO` |
| 8 | Ninguna de las anteriores | `OCUPADA_SIN_COMANDA` |

Como es un valor calculado, filtrar por `operationalState` se resuelve en memoria sobre las mesas de una sucursal. Esto es aceptable porque son pocas, pero conviene medirlo en la prueba de carga.

## 4. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `tables.read` | Sí | Sí | Sí | Sí |
| `tables.manage` | Sí | Sí | No | No |
| `table_sessions.read` | Sí | Sí | Sí | Sí |
| `table_sessions.open` | Sí | Sí | Sí | No |
| `table_sessions.update` | Sí | Sí | Sí | No |
| `table_sessions.close` | Sí | Sí | Sí | No |
| `table_sessions.cancel` | Sí | Sí | No | No |
| `tables.clean` | Sí | Sí | Sí | No |

`tables.clean` incluye a WAITER porque aún no existe un rol de limpieza.

## 5. Cambios al modelo físico

- **`tables`:** `uuid`, `branch_id`, `number`, `capacity`, `zone`, `status`, `qr_token`, `version`.
- **`table_sessions`:** `uuid`, `branch_id`, `status`, `guest_count`, `responsible_user_id`, `reservation_id`, `membership_version`, `business_date`, `opened_by`, `opened_at`, `close_requested_at`, `closed_at`, `cancel_reason`, `version`.
- **`table_assignments`:** se agrega `phase` (`SEATED` o `CLEANING`). `active` se mantiene en `true` hasta confirmar la limpieza, así el índice único parcial sigue bloqueando la mesa.
- **`table_cleaning_confirmations`:** `status` (`PENDING` o `CONFIRMED`), `confirmed_by`, `confirmed_at`.
- **`branches`:** `operations_epoch`.
- **Índices nuevos:** `unique(uuid)` en `tables` y `table_sessions`.
- **Códigos de error nuevos:** `TABLE_NUMBER_TAKEN`, `BRANCH_INACTIVE`, `SESSION_LAST_TABLE`, `SESSION_HAS_OPEN_CHECKS`, `SESSION_HAS_PENDING_UNITS`, `SESSION_HAS_ACTIVITY`, `CLEANING_CONFIRMATION_STALE`.

## 6. Concurrencia

- **Dos aperturas sobre la misma mesa:** el índice único parcial de `table_assignments` deja pasar una sola. La otra recibe `409 TABLE_ALREADY_ASSIGNED`.
- **Desactivar la sucursal o cambiar el estado de una mesa mientras se abre una sesión:** abrir una sesión, agregar mesas, cambiar el estado de una mesa y desactivar la sucursal incrementan `branches.operations_epoch` en su transacción, de modo que MongoDB las hace chocar y una reintenta. No cambia `version`, para no invalidar el `If-Match` de quien edita la sucursal. La apertura es poco frecuente, así que la contención debería ser baja. Con una sesión activa, las comandas no necesitan tocar este campo porque esa sesión ya bloquea la desactivación.
- **Cerrar mientras entra una comanda:** Orders debe escribir en el documento de la sesión dentro de su transacción. Queda como requisito para la tarea 24.6.
- **Pendiente para otros módulos:** abrir un turno de caja y confirmar una reservación también deben incrementar `operations_epoch`, porque bloquean la desactivación (B-4).

Falta probar esta estrategia con carga real; es parte de la tarea 37.
