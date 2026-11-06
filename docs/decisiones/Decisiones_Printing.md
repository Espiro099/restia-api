# Módulo Printing: decisiones del contrato

**Estado:** APROBADAS (PR-1 a PR-13)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en la sección 14 del documento maestro: los estados `PENDING`, `CLAIMED`, `PRINTING`, `PRINTED`, `FAILED`, `UNKNOWN` y `CANCELLED`; un `UNKNOWN` no se reintenta automáticamente; una reimpresión crea un trabajo nuevo vinculado al original, con actor y motivo; y el agente se autentica con un Service Bearer Token.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **PR-1** | Conexión del agente | Programa local en el restaurante que consulta trabajos hacia afuera, sin puertos abiertos | Conexión permanente, o impresión directa desde Android |
| **PR-2** | Rutas | `print_stations` une un destino (estación de producción o tipo de documento) con una impresora | Impresora asignada a cada estación de producción |
| **PR-3** | Tipos y disparadores | Automáticos: comanda por estación y aviso de anulación. A petición: precuenta, recibo, reporte de caja y prueba | Todo a petición |
| **PR-4** | Contenido | El servidor genera un documento neutro al crear el trabajo, y el agente lo convierte a ESC/POS | Generar ESC/POS en el servidor |
| **PR-5** | Reclamo | Atómico, con plazo de 60 s. Si vence en `CLAIMED`, vuelve a `PENDING`; si vence en `PRINTING`, pasa a `UNKNOWN` | Mismo tratamiento en ambos |
| **PR-6** | Reintentos | Automáticos, hasta 3, solo si el agente confirma que no envió nada | Sin reintentos automáticos |
| **PR-7** | Resolver `UNKNOWN` | Una persona revisa y declara si salió impreso; si no, queda `FAILED` y se puede reintentar | Tratarlo siempre como reimpresión |
| **PR-8** | Reintento y reimpresión | Reintento: el mismo trabajo, porque no salió. Reimpresión: trabajo nuevo, marcado "REIMPRESIÓN" | Todo como trabajo nuevo |
| **PR-9** | Comanda vencida | **Amplía el documento:** una comanda sin imprimir tras 15 min pasa a `FAILED` con alerta, en vez de salir tarde | Imprimir siempre |
| **PR-10** | Unidad cancelada antes de imprimir | Si toda la comanda se canceló, el trabajo pendiente pasa a `CANCELLED` | Imprimir de todos modos |
| **PR-11** | Efecto de una falla | Nunca bloquea la comanda ni el cobro; genera una alerta | Bloquear la comanda |
| **PR-12** | Identidades de servicio | **Completa D-36:** API para emitir, rotar y revocar; también la usará IA | Emitir tokens por consola |
| **PR-13** | Permisos | Matriz de la sección 5 | Solo gerentes reimprimen |

## 2. Máquina de estados

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear (automático) | Ninguno | Desde outbox; clave derivada de origen, estación y tipo | `print_jobs` | `PENDING` | Sin ruta: `FAILED` con alerta |
| Crear (a petición) | Ninguno | Permiso del documento; ruta activa | `print_jobs`, auditoría | `PENDING` | `PRINT_ROUTE_NOT_CONFIGURED`, `IDEMPOTENCY_KEY_REUSED` |
| Reclamar | `PENDING` | Impresora del agente; `next_attempt_at` vencido | `print_jobs`, `print_job_attempts` | `CLAIMED` | Sin trabajos: lista vacía |
| Vence el plazo | `CLAIMED` | Proceso interno; no se envió nada | Trabajo, intento | `PENDING` | No aplica |
| Iniciar impresión | `CLAIMED` | Intento vigente | Trabajo, intento | `PRINTING` | `PRINT_JOB_LEASE_LOST` |
| Vence el plazo | `PRINTING` | Proceso interno | Trabajo, intento, outbox | `UNKNOWN` | No aplica |
| Resultado `PRINTED` | `PRINTING`, o `UNKNOWN` por vencimiento | Intento vigente | Trabajo, intento | `PRINTED` | `PRINT_JOB_LEASE_LOST` |
| Resultado `NOT_SENT` | `PRINTING`, o `UNKNOWN` por vencimiento | Intento vigente | Trabajo, intento, outbox si se agotan | `PENDING` con espera, o `FAILED` al tercer intento | `PRINT_JOB_LEASE_LOST` |
| Resultado `UNCERTAIN` | `PRINTING` | Intento vigente | Trabajo, intento, outbox | `UNKNOWN` | `PRINT_JOB_LEASE_LOST` |
| Resolver | `UNKNOWN` | `If-Match`; permiso | Trabajo, auditoría | `PRINTED` o `FAILED` | `VERSION_CONFLICT`, `PRINT_JOB_INVALID_STATE` |
| Reintentar | `FAILED` | `If-Match`; impresora opcional | Trabajo, auditoría | `PENDING` | `VERSION_CONFLICT`, `PRINT_JOB_INVALID_STATE` |
| Cancelar | `PENDING`, `FAILED`, `UNKNOWN` | `If-Match`; motivo | Trabajo, auditoría | `CANCELLED` | `VERSION_CONFLICT`, `PRINT_JOB_INVALID_STATE` |
| Reimprimir | `PRINTED`, `UNKNOWN` | Motivo; `Idempotency-Key` | Trabajo nuevo con `originalJobId`, auditoría | `PENDING` | `PRINT_JOB_INVALID_STATE` |
| Vence la comanda | `PENDING` | Proceso interno, solo comandas | Trabajo, outbox | `FAILED` (`PRINT_JOB_EXPIRED`) | No aplica |

## 3. Reglas

**Por qué `CLAIMED` y `PRINTING` son distintos (PR-5)**

- `CLAIMED` garantiza que no se envió nada, así que repetirlo no duplica papel. El agente no envía bytes hasta que `start` responde bien.
- Si el agente se cae en `PRINTING`, no se sabe si salió, de modo que pasa a `UNKNOWN`. En cocina, un duplicado puede significar cocinar dos veces.
- Si el resultado del mismo intento llega después del vencimiento, se acepta, para evitar revisiones manuales innecesarias.

**Qué significa `PRINTED`**

Solo que la impresora aceptó los datos. ESC/POS no confirma que el papel salió bien; un atasco posterior no se detecta. El agente reporta `UNCERTAIN` si la conexión se corta después de empezar a enviar.

**Tipos de documento (PR-3)**

| Tipo | Origen | Disparador | Prioridad |
|---|---|---|---|
| `KITCHEN_TICKET` | Comanda, una por estación | `order.created` | Alta |
| `VOID_NOTICE` | Unidad anulada | `order_unit.voided` | Alta |
| `PRE_CHECK` | Cuenta | A petición | Normal |
| `PAYMENT_RECEIPT` | Pago | A petición | Normal |
| `CASH_SHIFT_REPORT` | Turno | A petición | Baja |
| `TEST` | Impresora | A petición | Baja |

**Comanda vencida (PR-9)**

Si el agente estuvo caído 40 minutos, imprimir todo al volver haría que cocina prepare platillos que quizá ya se resolvieron a mano. Por eso la comanda vencida requiere decisión humana. El valor de 15 minutos es una propuesta configurable por sucursal; conviene ajustarlo con la operación.

**Contenido (PR-4)**

- Es una copia inmutable, por lo que una reimpresión muestra lo original aunque cambie el menú.
- Nunca incluye número de tarjeta, RFC ni datos del receptor. El recibo lleva la leyenda de que no es un comprobante fiscal.
- El reporte de caja tiene importes; por eso exige `cash.read`.

**Agentes e impresoras**

- Una impresora pertenece a un agente, que solo reclama trabajos de sus impresoras.
- Sin latido por 2 minutos se publica `print_agent.offline`.
- Una impresora con trabajos `CLAIMED` o `PRINTING` no se desactiva. Los `PENDING` se pueden mover con reintento o cancelar.

**Identidades de servicio (PR-12)**

D-36 definió estas reglas: las emite un `ADMIN`, el token se muestra una sola vez y dura 90 días, al rotar el anterior vale 7 días más, y se revocan de inmediato. El contrato aún no tenía operaciones para hacerlo. Se agregan en Authentication, y IA (24.14) las reutilizará.

**Sin conexión**

Reimprimir queda bloqueado sin conexión (D-36). Las comandas capturadas sin conexión se imprimen al sincronizarse, porque entonces se crea la comanda en el servidor.

**Fuera de v1**

Apertura del cajón de dinero, impresión de CFDI y `fallback` automático a otra impresora. Una impresora dañada se resuelve cambiando la ruta y reintentando.

## 4. Concurrencia

- **Doble reclamo:** `findOneAndUpdate` condicionado a `PENDING` entrega cada trabajo a un solo agente. Sin transacción, porque es un único documento.
- **Agente lento:** cada reclamo crea un intento. Una respuesta de un intento anterior recibe `PRINT_JOB_LEASE_LOST`, y el agente debe descartar ese trabajo sin imprimir.
- **Evento repetido:** outbox entrega al menos una vez; la clave derivada y `unique(idempotency_key)` evitan comandas duplicadas.
- **Resolver contra un resultado tardío:** ambos escriben con la versión; si la persona ya resolvió, el intento queda fuera.

## 5. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `service_identities.manage` | Sí | No | No | No |
| `printing.manage` (impresoras, rutas, agentes) | Sí | Sí | No | No |
| `printing.read` | Sí | Sí | Sí | Sí |
| `printing.request` (precuenta, recibo) | Sí | Sí | Sí | No |
| `printing.reprint` | Sí | Sí | Sí | Sí |
| `printing.resolve` (reintentar, resolver, cancelar) | Sí | Sí | Sí | Sí |

- `CASH_SHIFT_REPORT` exige además `cash.read`, y `TEST` exige `printing.manage`.
- `printing-agent` solo reclama y actualiza trabajos de las impresoras de su agente, sin acceder a otros módulos.
- WAITER y KITCHEN resuelven porque ven la impresora y no deberían esperar a un gerente en plena operación. **El riesgo:** se puede reimprimir un recibo o una comanda. Queda en auditoría con actor y motivo; si te preocupa, conviene dejar solo a gerentes la reimpresión de recibos.

## 6. Cambios a contratos ya aprobados

| Módulo | Cambio | Origen |
|---|---|---|
| Authentication | Operaciones de `service_identities` | PR-12 |

Orders no cambia: `order.created` y `order_unit.voided` ya se publican en outbox.

## 7. Cambios al modelo físico

- **`printers`:** `uuid`, `branch_id`, `agent_id`, `code`, `name`, `connection` (tipo y dirección), `paper_width`, `status`, `version`.
- **`print_stations`:** `uuid`, `branch_id`, `code`, `name`, `station_id`, `job_types`, `printer_id`, `status`, `version`.
- **`print_agents`:** `uuid`, `branch_id`, `service_identity_id`, `name`, `status`, `last_seen_at`, `version`.
- **`print_jobs`:** `uuid`, `branch_id`, `type`, `status`, `priority`, `printer_id`, `print_station_id`, `source_type`, `source_id`, `original_job_id`, `reason`, `document`, `attempts`, `next_attempt_at`, `claimed_agent_id`, `lease_until`, `expires_at`, `last_error`, `idempotency_key`, `created_by`, `version`.
- **`print_job_attempts`:** `uuid`, `job_id`, `agent_id`, `status`, `started_at`, `finished_at`, `error_code`.
- **`service_identities`:** ver D-36.
- **Índices nuevos:** `unique(uuid)` donde aplique; `unique(branch_id, code)` en impresoras y rutas; `(branch_id, printer_id, status, priority, created_at)` en `print_jobs` para reclamar; `(job_id, started_at)` en intentos.
- **Índice que cambia:** `(claimed_agent_id)` en lugar de `(claimed_device_id)`, porque quien reclama es un agente y no un dispositivo Android.
- **Códigos de error nuevos:** `PRINT_JOB_INVALID_STATE`, `PRINT_JOB_LEASE_LOST`, `PRINT_JOB_EXPIRED`, `PRINT_ROUTE_NOT_CONFIGURED`, `PRINTER_HAS_ACTIVE_JOBS`, `PRINT_AGENT_INACTIVE`, `PRINTER_INACTIVE`, `SERVICE_IDENTITY_NAME_TAKEN`, `SERVICE_IDENTITY_REVOKED`.
