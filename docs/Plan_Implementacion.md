# Plan de implementación de Restia

**Estado:** APROBADO
**Fecha:** 2026-11-02
**Base:** `openapi/restia-api.yaml` y las decisiones aprobadas

El contrato está cerrado, pero nada se ha construido. Este plan ordena lo que sigue. **No tiene fechas**, porque no conozco el tamaño ni la dedicación del equipo; los tamaños son relativos (S, M, L) y son estimación mía.

## 1. Bloqueos antes de escribir módulos

| # | Pendiente | Responsable | Bloquea |
|---|---|---|---|
| 1 | ~~Prueba técnica con Laravel 13~~ Hecha (`Resultados_Prueba_Tecnica.md`); falta repetir 5 y 7 en staging | Líder técnico | Salida a producción |
| 2 | Revisar `Restia_Documento_Maestro_v2.md` y reemplazar el original | Dueño del producto | Evitar dos fuentes de verdad |
| 3 | Adenda, T01–T12, y texto de D-01 a D-06 y D-29 a D-35: revisar contra lo aprobado | Dueño del producto | Cierre formal; pueden contradecir decisiones |
| 4 | Framework de Web (Android ya definido: Kotlin en Android Studio) | Líder técnico | Cliente Web |
| 5 | Identificador para empleados sin correo (U-2) | Dueño del producto | Users |
| 6 | Rol de cajero: confirmar que no hace falta (P-11, CA-13) | Dueño del producto | Payments, Cash |
| 7 | ¿Se cobra por adelantado? (limitación de C-2) ¿Se vende a crédito? (F-5) | Dueño del producto | Checks, Fiscal |
| 8 | Elegir PAC, terminal de pago y proveedor de IA | Dueño del producto | Fiscal, IA |
| 9 | Validación contable de F-1 a F-15 y M-7, incluido IEPS | Contador | Fiscal |
| 10 | Revisión legal: privacidad, retención y anonimización | Legal | Salida a producción |

Los puntos 1 a 7 conviene cerrarlos primero. Los puntos 8 a 10 pueden avanzar en paralelo, y solo detienen los módulos indicados.

## 2. Fases

| Fase | Contenido | Tamaño | Criterio de salida |
|---|---|---|---|
| **0. Cierre** | Puntos 2 a 7 de la sección 1 | S | Documento maestro y contrato coinciden |
| **1. Prueba técnica** | Sección 3 | S | Las 9 pruebas pasan, o se decide otra versión |
| **2. Cimientos** | Repositorios, CI, staging y capa común (sección 4) | M | Una operación de ejemplo pasa por toda la capa común en staging |
| **3. Identidad** | Authentication, Devices, Users, Branches | M | Casos CRED de D-36 y aislamiento entre sucursales |
| **4. Servicio en piso** | Tables/Sessions, catálogos de Menu e Inventory, Orders, Printing | L | Una comanda sale en cocina, con fallas de impresora simuladas |
| **5. Cobro** | Checks, Cash, Payments, stock e inventario completo | L | Abrir mesa, comandar, cobrar y cerrar caja, con choques simulados |
| **6. Experiencia y gestión** | Reservations, Public QR, Customers, Reports, Audit | M | Conformidad de la API y casos negativos de estos módulos |
| **7. Fiscal** | Emisores, facturas, global, egresos | L | Ciclo completo en el ambiente de pruebas del PAC, validado por contabilidad |
| **8. IA** | Capa intermedia y canal | M | Pruebas de inyección superadas |
| **9. Piloto** | Una sucursal real, con operación supervisada | M | Lista de la sección 8 |

Las fases 6 a 8 se pueden hacer en paralelo si hay personas. Sugiero que IA sea la última y que no bloquee el piloto.

**El orden difiere del documento maestro a propósito.** La sección 35 de ese documento ordena el contrato; para construir hay dependencias distintas:

- **Ingredientes antes que recetas:** `publishProductRecipe` exige ingredientes existentes, y `START` crea movimientos. El catálogo de Inventory va junto con Menu.
- **Caja antes que pagos:** todo pago exige un turno `OPEN`.
- **Impresión junto con Orders:** sin ella, no hay comanda real en cocina. Por eso va antes de lo que su orden sugiere.

**Cambio de prioridad (2026-11-02):** el trabajo actual es la app móvil, y cada app tiene su propio repositorio. Mientras no exista `restia-api`, la app se desarrolla contra el mock del contrato (`Pruebas_Contrato.md`), que solo valida peticiones.

## 3. Prueba técnica

**Hecha el 2026-11-02:** Laravel 13 se mantiene. Ver `Resultados_Prueba_Tecnica.md`.

| # | Prueba | Si falla |
|---|---|---|
| 1 | Laravel 13, `laravel-mongodb` y Sanctum, con `PersonalAccessToken` propio | Probar Laravel 12, que da margen hasta 2027-02-24 |
| 2 | Sesión Web de Laravel en MongoDB | Otro almacén de sesiones |
| 3 | Costo de `last_used_at` en cada petición | Actualizarlo con menos frecuencia |
| 4 | `findOneAndUpdate` en transacción con reintentos | Revisar D-36 |
| 5 | Dos transacciones que escriben el mismo documento chocan, y la que reintenta ve lo nuevo | **Rediseñar la concurrencia** |
| 6 | Índice único parcial dentro de una transacción | Rediseñar esas garantías |
| 7 | Contención de `operations_epoch` en hora pico simulada | Dividir el campo por área |
| 8 | Serialización de `Decimal128` conservando la escala | Capa de conversión propia |
| 9 | Parámetros `status[$ne]=x` rechazados por la capa de validación | Filtro global |

La prueba 5 es la más importante: de ella dependen `operations_epoch`, `activity_epoch` y la protección de pagos contra cierre de caja.

## 4. Cimientos

**Repositorios:** el documento maestro nombra `restia-api`, `restia-mobile` y `restia-web`. Recomiendo agregar `restia-print-agent`, porque se instala en cada sucursal y tiene su propio ciclo, y dejar el contrato en `restia-api`, como fuente única. La página pública puede vivir en `restia-web`, siempre que se publique en otro dominio (QR-11).

**Ambientes:**

| Ambiente | Contenido |
|---|---|
| Local | Contenedores con MongoDB como replica set, porque las transacciones lo exigen |
| Staging | Igual a producción, con PAC y terminal de prueba y datos sembrados |
| Producción | Replica set, secretos en un gestor, respaldos con restauración probada |

**CI, en cada cambio:**

- `pytest tests/contract` y Redocly.
- Comparar el contrato con el anterior y bloquear cambios incompatibles que no tengan registro.
- Análisis estático, pruebas con replica set, revisión de dependencias y de secretos.
- En staging: conformidad de la API y colección de Postman.

**Capa común del backend**, antes de cualquier módulo:

- `X-Request-ID`, formato de error y envoltura `{data, meta}`.
- Autenticación, membresía, sucursal y permisos (D-12).
- Aislamiento de IDs por sucursal.
- `Idempotency-Key` (CON-1), `If-Match`, transacciones con hasta 10 reintentos y espera aleatoria, `E11000` a `409` y reintentos agotados a `503 SERVICE_BUSY`.
- Auditoría y outbox.
- Límites de uso y logs sin secretos.

**DTOs:** los clientes los generan desde el contrato. En Laravel, las validaciones y respuestas se escriben a mano, y la prueba de conformidad comprueba que coincidan.

**Migración:** no conozco datos existentes, así que no hay migración. Solo un comando de instalación (B-2), la creación de índices y datos de prueba. **Si hay datos de otro sistema, avísame, porque esto cambia.**

**Monitoreo, alertas ya definidas en las decisiones:**

- Proyección de stock detenida (I-1).
- Outbox atrasado.
- Agente sin latido (sección 3 de Printing).
- Pagos, facturas e impresiones en `UNKNOWN`.
- Turnos olvidados y diferencias de caja.
- CSD por vencer.
- Complementos por vencer.
- Actividad anormal de la IA.

## 5. Pruebas por fase

Sigue los niveles de `Pruebas_Contrato.md`. Un módulo se considera terminado cuando:

- Pasa la conformidad con el contrato.
- Cada fila de `casos_negativos.csv` de ese módulo tiene su prueba.
- Se recorrió su matriz con cada rol, comprobando el `403`.
- Se probó con IDs de otra sucursal, comprobando el `404`.
- Sus escenarios de concurrencia se ejecutaron en paralelo.

La carga completa se hace antes del piloto: contención, consulta periódica de cocina (RT-1) y límites del wifi (QR-9).

## 6. RACI

Los roles son genéricos, y una misma persona puede cubrir varios. R ejecuta, A responde, C es consultado, I es informado.

| Actividad | Dueño de producto | Líder técnico | Backend | Android y Web | QA | DevOps | Seguridad | Contador y legal |
|---|---|---|---|---|---|---|---|---|
| Decisiones de negocio | A/R | C | I | I | I | I | C | C |
| Control de cambios del contrato | A | R | C | C | C | I | C | I |
| Prueba técnica | I | A | R | I | C | C | I | – |
| Staging y CI | I | A | C | C | C | R | C | – |
| Capa común | I | A | R | I | C | C | C | – |
| Módulos de backend | C | A | R | C | C | I | I | – |
| Android y Web | C | A | C | R | C | I | I | – |
| Agente de impresión | C | A | R | I | C | C | I | – |
| Pruebas de contrato | I | A | C | C | R | C | I | – |
| Módulo Fiscal | C | A | R | C | C | I | I | C |
| Revisión contable | A | C | C | – | – | – | – | R |
| Privacidad y retención | A | C | I | I | – | I | C | R |
| Pentest | I | A | C | C | C | C | R | I |
| Piloto | A | R | C | C | C | C | I | C |

## 7. Riesgos

| # | Riesgo | Prob. | Impacto | Mitigación | Origen |
|---|---|---|---|---|---|
| 1 | La concurrencia por `epoch` rinde menos con tres nodos | Baja | **Alto** | Probada en un nodo; repetir en staging | Consistencia, sección 5 |
| 2 | Faltan flujos de D-36 por probar con Sanctum en Laravel 13 | Baja | Medio | Compatibilidad básica confirmada; casos CRED en la fase 3 | SEC-2 |
| 3 | Los documentos que no tengo contradicen lo aprobado | Media | Alto | Revisarlos en la fase 0 | Punto 3 |
| 4 | Criterios fiscales rechazados por contador o PAC | Media | Alto | Validar antes de la fase 7; prueba de redondeo | F-9, C-6 |
| 5 | El alcance excede la capacidad del equipo: 212 operaciones y varias plataformas | **Alta** | Alto | Piloto sin IA ni PPD; revisar alcance tras la fase 1 | Plan |
| 6 | El documento maestro y el contrato divergen | Alta | Medio | Actualizarlo y aplicar control de cambios | Sección 37 |
| 7 | Una diferencia de caja no se puede atribuir | Alta | Medio | Decidir sobre el rol de cajero | CA-13 |
| 8 | Contención o demoras en hora pico | Media | Medio | Prueba de carga antes del piloto | I-1, RT-1 |
| 9 | Agente de impresión caído en servicio | Media | Medio | Alerta, pantalla de cocina y comanda vencida | PR-9 |
| 10 | Datos personales enviados al proveedor de IA | Media | Medio | Revisión legal; IA apagada por omisión | IA-7 |
| 11 | Inyección de instrucciones a la IA | Media | Medio | Controles en la API; pruebas antes de lanzar | IA-2 |
| 12 | Saltarse el aislamiento entre sucursales en un módulo | Media | **Alto** | Capa común y prueba por cada campo | Seguridad, hallazgo 12 |

## 8. Listas de verificación

**Antes de empezar los módulos:**

- [ ] Bloqueos 1 a 7 cerrados.
- [x] Prueba técnica hecha.
- [ ] Registro de control de cambios de SEC-2 firmado.
- [ ] Documento maestro actualizado.
- [ ] Repositorios, CI y staging funcionando.
- [ ] Capa común revisada por seguridad.

**Antes del piloto:**

- [ ] Conformidad, casos negativos y matriz por rol en verde.
- [ ] Prueba de carga con datos de una sucursal real.
- [ ] Pentest con hallazgos críticos y altos resueltos.
- [ ] Respaldo y restauración probados.
- [ ] Alertas de la sección 4 activas, con responsable.
- [ ] Revisiones contable y legal firmadas, si Fiscal entra en el piloto.
- [ ] Capacitación del personal y procedimiento manual si falla el sistema.
- [ ] Plan de regreso definido.

## 9. Registro de control de cambios: SEC-2

| Campo | Contenido |
|---|---|
| **ID** | SEC-2 |
| **Decisión anterior** | Laravel 11 (secciones 1 y 2) |
| **Nueva decisión** | Laravel 13, sujeto a la prueba técnica; Laravel 12 como alternativa |
| **Motivo** | Laravel 11 sin parches de seguridad desde 2026-03-12 |
| **Modelo de datos** | Sin cambios esperados; lo confirma la prueba técnica |
| **Estados** | Sin cambios |
| **API** | Sin cambios |
| **Android** | Sin cambios |
| **Web** | Sin cambios |
| **Seguridad** | Mejora: versión con soporte hasta 2028-03-17 |
| **Auditoría** | Sin cambios |
