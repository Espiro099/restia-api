# Módulo Public QR: decisiones del contrato

**Estado:** APROBADAS (QR-1 a QR-12)
**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

Se conserva lo definido en las secciones 2, 19 y 22 del documento maestro: página web pública sin instalación, token opaco en `X-QR-Token`, sin exponer empleados, inventario, pagos, clientes ajenos, ObjectIds ni estructura interna. También se conserva que `table_presence_signals` representa actividad reciente y no ocupación.

El documento no dice qué hace el cliente en esa página. Esa es la decisión de fondo del módulo.

## 1. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **QR-1** | Alcance en v1 | Solo ver el menú y registrar presencia | Agregar llamar al mesero, pedir la cuenta o comandar |
| **QR-2** | Datos de la sesión | Nunca: ni comandas, ni cuenta, ni estado de la mesa | Mostrar la cuenta al cliente |
| **QR-3** | Relación con la sesión (D-36) | El token identifica la mesa, no la sesión; es fijo e impreso | QR dinámico por sesión |
| **QR-4** | Rotación | Manual, por mesa; el token anterior sirve 7 días más | Rotación automática |
| **QR-5** | Ubicación del token | En el fragmento de la URL (`#`), y la página lo envía en `X-QR-Token` | En la ruta de la URL |
| **QR-6** | Identificadores públicos | La API pública no recibe ni devuelve IDs de sucursal, mesa o sesión; el token basta | Devolver UUID de sucursal y mesa |
| **QR-7** | Menú público | Vista reducida con `ETag`: sin códigos, recetas, costos, datos fiscales ni estaciones; agotados visibles y marcados | Ocultar los agotados |
| **QR-8** | Presencia | Un documento por mesa; sin datos del visitante, IP ni cookies | Registrar visitantes |
| **QR-9** | Límites de uso | Por IP y por token, con `429`; el token inválido siempre da la misma respuesta | Solo por IP |
| **QR-10** | Autofacturación (pendiente de Fiscal) | Fuera de v1; las facturas las genera el personal | Portal público de facturación |
| **QR-11** | Despliegue | Dominio distinto al del panel, sin cookies, `Referrer-Policy: no-referrer`, `noindex` y sin scripts de terceros | Mismo dominio del panel |
| **QR-12** | Permisos del personal | `ADMIN` y `MANAGER` consultan y rotan, solo en Web | Solo `ADMIN` |

## 2. Por qué tan limitado (QR-1, QR-2)

Un QR impreso es permanente y cualquiera puede fotografiarlo. El token solo prueba que **alguien vio esa mesa alguna vez**, no que esté ahí ahora. De esto se desprende:

| Función | Efecto de un mal uso | Decisión |
|---|---|---|
| Ver el menú | Ninguno; es información pública | Sí |
| Registrar presencia | Una mesa aparece como `POSIBLE_OCUPACION` por 10 min; es solo un indicio (T-6) | Sí, con límites |
| Ver cuenta o comandas | Quien guardó la foto vería el consumo de los siguientes clientes | **No** |
| Llamar al mesero, pedir la cuenta | Avisos falsos desde cualquier lugar durante el servicio | **No en v1** |
| Comandar | Comida preparada que nadie pidió, e inventario consumido | **No en v1** |

Si más adelante quieres alguna de las tres últimas, primero hace falta probar que la persona está en la mesa, por ejemplo con un código visible que cambia con cada sesión. Es un diseño aparte.

## 3. Reglas

**Token (QR-3, QR-4)**

- Opaco, de 256 bits, generado al crear la mesa. Es único aunque esté en periodo de gracia.
- Se guarda sin cifrar: ya está impreso sobre la mesa, solo permite estas tres operaciones, y el personal debe poder reimprimirlo.
- Rotar reemplaza el token. El anterior sigue válido 7 días, para cambiar los impresos sin dejar mesas sin servicio. Una segunda rotación en ese plazo invalida al anterior de inmediato.
- Se rota si alguien abusa de una mesa, no de forma periódica, porque cada rotación exige reimprimir.

**URL (QR-5)**

El QR codifica `https://<dominio público>/m#<token>`. El navegador no envía el fragmento al servidor, así que no aparece en logs de acceso, proxies ni encabezados `Referer`. El costo es que la página requiere JavaScript.

**Estado de sucursal y mesa**

| Situación | Menú | Presencia |
|---|---|---|
| Mesa `ACTIVE`, sucursal activa | Sí | Se registra |
| Mesa `MAINTENANCE` | Sí | `204`, sin registrar |
| Mesa `INACTIVE` o sucursal inactiva | `401 QR_TOKEN_INVALID` | `401 QR_TOKEN_INVALID` |
| Token inexistente o vencido | `401 QR_TOKEN_INVALID` | `401 QR_TOKEN_INVALID` |

**Menú (QR-7)**

- Solo productos activos, con precio con impuestos incluidos (M-7).
- Usa `menu_version` como `ETag`, igual que el menú interno, y no consulta la mesa para armar el contenido.
- **Alérgenos:** el documento maestro no los cubre, como señalé en Inventory. En una página para clientes el vacío es más visible; hay que decidir si se muestran.

**Presencia (QR-8)**

- La página la envía al cargar y cada 5 minutos mientras está visible.
- Se actualiza un solo documento por mesa con `expires_at` a 10 minutos, de modo que el índice TTL del documento maestro limpia lo viejo y no crece la colección.
- Con una sesión activa, el estado derivado ya ignora la presencia (sección 3 de Tables/Sessions).
- No genera auditoría: no es una acción de negocio y sería un vector de saturación.

**Límites (QR-9), valores iniciales**

| Operación | Límite |
|---|---|
| Lecturas | 60 por minuto por IP |
| Presencia | 6 por minuto por token |
| Tokens inválidos | 20 por minuto por IP |

Muchos clientes pueden compartir la IP del wifi del restaurante, así que hay que medir estos valores en piso (tarea 37).

**CSRF y CORS**

No aplica CSRF: no hay cookies y el token va en un encabezado (sección 25). CORS solo admite el dominio público.

**Privacidad**

La página no recoge datos personales, aunque los logs del servidor registran direcciones IP. Falta confirmar con el área legal si requiere aviso de privacidad y por cuánto tiempo se retienen esos logs.

## 4. Concurrencia

- **Presencia:** `upsert` sin transacción; dos señales simultáneas pueden dejar dos documentos en la primera escritura, sin efecto, porque el estado derivado solo pregunta si hay alguno vigente.
- **Rotar contra rotar:** `If-Match` en la mesa.
- **Rotar contra leer:** quien usa el token anterior sigue siendo válido durante el periodo de gracia.

## 5. Permisos

| Permiso | ADMIN | MANAGER | WAITER | KITCHEN |
|---|---|---|---|---|
| `tables.qr_read` | Sí | Sí | No | No |
| `tables.qr_rotate` | Sí | Sí | No | No |

Las operaciones públicas no usan roles: su único actor es quien tiene el token.

## 6. Cambios a contratos ya aprobados

| Módulo | Cambio | Origen |
|---|---|---|
| Fiscal | Autofacturación descartada para v1 | QR-10 |
| Tables | Consulta y rotación de token, que Tables/Sessions dejó para este módulo | QR-4 |

## 7. Cambios al modelo físico

- **`tables`:** `previous_qr_token`, `previous_qr_expires_at`, además de `qr_token`.
- **`table_presence_signals`:** `branch_id`, `table_id`, `last_seen_at`, `expires_at`. Sin datos del visitante.
- **Índices nuevos:** `partial unique(previous_qr_token)` donde exista; `(branch_id, table_id)` en señales para el `upsert`.
- **Códigos de error nuevos:** `QR_TOKEN_INVALID`.
