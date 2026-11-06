# Catálogo de errores

**Estado:** generado desde `openapi/restia-api.yaml`
**Fecha:** 2026-11-02

Reúne los 102 códigos que el contrato puede devolver, según el formato `ErrorResponse` de la sección 29 del documento maestro. Se regenera con `tools/gen_matrices.py`; no se edita a mano.

## 1. Códigos

| Código | HTTP | Origen | Operaciones | Mensaje | Qué debe hacer el cliente |
|---|---|---|---|---|---|
| `APPROVAL_DENIED` | 403 | Nuevo | 1 | No se pudo autorizar la acción. | Mostrar el motivo; corregir la situación antes de reintentar |
| `APPROVAL_INVALID` | 409 | Nuevo | 4 | La aprobación venció, ya se usó o no corresponde a esta operación. | Mostrar el motivo; corregir la situación antes de reintentar |
| `AUTHENTICATION_INVALID` | 401 | Documento maestro | 2 | Las credenciales no son válidas. | Renovar la sesión o iniciar sesión |
| `AUTHENTICATION_REQUIRED` | 401 | Documento maestro | 202 | Se requiere autenticación. | Renovar la sesión o iniciar sesión |
| `BRANCH_ACCESS_DENIED` | 403 | Documento maestro | 197 | No tienes acceso a esta sucursal. | No reintentar; falta permiso o acceso |
| `BRANCH_CODE_TAKEN` | 409 | Nuevo | 1 | Ya existe una sucursal con ese código. | Mostrar el motivo; corregir la situación antes de reintentar |
| `BRANCH_HAS_ACTIVE_OPERATIONS` | 409 | Nuevo | 2 | La sucursal tiene operaciones activas. | Mostrar el motivo; corregir la situación antes de reintentar |
| `BRANCH_INACTIVE` | 409 | Nuevo | 14 | La sucursal está inactiva. | Volver a leer el estado; no reintentar igual |
| `CASH_REGISTER_HAS_ACTIVE_SHIFT` | 409 | Nuevo | 1 | La caja tiene un turno activo. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CASH_REGISTER_INACTIVE` | 409 | Nuevo | 1 | La caja está inactiva. | Volver a leer el estado; no reintentar igual |
| `CASH_SHIFT_ALREADY_OPEN` | 409 | Documento maestro | 1 | La caja ya tiene un turno activo. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CASH_SHIFT_HAS_UNRESOLVED_OPERATIONS` | 409 | Nuevo | 1 | El turno tiene operaciones sin resolver. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CASH_SHIFT_INVALID_STATE` | 409 | Nuevo | 3 | El turno no permite esta operación en su estado actual. | Volver a leer el estado; no reintentar igual |
| `CASH_SHIFT_NOT_OPEN` | 409 | Documento maestro | 3 | El turno de caja no está abierto. | Volver a leer el estado; no reintentar igual |
| `CASH_SHIFT_REASON_REQUIRED` | 409 | Nuevo | 2 | La diferencia requiere un motivo. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CATALOG_CODE_TAKEN` | 409 | Nuevo | 7 | Ya existe un registro con ese código. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CHECK_EMPTY` | 409 | Nuevo | 1 | La cuenta no tiene unidades válidas. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CHECK_HAS_ACTIVE_INVOICE` | 409 | Nuevo | 3 | La cuenta tiene una factura activa. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CHECK_HAS_CONFIRMED_PAYMENT` | 409 | Documento maestro | 4 | La cuenta ya tiene pagos confirmados. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CHECK_HAS_OPEN_PAYMENT` | 409 | Nuevo | 4 | La cuenta tiene un pago en curso o por conciliar. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CHECK_INVALID_STATE` | 409 | Documento maestro | 8 | La cuenta indicada no está en OPEN. | Volver a leer el estado; no reintentar igual |
| `CHECK_NOT_EMPTY` | 409 | Nuevo | 1 | La cuenta todavía tiene unidades válidas. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CHECK_SESSION_MISMATCH` | 409 | Nuevo | 1 | Las cuentas pertenecen a sesiones distintas. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CLEANING_CONFIRMATION_STALE` | 409 | Nuevo | 1 | La mesa cambió. Actualiza la pantalla e inténtalo de nuevo. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CLIENT_ORDER_CONFLICT` | 409 | Nuevo | 1 | Ya existe una comanda con ese identificador y otros datos. | Mostrar el motivo; corregir la situación antes de reintentar |
| `COMPLEMENT_INVALID_STATE` | 409 | Nuevo | 2 | El complemento no permite esta operación en su estado actual. | Volver a leer el estado; no reintentar igual |
| `CREDIT_NOTE_EXCEEDS_AVAILABLE` | 409 | Nuevo | 1 | El monto supera lo disponible en la factura. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CSD_INVALID` | 409 | Nuevo | 1 | El certificado o la llave no son válidos. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CSRF_TOKEN_MISMATCH` | 403 | Nuevo | 198 | La página expiró. Recárgala e inténtalo de nuevo. | Mostrar el motivo; corregir la situación antes de reintentar |
| `CUSTOMER_ANONYMIZED` | 409 | Nuevo | 2 | El cliente fue anonimizado. | Mostrar el motivo; corregir la situación antes de reintentar |
| `DEVICE_ALREADY_ENROLLED` | 409 | Nuevo | 1 | Este dispositivo ya está registrado. | Mostrar el motivo; corregir la situación antes de reintentar |
| `DEVICE_BLOCKED` | 403 | Nuevo | 2 | Este dispositivo ya no está autorizado. | Mostrar el motivo; corregir la situación antes de reintentar |
| `DEVICE_BRANCH_MISMATCH` | 403 | Nuevo | 195 | Este dispositivo pertenece a otra sucursal. | Mostrar el motivo; corregir la situación antes de reintentar |
| `DEVICE_NOT_ENROLLED` | 401 | Nuevo | 2 | El dispositivo no está registrado. | Mostrar el motivo; corregir la situación antes de reintentar |
| `ENROLLMENT_CODE_INVALID` | 401 | Nuevo | 1 | El código de alta no es válido. | Mostrar el motivo; corregir la situación antes de reintentar |
| `FISCAL_ISSUER_NOT_CONFIGURED` | 409 | Nuevo | 4 | La sucursal no tiene un emisor activo con CSD vigente. | Mostrar el motivo; corregir la situación antes de reintentar |
| `FISCAL_ISSUER_RFC_TAKEN` | 409 | Nuevo | 1 | Ya existe un emisor con ese RFC. | Mostrar el motivo; corregir la situación antes de reintentar |
| `FISCAL_OPERATION_FAILED` | 409 | Documento maestro | 1 | El PAC rechazó la operación. | Mostrar el motivo; corregir la situación antes de reintentar |
| `FISCAL_STATUS_UNKNOWN` | 409 | Documento maestro | 3 | No se conoce el resultado de la operación fiscal. | Resolver o reconciliar antes de seguir |
| `FORBIDDEN` | 403 | Documento maestro | 196 | No tienes permiso para esta operación. | No reintentar; falta permiso o acceso |
| `IDEMPOTENCY_KEY_REUSED` | 409 | Documento maestro | 34 | La clave ya se usó con otros datos. | Corregir el cliente; no reintentar con esa clave |
| `INGREDIENT_IN_USE` | 409 | Nuevo | 1 | Una receta vigente usa este ingrediente. | Mostrar el motivo; corregir la situación antes de reintentar |
| `INTERNAL_ERROR` | 500 | Nuevo | 212 | Ocurrió un error inesperado. | Reintentar con la misma Idempotency-Key; reportar con requestId |
| `INVENTORY_BLOCKED_FOOD_SAFETY` | 409 | Nuevo | 1 | El ingrediente está bloqueado por seguridad alimentaria. | Mostrar el motivo; corregir la situación antes de reintentar |
| `INVENTORY_COUNT_ALREADY_OPEN` | 409 | Nuevo | 1 | Ya existe un conteo abierto en la sucursal. | Mostrar el motivo; corregir la situación antes de reintentar |
| `INVENTORY_COUNT_INVALID_STATE` | 409 | Nuevo | 3 | El conteo no permite esta operación en su estado actual. | Volver a leer el estado; no reintentar igual |
| `INVENTORY_INSUFFICIENT` | 409 | Documento maestro | 1 | No hay inventario suficiente. | Mostrar el motivo; corregir la situación antes de reintentar |
| `INVOICE_BATCH_EMPTY` | 409 | Nuevo | 1 | El periodo no tiene cuentas por facturar. | Mostrar el motivo; corregir la situación antes de reintentar |
| `INVOICE_CHECK_ALREADY_INVOICED` | 409 | Nuevo | 1 | La cuenta ya tiene una factura activa. | Mostrar el motivo; corregir la situación antes de reintentar |
| `INVOICE_CHECK_IN_STAMPED_GLOBAL` | 409 | Nuevo | 1 | La cuenta está en una factura global timbrada. | Mostrar el motivo; corregir la situación antes de reintentar |
| `INVOICE_CHECK_NOT_ELIGIBLE` | 409 | Nuevo | 1 | La cuenta no se puede facturar en su estado actual. | Mostrar el motivo; corregir la situación antes de reintentar |
| `INVOICE_INVALID_STATE` | 409 | Nuevo | 9 | La factura no permite esta operación en su estado actual. | Volver a leer el estado; no reintentar igual |
| `INVOICE_REPLACEMENT_REQUIRED` | 409 | Nuevo | 1 | El motivo 01 requiere una factura sustituta timbrada. | Mostrar el motivo; corregir la situación antes de reintentar |
| `LAST_BRANCH_ADMIN` | 409 | Nuevo | 3 | La sucursal debe conservar al menos un ADMIN. | Mostrar el motivo; corregir la situación antes de reintentar |
| `MEMBERSHIP_ALREADY_EXISTS` | 409 | Nuevo | 1 | El empleado ya pertenece a esta sucursal. | Mostrar el motivo; corregir la situación antes de reintentar |
| `NO_TABLE_AVAILABLE` | 409 | Documento maestro | 3 | No hay una mesa compatible disponible. | Mostrar el motivo; corregir la situación antes de reintentar |
| `ORDER_UNIT_INVALID_STATE` | 409 | Documento maestro | 4 | La unidad no permite esta operación en su estado actual. | Volver a leer el estado; no reintentar igual |
| `ORDER_UNIT_PAID` | 409 | Nuevo | 1 | La unidad ya fue pagada. | Mostrar el motivo; corregir la situación antes de reintentar |
| `PASSWORD_TOKEN_INVALID` | 409 | Nuevo | 1 | El enlace no es válido o ya venció. | Mostrar el motivo; corregir la situación antes de reintentar |
| `PAYMENT_EXCEEDS_BALANCE` | 409 | Nuevo | 1 | El monto supera el saldo disponible. | Mostrar el motivo; corregir la situación antes de reintentar |
| `PAYMENT_INVALID_STATE` | 409 | Documento maestro | 2 | El pago no permite esta operación en su estado actual. | Volver a leer el estado; no reintentar igual |
| `PAYMENT_NOT_REFUNDABLE` | 409 | Nuevo | 1 | El pago no se puede reembolsar. | Mostrar el motivo; corregir la situación antes de reintentar |
| `PAYMENT_REQUIRES_RECONCILIATION` | 409 | Documento maestro | 1 | La cuenta tiene un pago sin resolver. | Resolver o reconciliar antes de seguir |
| `PRINTER_HAS_ACTIVE_JOBS` | 409 | Nuevo | 1 | La impresora tiene trabajos en curso. | Mostrar el motivo; corregir la situación antes de reintentar |
| `PRINTER_INACTIVE` | 409 | Nuevo | 2 | La impresora está inactiva. | Volver a leer el estado; no reintentar igual |
| `PRINT_AGENT_INACTIVE` | 409 | Nuevo | 2 | El agente está inactivo. | Volver a leer el estado; no reintentar igual |
| `PRINT_JOB_INVALID_STATE` | 409 | Nuevo | 4 | El trabajo no permite esta operación en su estado actual. | Volver a leer el estado; no reintentar igual |
| `PRINT_JOB_LEASE_LOST` | 409 | Nuevo | 2 | Este intento ya no está vigente; descarte el trabajo sin imprimir. | Mostrar el motivo; corregir la situación antes de reintentar |
| `PRINT_JOB_UNKNOWN` | 409 | Documento maestro | 1 | No se sabe si el trabajo salió impreso; hay que resolverlo. | Resolver o reconciliar antes de seguir |
| `PRINT_ROUTE_NOT_CONFIGURED` | 409 | Nuevo | 1 | No hay una ruta activa para este documento. | Mostrar el motivo; corregir la situación antes de reintentar |
| `PRODUCT_NOT_SELLABLE` | 409 | Nuevo | 2 | El producto necesita al menos una estación activa. | Mostrar el motivo; corregir la situación antes de reintentar |
| `PRODUCT_UNAVAILABLE` | 409 | Nuevo | 1 | Un producto no está disponible. | Mostrar el motivo; corregir la situación antes de reintentar |
| `QR_TOKEN_INVALID` | 401 | Nuevo | 3 | Este código no está disponible. | No reintentar |
| `REFUND_ALREADY_EXISTS` | 409 | Nuevo | 1 | El pago ya tiene un reembolso activo. | Mostrar el motivo; corregir la situación antes de reintentar |
| `REFUND_INVALID_STATE` | 409 | Nuevo | 2 | El reembolso no permite esta operación en su estado actual. | Volver a leer el estado; no reintentar igual |
| `REPORT_REBUILD_IN_PROGRESS` | 409 | Nuevo | 1 | Ya hay una reconstrucción en curso. | Mostrar el motivo; corregir la situación antes de reintentar |
| `RESERVATION_ACTIVE_LIMIT_EXCEEDED` | 409 | Nuevo | 1 | Este teléfono ya alcanzó el máximo de reservas activas. | Mostrar el motivo; corregir la situación antes de reintentar |
| `RESERVATION_AI_DISABLED` | 409 | Nuevo | 5 | Las reservaciones por IA están apagadas en esta sucursal. | Mostrar el motivo; corregir la situación antes de reintentar |
| `RESERVATION_INVALID_STATE` | 409 | Documento maestro | 6 | La reserva no permite esta operación en su estado actual. | Volver a leer el estado; no reintentar igual |
| `RESERVATION_NOT_AVAILABLE` | 409 | Documento maestro | 2 | No hay disponibilidad para ese horario. | Mostrar el motivo; corregir la situación antes de reintentar |
| `RESERVATION_PRIORITY_CONFLICT` | 409 | Nuevo | 1 | Otra reserva tiene prioridad para esas mesas. | Mostrar el motivo; corregir la situación antes de reintentar |
| `RESERVATION_REQUIRES_STAFF` | 409 | Nuevo | 1 | Esta reserva debe atenderla el personal. | Mostrar el motivo; corregir la situación antes de reintentar |
| `RESOURCE_NOT_FOUND` | 404 | Documento maestro | 196 | El recurso no existe. | No reintentar |
| `SERVICE_BUSY` | 503 | Nuevo | 117 | El servicio está ocupado. Inténtalo de nuevo. | Reintentar con la misma Idempotency-Key |
| `SERVICE_IDENTITY_NAME_TAKEN` | 409 | Nuevo | 1 | Ya existe una identidad con ese nombre. | Mostrar el motivo; corregir la situación antes de reintentar |
| `SERVICE_IDENTITY_REVOKED` | 409 | Nuevo | 4 | La identidad de servicio está revocada. | Mostrar el motivo; corregir la situación antes de reintentar |
| `SESSION_HAS_ACTIVITY` | 409 | Nuevo | 1 | La sesión ya tiene actividad y no se puede cancelar. | Mostrar el motivo; corregir la situación antes de reintentar |
| `SESSION_HAS_OPEN_CHECKS` | 409 | Nuevo | 1 | La sesión tiene cuentas sin liquidar. | Mostrar el motivo; corregir la situación antes de reintentar |
| `SESSION_HAS_PENDING_UNITS` | 409 | Nuevo | 1 | La sesión tiene unidades sin terminar. | Mostrar el motivo; corregir la situación antes de reintentar |
| `SESSION_LAST_TABLE` | 409 | Nuevo | 1 | La sesión debe conservar al menos una mesa. | Mostrar el motivo; corregir la situación antes de reintentar |
| `SESSION_NOT_ACTIVE` | 409 | Documento maestro | 7 | La sesión no está activa. | Volver a leer el estado; no reintentar igual |
| `SESSION_REVOKED` | 401 | Nuevo | 1 | La sesión ya no es válida. Inicia sesión de nuevo. | Renovar la sesión o iniciar sesión |
| `STATION_IN_USE` | 409 | Nuevo | 1 | La estación tiene trabajo pendiente o es la única ruta de un producto. | Mostrar el motivo; corregir la situación antes de reintentar |
| `TABLE_ALREADY_ASSIGNED` | 409 | Documento maestro | 4 | La mesa tiene una sesión activa. | Mostrar el motivo; corregir la situación antes de reintentar |
| `TABLE_NOT_AVAILABLE` | 409 | Documento maestro | 3 | La mesa no está disponible. | Mostrar el motivo; corregir la situación antes de reintentar |
| `TABLE_NUMBER_TAKEN` | 409 | Nuevo | 2 | Ya existe una mesa con ese número. | Mostrar el motivo; corregir la situación antes de reintentar |
| `TABLE_RESERVED_SOON` | 409 | Nuevo | 1 | La mesa tiene una reserva próxima. | Mostrar el motivo; corregir la situación antes de reintentar |
| `TOO_MANY_ATTEMPTS` | 429 | Nuevo | 22 | Demasiados intentos. Espera antes de reintentar. | Esperar lo que indica Retry-After |
| `USER_INACTIVE` | 409 | Nuevo | 2 | El usuario está inactivo. | Volver a leer el estado; no reintentar igual |
| `VALIDATION_FAILED` | 422 | Documento maestro | 153 | Los datos enviados no son válidos. | Corregir los campos indicados en details |
| `VERSION_CONFLICT` | 409 | Documento maestro | 75 | El recurso fue modificado por otra operación. | Volver a leer el recurso y decidir de nuevo |
| `VERSION_REQUIRED` | 400 | Nuevo | 74 | Esta operación requiere el header If-Match. | Corregir el cliente: falta If-Match |

## 2. Códigos del catálogo inicial que no se usan

| Código | Motivo |
|---|---|
| `AUTHENTICATION_EXPIRED` | D-36: ante cualquier `401`, el cliente intenta renovar la sesión, así que no hace falta distinguir la expiración. |
| `INVENTORY_OVERRIDE_REQUIRED` | Aprobado en Inventory: `INVENTORY_INSUFFICIENT` cubre el caso, con los faltantes en `details`. |
| `ORDER_INVALID_STATE` | D-07: `orders.status` es una proyección, sin transiciones propias; el estado lo validan las unidades (`ORDER_UNIT_INVALID_STATE`). |

## 3. Notas

- **Códigos por operación:** `x-restia.conflictCodes` indica los `409` exactos de cada operación, y la columna Operaciones los cuenta. El detalle por evento está en [Matriz_Transiciones.md](Matriz_Transiciones.md).
- **Validación:** contraseña débil o actual incorrecta, y rango de auditoría excedido, responden `422 VALIDATION_FAILED` con `details`, conforme a la sección 30.
- **`PRINT_JOB_EXPIRED`:** no es una respuesta de la API, sino el valor de `lastError` en un trabajo vencido.
- **`304`:** el contrato lo usa para `ETag`, aunque no aparece en la sección 30 del documento maestro.
