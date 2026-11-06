# Revisión de seguridad del contrato

**Fecha:** 2026-11-02
**Alcance:** `openapi/restia-api.yaml` y las decisiones aprobadas
**Estado:** SEC-1 y SEC-2 APROBADAS; Laravel 13 confirmado en la prueba técnica

**Esto revisa un diseño, no un sistema.** No hay código que atacar, así que no sustituye un pentest ni una revisión del backend. Conviene que una persona de seguridad la confirme antes de producción.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **SEC-1** | Acciones sobre un usuario de varias sucursales (precisa U-2 y U-5) | Generar enlace de contraseña, desactivar y reactivar exigen ser `ADMIN` en **todas** sus sucursales | Mantener "al menos una" |
| **SEC-2** | Versión de Laravel | Cambiar a Laravel 13, sujeto a la prueba técnica de D-36 | Laravel 12, con soporte hasta 2027-02-24 |

## 2. Hallazgos

| # | Severidad | Hallazgo | Estado |
|---|---|---|---|
| 1 | **Crítica** | Laravel 11 ya no recibe parches de seguridad desde 2026-03-12 | Resuelto: Laravel 13 confirmado |
| 2 | **Alta** | Robo de cuenta entre sucursales: un `ADMIN` de una sucursal podía generar el enlace de contraseña de un `ADMIN` de otra | Corregido; SEC-1 aprobada |
| 3 | **Alta** | `Money` aceptaba negativos en peticiones: un pago de `-100` o una propina negativa pasaban el esquema | Corregido |
| 4 | **Alta** | `imageUrl` admitía `javascript:` y `data:`, y se muestra en el menú público | Corregido: solo `https://` |
| 5 | Media | Búsqueda y consulta de clientes sin límite de uso, lo que permitía extraer la base | Corregido: `429` |
| 6 | Media | Generar enlaces de contraseña y códigos de alta sin límite de uso | Corregido: `429` |
| 7 | Media | 11 listas y 12 números sin tope en peticiones | Corregido |
| 8 | Media | Tokens y enlaces en respuestas sin `Cache-Control: no-store` | Corregido |
| 9 | Media | Contraseñas nuevas y CSD sin `writeOnly`; archivos del CSD sin tamaño máximo | Corregido |
| 10 | Media | El enlace de contraseña podía llevar el token en la query | Corregido: en el fragmento, como QR-5 |
| 11 | Media | Dar de alta a un usuario invitado desde otra sucursal podía entregar un enlace nuevo | Corregido: solo si el alta creó al usuario |
| 12 | Media | Identificadores de otra sucursal en el cuerpo (39 campos) | **Requisito de backend** (sección 3) |
| 13 | Media | Repetir una `Idempotency-Key` ajena podría devolver la respuesta de otra persona | **Requisito y CON-1** |
| 14 | Media | Inyección de operadores de MongoDB por parámetros como `status[$ne]=x` | **Requisito de backend** |
| 15 | Media | Texto libre usado en panel e impresoras: XSS y comandos ESC/POS | **Requisito de backend** |
| 16 | Baja | Número de tarjeta capturado por error en `reference` o `notes` | **Requisito de backend** |
| 17 | Baja | Un `ADMIN` de una sucursal puede cerrar las sesiones de quien también pertenece a otra (A-10) | Aceptado: es molesto, pero no da acceso |

Los hallazgos 3 a 11 y las reglas de la sección 5 ahora los comprueba `tests/contract`. De las 13 pruebas nuevas, 12 fallaron antes de corregir y todas pasan después. La otra, sin datos personales en la query, ya se cumplía.

## 3. Requisitos para el backend

El contrato no los puede garantizar:

- **Aislamiento entre sucursales:** todo ID, en ruta o cuerpo, se resuelve dentro de la sucursal de la ruta. Si es de otra, responde `404`, sin revelar que existe. Conviene aplicarlo en una capa común, no en cada controlador, y probarlo por cada campo.
- **Idempotencia:** el registro incluye actor y operación. Otro actor con la misma clave recibe `IDEMPOTENCY_KEY_REUSED`, nunca la respuesta guardada.
- **Consultas:** rechazar parámetros de tipo arreglo u objeto cuando el contrato pide un valor simple, porque Laravel los convierte automáticamente y `laravel-mongodb` podría interpretarlos como operadores.
- **Salida:** escapar el texto libre en el panel. El agente elimina caracteres de control antes de imprimir.
- **Tarjetas:** rechazar en `reference` secuencias de 13 a 19 dígitos que pasen la validación de Luhn.
- **Logs:** nunca registrar `Authorization`, `X-QR-Token`, `X-Approval-Token`, `X-XSRF-TOKEN`, cookies, cuerpos de autenticación ni CSD.
- **Transporte:** solo TLS, HSTS, cookies `Secure`, `HttpOnly` y `SameSite`, y CORS por lista cerrada.
- **Código de confirmación:** al menos 8 caracteres aleatorios, para que el límite de intentos baste.

## 4. Riesgos aceptados en decisiones aprobadas

Los aprobaste con conocimiento, y aquí quedan reunidos:

| Decisión | Riesgo |
|---|---|
| P-11, CA-13 | Cualquier mesero cobra en cualquier turno, así que una diferencia no se atribuye a una persona |
| I-3 | Un gerente se puede aprobar a sí mismo, con marca para revisión |
| F-15 | WAITER ve RFC y razón social de las facturas de su sucursal |
| PR-13 | WAITER y KITCHEN pueden reimprimir recibos y comandas |
| U-2 | Quien da de alta ve el enlace de contraseña |
| AU-1 | `MANAGER` ve la auditoría de otros gerentes de su sucursal |
| I-11 | KITCHEN ve costos de compra en recepciones |

## 5. Lo que salió bien

- Todas las peticiones rechazan campos no declarados, lo que evita asignación masiva.
- Ningún dato personal viaja en la query, y `lookup` y la búsqueda de clientes usan `POST`.
- Sin `DELETE` en el contrato.
- `reservation-ai` y `printing-agent` están limitados a listas cerradas de operaciones.
- La API pública no expone IDs internos ni datos operativos.
- Login y recuperación no revelan si un correo existe.

## 6. SEC-2: versión de Laravel

| Versión | Parches de seguridad hasta |
|---|---|
| Laravel 11 (documento maestro) | 2026-03-12, **vencido** |
| Laravel 12 | 2027-02-24 |
| Laravel 13 | 2028-03-17 |

Recomiendo Laravel 13 porque Restia aún no tiene código y es la opción con mayor margen. **Actualización:** la prueba técnica confirmó que `laravel-mongodb` y Sanctum funcionan con Laravel 13 (`Resultados_Prueba_Tecnica.md`). También confirmó el hallazgo 14: sin validación, la inyección de operadores funciona.
