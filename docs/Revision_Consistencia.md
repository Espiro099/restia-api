# Revisión de consistencia API ↔ MongoDB

**Fecha:** 2026-11-02
**Alcance:** `openapi/restia-api.yaml`, las decisiones aprobadas y las secciones 16 y 17 del documento maestro
**Estado:** CON-1 APROBADA

Es un cruce de documentos. Nada se ha probado en MongoDB, porque aún no hay código.

## 1. Decisión aprobada

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **CON-1** | `idempotency_records`, pendiente desde R-12 | Colección única para toda `Idempotency-Key`, escrita en la misma transacción; los índices únicos de cada módulo quedan como última defensa | Un mecanismo por módulo |

**Diseño propuesto:**

- **Campos:** `branch_id`, `key`, `actor_id`, `operation_id`, `request_hash`, `status_code`, `response_body`, `resource_id`, `created_at`, `expires_at`.
- **Índices:** `unique(branch_id, key)` y TTL en `expires_at`.
- **Retención:** 72 horas.
- **Flujo:**
  - Misma clave, actor, operación y contenido: devuelve la respuesta guardada.
  - Cualquier diferencia: `409 IDEMPOTENCY_KEY_REUSED`.
- **Por qué en la misma transacción:** si la operación falla, no queda ningún registro, y un reintento vuelve a ejecutarla.
- **Sin `branch_id`:** las operaciones globales no usan `Idempotency-Key`, así que la clave siempre tiene sucursal.
- **Costo:** guarda respuestas con datos personales por 72 horas. Las operaciones que devuelven secretos no usan `Idempotency-Key`.

## 2. Resultado del cruce

| Revisión | Resultado |
|---|---|
| Campos de la API contra el modelo | Consistente, salvo la sección 3 |
| Índices citados en `concurrency` | Todos tienen definición |
| Códigos, permisos y estados | Consistentes (ver tareas 26 a 28) |
| `Money` y `Decimal128` | Consistente con la sección 27, salvo la regla de la sección 3 |
| Eventos de outbox | 124 nombres; corregí los plurales (`products.created`, `modifiers.created`, `stations.created`) a singular |

## 3. Correcciones

**Ya aplicadas en las decisiones:**

| Inconsistencia | Corrección |
|---|---|
| `unique(email)` contra `unique(normalized_email)` del documento maestro | Se conserva el del documento maestro |
| Membresías con `active` e índice parcial, contra `status` e índice único | Se conserva el del documento maestro; volver a dar de alta reactiva el documento |
| Índice por teléfono sobre `customer.phone`, un campo que no existe | `customer_snapshot.phone` |
| `check_allocations` sin `branch_id`, que su índice sí usa | Agregado |

**Reglas generales que faltaban:**

- Todo documento lleva `uuid`, `created_at` y `updated_at`, y `branch_id` si pertenece a una sucursal, aunque la decisión del módulo no los enumere.
- Cada transición de unidad guarda su hora: `preparing_at`, `ready_at`, `delivered_at`, `cancelled_at` y `voided_at`.
- `inventory_counts` también guarda `notes`, `created_by` y `applied_at`; `print_agents`, `agent_version`; `print_jobs`, `printed_at`; `print_job_attempts`, `claimed_at`.
- Las cantidades de inventario y `unitValue` también son `Decimal128`, no solo el dinero. `Decimal128` debe conservar la escala al serializarse (`10.50`, no `10.5`).

**Campos derivados, sin campo propio:** `Table.operationalState`, `Table.currentSessionId`, `Payment.refundId`, `Member.active`, `Customer.anonymized` y `balance`, que sale de los totales.

## 4. Índices que faltan

Sin ellos, estos filtros del contrato recorrerían la colección:

| Colección | Índice | Para |
|---|---|---|
| `checks` | `(branch_id, business_date, status)` | `listChecks` |
| `orders` | `(branch_id, business_date, status)` | `listOrders` |
| `table_sessions` | `(branch_id, business_date, status)` | `listTableSessions` |
| `cash_shifts` | `(branch_id, business_date)` | `listCashShifts` |
| `cash_shifts` | `partial (branch_id) where review_required=true` | Turnos por revisar |
| `invoices` | `partial (branch_id) where credit_note_required=true` | Egresos pendientes |
| `invoices` | `(branch_id, batch_id)` | Factura global |
| `invoice_payment_complements` | `(invoice_id)` y `(status, due_at)` | Lista y vencimientos |
| `inventory_movements` | `(branch_id, type, created_at)` | `listInventoryMovements` |
| `inventory_movements` | `partial (branch_id, created_at) where self_approved=true` | Revisión de overrides |
| `refunds` | `(branch_id, payment_id)` y `(branch_id, check_id)` | `listRefunds` |
| `print_jobs` | `(branch_id, status, created_at)` | `listPrintJobs` |
| `audit_log` | `(branch_id, created_at)` | Consulta por rango |

Son una estimación por lectura del contrato. Hay que confirmarlos con `explain` y carga real, porque cada índice también encarece las escrituras.

**Búsqueda por nombre de cliente (CU-3):** requiere texto o prefijo y no tiene índice directo, porque `name` está en `customers` y la sucursal en `customer_branch_profiles`. Hay que resolverlo al implementar, posiblemente copiando el nombre en el perfil.

## 5. Supuestos que requieren prueba

Varias garantías del contrato descansan en comportamiento de MongoDB que hay que demostrar, no suponer:

| Supuesto | Dónde se usa |
|---|---|
| Dos transacciones que escriben el mismo documento chocan, y una reintenta con datos nuevos | `operations_epoch`, `activity_epoch` |
| Los reintentos de transacción funcionan con `laravel-mongodb` | Todo |
| El índice único parcial se comporta igual dentro de una transacción | `table_assignments`, `check_allocations`, `refunds`, `cash_shifts` |
| Escribir `operations_epoch` no causa demasiados reintentos en hora pico | Apertura de sesiones, reservas |
| El TTL no elimina a tiempo exacto, y el código debe comprobar `expires_at` | Tokens, presencia, idempotencia |

**Actualización:** los primeros cuatro se comprobaron en un nodo (`Resultados_Prueba_Tecnica.md`). Falta repetirlos con tres nodos, y medir el TTL.

## 6. Cambios en el documento maestro

Aplicados en la copia `Restia_Documento_Maestro_v2.md`. El original no cambió:

| Sección | Cambio |
|---|---|
| 1 y 2 | Laravel 13 (SEC-2), sujeto a la prueba técnica y con su registro de control de cambios |
| 5 | D-36 y D-37 aprobadas |
| 9 | Cuenta: estado `REFUNDED` (P-8) |
| 13 | Factura: estado `DISCARDED` (F-10) |
| 16 | **Agregar:** `auth_sessions`, `device_enrollment_codes`, `personal_access_tokens`, `service_identities`, `password_setup_tokens`, `approval_grants`, `check_adjustments`, `cash_movements`, `invoice_counters`, `report_rebuilds`. **Fuera de v1:** `loyalty_ledger`, `calls` |
| 17 | Índices de las decisiones y de la sección 4. **Cambian:** turno activo a `active=true`; `(claimed_device_id)` a `(claimed_agent_id)` |
| 27 | Agregar `MoneyPositive` y `MoneyNonNegative` |
| 30 | Agregar `304` |
| 31 | Reemplazar el catálogo inicial por `Catalogo_Errores.md`, sin `AUTHENTICATION_EXPIRED`, `INVENTORY_OVERRIDE_REQUIRED` ni `ORDER_INVALID_STATE` |
| 34 | Reemplazar el esqueleto por `openapi/restia-api.yaml`, con `Users`, `Customers`, `Reports`, `Audit` y `Menu` |
| 35 | Marcar como completados los módulos y las tareas de contrato |
