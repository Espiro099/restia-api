# Módulo Fiscal: decisiones del contrato

**Estado:** APROBADAS (F-1 a F-15), sujetas a validación contable
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en D-15 y la sección 13 del documento maestro: ciclo comercial y fiscal separados, CFDI 4.0, PUE/PPD separados de la forma de pago, complementos para PPD, los diez estados de la factura, cancelación como proceso, `invoice_events` append-only y reconciliación cuando el resultado del PAC es desconocido.

**Este documento no sustituye la revisión de un contador.** La sección 3 separa lo que comprobé en la fuente oficial de lo que necesita validación.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **F-1** | PAC | Contrato independiente del proveedor; un proceso interno habla con el PAC | Elegir PAC antes de especificar |
| **F-2** | Timbrado | Asíncrono: `stamp` responde `202` y un proceso interno envía al PAC | Síncrono, con la petición esperando al PAC |
| **F-3** | Alcance de una factura nominativa | Una cuenta completa por factura. Dos personas facturan por separado solo si dividen en dos cuentas antes de pagar | Facturar por pago, o varias cuentas en una factura |
| **F-4** | Cuándo facturar | PUE con la cuenta `PAID`; PPD con `REQUESTED` o `PARTIALLY_PAID`, solo gerentes | Solo cuentas `PAID` |
| **F-5** | PPD y complementos | En el contrato, porque D-15 los exige; el complemento nace de `payment.confirmed` | Posponer PPD |
| **F-6** | Factura global | `invoice_batches` con las cuentas `PAID` sin factura nominativa; periodicidad por emisor, mensual por omisión; creación manual con recordatorio | Global automática |
| **F-7** | Factura nominativa tras la global | Flujo oficial: cancelar la global con motivo 04, reemitirla sin esa cuenta y facturar | Negar la factura una vez emitida la global |
| **F-8** | Reembolso con CFDI timbrado | CFDI de egreso creado por un gerente; el reembolso solo marca `creditNoteRequired`, y nunca se bloquea por motivos fiscales | Cancelar la factura con motivo 03 |
| **F-9** | Propinas y cortesías | **Criterio provisional:** la propina no entra al CFDI; una unidad en cortesía tampoco | Incluirlas como concepto o descuento |
| **F-10** | Borradores | **Amplía el documento:** nuevo estado `DISCARDED` para `DRAFT` y `STAMP_FAILED`, que nunca existieron ante el SAT | Conservarlos sin salida |
| **F-11** | `UNKNOWN` | Nunca se reenvía a ciegas; se consulta al PAC por nuestra referencia. Una caída durante `STAMPING` termina en `UNKNOWN` | Reintentar y confiar en el PAC |
| **F-12** | CSD | Se entrega al PAC y Restia guarda solo referencia y vigencia; nunca se devuelve ni se registra en logs | Guardarlo cifrado con KMS |
| **F-13** | Emisores | Catálogo global con RFC y régimen; cada sucursal apunta a uno | Un emisor por sucursal, sin compartir |
| **F-14** | Archivos | XML y PDF en almacenamiento de objetos, descarga solo por la API | Guardarlos en MongoDB |
| **F-15** | Permisos | Matriz de la sección 6 | Solo gerentes |

## 2. Máquinas de estados

### Factura

| Evento | Estado previo | Validaciones | Documentos modificados | Estado resultante | Errores |
|---|---|---|---|---|---|
| Crear | Ninguno | Emisor activo con CSD vigente; cuenta elegible; sin otra factura activa | `invoices`, `invoice_source_allocations`, `checks.active_invoice_id`, eventos, auditoría | `DRAFT` | `FISCAL_ISSUER_NOT_CONFIGURED`, `INVOICE_CHECK_NOT_ELIGIBLE`, `INVOICE_CHECK_ALREADY_INVOICED`, `INVOICE_CHECK_IN_STAMPED_GLOBAL`, `IDEMPOTENCY_KEY_REUSED` |
| Corregir | `DRAFT`, `STAMP_FAILED` | `If-Match` | Factura, eventos | `DRAFT` | `VERSION_CONFLICT`, `INVOICE_INVALID_STATE` |
| Descartar | `DRAFT`, `STAMP_FAILED` | `If-Match`; motivo | Factura, asignaciones (liberadas), cuenta, eventos, auditoría | `DISCARDED` | `VERSION_CONFLICT`, `INVOICE_INVALID_STATE` |
| Solicitar timbrado | `DRAFT`, `STAMP_FAILED` | `If-Match`; `Idempotency-Key` | Factura, eventos, outbox | `PENDING_STAMP` | `VERSION_CONFLICT`, `INVOICE_INVALID_STATE` |
| Tomar el trabajo | `PENDING_STAMP` | Proceso interno, con plazo de ocupación | Factura, eventos | `STAMPING` | No aplica |
| Respuesta del PAC | `STAMPING` | Proceso interno | Factura, archivos, eventos, outbox | `STAMPED`, `STAMP_FAILED` o `UNKNOWN` | No aplica |
| Reconciliar | `UNKNOWN` | Consulta al PAC por nuestra referencia | Factura, eventos, auditoría | `STAMPED`, `STAMP_FAILED`, `CANCELLED` o `UNKNOWN` | `FISCAL_STATUS_UNKNOWN`, `INVOICE_INVALID_STATE` |
| Solicitar cancelación | `STAMPED`, `CANCELLATION_REJECTED` | `If-Match`; motivo SAT; sustituta timbrada si el motivo es 01 | Factura, eventos, auditoría, outbox | `CANCELLATION_REQUESTED` | `VERSION_CONFLICT`, `INVOICE_INVALID_STATE`, `INVOICE_REPLACEMENT_REQUIRED` |
| El PAC acepta la solicitud | `CANCELLATION_REQUESTED` | Proceso interno | Factura, eventos | `CANCELLATION_IN_PROGRESS` o `UNKNOWN` | No aplica |
| Resultado del SAT | `CANCELLATION_IN_PROGRESS` | Proceso interno | Factura, asignaciones si se canceló, eventos, outbox | `CANCELLED` o `CANCELLATION_REJECTED` | No aplica |

### Complemento de pago

`PENDING_STAMP` → `STAMPING` → `STAMPED`, `STAMP_FAILED` o `UNKNOWN`. Los dos últimos admiten reintento y reconciliación, con las mismas reglas de la factura.

## 3. Qué está comprobado y qué falta validar

**Comprobado en las [preguntas frecuentes de cancelación del SAT 2026](https://www.sat.gob.mx/minisitio/Factura/documentos/PreguntasFrecuentes_CancelacionFactura25082026.pdf):**

- Los motivos 01 a 04, y que el 01 requiere emitir primero la sustituta con relación 04.
- El flujo de una factura nominativa tras la global: cancelar con motivo 04, reemitir la global sin esa operación y emitir la nominativa.
- No requieren aceptación del receptor, entre otros: hasta $1,000, egresos, público en general y cancelaciones dentro de 24 horas.
- El receptor tiene tres días hábiles. Si no responde a la primera solicitud, se cancela; si rechazó y luego no responde, la factura sigue vigente en "Plazo vencido".

**Encontrado solo en fuentes secundarias, sin confirmar en la regla vigente:**

- Emitir la global a más tardar 24 horas después del cierre del periodo, y sus periodicidades.
- Emitir el complemento a más tardar el día 5 natural del mes siguiente.
- Usar la relación 01 en el CFDI de egreso.

**Sin sustento encontrado; es criterio mío:**

- Excluir propinas del CFDI.
- Excluir unidades en cortesía, o reflejarlas como descuento.
- Forma de pago en pagos mixtos (se propone la de mayor importe).
- El plazo máximo para cancelar y para emitir una nominativa de un periodo anterior: se dejan al rechazo del PAC, sin regla en la API.
- IEPS: `ProductFiscal` solo admite IVA. Si aplica, el producto necesita una lista de impuestos.

**Riesgo técnico:** C-6 redondea el impuesto por unidad desde un precio con IVA incluido. El PAC valida importes con sus propias reglas, así que hay que probar que la factura cuadre con `check.total` antes de implementar.

## 4. Reglas

**Una cuenta, una factura activa (F-3)**

- `invoice_source_allocations` garantiza una factura activa por cuenta, nominativa o global.
- Una cuenta en cero no se factura.
- **Limitación:** dividir en partes iguales (C-1) son varios pagos sobre una cuenta, y solo admite una factura. Si cada persona quiere la suya, hay que dividir la cuenta antes del primer pago.

**Datos**

- Conceptos, claves e impuestos salen de la copia guardada en la partida, no del producto actual (M-7).
- Los datos del receptor se copian en la factura. Son datos personales: no se escriben en logs, y su retención sigue pendiente de definir.
- La API no permite editar conceptos; solo el receptor y el uso de CFDI. Un error de precio se corrige del lado comercial.

**PUE y PPD (F-4, F-5)**

- La forma de pago se deriva: `CASH` 01, `TRANSFER` 03, `CARD_CREDIT` 04, `CARD_DEBIT` 28. PPD usa 99.
- **Limitación:** una cuenta sin pagar impide cerrar su sesión de mesa, y Restia no maneja cuentas por cobrar. En v1, PPD solo sirve si el cliente paga pronto. **Si vendes a crédito a empresas, falta diseñarlo.** Si no lo haces, se puede dejar de implementar sin cambiar el resto.
- **Limitación:** el contrato no cancela complementos. El SAT marca como "No cancelable" un comprobante con otros relacionados, así que una factura PPD con complementos, o el reembolso de un pago ya documentado en uno, necesitaría esa operación. Hay que resolverlo si PPD se implementa.

**Factura global (F-6, F-7)**

- Incluye cuentas `PAID` por `paid_at` dentro del periodo, sin factura nominativa ni en estado `REFUNDED`, y descuenta los reembolsos parciales.
- Recomiendo mensual porque, hasta cerrar el mes, un cliente puede pedir su factura sin cancelar nada. Una global diaria obliga a cancelar y reemitir casi cada vez. El régimen del emisor puede limitar esta opción.
- Excluir una cuenta cancela la global con motivo 04 y crea un lote de reemplazo. La cuenta queda libre solo cuando la global está `CANCELLED`, para respetar el orden del SAT.

**Reembolsos (F-8)**

- El lado comercial nunca espera al fiscal, conforme a D-15.
- `refund.confirmed` marca la factura con `creditNoteRequired`, y un gerente crea el egreso por el monto, sin propina.
- Se recomienda egreso y no motivo 03 porque el consumo sí ocurrió.
- Un reembolso total deja la cuenta `REFUNDED`. Mover sus unidades a otra cuenta (P-8) exige antes un egreso por el total o una factura cancelada, porque de lo contrario se facturaría dos veces.

**Sustitución (motivo 01)**

- Se crea una factura con `replacesInvoiceId` sobre la misma cuenta y se timbra. La asignación pasa a la sustituta al quedar timbrada.
- Después se solicita la cancelación de la original, indicando la sustituta.

**Fuera de v1**

- Autofacturación del cliente desde el ticket. Se revisará en Public QR (24.13).
- Envío por correo: `invoice.stamped` queda en outbox, pero el canal no está definido.

## 5. Concurrencia

- **Doble factura:** el índice único parcial de asignaciones deja pasar una, y la otra recibe `INVOICE_CHECK_ALREADY_INVOICED`.
- **Cuenta contra factura:** crear una factura escribe en la cuenta. Mover unidades o dar una cortesía la lee y escribe también, así que MongoDB las hace chocar, y quien reintenta recibe `CHECK_HAS_ACTIVE_INVOICE`.
- **Doble timbrado:** la transición condicional de `PENDING_STAMP` a `STAMPING` deja un solo proceso. Si se cae, al vencer su plazo la factura pasa a `UNKNOWN`, nunca a un reenvío.
- **Folios:** contador atómico por emisor y serie en `invoice_counters`, fuera de la transacción. Puede dejar huecos, y el identificador fiscal es el folio fiscal. Hay que confirmarlo con contabilidad.
- **Contacto con el PAC:** nunca dentro de una transacción de MongoDB.

## 6. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `fiscal.manage_issuers` (incluye CSD) | Sí | No | No | No |
| `invoices.read` | Sí | Sí | Sí | No |
| `invoices.create` (nominativa PUE) y `invoices.stamp` | Sí | Sí | Sí | No |
| `invoices.create_ppd` | Sí | Sí | No | No |
| `invoices.credit_note` | Sí | Sí | No | No |
| `invoices.cancel` | Sí | Sí | No | No |
| `invoices.global` | Sí | Sí | No | No |
| `invoices.reconcile` | Sí | Sí | No | No |

WAITER factura porque el cliente suele pedirlo en la mesa, pero con esto puede ver RFC y razón social de cualquier factura de su sucursal. **Si prefieres limitar esos datos, solo los gerentes facturarían.**

## 7. Cambios a contratos ya aprobados

| Módulo | Cambio | Origen |
|---|---|---|
| Checks | Con factura activa no se mueven unidades ni se dan cortesías: `CHECK_HAS_ACTIVE_INVOICE` | Sección 5 |
| Checks | Campos `paidAt` y `activeInvoiceId` | F-3, F-6 |
| Payments | Mover unidades desde `REFUNDED` exige egreso total o factura cancelada (precisa P-8) | F-8 |
| Branches | Campo `fiscalIssuerId` y operación para asignarlo | F-13 |

## 8. Requisitos para otros módulos

- **Infraestructura:** almacenamiento de objetos y proceso interno con acceso al PAC.
- **Public QR (24.13):** decidir la autofacturación.
- **Reports:** ventas y facturado son conceptos distintos, por D-15.

## 9. Cambios al modelo físico

- **`fiscal_issuers`:** `uuid`, `rfc`, `legal_name`, `tax_regime`, `postal_code`, `global_periodicity`, `csd` (`provider_ref`, `serial`, `valid_until`), `status`, `version`.
- **`invoices`:** `uuid`, `branch_id`, `issuer_id`, `type`, `scope`, `status`, `check_id`, `batch_id`, `replaces_invoice_id`, `related_invoice_id`, `refund_id`, `receiver`, `cfdi_use`, `sat_payment_method`, `sat_payment_form`, `series`, `folio`, `lines[]`, `subtotal`, `tax_total`, `total`, `fiscal_uuid`, `stamped_at`, `cancellation`, `credit_note_required`, `last_error`, `lease_until`, `files`, `idempotency_key`, `version`.
- **`invoice_batches`:** `uuid`, `branch_id`, `issuer_id`, `periodicity`, `period_from`, `period_to`, `check_ids`, `invoice_id`, `status`, `superseded_by`, `idempotency_key`, `version`.
- **`invoice_source_allocations`:** `branch_id`, `check_id`, `invoice_id`, `active`, `created_at`, `closed_at`.
- **`invoice_events`:** `invoice_id`, `from_status`, `to_status`, `actor`, `provider_code`, `details`, `created_at`.
- **`invoice_payment_complements`:** `uuid`, `branch_id`, `invoice_id`, `payment_id`, `status`, `amount`, `fiscal_uuid`, `due_at`, `lease_until`, `last_error`, `version`.
- **Colección nueva `invoice_counters`:** `issuer_id`, `series`, `next_folio`.
- **`checks`:** `paid_at`, `active_invoice_id`. **`branches`:** `fiscal_issuer_id`.
- **Índices nuevos:** `unique(uuid)` donde aplique; `unique(rfc)` en `fiscal_issuers`; `unique(branch_id, idempotency_key)` en `invoices` e `invoice_batches`; `partial unique(branch_id, check_id) where active=true` en asignaciones; `unique(payment_id)` en complementos; `unique(issuer_id, series)` en contadores; `(invoice_id, created_at)` en eventos.
- **Códigos de error nuevos:** `FISCAL_ISSUER_NOT_CONFIGURED`, `FISCAL_ISSUER_RFC_TAKEN`, `CSD_INVALID`, `INVOICE_INVALID_STATE`, `INVOICE_CHECK_NOT_ELIGIBLE`, `INVOICE_CHECK_ALREADY_INVOICED`, `INVOICE_CHECK_IN_STAMPED_GLOBAL`, `INVOICE_REPLACEMENT_REQUIRED`, `INVOICE_BATCH_EMPTY`, `CREDIT_NOTE_EXCEEDS_AVAILABLE`, `COMPLEMENT_INVALID_STATE`, `CHECK_HAS_ACTIVE_INVOICE`.
