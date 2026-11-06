# Módulos restantes: Users, Customers, Reports y Audit

**Estado:** APROBADAS (U-1 a U-5, CU-1 a CU-4, RP-1 a RP-3, AU-1, AU-2, RT-1 y X-1)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

La lista de la sección 35 del documento maestro termina en IA, pero al revisar módulos y colecciones contra el contrato quedaron huecos. El más grave: **no había forma de dar de alta a un empleado ni de cambiar una contraseña.** D-08 y D-12 definen usuarios y membresías, y D-36 dejó la recuperación de contraseña para Authentication, pero ninguna tarea los incluía.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **U-1** | Alta de empleados | Por sucursal: se crea la membresía, y también el usuario si su correo no existe | Alta global separada de la membresía |
| **U-2** | Primera contraseña y recuperación | Enlace de un solo uso que el gerente entrega a mano (72 h); recuperación por correo (60 min) | Contraseña temporal |
| **U-3** | Permisos | Un rol por membresía y matriz fija en código, sin excepciones por usuario en v1 | Permisos editables por usuario |
| **U-4** | Quién gestiona a quién | `ADMIN` gestiona todo; `MANAGER` solo `WAITER` y `KITCHEN`; nadie se cambia a sí mismo; siempre queda un `ADMIN` por sucursal | Solo `ADMIN` |
| **U-5** | Bajas | Membresía revocada o usuario `INACTIVE`, sin borrar, porque el historial los referencia | Borrado físico |
| **CU-1** | Alcance de clientes | Datos básicos y perfil por sucursal; sin datos fiscales guardados | No implementar clientes en v1 |
| **CU-2** | Visibilidad (D-09) | Cada sucursal ve solo clientes con perfil en ella. Mismo teléfono no une identidades; unir queda fuera de v1 | Búsqueda global |
| **CU-3** | Búsqueda | Por `POST`, para que el teléfono no aparezca en URLs | `GET` con filtro |
| **CU-4** | Derechos del titular | Anonimizar, solo `ADMIN`, conservando lo fiscal y financiero | Borrado físico |
| **RP-1** | Alcance de reportes | Solo las dos proyecciones del documento: ventas diarias y ocupación por hora | Agregar ventas por producto |
| **RP-2** | Definición de venta | Por `business_date` del pago: confirmado menos reembolsado. Propinas, cortesías y facturado, aparte | Por fecha de la cuenta |
| **RP-3** | Actualización | Asíncrona desde outbox, con `asOf`; reconstrucción manual por `ADMIN` | Calcular en cada lectura |
| **AU-1** | Consulta de auditoría | Solo lectura; `ADMIN` y `MANAGER` por sucursal, y lo global solo `ADMIN`; rango máximo de 31 días | Solo `ADMIN` |
| **AU-2** | Eventos de outbox | Internos, sin API en v1; se operan por consola | Panel de eventos |
| **RT-1** | Tiempo real | Consulta periódica con `ETag` en v1; sin canal en el contrato | WebSockets o SSE |
| **X-1** | `loyalty_ledger` y `calls` | Fuera de v1: el documento las nombra sin ninguna regla | Definirlas ahora |

## 2. Users

**Alta (U-1)**

- `POST /branches/{id}/members` recibe correo, nombre y rol. Si el correo ya existe, agrega la membresía y no modifica nombre ni contraseña.
- Devuelve `setupUrl` solo si esta petición creó al usuario (Revisión de seguridad, hallazgo 11).

**Contraseñas (U-2)**

| Flujo | Cómo | Efecto en sesiones |
|---|---|---|
| Primera vez | Enlace mostrado al gerente, 72 h | Ninguno |
| Reinicio por un administrador | Enlace nuevo, 72 h | Revoca todas |
| Olvidé mi contraseña | Correo, 60 min; siempre responde `204` | Revoca todas |
| Cambio propio | Exige la contraseña actual | Revoca las demás (D-36) |

- El token es de un solo uso y se guarda como hash.
- Mínimo 12 caracteres y rechazo de contraseñas filtradas conocidas.
- Se usa enlace en vez de correo porque muchos empleados de piso no revisan uno de trabajo, y así el alta no depende de un proveedor de correo.
- **El riesgo:** quien da de alta ve el enlace y podría usarlo. Queda en auditoría, y el empleado lo notaría al no poder entrar.
- **Requisito de D-36:** el acceso exige correo. **Si hay empleados sin correo, hace falta otro identificador.** Conviene decidirlo antes de implementar.

**Permisos (U-3)**

La matriz de cada módulo es la fuente, y al terminar quedará consolidada en la tarea 27. BAR sigue siendo una estación, no un rol (D-12).

**Bajas (U-5)**

- Revocar una membresía tiene efecto en la siguiente petición, porque cada una revalida membresía y permiso (D-12).
- Desactivar un usuario es global y revoca sus sesiones (`USER_DISABLED`). Sigue la misma regla de A-10: basta ser `ADMIN` en una sucursal donde el usuario tenga membresía.

## 3. Customers

- **`customers`:** nombre, teléfono y correo opcional. **`customer_branch_profiles`:** notas, preferencias y número de visitas.
- Una reserva puede enlazar `customerId`, y mantiene su propia copia (R-11).
- Registrar el mismo teléfono en otra sucursal crea otra identidad, para no revelar que esa persona es cliente de otra sucursal.
- Los datos fiscales se capturan en cada factura (F-3). Guardarlos para reutilizarlos se puede agregar después.
- **Anonimizar (CU-4):** reemplaza nombre, teléfono, correo y notas, y también la copia en reservas pasadas. Las facturas no cambian, por su obligación de conservación. **Hay que validar el alcance con el área legal.**

## 4. Reports

| Reporte | Contenido |
|---|---|
| `daily-sales` | Ventas netas, reembolsos, propinas, cortesías, cuentas, personas, total por método, facturado |
| `hourly-occupancy` | Sesiones abiertas, mesas ocupadas y personas, por hora |

- Son reconstruibles (principio 2), y una reconstrucción por rango no toca datos de origen.
- Cada lectura es por sucursal (D-26); comparar sucursales queda del lado del cliente.
- Sin costo de lo vendido, por I-9.
- Para caja se usa el resumen congelado (CA-6), sin duplicarlo aquí.

## 5. Audit

- `audit_log` no tiene operaciones de escritura en la API.
- Nunca incluye secretos, y los valores sensibles se enmascaran al escribirse, no al leerse.
- `MANAGER` ve lo de su sucursal, incluso sus propias acciones. Si se necesita que no vea las de otros gerentes, conviene dejarlo solo a `ADMIN`.

## 6. Tiempo real (RT-1)

El principio 6 dice que tiempo real es un mecanismo de actualización y no la fuente de verdad, pero ningún documento define un canal. Recomiendo que en v1 cocina y piso consulten cada pocos segundos con `ETag`, porque funciona igual en Android y Web, y sobrevive a cortes de red. **El costo es una demora de segundos y más peticiones.** Falta medirlo con carga (tarea 37). Si no alcanza, un canal se puede agregar sin cambiar el contrato actual.

## 7. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `members.read` | Sí | Sí | No | No |
| `members.manage` | Sí | Solo `WAITER` y `KITCHEN` | No | No |
| `users.manage` (desactivar, reiniciar contraseña) | Sí | No | No | No |
| `customers.read`, `customers.create`, `customers.update` | Sí | Sí | Sí | No |
| `customers.anonymize` | Sí | No | No | No |
| `reports.read` | Sí | Sí | No | No |
| `reports.rebuild` | Sí | No | No | No |
| `audit.read` | Sí | Sí | No | No |
| `audit.read_global` | Sí | No | No | No |

`MANAGER` no reinicia contraseñas con `users.manage`, porque un usuario puede tener rol más alto en otra sucursal.

## 8. Cambios a contratos ya aprobados

| Módulo | Cambio | Origen |
|---|---|---|
| Authentication | Cambio, recuperación y reinicio de contraseña | U-2 |
| Reservations | `customerId` debe tener perfil en la sucursal | CU-2 |

## 9. Cambios al modelo físico

- **`users`:** `uuid`, `email`, `name`, `password_hash`, `normalized_email`, `status`, `version`. **Índice:** `unique(normalized_email)`, el del documento maestro.
- **`user_branch_memberships`:** `uuid`, `user_id`, `branch_id`, `role`, `status`, `version`. **Índice:** `unique(user_id, branch_id)`, el del documento maestro; volver a dar de alta reactiva el mismo documento, y `active` en la API se deriva de `status`.
- **Colección nueva `password_setup_tokens`:** `user_id`, `token_hash`, `purpose`, `expires_at`, `used_at`. **Índices:** `unique(token_hash)` y TTL.
- **`customers`:** `uuid`, `name`, `phone`, `email`, `anonymized_at`, `version`. **Índice:** `(phone)`, no único. La idempotencia usa `idempotency_records`.
- **`customer_branch_profiles`:** `notes`, `preferences`, `visit_count`, `last_visit_at`, además de lo existente.
- **`daily_sales` y `hourly_occupancy`:** según la sección 4, más `as_of`. **Colección nueva `report_rebuilds`:** `uuid`, `branch_id`, `from`, `to`, `status`.
- **Fuera de v1:** `loyalty_ledger` y `calls`.
- **Códigos de error nuevos:** `LAST_BRANCH_ADMIN`, `MEMBERSHIP_ALREADY_EXISTS`, `USER_INACTIVE`, `PASSWORD_TOKEN_INVALID`, `CUSTOMER_ANONYMIZED`, `REPORT_REBUILD_IN_PROGRESS`. Contraseña débil o actual incorrecta, y rango de auditoría excedido, responden `422 VALIDATION_FAILED` (sección 30).
