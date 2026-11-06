# Módulo Branches: decisiones del contrato

**Estado:** APROBADAS (B-1 a B-7)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

El documento maestro solo define de `branches` sus índices, `unique(uuid)` y `status`. Lo demás en este módulo es propuesta.

## Endpoints

| Método y ruta | Permiso | Cliente | Versión |
|---|---|---|---|
| `GET /branches` | Membresía activa | Android, Web | No |
| `POST /branches` | `branches.create` | Web | No |
| `GET /branches/{branchId}` | Membresía activa | Android, Web | No |
| `PATCH /branches/{branchId}` | `branches.update` | Web | `If-Match` |
| `POST /branches/{branchId}/activate` | `branches.manage_status` | Web | `If-Match` |
| `POST /branches/{branchId}/deactivate` | `branches.manage_status` | Web | `If-Match` |

## Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **B-1** | Campos de la sucursal | `code`, `name`, `timezone`, `currency`, `businessDayCutoff`, `phone`, `address` | Agregar o quitar campos |
| **B-2** | Quién crea sucursales | `branches.create` en al menos una sucursal; el creador queda como `ADMIN` de la nueva. La primera se crea con un comando de instalación | Administrador global en `users` |
| **B-3** | Estados | `ACTIVE` e `INACTIVE`, sin `DELETE` | Agregar un estado de cierre temporal |
| **B-4** | Requisitos para desactivar | Sin sesiones de mesa activas, turnos `OPEN` o `CLOSING`, pagos o facturas en `UNKNOWN`, ni reservaciones futuras `CONFIRMED` | Permitirlo con autorización especial |
| **B-5** | Efecto de `INACTIVE` | Permite consultas; rechaza operaciones nuevas con `409 BRANCH_INACTIVE` | Bloquear también las consultas |
| **B-6** | Cambio de `timezone` o `businessDayCutoff` | Solo sin operación activa, porque altera `business_date` | Aplicarlo desde el siguiente día operativo |
| **B-7** | Falta de `If-Match` | `400 VERSION_REQUIRED` en todo el contrato | `428`, que no está en la sección 30 |

B-6 también adelanta parte de la tarea 17: el corte de `business_date` se configura por sucursal.

## Cambios al modelo físico

- **Campos nuevos en `branches`:** `code`, `name`, `timezone`, `currency`, `business_day_cutoff`, `phone`, `address`, `version`.
- **Índice nuevo:** `unique(code)`.
- **Permisos nuevos:** `branches.create`, `branches.update`, `branches.manage_status`.
- **Códigos de error nuevos:** `VERSION_REQUIRED`, `BRANCH_CODE_TAKEN`, `BRANCH_HAS_ACTIVE_OPERATIONS`, `BRANCH_INACTIVE`.

`BRANCH_INACTIVE` se declarará en los módulos operativos, que son los que pueden recibirlo.

## Riesgo por resolver en la implementación

Desactivar una sucursal puede competir con una operación que empieza al mismo tiempo, por ejemplo abrir una mesa. Una verificación dentro de la transacción no basta por sí sola en MongoDB, porque dos transacciones que no escriben el mismo documento no entran en conflicto.

La solución propuesta está en la decisión T-9 de `Decisiones_Tables_Sessions.md`.
