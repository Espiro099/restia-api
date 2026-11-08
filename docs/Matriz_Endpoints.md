# Matriz endpoint × rol × estado

**Estado:** generado desde `openapi/restia-api.yaml`
**Fecha:** 2026-11-02

Cubre las 212 operaciones del contrato. Se regenera con `tools/gen_matrices.py`; no se edita a mano.

## 1. Criterios de la sección 36

| # | Criterio | Operaciones que lo responden |
|---|---|---|
| 1 | ¿Quién puede invocarlo? | 212 de 212 |
| 2 | ¿En qué sucursal? | 212 de 212 |
| 3 | ¿Qué permiso necesita? | 212 de 212 |
| 4 | ¿Qué estado requiere? | 212 de 212 |
| 5 | ¿Qué payload recibe? | 193 de 212 (las otras 19 son escrituras sin cuerpo, porque la ruta basta) |
| 6 | ¿Qué devuelve? | 212 de 212 |
| 7 | ¿Qué errores puede producir? | 212 de 212 (`x-restia.conflictCodes` da los 409 exactos) |
| 8 | ¿Es idempotente? | 212 de 212 |
| 9 | ¿Requiere versión? | 211 de 212 (Orders por lotes lleva la versión en el cuerpo) |
| 10 | ¿Qué ocurre con concurrencia? | 212 de 212 |
| 11 | ¿Genera auditoría? | 212 de 212 |
| 12 | ¿Genera outbox? | 212 de 212 |
| 13 | ¿Es síncrono o asíncrono? | 212 de 212 |
| 14 | ¿Qué datos son sensibles? | 212 de 212 |
| 15 | ¿Qué cliente lo consume? | 212 de 212 |

## 2. Operaciones

Otros actores: identidades de servicio (`reservation-ai`, `printing-agent`) o visitantes públicos.

### Authentication (20)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /sanctum/csrf-cookie` | – | – | – | – | Visitante Web | Ninguno | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `POST /auth/login` | – | – | – | – | Empleado | Usuario activo | Usuario activo | No | No | Login correcto y bloqueo por intentos | No | Síncrono | Web | – |
| `POST /auth/mobile/enroll` | – | – | – | – | Dispositivo con código de alta | Código vigente de un solo uso | Código sin usar y sin vencer | No | No; el código solo admite un uso | Alta de dispositivo | device.enrolled | Síncrono, en transacción | Android | DEVICE_ALREADY_ENROLLED |
| `POST /auth/mobile/login` | – | – | – | – | Empleado | Membresía activa en la sucursal del dispositivo | Usuario activo y dispositivo ACTIVE | No | No | Login correcto, sesiones revocadas y bloqueo por intentos | No | Síncrono, en transacción | Android | – |
| `POST /auth/mobile/refresh` | – | – | – | – | Sesión Android | Refresh token vigente | Sesión ACTIVE y dispositivo ACTIVE | No | No usa Idempotency-Key; la gracia de 60 s cubre los reintentos | Solo cuando se detecta reúso | No | Síncrono, en transacción | Android | – |
| `POST /auth/logout` | – | – | – | – | Empleado autenticado | Ninguno | Sesión ACTIVE | No | Sí, en efecto | Logout | No | Síncrono, en transacción | Android, Web | – |
| `GET /auth/me` | – | – | – | – | Empleado autenticado | Ninguno | Sesión ACTIVE | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /auth/sessions` | – | – | – | – | Empleado autenticado | Solo sesiones propias | Sesión ACTIVE | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /auth/sessions/{sessionId}/revoke` | – | – | – | – | Dueño de la sesión | Solo sesiones propias; una ajena responde 404 | Cualquiera; si ya está cerrada no hay cambios | No | Sí, en efecto | Revocación | No | Síncrono, en transacción | Android, Web | – |
| `POST /users/{userId}/sessions/revoke` | Sí | – | – | – | – | users.sessions.revoke | Cualquiera; si no hay sesiones activas no hay cambios | No | Sí, en efecto | Revocación con actor y motivo | user.sessions_revoked | Síncrono, en transacción | Web | – |
| `POST /branches/{branchId}/approvals` | – | – | – | – | Empleado autenticado, con credenciales de un aprobador | El aprobador debe tener el permiso de la acción: order_units.void, inventory.override, checks.reopen o checks.adjust | Aprobador activo con membresía en la sucursal | No | No; cada petición emite un token distinto | Solicitante, aprobador, acción y unidades; nunca credenciales | No | Síncrono, en transacción | Android, Web | – |
| `GET /service-identities` | Sí | – | – | – | – | service_identities.manage | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `POST /service-identities` | Sí | – | – | – | – | service_identities.manage | Nombre sin usar | No | No usa Idempotency-Key; el nombre único evita duplicados | Creación; nunca el token | service_identity.created | Síncrono, en transacción | Web | SERVICE_IDENTITY_NAME_TAKEN |
| `POST /service-identities/{serviceIdentityId}/rotate` | Sí | – | – | – | – | service_identities.manage | Activa | Sí | No; cada llamada emite un token nuevo | Rotación; nunca el token | service_identity.rotated | Síncrono, en transacción | Web | VERSION_CONFLICT, SERVICE_IDENTITY_REVOKED |
| `POST /service-identities/{serviceIdentityId}/revoke` | Sí | – | – | – | – | service_identities.manage | Activa | Sí | Sí, en efecto | Revocación, con motivo | service_identity.revoked | Síncrono, en transacción | Web | VERSION_CONFLICT, SERVICE_IDENTITY_REVOKED |
| `GET /service-identities/me` | – | – | – | – | reservation-ai, printing-agent | service_identities.read_self | Identidad activa | No | Sí, operación de lectura | No | No | Síncrono | IA, printing-agent | – |
| `PATCH /service-identities/{serviceIdentityId}` | Sí | – | – | – | – | service_identities.manage | Activa | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | service_identity.updated | Síncrono, en transacción | Web | VERSION_CONFLICT, SERVICE_IDENTITY_REVOKED |
| `POST /auth/password/change` | Sí | Sí | Sí | Sí | – | Sesión propia | Usuario activo | No | No; la contraseña actual deja de coincidir | Cambio; nunca la contraseña | user.password_changed | Síncrono, en transacción | Android, Web | – |
| `POST /auth/password/forgot` | – | – | – | – | Público | Ninguno | Ninguno | No | Sí, en efecto; invalida el enlace anterior | Solicitud, si el usuario existe | user.password_reset_requested | Síncrono; el correo se envía de forma asíncrona | Web | – |
| `POST /auth/password/reset` | – | – | – | – | Público con enlace | Token de un solo uso | Token vigente sin usar | No | No; el token solo sirve una vez | Definición; nunca la contraseña ni el token | user.password_changed | Síncrono, en transacción | Web, Android | PASSWORD_TOKEN_INVALID |

### Devices (4)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `POST /branches/{branchId}/devices/enrollment-codes` | Sí | Sí | – | – | – | devices.enroll | Sucursal activa | No | No; cada petición genera un código distinto | Emisión del código, sin su valor | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/devices` | Sí | Sí | – | – | – | devices.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/devices/{deviceId}/block` | Sí | Sí | – | – | – | devices.manage | ACTIVE; en BLOCKED o RETIRED no hay cambios | No | Sí, en efecto | Bloqueo y sesiones revocadas, con actor y motivo | device.blocked | Síncrono, en transacción | Android, Web | – |
| `POST /branches/{branchId}/devices/{deviceId}/retire` | Sí | Sí | – | – | – | devices.manage | ACTIVE; en BLOCKED o RETIRED no hay cambios | No | Sí, en efecto | Retiro y sesiones revocadas, con actor y motivo | device.retired | Síncrono, en transacción | Android, Web | – |

### Branches (6)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches` | – | – | – | – | Empleado autenticado | Membresía activa | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches` | Sí | – | – | – | – | branches.create | Código de sucursal sin usar | No | No usa Idempotency-Key; el código único evita duplicados | Creación de sucursal y de membresía | branch.created | Síncrono, en transacción | Web | BRANCH_CODE_TAKEN |
| `GET /branches/{branchId}` | – | – | – | – | Empleado con membresía | Membresía activa en la sucursal | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}` | Sí | – | – | – | – | branches.update | Sin operación activa si cambia timezone o businessDayCutoff | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | branch.updated | Síncrono, en transacción | Web | VERSION_CONFLICT, BRANCH_HAS_ACTIVE_OPERATIONS |
| `POST /branches/{branchId}/activate` | Sí | – | – | – | – | branches.manage_status | INACTIVE; si ya está ACTIVE no hay cambios | Sí | Sí, en efecto | Cambio de estado | branch.activated | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `POST /branches/{branchId}/deactivate` | Sí | – | – | – | – | branches.manage_status | ACTIVE y sin operación activa; si ya está INACTIVE no hay cambios | Sí | Sí, en efecto | Cambio de estado, con actor y motivo | branch.deactivated | Síncrono, en transacción | Web | VERSION_CONFLICT, BRANCH_HAS_ACTIVE_OPERATIONS |

### Tables (7)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/tables` | Sí | Sí | Sí | Sí | – | tables.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/tables` | Sí | Sí | – | – | – | tables.manage | Sucursal activa y número sin usar | No | No usa Idempotency-Key; el número único evita duplicados | Creación | table.created | Síncrono, en transacción | Web | BRANCH_INACTIVE, TABLE_NUMBER_TAKEN |
| `GET /branches/{branchId}/tables/{tableId}` | Sí | Sí | Sí | Sí | – | tables.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/tables/{tableId}` | Sí | Sí | – | – | – | tables.manage | Cualquiera | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | table.updated | Síncrono, en transacción | Web | VERSION_CONFLICT, TABLE_NUMBER_TAKEN |
| `POST /branches/{branchId}/tables/{tableId}/status` | Sí | Sí | – | – | – | tables.manage | Sin asignación activa, salvo para pasar a ACTIVE | Sí | Sí, en efecto | Cambio de estado, con actor y motivo | table.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, TABLE_ALREADY_ASSIGNED |
| `GET /branches/{branchId}/tables/{tableId}/qr` | Sí | Sí | – | – | – | tables.qr_read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `POST /branches/{branchId}/tables/{tableId}/qr/rotate` | Sí | Sí | – | – | – | tables.qr_rotate | Mesa distinta de INACTIVE | Sí | No; cada llamada emite un token nuevo, y If-Match rechaza la repetición | Rotación, con motivo; nunca el token | table.qr_rotated | Síncrono, en transacción | Web | VERSION_CONFLICT, BRANCH_INACTIVE |

### Table Sessions (9)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/table-sessions` | Sí | Sí | Sí | Sí | – | table_sessions.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/table-sessions` | Sí | Sí | Sí | – | – | table_sessions.open | Sucursal activa; mesas ACTIVE sin asignación activa | No | Sí, con Idempotency-Key | Apertura | table_session.opened | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, BRANCH_INACTIVE, TABLE_NOT_AVAILABLE, TABLE_ALREADY_ASSIGNED, TABLE_RESERVED_SOON |
| `GET /branches/{branchId}/table-sessions/{sessionId}` | Sí | Sí | Sí | Sí | – | table_sessions.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/table-sessions/{sessionId}` | Sí | Sí | Sí | – | – | table_sessions.update | ACTIVE | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | table_session.updated | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, SESSION_NOT_ACTIVE |
| `POST /branches/{branchId}/table-sessions/{sessionId}/tables` | Sí | Sí | Sí | – | – | table_sessions.update | ACTIVE; mesas disponibles | Sí | No; If-Match rechaza la repetición con versión anterior | Asignación | table_session.tables_added | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, SESSION_NOT_ACTIVE, TABLE_NOT_AVAILABLE, TABLE_ALREADY_ASSIGNED |
| `POST /branches/{branchId}/table-sessions/{sessionId}/tables/{tableId}/release` | Sí | Sí | Sí | – | – | table_sessions.update | ACTIVE; no puede ser la última mesa | Sí | No; If-Match rechaza la repetición con versión anterior | Separación | table_session.table_released | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, SESSION_NOT_ACTIVE, SESSION_LAST_TABLE |
| `POST /branches/{branchId}/table-sessions/{sessionId}/tables/{tableId}/cleaning-confirmation` | Sí | Sí | Sí | – | – | tables.clean | Limpieza PENDING de esa mesa con la versión enviada | No | Sí, en efecto | Confirmación | table.cleaned; table_session.closed al liberar la última mesa | Síncrono, en transacción | Android, Web | CLEANING_CONFIRMATION_STALE |
| `POST /branches/{branchId}/table-sessions/{sessionId}/close` | Sí | Sí | Sí | – | – | table_sessions.close | ACTIVE; cuentas PAID, CANCELLED o REFUNDED; sin unidades PENDING, PREPARING o READY; las cuentas sin unidades válidas ni pagos se cancelan en la misma transacción | Sí | No; If-Match rechaza la repetición con versión anterior | Cierre | table_session.close_requested; reservation.completed si hay reserva vinculada | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, SESSION_NOT_ACTIVE, SESSION_HAS_OPEN_CHECKS, SESSION_HAS_PENDING_UNITS |
| `POST /branches/{branchId}/table-sessions/{sessionId}/cancel` | Sí | Sí | – | – | – | table_sessions.cancel | ACTIVE; sin unidades, pagos ni mesas en limpieza | Sí | No; If-Match rechaza la repetición con versión anterior | Cancelación, con actor y motivo | table_session.cancelled; la reserva vinculada vuelve a ARRIVED_WAITING | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, SESSION_NOT_ACTIVE, SESSION_HAS_ACTIVITY |

### Reservations (13)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/reservations/availability` | Sí | Sí | Sí | – | reservation-ai | reservations.availability | Sucursal activa | No | Sí, operación de lectura | No | No | Síncrono | Android, Web, IA | RESERVATION_AI_DISABLED |
| `POST /branches/{branchId}/reservations/lookup` | Sí | Sí | Sí | – | reservation-ai | reservations.lookup | Ninguno | No | Sí, operación de lectura | Consultas de reservation-ai e intentos fallidos, sin el teléfono | No | Síncrono | Android, Web, IA | RESERVATION_AI_DISABLED |
| `GET /branches/{branchId}/reservations/waiting-queue` | Sí | Sí | Sí | – | – | reservations.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/reservations` | Sí | Sí | Sí | – | – | reservations.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/reservations` | Sí | Sí | Sí | – | reservation-ai | reservations.create | Sucursal activa | No | Sí, con Idempotency-Key | Creación | reservation.created | Síncrono, en transacción | Android, Web, IA | IDEMPOTENCY_KEY_REUSED, BRANCH_INACTIVE, RESERVATION_AI_DISABLED, RESERVATION_REQUIRES_STAFF, RESERVATION_ACTIVE_LIMIT_EXCEEDED |
| `GET /branches/{branchId}/reservations/{reservationId}` | Sí | Sí | Sí | – | – | reservations.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/reservations/{reservationId}` | Sí | Sí | Sí | – | – | reservations.update | PENDING o CONFIRMED | Sí | Sí, con Idempotency-Key | Valores anteriores y nuevos | reservation.updated | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, RESERVATION_INVALID_STATE, RESERVATION_NOT_AVAILABLE, NO_TABLE_AVAILABLE |
| `POST /branches/{branchId}/reservations/{reservationId}/confirm` | Sí | Sí | Sí | – | reservation-ai | reservations.confirm | PENDING | Sí | Sí, con Idempotency-Key | Confirmación | reservation.confirmed | Síncrono, en transacción | Android, Web, IA | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, RESERVATION_INVALID_STATE, RESERVATION_NOT_AVAILABLE, NO_TABLE_AVAILABLE, RESERVATION_AI_DISABLED |
| `POST /branches/{branchId}/reservations/{reservationId}/arrive` | Sí | Sí | Sí | – | – | reservations.arrive | CONFIRMED | Sí | Sí, con Idempotency-Key | Llegada | reservation.arrived | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, RESERVATION_INVALID_STATE |
| `POST /branches/{branchId}/reservations/{reservationId}/check-in` | Sí | Sí | Sí | – | – | reservations.check_in; reservations.override_priority para saltar la prioridad | CONFIRMED o ARRIVED_WAITING | Sí | Sí, con Idempotency-Key | Check-in y apertura de sesión | reservation.checked_in; table_session.opened | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, RESERVATION_INVALID_STATE, NO_TABLE_AVAILABLE, TABLE_ALREADY_ASSIGNED, TABLE_NOT_AVAILABLE, RESERVATION_PRIORITY_CONFLICT |
| `POST /branches/{branchId}/reservations/{reservationId}/cancel` | Sí | Sí | Sí | – | reservation-ai | reservations.cancel | PENDING, CONFIRMED o ARRIVED_WAITING | Sí | Sí, con Idempotency-Key | Cancelación, con actor y motivo | reservation.cancelled | Síncrono, en transacción | Android, Web, IA | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, RESERVATION_INVALID_STATE, RESERVATION_AI_DISABLED |
| `POST /branches/{branchId}/reservations/{reservationId}/no-show` | Sí | Sí | Sí | – | – | reservations.mark_no_show | CONFIRMED, sin llegada y pasada la tolerancia | Sí | Sí, con Idempotency-Key | Cambio de estado | reservation.no_show | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, RESERVATION_INVALID_STATE |
| `GET /branches/{branchId}/reservations/policy` | – | – | – | – | reservation-ai | reservations.policy | Sucursal autorizada para la identidad | No | Sí, operación de lectura | No | No | Síncrono | IA | – |

### Menu (21)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/stations` | Sí | Sí | Sí | Sí | – | menu.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/stations` | Sí | Sí | – | – | – | menu.manage | Sucursal activa y código sin usar | No | No usa Idempotency-Key; el código único evita duplicados | Creación | station.created | Síncrono, en transacción | Web | BRANCH_INACTIVE, CATALOG_CODE_TAKEN |
| `GET /branches/{branchId}/stations/{stationId}` | Sí | Sí | Sí | Sí | – | menu.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/stations/{stationId}` | Sí | Sí | – | – | – | menu.manage | Cualquiera | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | station.updated | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `POST /branches/{branchId}/stations/{stationId}/status` | Sí | Sí | – | – | – | menu.manage | Para desactivar: sin unidades sin terminar ni ser la única ruta de un producto activo | Sí | Sí, en efecto | Cambio de estado, con actor y motivo | station.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, STATION_IN_USE |
| `GET /branches/{branchId}/modifiers` | Sí | Sí | Sí | Sí | – | menu.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/modifiers` | Sí | Sí | – | – | – | menu.manage | Sucursal activa y código sin usar | No | No usa Idempotency-Key; el código único evita duplicados | Creación | modifier.created | Síncrono, en transacción | Web | BRANCH_INACTIVE, CATALOG_CODE_TAKEN |
| `GET /branches/{branchId}/modifiers/{modifierId}` | Sí | Sí | Sí | Sí | – | menu.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/modifiers/{modifierId}` | Sí | Sí | – | – | – | menu.manage | Cualquiera | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | modifier.updated | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `POST /branches/{branchId}/modifiers/{modifierId}/status` | Sí | Sí | – | – | – | menu.manage | Cualquiera | Sí | Sí, en efecto | Cambio de estado, con actor y motivo | modifier.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `GET /branches/{branchId}/products` | Sí | Sí | Sí | Sí | – | menu.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/products` | Sí | Sí | – | – | – | menu.manage | Sucursal activa y código sin usar | No | No usa Idempotency-Key; el código único evita duplicados | Creación | product.created | Síncrono, en transacción | Web | BRANCH_INACTIVE, CATALOG_CODE_TAKEN |
| `GET /branches/{branchId}/products/{productId}` | Sí | Sí | Sí | Sí | – | menu.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/products/{productId}` | Sí | Sí | – | – | – | menu.manage | Cualquiera | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | product.updated | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `POST /branches/{branchId}/products/{productId}/status` | Sí | Sí | – | – | – | menu.manage | Para activar: al menos una ruta activa | Sí | Sí, en efecto | Cambio de estado, con actor y motivo | product.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, PRODUCT_NOT_SELLABLE |
| `POST /branches/{branchId}/products/{productId}/availability` | Sí | Sí | Sí | Sí | – | menu.set_availability | Cualquiera | No | Sí, en efecto | Cambio de disponibilidad | product.availability_changed | Síncrono, en transacción | Android, Web | – |
| `PUT /branches/{branchId}/products/{productId}/routes` | Sí | Sí | – | – | – | menu.manage | Estaciones activas; un producto activo debe conservar al menos una ruta | Sí | Sí, en efecto | Rutas anteriores y nuevas | product.routes_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, PRODUCT_NOT_SELLABLE |
| `GET /branches/{branchId}/products/{productId}/recipes` | Sí | Sí | – | Sí | – | recipes.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/products/{productId}/recipes` | Sí | Sí | – | – | – | recipes.manage | Ingredientes existentes | Sí | No; If-Match rechaza la repetición con versión anterior | Publicación | recipe.published | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `GET /branches/{branchId}/products/{productId}/recipes/{recipeVersion}` | Sí | Sí | – | Sí | – | recipes.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/menu` | Sí | Sí | Sí | Sí | – | menu.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |

### Orders (7)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/orders` | Sí | Sí | Sí | Sí | – | orders.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/orders` | Sí | Sí | Sí | – | – | orders.create | Sesión ACTIVE; productos activos, disponibles y con estación activa | No | Sí, con Idempotency-Key | Creación | order.created | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, SESSION_NOT_ACTIVE, PRODUCT_UNAVAILABLE, CLIENT_ORDER_CONFLICT, CHECK_INVALID_STATE |
| `GET /branches/{branchId}/orders/{orderId}` | Sí | Sí | Sí | Sí | – | orders.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/orders/{orderId}/items/{itemId}` | Sí | Sí | Sí | – | – | orders.update | Todas las unidades de la partida en PENDING | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | order_item.updated | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, ORDER_UNIT_INVALID_STATE |
| `GET /branches/{branchId}/order-units` | Sí | Sí | Sí | Sí | – | orders.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/order-units/transitions` | Sí | Sí | Sí | Sí | – | Según action: order_units.prepare, .deliver, .cancel o .void | Estado previo válido en todas las unidades | En el cuerpo: units[].version | Sí, con Idempotency-Key | Cada transición; en VOID, también el aprobador | order_unit.started, .ready, .delivered, .cancelled o .voided; inventory.consumed en START | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, ORDER_UNIT_INVALID_STATE, ORDER_UNIT_PAID, INVENTORY_INSUFFICIENT, INVENTORY_BLOCKED_FOOD_SAFETY, APPROVAL_INVALID |
| `POST /branches/{branchId}/order-units/{unitId}/station` | Sí | Sí | – | Sí | – | order_units.reassign | PENDING; estación destino activa | Sí | No; If-Match rechaza la repetición con versión anterior | Estación anterior y nueva, con motivo | order_unit.reassigned | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, ORDER_UNIT_INVALID_STATE |

### Checks (9)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/checks` | Sí | Sí | Sí | – | – | checks.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/checks` | Sí | Sí | Sí | – | – | checks.create | Sesión ACTIVE | No | Sí, con Idempotency-Key | Creación | check.created | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, SESSION_NOT_ACTIVE |
| `GET /branches/{branchId}/checks/{checkId}` | Sí | Sí | Sí | – | – | checks.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/checks/{checkId}/units/move` | Sí | Sí | Sí | – | – | checks.move_units | Origen OPEN, REQUESTED o REFUNDED; destino OPEN; sin pagos confirmados vigentes ni en curso | Sí | No; If-Match rechaza la repetición con versión anterior | Unidades, origen y destino | check.units_moved | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, CHECK_INVALID_STATE, CHECK_SESSION_MISMATCH, CHECK_HAS_CONFIRMED_PAYMENT, CHECK_HAS_OPEN_PAYMENT, CHECK_HAS_ACTIVE_INVOICE |
| `POST /branches/{branchId}/checks/{checkId}/request` | Sí | Sí | Sí | – | – | checks.request | OPEN con al menos una unidad válida | Sí | No; If-Match rechaza la repetición con versión anterior | Solicitud | check.requested | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, CHECK_INVALID_STATE, CHECK_EMPTY |
| `POST /branches/{branchId}/checks/{checkId}/reopen` | Sí | Sí | Sí | – | – | checks.reopen, o token CHECK_REOPEN | REQUESTED sin pagos confirmados ni en curso | Sí | No; If-Match rechaza la repetición con versión anterior | Reapertura, con solicitante y aprobador | check.reopened | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, CHECK_INVALID_STATE, CHECK_HAS_CONFIRMED_PAYMENT, CHECK_HAS_OPEN_PAYMENT, APPROVAL_INVALID |
| `POST /branches/{branchId}/checks/{checkId}/cancel` | Sí | Sí | Sí | – | – | checks.cancel | OPEN o REQUESTED, sin unidades válidas ni pagos | Sí | No; If-Match rechaza la repetición con versión anterior | Cancelación, con motivo | check.cancelled | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, CHECK_INVALID_STATE, CHECK_NOT_EMPTY |
| `POST /branches/{branchId}/checks/{checkId}/adjustments` | Sí | Sí | Sí | – | – | checks.adjust, o token CHECK_ADJUSTMENT | OPEN o REQUESTED; unidad válida sin cortesía; sin pagos confirmados ni en curso | Sí | No; If-Match rechaza la repetición con versión anterior | Cortesía, con solicitante, aprobador y motivo | check.adjustment_applied | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, CHECK_INVALID_STATE, CHECK_HAS_CONFIRMED_PAYMENT, CHECK_HAS_OPEN_PAYMENT, CHECK_HAS_ACTIVE_INVOICE, ORDER_UNIT_INVALID_STATE, APPROVAL_INVALID |
| `POST /branches/{branchId}/checks/{checkId}/adjustments/{adjustmentId}/revert` | Sí | Sí | Sí | – | – | checks.adjust, o token CHECK_ADJUSTMENT | OPEN o REQUESTED, sin pagos confirmados ni en curso | Sí | No; If-Match rechaza la repetición con versión anterior | Reversión, con solicitante, aprobador y motivo | check.adjustment_reverted | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, CHECK_INVALID_STATE, CHECK_HAS_CONFIRMED_PAYMENT, CHECK_HAS_OPEN_PAYMENT, CHECK_HAS_ACTIVE_INVOICE, APPROVAL_INVALID |

### Payments (10)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/payments` | Sí | Sí | Sí | – | – | payments.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/payments` | Sí | Sí | Sí | – | – | payments.create | Turno OPEN; cuenta OPEN, REQUESTED o PARTIALLY_PAID; sin UNKNOWN; monto dentro del saldo | No | Sí, con Idempotency-Key | Creación | payment.created; payment.confirmed en efectivo | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, CASH_SHIFT_NOT_OPEN, CHECK_INVALID_STATE, PAYMENT_REQUIRES_RECONCILIATION, PAYMENT_EXCEEDS_BALANCE |
| `GET /branches/{branchId}/payments/{paymentId}` | Sí | Sí | Sí | – | – | payments.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/payments/{paymentId}/result` | Sí | Sí | Sí | – | – | payments.record_result | PENDING | Sí | Sí, con Idempotency-Key | Resultado | payment.confirmed, .failed, .cancelled o .unknown | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, PAYMENT_INVALID_STATE |
| `POST /branches/{branchId}/payments/{paymentId}/reconcile` | Sí | Sí | – | – | – | payments.reconcile | UNKNOWN | Sí | Sí, con Idempotency-Key | Reconciliación, con actor, motivo y evidencia | payment.reconciled | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, PAYMENT_INVALID_STATE |
| `POST /branches/{branchId}/payments/{paymentId}/refunds` | Sí | Sí | – | – | – | refunds.create | Pago CONFIRMED sin reembolso activo; turno OPEN | No | Sí, con Idempotency-Key | Reembolso, con actor y motivo | refund.created; refund.confirmed en efectivo | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, PAYMENT_NOT_REFUNDABLE, REFUND_ALREADY_EXISTS, CASH_SHIFT_NOT_OPEN |
| `GET /branches/{branchId}/refunds` | Sí | Sí | – | – | – | refunds.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/refunds/{refundId}` | Sí | Sí | – | – | – | refunds.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/refunds/{refundId}/result` | Sí | Sí | – | – | – | refunds.record_result | PENDING | Sí | Sí, con Idempotency-Key | Resultado | refund.confirmed, .failed o .unknown | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, REFUND_INVALID_STATE |
| `POST /branches/{branchId}/refunds/{refundId}/reconcile` | Sí | Sí | – | – | – | refunds.reconcile | UNKNOWN | Sí | Sí, con Idempotency-Key | Reconciliación, con actor, motivo y evidencia | refund.reconciled | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, REFUND_INVALID_STATE |

### Inventory (17)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/ingredients` | Sí | Sí | – | Sí | – | inventory.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/ingredients` | Sí | Sí | – | – | – | inventory.manage | Sucursal activa y código sin usar | No | No usa Idempotency-Key; el código único evita duplicados | Creación | ingredient.created | Síncrono, en transacción | Web | BRANCH_INACTIVE, CATALOG_CODE_TAKEN |
| `GET /branches/{branchId}/ingredients/{ingredientId}` | Sí | Sí | – | Sí | – | inventory.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/ingredients/{ingredientId}` | Sí | Sí | – | – | – | inventory.manage | Cualquiera | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | ingredient.updated | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `POST /branches/{branchId}/ingredients/{ingredientId}/status` | Sí | Sí | – | – | – | inventory.manage | Para desactivar: sin recetas vigentes que lo usen | Sí | Sí, en efecto | Cambio de estado, con motivo | ingredient.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, INGREDIENT_IN_USE |
| `POST /branches/{branchId}/ingredients/{ingredientId}/food-safety` | Sí | Sí | – | Sí | – | inventory.food_safety; desbloquear solo ADMIN o MANAGER | Cualquiera | No | Sí, en efecto | Bloqueo o desbloqueo, con actor y motivo | ingredient.food_safety_blocked o .food_safety_unblocked | Síncrono, en transacción | Android, Web | – |
| `GET /branches/{branchId}/inventory-movements` | Sí | Sí | – | Sí | – | inventory.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/inventory-movements` | Sí | Sí | – | Sí | – | inventory.adjust para ADJUSTMENT; inventory.record_waste para WASTE | Ingrediente existente | No | Sí, con Idempotency-Key | Movimiento, con actor y motivo | inventory.movement_created | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, BRANCH_INACTIVE |
| `GET /branches/{branchId}/inventory-receipts` | Sí | Sí | – | Sí | – | inventory.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/inventory-receipts` | Sí | Sí | – | Sí | – | inventory.receive | Ingredientes existentes | No | Sí, con Idempotency-Key | Recepción | inventory.received | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, BRANCH_INACTIVE |
| `GET /branches/{branchId}/inventory-receipts/{receiptId}` | Sí | Sí | – | Sí | – | inventory.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/inventory-counts` | Sí | Sí | – | Sí | – | inventory.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/inventory-counts` | Sí | Sí | – | Sí | – | inventory.count | Sin otro conteo OPEN | No | Sí, con Idempotency-Key | Apertura | No | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, INVENTORY_COUNT_ALREADY_OPEN |
| `GET /branches/{branchId}/inventory-counts/{countId}` | Sí | Sí | – | Sí | – | inventory.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PUT /branches/{branchId}/inventory-counts/{countId}/lines` | Sí | Sí | – | Sí | – | inventory.count | OPEN | Sí | Sí, en efecto | Cambios en las líneas | No | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, INVENTORY_COUNT_INVALID_STATE |
| `POST /branches/{branchId}/inventory-counts/{countId}/apply` | Sí | Sí | – | – | – | inventory.apply_count | OPEN con al menos una línea | Sí | Sí, con Idempotency-Key | Aplicación y diferencias | inventory.count_applied | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, INVENTORY_COUNT_INVALID_STATE |
| `POST /branches/{branchId}/inventory-counts/{countId}/cancel` | Sí | Sí | – | – | – | inventory.apply_count | OPEN | Sí | Sí, en efecto | Cancelación, con motivo | No | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, INVENTORY_COUNT_INVALID_STATE |

### Cash (14)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/cash-registers` | Sí | Sí | Sí | – | – | cash.read o cash.read_open | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/cash-registers` | Sí | Sí | – | – | – | cash.manage_registers | Sucursal activa y código sin usar | No | No usa Idempotency-Key; el código único evita duplicados | Creación | cash_register.created | Síncrono, en transacción | Web | BRANCH_INACTIVE, CATALOG_CODE_TAKEN |
| `GET /branches/{branchId}/cash-registers/{cashRegisterId}` | Sí | Sí | Sí | – | – | cash.read o cash.read_open | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/cash-registers/{cashRegisterId}` | Sí | Sí | – | – | – | cash.manage_registers | Cualquiera | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | cash_register.updated | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `POST /branches/{branchId}/cash-registers/{cashRegisterId}/status` | Sí | Sí | – | – | – | cash.manage_registers | Para desactivar: sin turno OPEN ni CLOSING | Sí | Sí, en efecto | Cambio de estado, con motivo | cash_register.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, CASH_REGISTER_HAS_ACTIVE_SHIFT |
| `GET /branches/{branchId}/cash-shifts` | Sí | Sí | Sí | – | – | cash.read para ver montos; cash.read_open solo para turnos OPEN, sin montos | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/cash-shifts` | Sí | Sí | – | – | – | cash.open | Sucursal activa; caja ACTIVE; sin turno OPEN ni CLOSING | No | Sí, con Idempotency-Key | Apertura, con fondo y diferencia | cash_shift.opened | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, BRANCH_INACTIVE, CASH_REGISTER_INACTIVE, CASH_SHIFT_ALREADY_OPEN, CASH_SHIFT_REASON_REQUIRED |
| `GET /branches/{branchId}/cash-shifts/{cashShiftId}` | Sí | Sí | Sí | – | – | cash.read para ver montos; cash.read_open solo para turnos OPEN, sin montos | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/cash-shifts/{cashShiftId}/summary` | Sí | Sí | – | – | – | cash.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/cash-shifts/{cashShiftId}/movements` | Sí | Sí | – | – | – | cash.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/cash-shifts/{cashShiftId}/movements` | Sí | Sí | – | – | – | cash.move | Turno OPEN | No | Sí, con Idempotency-Key | Movimiento, con actor y motivo | cash_movement.created | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, CASH_SHIFT_NOT_OPEN |
| `POST /branches/{branchId}/cash-shifts/{cashShiftId}/start-closing` | Sí | Sí | – | – | – | cash.close | OPEN | Sí | Sí, en efecto | Inicio de cierre | cash_shift.closing_started | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, CASH_SHIFT_INVALID_STATE |
| `POST /branches/{branchId}/cash-shifts/{cashShiftId}/cancel-closing` | Sí | Sí | – | – | – | cash.close | CLOSING | Sí | Sí, en efecto | Cancelación, con motivo | cash_shift.closing_cancelled | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, CASH_SHIFT_INVALID_STATE |
| `POST /branches/{branchId}/cash-shifts/{cashShiftId}/close` | Sí | Sí | – | – | – | cash.close | CLOSING, sin pagos ni reembolsos en PENDING o UNKNOWN | Sí | Sí, con Idempotency-Key | Cierre, con esperado, contado y diferencia | cash_shift.closed; cash_shift.difference_detected si supera la tolerancia | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, CASH_SHIFT_INVALID_STATE, CASH_SHIFT_HAS_UNRESOLVED_OPERATIONS, CASH_SHIFT_REASON_REQUIRED |

### Fiscal (26)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /fiscal-issuers` | Sí | – | – | – | – | fiscal.manage_issuers | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `POST /fiscal-issuers` | Sí | – | – | – | – | fiscal.manage_issuers | RFC sin usar | No | No usa Idempotency-Key; el RFC único evita duplicados | Creación | fiscal_issuer.created | Síncrono, en transacción | Web | FISCAL_ISSUER_RFC_TAKEN |
| `GET /fiscal-issuers/{fiscalIssuerId}` | Sí | – | – | – | – | fiscal.manage_issuers | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `PATCH /fiscal-issuers/{fiscalIssuerId}` | Sí | – | – | – | – | fiscal.manage_issuers | Cualquiera | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | fiscal_issuer.updated | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `POST /fiscal-issuers/{fiscalIssuerId}/status` | Sí | – | – | – | – | fiscal.manage_issuers | Para activar: CSD vigente | Sí | Sí, en efecto | Cambio de estado, con motivo | fiscal_issuer.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, FISCAL_ISSUER_NOT_CONFIGURED |
| `PUT /fiscal-issuers/{fiscalIssuerId}/csd` | Sí | – | – | – | – | fiscal.manage_issuers | Cualquiera | Sí | Sí, en efecto | Carga, con serie y vigencia; nunca el contenido | fiscal_issuer.csd_updated | Síncrono; contacta al PAC fuera de la transacción | Web | VERSION_CONFLICT, CSD_INVALID, FISCAL_OPERATION_FAILED |
| `PUT /branches/{branchId}/fiscal-issuer` | Sí | – | – | – | – | fiscal.manage_issuers | Emisor activo | Sí | Sí, en efecto | Emisor anterior y nuevo | branch.fiscal_issuer_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, FISCAL_ISSUER_NOT_CONFIGURED |
| `GET /branches/{branchId}/invoices` | Sí | Sí | Sí | – | – | invoices.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/invoices` | Sí | Sí | Sí | – | – | invoices.create; invoices.create_ppd si es PPD | PUE: cuenta PAID. PPD: REQUESTED o PARTIALLY_PAID. Emisor activo; sin otra factura activa | No | Sí, con Idempotency-Key | Creación | No | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, FISCAL_ISSUER_NOT_CONFIGURED, INVOICE_CHECK_NOT_ELIGIBLE, INVOICE_CHECK_ALREADY_INVOICED, INVOICE_CHECK_IN_STAMPED_GLOBAL |
| `GET /branches/{branchId}/invoices/{invoiceId}` | Sí | Sí | Sí | – | – | invoices.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/invoices/{invoiceId}` | Sí | Sí | Sí | – | – | invoices.create | DRAFT o STAMP_FAILED | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | No | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, INVOICE_INVALID_STATE |
| `POST /branches/{branchId}/invoices/{invoiceId}/discard` | Sí | Sí | Sí | – | – | invoices.create | DRAFT o STAMP_FAILED | Sí | Sí, en efecto | Descarte, con motivo | No | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, INVOICE_INVALID_STATE |
| `POST /branches/{branchId}/invoices/{invoiceId}/stamp` | Sí | Sí | Sí | – | – | invoices.stamp | DRAFT o STAMP_FAILED | Sí | Sí, con Idempotency-Key | Solicitud y resultado en invoice_events | invoice.stamp_requested; invoice.stamped; invoice.stamp_failed | Asíncrono: responde 202 y un proceso interno contacta al PAC | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, INVOICE_INVALID_STATE, FISCAL_STATUS_UNKNOWN |
| `POST /branches/{branchId}/invoices/{invoiceId}/reconcile` | Sí | Sí | – | – | – | invoices.reconcile | UNKNOWN | No | Sí, en efecto | Consulta y resultado en invoice_events | invoice.reconciled | Asíncrono: responde 202 y un proceso interno contacta al PAC | Android, Web | INVOICE_INVALID_STATE, FISCAL_STATUS_UNKNOWN |
| `POST /branches/{branchId}/invoices/{invoiceId}/cancel` | Sí | Sí | – | – | – | invoices.cancel | STAMPED o CANCELLATION_REJECTED; con motivo 01, sustituta STAMPED | Sí | Sí, con Idempotency-Key | Solicitud, motivo y resultado en invoice_events | invoice.cancellation_requested; invoice.cancelled; invoice.cancellation_rejected | Asíncrono: responde 202 y un proceso interno contacta al PAC | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, INVOICE_INVALID_STATE, INVOICE_REPLACEMENT_REQUIRED |
| `POST /branches/{branchId}/invoices/{invoiceId}/credit-notes` | Sí | Sí | – | – | – | invoices.credit_note | Factura STAMPED; monto dentro de lo disponible | No | Sí, con Idempotency-Key | Creación | No | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, INVOICE_INVALID_STATE, CREDIT_NOTE_EXCEEDS_AVAILABLE |
| `GET /branches/{branchId}/invoices/{invoiceId}/events` | Sí | Sí | – | – | – | invoices.reconcile | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/invoices/{invoiceId}/xml` | Sí | Sí | Sí | – | – | invoices.read | Con folio fiscal | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | INVOICE_INVALID_STATE |
| `GET /branches/{branchId}/invoices/{invoiceId}/pdf` | Sí | Sí | Sí | – | – | invoices.read | Con folio fiscal | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | INVOICE_INVALID_STATE |
| `GET /branches/{branchId}/invoices/{invoiceId}/payment-complements` | Sí | Sí | Sí | – | – | invoices.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/payment-complements/{complementId}/stamp` | Sí | Sí | – | – | – | invoices.reconcile | STAMP_FAILED | Sí | Sí, en efecto | Reintento en invoice_events | invoice.complement_stamped | Asíncrono: responde 202 y un proceso interno contacta al PAC | Android, Web | VERSION_CONFLICT, COMPLEMENT_INVALID_STATE |
| `POST /branches/{branchId}/payment-complements/{complementId}/reconcile` | Sí | Sí | – | – | – | invoices.reconcile | UNKNOWN | No | Sí, en efecto | Consulta y resultado en invoice_events | invoice.complement_reconciled | Asíncrono: responde 202 y un proceso interno contacta al PAC | Android, Web | COMPLEMENT_INVALID_STATE, FISCAL_STATUS_UNKNOWN |
| `GET /branches/{branchId}/invoice-batches` | Sí | Sí | – | – | – | invoices.global | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/invoice-batches` | Sí | Sí | – | – | – | invoices.global | Periodo con cuentas PAID sin factura; emisor activo | No | Sí, con Idempotency-Key | Creación, con periodo y cuentas | invoice_batch.created | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, FISCAL_ISSUER_NOT_CONFIGURED, INVOICE_BATCH_EMPTY |
| `GET /branches/{branchId}/invoice-batches/{batchId}` | Sí | Sí | – | – | – | invoices.global | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/invoice-batches/{batchId}/exclude-checks` | Sí | Sí | – | – | – | invoices.global | Factura global STAMPED | Sí | Sí, con Idempotency-Key | Exclusión, con motivo y cuentas | invoice_batch.superseded; invoice.cancellation_requested | Asíncrono: responde 202 y un proceso interno contacta al PAC | Android, Web | IDEMPOTENCY_KEY_REUSED, VERSION_CONFLICT, INVOICE_INVALID_STATE |

### Printing (27)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/printers` | Sí | Sí | Sí | Sí | – | printing.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/printers` | Sí | Sí | – | – | – | printing.manage | Agente activo de la sucursal | No | No usa Idempotency-Key; el código único evita duplicados | Creación | printer.created | Síncrono, en transacción | Web | CATALOG_CODE_TAKEN, PRINT_AGENT_INACTIVE |
| `GET /branches/{branchId}/printers/{printerId}` | Sí | Sí | Sí | Sí | – | printing.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/printers/{printerId}` | Sí | Sí | – | – | – | printing.manage | Cualquiera | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | printer.updated | Síncrono, en transacción | Web | VERSION_CONFLICT, PRINT_AGENT_INACTIVE |
| `POST /branches/{branchId}/printers/{printerId}/status` | Sí | Sí | – | – | – | printing.manage | Para desactivar: sin trabajos CLAIMED ni PRINTING | Sí | Sí, en efecto | Cambio de estado, con motivo | printer.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, PRINTER_HAS_ACTIVE_JOBS |
| `GET /branches/{branchId}/print-stations` | Sí | Sí | Sí | Sí | – | printing.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/print-stations` | Sí | Sí | – | – | – | printing.manage | Impresora activa | No | No usa Idempotency-Key; el código único evita duplicados | Creación | print_station.created | Síncrono, en transacción | Web | CATALOG_CODE_TAKEN, PRINTER_INACTIVE |
| `GET /branches/{branchId}/print-stations/{printStationId}` | Sí | Sí | Sí | Sí | – | printing.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/print-stations/{printStationId}` | Sí | Sí | – | – | – | printing.manage | Cualquiera | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos | print_station.updated | Síncrono, en transacción | Web | VERSION_CONFLICT, PRINTER_INACTIVE |
| `POST /branches/{branchId}/print-stations/{printStationId}/status` | Sí | Sí | – | – | – | printing.manage | Cualquiera | Sí | Sí, en efecto | Cambio de estado, con motivo | print_station.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `GET /branches/{branchId}/print-agents` | Sí | Sí | – | – | – | printing.manage | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `POST /branches/{branchId}/print-agents` | Sí | Sí | – | – | – | printing.manage | Identidad de servicio activa, autorizada para la sucursal | No | No; una identidad admite un solo agente | Creación | print_agent.created | Síncrono, en transacción | Web | SERVICE_IDENTITY_REVOKED |
| `GET /branches/{branchId}/print-agents/{printAgentId}` | Sí | Sí | – | – | – | printing.manage | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `POST /branches/{branchId}/print-agents/{printAgentId}/status` | Sí | Sí | – | – | – | printing.manage | Cualquiera | Sí | Sí, en efecto | Cambio de estado, con motivo | print_agent.status_changed | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `GET /branches/{branchId}/print-jobs` | Sí | Sí | Sí | Sí | – | printing.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/print-jobs` | Sí | Sí | Sí | – | – | printing.request; CASH_SHIFT_REPORT exige cash.read; TEST exige printing.manage | Origen existente; ruta activa | No | Sí, con Idempotency-Key | Solicitud | No | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, PRINT_ROUTE_NOT_CONFIGURED |
| `GET /branches/{branchId}/print-jobs/{printJobId}` | Sí | Sí | Sí | Sí | – | printing.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/print-jobs/{printJobId}/attempts` | Sí | Sí | Sí | Sí | – | printing.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/print-jobs/{printJobId}/retry` | Sí | Sí | Sí | Sí | – | printing.resolve | FAILED | Sí | Sí, en efecto | Reintento, con impresora si cambia | No | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, PRINT_JOB_INVALID_STATE, PRINT_JOB_UNKNOWN |
| `POST /branches/{branchId}/print-jobs/{printJobId}/resolve` | Sí | Sí | Sí | Sí | – | printing.resolve | UNKNOWN | Sí | Sí, en efecto | Resolución, con actor y motivo | No | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, PRINT_JOB_INVALID_STATE |
| `POST /branches/{branchId}/print-jobs/{printJobId}/cancel` | Sí | Sí | Sí | Sí | – | printing.resolve | PENDING, FAILED o UNKNOWN | Sí | Sí, en efecto | Cancelación, con motivo | No | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, PRINT_JOB_INVALID_STATE |
| `POST /branches/{branchId}/print-jobs/{printJobId}/reprint` | Sí | Sí | Sí | Sí | – | printing.reprint | PRINTED o UNKNOWN | No | Sí, con Idempotency-Key | Reimpresión, con actor y motivo | No | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, PRINT_JOB_INVALID_STATE |
| `GET /print-agent/config` | – | – | – | – | printing-agent | printing-agent | Agente activo | No | Sí, operación de lectura | No | No | Síncrono | printing-agent | – |
| `POST /print-agent/heartbeat` | – | – | – | – | printing-agent | printing-agent | Agente activo | No | Sí, en efecto | No | print_agent.offline lo publica un proceso interno | Síncrono | printing-agent | – |
| `POST /print-agent/jobs/claim` | – | – | – | – | printing-agent | printing-agent | Trabajos PENDING de sus impresoras | No | No; reclamar dos veces entrega trabajos distintos | No | No | Síncrono | printing-agent | – |
| `POST /print-agent/attempts/{attemptId}/start` | – | – | – | – | printing-agent | printing-agent | CLAIMED, con este intento vigente | No | Sí, en efecto | No | No | Síncrono | printing-agent | PRINT_JOB_LEASE_LOST |
| `POST /print-agent/attempts/{attemptId}/result` | – | – | – | – | printing-agent | printing-agent | PRINTING, o UNKNOWN por vencimiento, con este intento vigente | No | Sí, en efecto | No | print_job.failed o print_job.unknown | Síncrono | printing-agent | PRINT_JOB_LEASE_LOST |

### Public QR (3)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /public/context` | – | – | – | – | Visitante con token QR | Token QR válido | Sucursal activa; mesa distinta de INACTIVE | No | Sí, operación de lectura | No | No | Síncrono | QR público | – |
| `GET /public/menu` | – | – | – | – | Visitante con token QR | Token QR válido | Sucursal activa; mesa distinta de INACTIVE | No | Sí, operación de lectura | No | No | Síncrono | QR público | – |
| `POST /public/presence` | – | – | – | – | Visitante con token QR | Token QR válido | Sucursal activa; mesa distinta de INACTIVE | No | Sí, en efecto | No | No | Síncrono | QR público | – |

### Users (8)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `POST /users/{userId}/password-setup-link` | Sí | – | – | – | – | users.manage | Usuario activo | No | No; cada llamada invalida el enlace anterior | Generación, con actor; nunca el enlace | user.password_reset_requested | Síncrono, en transacción | Web | USER_INACTIVE |
| `POST /users/{userId}/deactivate` | Sí | – | – | – | – | users.manage | ACTIVE o INVITED; no el propio; no deja una sucursal sin ADMIN | Sí | Sí, en efecto | Desactivación, con motivo | user.deactivated | Síncrono, en transacción | Web | VERSION_CONFLICT, LAST_BRANCH_ADMIN |
| `POST /users/{userId}/activate` | Sí | – | – | – | – | users.manage | INACTIVE | Sí | Sí, en efecto | Reactivación | user.activated | Síncrono, en transacción | Web | VERSION_CONFLICT |
| `GET /branches/{branchId}/members` | Sí | Sí | – | – | – | members.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `POST /branches/{branchId}/members` | Sí | Sí | – | – | – | members.manage; MANAGER solo WAITER y KITCHEN | Sucursal activa; sin membresía activa | No | No usa Idempotency-Key; el índice único evita duplicados | Alta, con rol | member.created | Síncrono, en transacción | Web | BRANCH_INACTIVE, MEMBERSHIP_ALREADY_EXISTS |
| `GET /branches/{branchId}/members/{userId}` | Sí | Sí | – | – | – | members.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `PATCH /branches/{branchId}/members/{userId}` | Sí | Sí | – | – | – | members.manage; MANAGER solo WAITER y KITCHEN; nunca la propia | Membresía activa; no deja la sucursal sin ADMIN | Sí | No; If-Match rechaza la repetición con versión anterior | Rol anterior y nuevo | member.role_changed | Síncrono, en transacción | Web | VERSION_CONFLICT, LAST_BRANCH_ADMIN, USER_INACTIVE |
| `POST /branches/{branchId}/members/{userId}/revoke` | Sí | Sí | – | – | – | members.manage; MANAGER solo WAITER y KITCHEN; nunca la propia | Membresía activa; no deja la sucursal sin ADMIN | Sí | Sí, en efecto | Revocación, con motivo | member.revoked | Síncrono, en transacción | Web | VERSION_CONFLICT, LAST_BRANCH_ADMIN |

### Customers (5)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `POST /branches/{branchId}/customers` | Sí | Sí | Sí | – | – | customers.create | Sucursal activa | No | Sí, con Idempotency-Key | Creación | No | Síncrono, en transacción | Android, Web | IDEMPOTENCY_KEY_REUSED, BRANCH_INACTIVE |
| `POST /branches/{branchId}/customers/search` | Sí | Sí | Sí | – | – | customers.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/customers/{customerId}` | Sí | Sí | Sí | – | – | customers.read | Con perfil en la sucursal | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `PATCH /branches/{branchId}/customers/{customerId}` | Sí | Sí | Sí | – | – | customers.update | Con perfil en la sucursal; sin anonimizar | Sí | No; If-Match rechaza la repetición con versión anterior | Valores anteriores y nuevos, enmascarados | No | Síncrono, en transacción | Android, Web | VERSION_CONFLICT, CUSTOMER_ANONYMIZED |
| `POST /branches/{branchId}/customers/{customerId}/anonymize` | Sí | – | – | – | – | customers.anonymize | Con perfil en la sucursal; sin anonimizar | Sí | Sí, en efecto | Anonimización, con motivo; sin los datos eliminados | customer.anonymized | Síncrono, en transacción | Web | VERSION_CONFLICT, CUSTOMER_ANONYMIZED |

### Reports (4)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/reports/daily-sales` | Sí | Sí | – | – | – | reports.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `GET /branches/{branchId}/reports/hourly-occupancy` | Sí | Sí | – | – | – | reports.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Android, Web | – |
| `POST /branches/{branchId}/reports/rebuilds` | Sí | – | – | – | – | reports.rebuild | Sin otra reconstrucción en curso en la sucursal | No | Sí, con Idempotency-Key | Solicitud | report.rebuild_requested | Asíncrono: responde 202 y un proceso interno reconstruye | Web | IDEMPOTENCY_KEY_REUSED, REPORT_REBUILD_IN_PROGRESS |
| `GET /branches/{branchId}/reports/rebuilds/{rebuildId}` | Sí | – | – | – | – | reports.rebuild | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |

### Audit (2)

| Operación | ADMIN | MANAGER | WAITER | KITCHEN | Otros | Permiso | Estado requerido | If-Match | Idempotencia | Auditoría | Outbox | Modo | Clientes | Códigos 409 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET /branches/{branchId}/audit-log` | Sí | Sí | – | – | – | audit.read | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |
| `GET /audit-log` | Sí | – | – | – | – | audit.read_global | Ninguno | No | Sí, operación de lectura | No | No | Síncrono | Web | – |

## 3. Permisos consolidados

Roles que invocan cada permiso según el contrato. Las condiciones por rol, como "solo `WAITER` y `KITCHEN`" o "con token", están en la decisión de cada módulo.

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN | Otros |
|---|---|---|---|---|---|
| `audit.read` | Sí | Sí | – | – | – |
| `audit.read_global` | Sí | – | – | – | – |
| `branches.create` | Sí | – | – | – | – |
| `branches.manage_status` | Sí | – | – | – | – |
| `branches.update` | Sí | – | – | – | – |
| `cash.close` | Sí | Sí | – | – | – |
| `cash.manage_registers` | Sí | Sí | – | – | – |
| `cash.move` | Sí | Sí | – | – | – |
| `cash.open` | Sí | Sí | – | – | – |
| `cash.read` | Sí | Sí | Sí | – | – |
| `cash.read_open` | Sí | Sí | Sí | – | – |
| `checks.adjust` | Sí | Sí | Sí | – | Empleado autenticado, con credenciales de un aprobador |
| `checks.cancel` | Sí | Sí | Sí | – | – |
| `checks.create` | Sí | Sí | Sí | – | – |
| `checks.move_units` | Sí | Sí | Sí | – | – |
| `checks.read` | Sí | Sí | Sí | – | – |
| `checks.reopen` | Sí | Sí | Sí | – | Empleado autenticado, con credenciales de un aprobador |
| `checks.request` | Sí | Sí | Sí | – | – |
| `customers.anonymize` | Sí | – | – | – | – |
| `customers.create` | Sí | Sí | Sí | – | – |
| `customers.read` | Sí | Sí | Sí | – | – |
| `customers.update` | Sí | Sí | Sí | – | – |
| `devices.enroll` | Sí | Sí | – | – | – |
| `devices.manage` | Sí | Sí | – | – | – |
| `devices.read` | Sí | Sí | – | – | – |
| `fiscal.manage_issuers` | Sí | – | – | – | – |
| `inventory.adjust` | Sí | Sí | – | Sí | – |
| `inventory.apply_count` | Sí | Sí | – | – | – |
| `inventory.count` | Sí | Sí | – | Sí | – |
| `inventory.food_safety` | Sí | Sí | – | Sí | – |
| `inventory.manage` | Sí | Sí | – | – | – |
| `inventory.override` | – | – | – | – | Empleado autenticado, con credenciales de un aprobador |
| `inventory.read` | Sí | Sí | – | Sí | – |
| `inventory.receive` | Sí | Sí | – | Sí | – |
| `inventory.record_waste` | Sí | Sí | – | Sí | – |
| `invoices.cancel` | Sí | Sí | – | – | – |
| `invoices.create` | Sí | Sí | Sí | – | – |
| `invoices.create_ppd` | Sí | Sí | Sí | – | – |
| `invoices.credit_note` | Sí | Sí | – | – | – |
| `invoices.global` | Sí | Sí | – | – | – |
| `invoices.read` | Sí | Sí | Sí | – | – |
| `invoices.reconcile` | Sí | Sí | – | – | – |
| `invoices.stamp` | Sí | Sí | Sí | – | – |
| `members.manage` | Sí | Sí | – | – | – |
| `members.read` | Sí | Sí | – | – | – |
| `menu.manage` | Sí | Sí | – | – | – |
| `menu.read` | Sí | Sí | Sí | Sí | – |
| `menu.set_availability` | Sí | Sí | Sí | Sí | – |
| `order_units.reassign` | Sí | Sí | – | Sí | – |
| `order_units.void` | – | – | – | – | Empleado autenticado, con credenciales de un aprobador |
| `orders.create` | Sí | Sí | Sí | – | – |
| `orders.read` | Sí | Sí | Sí | Sí | – |
| `orders.update` | Sí | Sí | Sí | – | – |
| `payments.create` | Sí | Sí | Sí | – | – |
| `payments.read` | Sí | Sí | Sí | – | – |
| `payments.reconcile` | Sí | Sí | – | – | – |
| `payments.record_result` | Sí | Sí | Sí | – | – |
| `printing.manage` | Sí | Sí | – | – | – |
| `printing.read` | Sí | Sí | Sí | Sí | – |
| `printing.reprint` | Sí | Sí | Sí | Sí | – |
| `printing.request` | Sí | Sí | Sí | – | – |
| `printing.resolve` | Sí | Sí | Sí | Sí | – |
| `recipes.manage` | Sí | Sí | – | – | – |
| `recipes.read` | Sí | Sí | – | Sí | – |
| `refunds.create` | Sí | Sí | – | – | – |
| `refunds.read` | Sí | Sí | – | – | – |
| `refunds.reconcile` | Sí | Sí | – | – | – |
| `refunds.record_result` | Sí | Sí | – | – | – |
| `reports.read` | Sí | Sí | – | – | – |
| `reports.rebuild` | Sí | – | – | – | – |
| `reservations.arrive` | Sí | Sí | Sí | – | – |
| `reservations.availability` | Sí | Sí | Sí | – | reservation-ai |
| `reservations.cancel` | Sí | Sí | Sí | – | reservation-ai |
| `reservations.check_in` | Sí | Sí | Sí | – | – |
| `reservations.confirm` | Sí | Sí | Sí | – | reservation-ai |
| `reservations.create` | Sí | Sí | Sí | – | reservation-ai |
| `reservations.lookup` | Sí | Sí | Sí | – | reservation-ai |
| `reservations.mark_no_show` | Sí | Sí | Sí | – | – |
| `reservations.override_priority` | Sí | Sí | Sí | – | – |
| `reservations.policy` | – | – | – | – | reservation-ai |
| `reservations.read` | Sí | Sí | Sí | – | – |
| `reservations.update` | Sí | Sí | Sí | – | – |
| `service_identities.manage` | Sí | – | – | – | – |
| `service_identities.read_self` | – | – | – | – | printing-agent, reservation-ai |
| `table_sessions.cancel` | Sí | Sí | – | – | – |
| `table_sessions.close` | Sí | Sí | Sí | – | – |
| `table_sessions.open` | Sí | Sí | Sí | – | – |
| `table_sessions.read` | Sí | Sí | Sí | Sí | – |
| `table_sessions.update` | Sí | Sí | Sí | – | – |
| `tables.clean` | Sí | Sí | Sí | – | – |
| `tables.manage` | Sí | Sí | – | – | – |
| `tables.qr_read` | Sí | Sí | – | – | – |
| `tables.qr_rotate` | Sí | Sí | – | – | – |
| `tables.read` | Sí | Sí | Sí | Sí | – |
| `users.manage` | Sí | – | – | – | – |
| `users.sessions.revoke` | Sí | – | – | – | – |
