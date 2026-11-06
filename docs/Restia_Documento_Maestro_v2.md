# RESTIA

## Documento maestro de arquitectura, reglas de negocio, modelo físico y contrato OpenAPI

**Proyecto:** Restia --- Ecosistema Integral de Gestión Restaurantera
Multiplataforma\
**Estado:** Diseño cerrado y contrato OpenAPI completo; sin implementación\
**Backend:** Laravel 13 (SEC-2) + MongoDB Replica Set\
**Cliente empleado:** Android\
**Back-office:** Web\
**Cliente público:** Web mediante QR, sin instalación\
**Arquitectura:** Monolito modular con API central\
**Última consolidación:** 2026-11-02
**Versión:** 2, copia para revisión; no reemplaza a la original hasta que se apruebe

------------------------------------------------------------------------

## 1. Propósito

Este documento consolida el trabajo realizado hasta ahora para Restia,
incluyendo arquitectura, alcance, reglas de negocio, máquinas de
estados, modelo físico MongoDB, índices, seguridad, concurrencia,
idempotencia, auditoría, outbox y el contrato OpenAPI.

La intención es utilizarlo como documento maestro de contexto para
continuar el diseño e implementación sin perder decisiones previamente
cerradas.

Este documento resume. El detalle está en los documentos de decisiones
(sección 5) y en `openapi/restia-api.yaml`. Ante una diferencia, manda el
contrato para la API y la decisión del módulo para la regla de negocio.

------------------------------------------------------------------------

# 2. Arquitectura aprobada

Restia elimina la aplicación del cliente y Google Wallet. El cliente
accede mediante una página web pública del restaurante, alcanzada por el
QR de la mesa.

Componentes:

-   **Backend/API:** Laravel 13 (SEC-2) + MongoDB Replica Set.
-   **Android:** aplicación para empleados.
-   **Web:** panel para administración, gerencia, cocina y barra.
-   **QR público:** página web mobile-first/adaptativa para clientes.
-   **IA de reservaciones:** capa conversacional que consume la API.
-   **Impresión:** agentes y estaciones de impresión.

El backend es la fuente de verdad.

Los clientes no deben duplicar reglas críticas de negocio.

------------------------------------------------------------------------

# 3. Arquitectura modular

Módulos conceptuales:

-   Authentication / Identity
-   Branches
-   Tables / Sessions
-   Reservations
-   Menu
-   Orders
-   Checks
-   Payments
-   Cash
-   Inventory
-   Fiscal
-   Printing
-   Reports
-   Public QR
-   Customers
-   Users
-   Audit
-   Outbox

La arquitectura es un monolito modular, no microservicios.

------------------------------------------------------------------------

# 4. Principios

1.  MongoDB contiene el estado operativo persistente.
2.  Las proyecciones deben poder reconstruirse.
3.  Datos financieros, fiscales, de inventario y auditoría no se
    eliminan físicamente.
4.  La concurrencia requiere validación de estado y versión; los índices
    no sustituyen las reglas.
5.  Las operaciones críticas utilizan idempotencia.
6.  Realtime es un mecanismo de actualización, no la fuente de verdad.
7.  Toda operación sigue, conceptualmente:

``` text
request
→ authentication
→ authorization
→ current-state validation
→ concurrency/version validation
→ domain validation
→ transaction
→ source of truth
→ audit
→ outbox
→ commit
→ async processing
```

------------------------------------------------------------------------

# 5. Decisiones D-07 a D-18

## D-07 --- Comandas

El modelo canónico es:

``` text
orders
  └── order_items
        └── order_item_units
```

`orders.items[]` no es fuente operativa.

## D-08 --- Usuarios

Identidad global de usuario con acceso autorizado a múltiples sucursales
mediante `user_branch_memberships`.

## D-09 --- Clientes

Identidad global de cliente. El teléfono no es identidad única. La
relación con sucursal se mantiene en `customer_branch_profiles`.

## D-10 --- Caja

Máximo un turno `OPEN` o `CLOSING` por caja registradora. Una sucursal
puede tener múltiples cajas.

## D-11 --- Inventario

Overrides requieren autorización, idealmente de un aprobador distinto al
solicitante. Nunca permiten bypass de bloqueos de seguridad alimentaria.

## D-12 --- Autorización

RBAC por sucursal + permisos + reglas contextuales.

Flujo:

``` text
usuario
→ membership
→ sucursal
→ rol
→ permiso
→ contexto
→ allow/deny
```

Roles iniciales:

``` text
ADMIN
MANAGER
WAITER
KITCHEN
```

BAR es inicialmente una estación, no necesariamente un rol.

## D-13 --- Producción

Productos pueden utilizar varias estaciones mediante
`product_preparation_routes`. Las recetas son versionadas e
históricamente inmutables.

## D-14 --- Reservaciones

Reserva separada de sesión. Disponibilidad basada en sucursal,
intervalo, número de personas, capacidad, mesas compatibles, reservas y
ocupación.

`start_at` y `end_at` son obligatorios.

## D-15 --- Fiscal

Ciclo comercial y fiscal separados.

Comercial:

``` text
checks
payments
```

Fiscal:

``` text
invoices
```

CFDI 4.0. PUE/PPD separados de forma de pago. PPD contempla complementos
de pago.

## D-16 --- Cuentas

`order_item_unit` es la unidad indivisible de cobro. Unidades no pagadas
pueden moverse entre cuentas cerrando una asignación y creando otra.
Unidades pagadas son históricamente inmutables.

## D-17 --- Nuevas comandas

Una cuenta `REQUESTED` no recibe nuevas unidades automáticamente. Nuevos
consumos generan una nueva cuenta `OPEN`.

Una cuenta `REQUESTED` sin pagos confirmados puede reabrirse
explícitamente y con autorización. Con pagos confirmados no se reabre
para introducir nuevos consumos.

## D-18 --- Reservas sin mesa

Una reserva confirmada cuyo cliente llega pero no puede ser sentado pasa
a:

``` text
ARRIVED_WAITING
```

No se crea sesión ni asignación ficticia. Una vez disponible una mesa
compatible:

``` text
ARRIVED_WAITING → CHECKED_IN
```

Después de registrar llegada no se convierte automáticamente en
`NO_SHOW`.

## D-36 --- Ciclo de vida de credenciales

Aprobada (A-1 a A-10), condicionada a la prueba técnica. Detalle en
`D-36_Ciclo_de_vida_de_credenciales.md`.

## D-37 --- Aprobaciones

Aprobada. Un token de aprobación de un solo uso permite una acción sin
permiso directo. Detalle en `Decisiones_Orders.md`.

## Decisiones por módulo

  Módulo                Decisiones              Documento
  --------------------- ----------------------- -----------------------------------
  Authentication        A-1 a A-10              D-36_Ciclo_de_vida_de_credenciales
  Branches              B-1 a B-7               Decisiones_Branches
  Tables / Sessions     T-1 a T-9               Decisiones_Tables_Sessions
  Reservations          R-1 a R-12              Decisiones_Reservations
  Menu                  M-1 a M-11              Decisiones_Menu
  Orders                D-37, O-1 a O-11        Decisiones_Orders
  Checks                C-1 a C-10              Decisiones_Checks
  Payments              P-1 a P-11              Decisiones_Payments
  Inventory             I-1 a I-11              Decisiones_Inventory
  Cash                  CA-1 a CA-13            Decisiones_Cash
  Fiscal                F-1 a F-15              Decisiones_Fiscal (validación contable pendiente)
  Printing              PR-1 a PR-13            Decisiones_Printing
  Public QR             QR-1 a QR-12            Decisiones_Public_QR
  IA                    IA-1 a IA-12            Decisiones_IA
  Users, Customers,     U, CU, RP, AU, RT-1,    Decisiones_Modulos_Restantes
  Reports, Audit        X-1
  Seguridad             SEC-1, SEC-2            Revision_Seguridad
  Consistencia          CON-1                   Revision_Consistencia

------------------------------------------------------------------------

# 6. Mesas y sesiones

Estado operativo derivado:

``` text
LIBRE
→ POSIBLE_OCUPACION
→ OCUPADA_SIN_COMANDA
→ ESPERANDO_COMIDA
→ CONSUMIENDO
→ CUENTA_SOLICITADA
→ POR_LIMPIAR
→ LIBRE
```

Los estados pueden saltarse cuando el flujo real lo permite.

`tables.status` es administrativo:

``` text
ACTIVE
INACTIVE
MAINTENANCE
```

y no representa ocupación.

La apertura de sesión modifica transaccionalmente:

-   `table_sessions`
-   `table_assignments`
-   `audit_log`
-   `outbox_events`

Separar mesas no separa automáticamente cuentas.

`table_presence_signals` representa actividad reciente y no ocupación.

La limpieza utiliza `session_membership_version`; una confirmación
obsoleta produce conflicto.

------------------------------------------------------------------------

# 7. Comandas y preparación

Estados de `order_item_unit`:

``` text
PENDING
PREPARING
READY
DELIVERED
CANCELLED
VOIDED
```

Flujo normal:

``` text
PENDING → PREPARING → READY → DELIVERED
```

Cancelación:

``` text
PENDING → CANCELLED
PREPARING → VOIDED
```

El `VOID` requiere autorización y motivo.

El consumo de inventario sucede en `PREPARING`.

`orders.status` es un resumen/proyección, no la fuente operativa
principal.

------------------------------------------------------------------------

# 8. Inventario

Fuente de verdad:

``` text
inventory_movements
```

`ingredients.stock_actual` es proyección.

Todo ajuste genera movimiento y auditoría.

El consumo es idempotente por unidad, ingrediente y versión de receta.

Si una unidad ya consumió inventario y luego se anula, el inventario no
se restaura automáticamente; puede registrarse desperdicio u otro
movimiento compensatorio autorizado.

------------------------------------------------------------------------

# 9. Cuentas y pagos

Estados de cuenta:

``` text
OPEN
REQUESTED
PARTIALLY_PAID
PAID
CANCELLED
REFUNDED
```

`REQUESTED != PAID`.

`REFUNDED` indica que se reembolsó todo lo pagado (P-8).

Una cuenta se marca `PAID` cuando los pagos confirmados cubren el total
válido y no existen operaciones financieras sin resolver.

Estados de pago:

``` text
PENDING
CONFIRMED
FAILED
CANCELLED
UNKNOWN
```

`UNKNOWN` requiere reconciliación antes de un nuevo intento.

Reembolsos completos están contemplados en v1. Un reembolso no restaura
automáticamente inventario.

------------------------------------------------------------------------

# 10. Reservaciones

Estados:

``` text
PENDING
CONFIRMED
ARRIVED_WAITING
CHECKED_IN
COMPLETED
CANCELLED
NO_SHOW
```

Confirmar una reserva requiere volver a validar disponibilidad bajo
concurrencia.

Prioridad de reservas esperando:

1.  `start_at` más antiguo.
2.  `arrival_registered_at` en empate.
3.  Siempre respetando capacidad y compatibilidad.

Una reserva no puede desalojar automáticamente a un cliente ya sentado.

`end_at` representa planificación; no expulsa automáticamente a
clientes.

------------------------------------------------------------------------

# 11. IA para reservaciones

La IA es únicamente una interfaz conversacional.

Puede interpretar solicitudes y consumir endpoints autorizados, pero no
decide:

-   disponibilidad;
-   asignación;
-   confirmación;
-   cancelación;
-   reglas comerciales.

La API es la fuente de verdad.

La IA se trata como un cliente no confiable (IA-2). `confirm` y `cancel`
le exigen código de confirmación y teléfono (IA-4), no recibe `notes` ni
mesas (IA-5), y está apagada por omisión en cada sucursal (IA-7).

------------------------------------------------------------------------

# 12. Caja

Estados:

``` text
OPEN
CLOSING
CLOSED
```

Debe existir como máximo un turno `OPEN`/`CLOSING` por caja.

No se permite cerrar con pagos o reembolsos en `PENDING` o `UNKNOWN`
(CA-4). Una diferencia de efectivo exige motivo, y si supera la tolerancia
de la caja queda marcada para revisión (CA-8).

Los depósitos y retiros se registran en `cash_movements` (CA-5).

------------------------------------------------------------------------

# 13. Fiscal

Colecciones principales:

``` text
fiscal_issuers
invoices
invoice_batches
invoice_source_allocations
invoice_events
invoice_payment_complements
```

Estados:

``` text
DRAFT
DISCARDED
PENDING_STAMP
STAMPING
STAMPED
STAMP_FAILED
CANCELLATION_REQUESTED
CANCELLATION_IN_PROGRESS
CANCELLED
CANCELLATION_REJECTED
UNKNOWN
```

`DISCARDED` aplica a `DRAFT` y `STAMP_FAILED`, que nunca existieron ante el
SAT (F-10).

La cancelación fiscal es un proceso, no una eliminación.

`invoice_events` es append-only.

Las operaciones con PAC deben reconciliarse si el resultado externo es
desconocido.

------------------------------------------------------------------------

# 14. Impresión

Colecciones:

``` text
printers
print_stations
print_agents
print_jobs
print_job_attempts
```

Estados:

``` text
PENDING
CLAIMED
PRINTING
PRINTED
FAILED
UNKNOWN
CANCELLED
```

Un `UNKNOWN` no se reintenta automáticamente.

Una reimpresión crea un nuevo trabajo vinculado al original, con actor y
motivo.

------------------------------------------------------------------------

# 15. Auditoría y eventos

`audit_log` es append-only.

Índices:

``` text
(branch_id, entity_type, entity_id, created_at)
(branch_id, actor_id, created_at)
```

No se almacenan secretos.

`outbox_events` permite publicar/procesar eventos después del commit.

Índices:

``` text
(status, next_attempt_at)
(branch_id, source_type, source_id)
```

------------------------------------------------------------------------

# 16. Modelo físico MongoDB

Colecciones:

``` text
branches
users
user_branch_memberships
devices
auth_sessions
device_enrollment_codes
personal_access_tokens
service_identities
password_setup_tokens
approval_grants

tables
table_sessions
table_assignments
table_presence_signals
table_cleaning_confirmations

orders
order_items
order_item_units

checks
check_allocations
check_adjustments
payments
refunds
cash_registers
cash_shifts
cash_movements

products
modifiers
recipes
stations
product_preparation_routes

ingredients
inventory_movements
inventory_receipts
inventory_counts

reservations
customers
customer_branch_profiles

fiscal_issuers
invoices
invoice_batches
invoice_source_allocations
invoice_events
invoice_payment_complements
invoice_counters

printers
print_stations
print_agents
print_jobs
print_job_attempts

audit_log
outbox_events
idempotency_records

daily_sales
hourly_occupancy
report_rebuilds
```

`loyalty_ledger` y `calls` quedan fuera de v1, porque no tienen reglas
definidas (X-1).

Los campos de cada colección están en la decisión de su módulo.

Reglas generales:

-   `_id` interno: `ObjectId`.
-   ID público: UUID.
-   timestamps: UTC.
-   `business_date`: fecha operacional.
-   dinero y cantidades de inventario: `Decimal128`, conservando la escala.
-   todo documento lleva `uuid`, `created_at` y `updated_at`, y `branch_id` si
    pertenece a una sucursal.
-   API: strings decimales.
-   `version`: entero.
-   recursos críticos no se eliminan físicamente.

------------------------------------------------------------------------

# 17. Índices principales

## Branches

``` text
unique(uuid)
status
```

## Users

``` text
unique(normalized_email)
unique(uuid)
status
```

## Memberships

``` text
unique(user_id, branch_id)
(branch_id, status)
(user_id, status)
```

## Devices

``` text
unique(device_uuid)
(branch_id, status, type)
```

## Tables

``` text
unique(branch_id, number)
status
unique(qr_token)
```

## Sessions

``` text
(branch_id, status, created_at)
```

## Assignments

``` text
partial unique(branch_id, table_id) where active=true
(branch_id, session_id, active)
(branch_id, table_id, active)
```

## Presence

``` text
(branch_id, table_id, expires_at)
TTL
```

## Cleaning

``` text
unique(branch_id, session_id, table_id, session_membership_version)
(branch_id, session_id, status)
```

## Orders

``` text
unique(branch_id, client_order_uuid)
(branch_id, session_id, created_at)
(branch_id, status, created_at)
```

## Order items

``` text
(branch_id, order_id)
```

## Order units

``` text
unique(branch_id, order_id, order_item_id, unit_number)
(branch_id, order_item_id, status)
(branch_id, status, station_id, created_at)
```

## Checks

``` text
(branch_id, session_id, status)
(branch_id, status, created_at)
(branch_id, business_date, status)
unique(uuid)
unique(branch_id, session_id, number)
```

## Allocations

``` text
partial unique(branch_id, order_item_unit_id) where active=true
(branch_id, check_id, active)
```

## Payments

``` text
unique(branch_id, idempotency_key)
(branch_id, check_id, status)
(branch_id, cash_shift_id, status)
(branch_id, status, created_at)
```

## Cash shifts

``` text
(branch_id, cash_register_id, status)
(branch_id, status, opened_at)
partial unique(branch_id, cash_register_id) where active=true
(branch_id, business_date)
partial (branch_id) where review_required=true
```

## Reservations

``` text
unique(branch_id, idempotency_key)
(branch_id, status, start_at)
(branch_id, start_at, status)
```

La superposición requiere lógica de dominio/concurrencia.

## Inventory movements

``` text
unique(branch_id, idempotency_key)
(branch_id, ingredient_id, created_at)
(branch_id, source_type, source_id)
```

## Customers

Identidad global; teléfono no es unique identity.

## Customer branch profiles

``` text
unique(customer_id, branch_id)
(branch_id, updated_at)
```

## Fiscal

``` text
(branch_id, status, created_at)
(branch_id, check_id)
unique(fiscal_uuid) cuando exista
```

## Print jobs

``` text
unique(idempotency_key)
(branch_id, printer_id, status, priority, created_at)
(branch_id, status, created_at)
(source_type, source_id)
(claimed_agent_id)
```

## Projections

``` text
unique(branch_id, business_date)
unique(branch_id, business_date, hour)
```

## Idempotency records

``` text
unique(branch_id, key)
TTL (expires_at)
```

## Índices adicionales

Son estimados y se deben confirmar con `explain` y carga real.

``` text
orders: (branch_id, business_date, status)
table_sessions: (branch_id, business_date, status)
invoices: partial (branch_id) where credit_note_required=true
invoices: (branch_id, batch_id)
invoice_payment_complements: (invoice_id), (status, due_at)
inventory_movements: (branch_id, type, created_at)
inventory_movements: partial (branch_id, created_at) where self_approved=true
refunds: (branch_id, payment_id), (branch_id, check_id), (branch_id, cash_shift_id, status)
audit_log: (branch_id, created_at)
reservations: (branch_id, customer_snapshot.phone, status)
```

Los índices propios de cada módulo están en su decisión.

------------------------------------------------------------------------

# 18. OpenAPI aprobado

Se aprobaron:

-   **D-19:** OpenAPI 3.0.3.
-   **D-20:** base `/api/v1`.
-   **D-21:** JSON sobre HTTPS.
-   **D-22:** UUID públicos.
-   **D-23:** ISO 8601 / UTC.
-   **D-24:** dinero como decimal string.
-   **D-25:** errores uniformes.
-   **D-26:** contexto de sucursal explícito.
-   **D-27:** `Idempotency-Key`.
-   **D-28:** `version` + `ETag`/`If-Match`.

------------------------------------------------------------------------

# 19. Autenticación OpenAPI

Consumidores:

  Consumidor   Mecanismo
  ------------ -----------------------------------
  Web          Laravel Sanctum + cookie HttpOnly
  Android      Bearer token
  QR público   Token QR opaco
  IA           Service Bearer Token
  Impresión    Service Bearer Token

La autenticación identifica al actor; la autorización decide qué puede
hacer.

------------------------------------------------------------------------

# 20. Web / Sanctum

Flujo:

``` text
GET /sanctum/csrf-cookie
→
POST /api/v1/auth/login
→
sesión autenticada
→
cookie HttpOnly
```

Las operaciones Web que modifican estado requieren CSRF.

------------------------------------------------------------------------

# 21. Android

Usa:

``` http
Authorization: Bearer <token>
```

CSRF no aplica al modelo Bearer.

Un token válido no implica autorización.

------------------------------------------------------------------------

# 22. QR público

Utiliza token opaco.

No expone:

-   empleados;
-   inventario;
-   pagos;
-   clientes ajenos;
-   ObjectIds;
-   estructura interna.

Solo información necesaria para la experiencia pública.

En v1, el cliente solo ve el menú y la página registra presencia. No ve la
cuenta ni comandas, ni comanda, porque un QR impreso no prueba que la persona
esté en la mesa (QR-1, QR-2).

------------------------------------------------------------------------

# 23. Servicios internos

Usan identidades técnicas con mínimo privilegio.

Ejemplos:

``` text
reservation-ai
printing-agent
```

La IA no debe tener permisos administrativos innecesarios.

Sus tokens se emiten, rotan y revocan por `/service-identities` (PR-12).

------------------------------------------------------------------------

# 24. Autorización

La autorización formal es:

``` text
user
→ branch membership
→ role
→ permission
→ contextual rules
→ resource state
→ allow/deny
```

Ejemplo:

`payments.create` no permite automáticamente cobrar cualquier cuenta.

------------------------------------------------------------------------

# 25. CSRF

Aplica a sesiones Web basadas en cookie.

No aplica a Android Bearer.

Métodos protegidos:

``` text
POST
PUT
PATCH
DELETE
```

Las lecturas no modificativas no requieren CSRF como protección de la
operación.

------------------------------------------------------------------------

# 26. Contrato de sesión

Endpoint conceptual:

``` http
GET /api/v1/auth/me
```

Debe devolver:

-   usuario;
-   memberships;
-   sucursales;
-   roles;
-   permisos.

El frontend puede utilizar permisos para UX, pero el backend siempre
valida.

------------------------------------------------------------------------

# 27. Contratos compartidos

## UUID

``` yaml
Uuid:
  type: string
  format: uuid
```

## Timestamp

``` yaml
Timestamp:
  type: string
  format: date-time
```

## BusinessDate

``` yaml
BusinessDate:
  type: string
  format: date
```

## Money

``` yaml
Money:
  type: object
  required:
    - amount
    - currency
  properties:
    amount:
      type: string
      pattern: '^-?\d+(\.\d{1,4})?$'
    currency:
      type: string
      pattern: '^[A-Z]{3}$'
```

En peticiones se usan `MoneyPositive` (mayor que 0) y `MoneyNonNegative`
(mayor o igual a 0), con hasta 12 enteros. `Money`, que admite signo, queda
para respuestas y para `priceDelta`.

## Pagination

Máximo:

``` text
100
```

registros por página.

## Meta

Incluye:

``` text
requestId
pagination
```

cuando corresponda.

------------------------------------------------------------------------

# 28. Request ID

Header:

``` http
X-Request-ID
```

Si no existe, el servidor lo genera.

Debe aparecer en respuestas y errores.

------------------------------------------------------------------------

# 29. ErrorResponse

``` yaml
ErrorResponse:
  type: object
  required:
    - error
  properties:
    error:
      type: object
      required:
        - code
        - message
        - requestId
      properties:
        code:
          type: string
        message:
          type: string
        details:
          type: object
          additionalProperties: true
        requestId:
          $ref: '#/components/schemas/Uuid'
```

Ejemplo:

``` json
{
  "error": {
    "code": "VERSION_CONFLICT",
    "message": "El recurso fue modificado por otra operación.",
    "details": {
      "expectedVersion": 8,
      "currentVersion": 9
    },
    "requestId": "..."
  }
}
```

------------------------------------------------------------------------

# 30. Códigos HTTP

``` text
200 OK
201 Created
202 Accepted
204 No Content
304 Not Modified
400 Bad Request
401 Unauthorized
403 Forbidden
404 Not Found
409 Conflict
422 Unprocessable Entity
429 Too Many Requests
500 Internal Server Error
503 Service Unavailable
```

`422` = validación.

`409` = conflicto con estado/concurrencia/idempotencia.

------------------------------------------------------------------------

# 31. Catálogo de errores

El catálogo vigente está en `Catalogo_Errores.md`, generado desde el
contrato, con 102 códigos. `x-restia.conflictCodes` indica los códigos `409`
exactos de cada operación.

Códigos del catálogo inicial que no se usan:

``` text
AUTHENTICATION_EXPIRED
INVENTORY_OVERRIDE_REQUIRED
ORDER_INVALID_STATE
```

------------------------------------------------------------------------

# 32. Idempotencia OpenAPI

Header:

``` http
Idempotency-Key: <uuid>
```

Obligatorio para operaciones críticas:

-   comandas;
-   pagos;
-   reembolsos;
-   inventario;
-   reservaciones;
-   caja;
-   facturación;
-   impresión.

La misma clave con diferente payload produce:

``` text
409 IDEMPOTENCY_KEY_REUSED
```

El mecanismo es `idempotency_records`, escrito en la misma transacción y
retenido 72 horas (CON-1). Otro actor u operación con la misma clave recibe
ese mismo error, nunca la respuesta guardada.

------------------------------------------------------------------------

# 33. Concurrencia OpenAPI

Los recursos modificables exponen:

``` json
{
  "version": 8
}
```

y pueden utilizar:

``` http
If-Match: "8"
```

Si el recurso cambió:

``` text
409 VERSION_CONFLICT
```

La versión técnica es independiente del estado de negocio.

------------------------------------------------------------------------

# 34. Contrato OpenAPI

El contrato vigente es `openapi/restia-api.yaml`, con 212 operaciones.

Módulos:

``` text
Authentication
Devices
Branches
Tables
Table Sessions
Reservations
Menu
Orders
Checks
Payments
Inventory
Cash
Fiscal
Printing
Public QR
Users
Customers
Reports
Audit
```

La extensión `x-restia` responde en cada operación los criterios de la
sección 36.

Esquema de seguridad adicional: `PublicQrToken`, encabezado `X-QR-Token`.

------------------------------------------------------------------------

# 35. Estado de completitud

## Completado

-   Contexto y problema.
-   Objetivo.
-   Alcance.
-   Arquitectura.
-   Plataformas.
-   Backend.
-   Monolito modular.
-   Roles.
-   RBAC.
-   Reglas contextuales.
-   Mesas.
-   Sesiones.
-   QR.
-   Comandas.
-   Unidades.
-   Producción.
-   Inventario.
-   Cuentas.
-   División de cuentas.
-   Pagos.
-   Caja.
-   Reservaciones.
-   `ARRIVED_WAITING`.
-   IA.
-   Fiscal.
-   Impresión.
-   Auditoría.
-   Outbox.
-   Idempotencia.
-   Concurrencia.
-   Modelo físico.
-   Índices.
-   Máquinas de estados.
-   D-07 a D-18.
-   D-19 a D-28 aprobadas.
-   Diseño base D-29 a D-35.
-   CSRF.
-   Contratos compartidos.
-   D-36 y D-37.
-   Contrato OpenAPI de todos los módulos.
-   Catálogo de errores.
-   Matrices de endpoints, permisos y transiciones.
-   Validación OpenAPI y pruebas estáticas de contrato.
-   Colección de Postman.
-   Revisión de seguridad del diseño.
-   Revisión de consistencia API ↔ MongoDB.

## Pendiente

Ver `Plan_Implementacion.md`. Lo principal:

-   repetir en staging, con tres nodos, las pruebas de concurrencia
    (`Resultados_Prueba_Tecnica.md`);
-   validación contable y revisión legal;
-   elección de PAC, terminal de pago y proveedor de IA;
-   implementación y pruebas contra una API real.

------------------------------------------------------------------------

# 36. Criterio de aceptación del contrato final

Cada endpoint debe poder responder:

1.  ¿Quién puede invocarlo?
2.  ¿En qué sucursal?
3.  ¿Qué permiso necesita?
4.  ¿Qué estado requiere el recurso?
5.  ¿Qué payload recibe?
6.  ¿Qué devuelve?
7.  ¿Qué errores puede producir?
8.  ¿Es idempotente?
9.  ¿Requiere versión?
10. ¿Qué ocurre con concurrencia?
11. ¿Genera auditoría?
12. ¿Genera outbox?
13. ¿Es síncrono o asíncrono?
14. ¿Qué datos son sensibles?
15. ¿Qué cliente lo consume?

Si una operación no puede responder estas preguntas, el contrato todavía
no está cerrado.

Estado: las 212 operaciones las responden (`Matriz_Endpoints.md`), y
`tests/contract` lo comprueba.

------------------------------------------------------------------------

# 37. Control de cambios

Ninguna decisión de negocio debe modificarse silenciosamente durante
implementación.

Todo cambio debe registrar:

``` text
ID
Decisión anterior
Nueva decisión
Motivo
Impacto en modelo de datos
Impacto en estados
Impacto en API
Impacto en Android
Impacto en Web
Impacto en seguridad
Impacto en auditoría
```

Esto evita que `restia-api`, `restia-mobile` y `restia-web` implementen
versiones incompatibles del mismo negocio.

Cambios a lo que este documento ya fijaba:

  Cambio                                     Origen     Registro
  ------------------------------------------ ---------- --------------------------
  Laravel 11 a Laravel 13                    SEC-2      Plan_Implementacion, sección 9
  Cuenta: nuevo estado `REFUNDED`            P-8        Decisiones_Payments
  Factura: nuevo estado `DISCARDED`          F-10       Decisiones_Fiscal
  Turno único con `active=true`              CA-12      Decisiones_Cash
  `claimed_agent_id` por `claimed_device_id` PR         Decisiones_Printing
  `loyalty_ledger` y `calls` fuera de v1     X-1        Decisiones_Modulos_Restantes
  Sin `INVENTORY_OVERRIDE_REQUIRED`          I          Decisiones_Inventory

Solo D-36 y SEC-2 tienen el registro completo en el formato anterior; los
demás se documentan en su decisión.

------------------------------------------------------------------------

# 38. Próximo paso

La prueba técnica ya se hizo. El siguiente bloque es la fase 0 de
`Plan_Implementacion.md`, seguida de los cimientos.

La especificación del contrato está cerrada. A partir de aquí, el trabajo es
verificación e implementación.
