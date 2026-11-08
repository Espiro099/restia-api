# restia-api

Backend de Restia: API REST en Laravel 13 con MongoDB. Es la fuente única del contrato que usan `restia-mobile` y `restia-web`.

**Estado:** el contrato y las decisiones están aprobados. El backend tiene la base técnica; aún no tiene módulos ni endpoints de negocio.

## Entorno local

Requisitos:

- PHP 8.3 o superior, con la extensión `mongodb`. El PHP 8.2 de XAMPP no sirve.
- Composer.
- MongoDB Server 8. El script usa el instalado en `C:\Program Files\MongoDB\Server`.

Primera vez:

```bash
composer run setup
```

```powershell
.\scripts\mongo-local.ps1
```

```bash
php artisan migrate
```

```bash
php artisan restia:install
```

```bash
php artisan serve
```

`mongo-local.ps1` arranca MongoDB como replica set de un nodo en el puerto **27018**, con sus datos en `.mongo-data/`. No toca un MongoDB que ya corra como servicio en el 27017. Para detenerlo: `.\scripts\mongo-local.ps1 -Stop`. Si `php` no está en el PATH, pasa `-Php <ruta a php.exe>`.

`restia:install` crea la primera sucursal y su `ADMIN` (decisión B-2), y solo funciona con la base vacía. Pide los datos o los toma de `--branch-code`, `--branch-name`, `--email` y `--name`. La contraseña se pide sin eco o se lee de `RESTIA_ADMIN_PASSWORD`; nunca va en un argumento.

La API responde en `http://127.0.0.1:8000/api/v1`, y `GET /up` indica si la aplicación está viva.

## Identidad (fase 3)

Flujo Android de D-36, ya implementado:

| Operación | Ruta |
|---|---|
| Código de alta (gerente) | `POST /branches/{branchId}/devices/enrollment-codes` |
| Alta del dispositivo | `POST /auth/mobile/enroll` |
| Login, renovación y logout | `POST /auth/mobile/login`, `/auth/mobile/refresh`, `/auth/logout` |
| Usuario, membresías y permisos | `GET /auth/me` |
| Sesiones propias | `GET /auth/sessions`, `POST /auth/sessions/{sessionId}/revoke` |
| Dispositivos | `GET /branches/{branchId}/devices`, `POST .../{deviceId}/block` y `/retire` |

Falta: login Web con cookie y CSRF, revocar sesiones de otro usuario, contraseñas (U-2), altas de empleados y gestión de sucursales.

Cada ruta autenticada pasa por dos middleware:

- `auth.session`: Bearer de Sanctum, y además sesión `ACTIVE` y en plazo, usuario activo y, en Android, dispositivo activo. Se revisa en cada petición, así que revocar corta el acceso al instante.
- `branch:<permiso>`: sucursal de la ruta, que el dispositivo sea de esa sucursal (A-6), membresía activa y permiso del rol.

Los permisos por rol están en `config/permissions.php`, generado por `tools/gen_permissions.py` desde `docs/Matriz_Endpoints.md` y la tabla de permisos de `Decisiones_Orders.md`. Las duraciones y límites de D-36 están en `config/restia.php`.

## Servicio en piso (fase 4, primera parte)

Lo que la app Android usa para atender una mesa:

| Operación | Ruta |
|---|---|
| Mesas con estado operativo derivado (T-5) | `GET /branches/{branchId}/tables`, `GET .../tables/{tableId}` |
| Sesiones de mesa | `GET`, `POST /branches/{branchId}/table-sessions`, `GET .../{sessionId}` |
| Menú con `ETag` (M-9) | `GET /branches/{branchId}/menu` |
| Comandas (O-1, O-2) | `GET`, `POST /branches/{branchId}/orders`, `GET .../{orderId}` |
| Cambios de estado por lote (O-5) | `POST /branches/{branchId}/order-units/transitions` |
| Autorización de un gerente (D-37) | `POST /branches/{branchId}/approvals` |
| Cola de cocina, barra y entrega, con `ETag` (RT-1) | `GET /branches/{branchId}/order-units` |
| Editar las notas de una partida (O-2) | `PATCH .../orders/{orderId}/items/{itemId}` |
| Reasignar la estación de una unidad (O-11) | `POST .../order-units/{unitId}/station` |
| Editar comensales y responsable (con `If-Match`) | `PATCH .../table-sessions/{sessionId}` |
| Agregar y separar mesas (T-1) | `POST .../{sessionId}/tables`, `POST .../{sessionId}/tables/{tableId}/release` |
| Cerrar y cancelar la sesión (T-3, T-4) | `POST .../{sessionId}/close`, `POST .../{sessionId}/cancel` |
| Confirmar la limpieza de una mesa (T-2) | `POST .../{sessionId}/tables/{tableId}/cleaning-confirmation` |

Las operaciones que crean o cambian algo exigen `Idempotency-Key` (CON-1): `App\Support\Idempotency` guarda la respuesta en `idempotency_records`, en la misma transacción, durante 72 horas.

La cola sin `status` trae solo lo que sigue en curso (`PENDING`, `PREPARING` y `READY`), de lo más antiguo a lo más reciente, con nombre, modificadores, notas y números de mesa. Las notas se editan mientras todas las unidades de la partida estén `PENDING`, y la estación se reasigna solo en `PENDING`.

Los cambios a una sesión ya abierta piden `If-Match` con su `version` y responden con el `ETag` nuevo. Cerrar deja cada mesa en `POR_LIMPIAR`; la sesión pasa a `CLOSED` cuando se confirma la limpieza de la última. Confirmar la limpieza no pide `If-Match`: usa `membershipVersion`, la versión de membresía con la que la mesa entró a limpieza, y repetirla no cambia nada.

Pendiente:

- **Siguiente parte de la fase 4:** impresión.
- **Con Inventory:** consumo de inventario en `START`.
- **Con Checks (fase 5):** asignar unidades a cuentas, `ORDER_UNIT_PAID` y `CUENTA_SOLICITADA`.
- **Con Public QR:** el estado `POSIBLE_OCUPACION`.

## Catálogo y mesas

Altas y cambios para el panel Web. La app Android solo lee el catálogo y marca productos agotados.

| Operación | Ruta |
|---|---|
| Mesas: alta, cambios y estado | `POST /branches/{branchId}/tables`, `PATCH .../tables/{tableId}`, `POST .../tables/{tableId}/status` |
| Estaciones | `GET`, `POST .../stations`, `GET`, `PATCH .../stations/{stationId}`, `POST .../{stationId}/status` |
| Modificadores y sus opciones | `GET`, `POST .../modifiers`, `GET`, `PATCH .../modifiers/{modifierId}`, `POST .../{modifierId}/status` |
| Productos | `GET`, `POST .../products`, `GET`, `PATCH .../products/{productId}`, `POST .../{productId}/status` |
| Agotar un producto, sin `If-Match` | `POST .../products/{productId}/availability` |
| Estaciones de un producto (M-2) | `PUT .../products/{productId}/routes` |

- **Productos:** nacen inactivos. Para activarse necesitan al menos una ruta a una estación activa (`PRODUCT_NOT_SELLABLE`).
- **Estaciones:** no se pueden desactivar si les quedan platillos sin terminar, ni si son la única ruta de un producto activo (`STATION_IN_USE`).
- **Opciones de un modificador:** no se borran, para que las comandas viejas sigan apuntando a ellas. Se desactivan.
- **Mesas:** una mesa con sesión o limpieza pendiente no puede salir de `ACTIVE` (`TABLE_ALREADY_ASSIGNED`).
- **`menu_version`:** cada cambio del catálogo la sube. Es el `ETag` de `GET /menu`.

Las recetas llegan con Inventory y el QR de mesa con Public QR. Estas rutas ya validan la sesión Bearer. La sesión con cookie del panel Web llega con el cliente Web.

Mientras no exista el panel Web para dar de alta el catálogo, `restia:demo` carga datos de prueba en una sucursal vacía: 6 mesas, cocina y barra, modificadores y productos (uno agotado). Con `--staff` crea además `mesero@demo.mx`, `cocina@demo.mx` y `gerente@demo.mx`, con la contraseña de `RESTIA_DEMO_PASSWORD`. No corre en producción.

```bash
RESTIA_DEMO_PASSWORD='una-clave-de-prueba' php artisan restia:demo --branch=CENTRO --staff
```

## Pruebas del backend

Con el replica set local arrancado:

```bash
php artisan test
```

Usan la base `restia_test` y la borran al empezar; nunca tocan `restia`.

## Base técnica

| Pieza | Archivo | Qué hace |
|---|---|---|
| Errores | `app/Support/ApiErrors.php` | Toda respuesta de error usa el `ErrorResponse` del contrato, con el estado y mensaje de `config/errors.php` |
| `X-Request-ID` | `app/Http/Middleware/AssignRequestId.php` | Respeta el UUID del cliente o genera uno, en toda respuesta |
| Transacciones | `app/Support/Mongo.php` | Hasta 10 reintentos con espera aleatoria; reintentos agotados son `503 SERVICE_BUSY` |
| Documentos | `app/Models/Document.php` y `Concerns/` | `uuid` público, `_id` oculto, `version` y `forBranch` por `ObjectId` |
| Conflictos | `app/Exceptions/DomainConflict.php` | Una regla de negocio violada, con su código del catálogo |
| Respuestas | `app/Support/Api.php` | Envoltura `{data, meta}`, paginación y validación que rechaza campos no declarados |
| Auditoría y outbox | `app/Support/Audit.php`, `Outbox.php` | Se escriben en la misma transacción que el cambio |

Cómo modelar cada colección está en `docs/Guia_Base_de_Datos_MongoDB.md`.

## Estructura

| Ruta | Contenido |
|---|---|
| `openapi/restia-api.yaml` | Contrato OpenAPI 3.0.3: 212 operaciones. Es la fuente de verdad. |
| `docs/Restia_Documento_Maestro_v2.md` | Documento maestro actualizado. |
| `docs/Guia_Base_de_Datos_MongoDB.md` | Cómo construir la base: colecciones, clases, índices y transacciones. |
| `docs/Plan_Implementacion.md` | Fases, RACI, riesgos y listas de verificación. |
| `docs/decisiones/` | Decisiones aprobadas por módulo y D-36 (credenciales). |
| `docs/` (resto) | Catálogo de errores, matrices, pruebas de contrato y revisiones. |
| `docs/prueba-tecnica/` | Código y resultados de la prueba técnica de Laravel 13 y de la guía. No es código de producción. |
| `tests/contract/` | Pruebas del contrato en Python. |
| `postman/` | Colección y ambiente local. |
| `tools/` | Scripts que generan las matrices a partir del contrato. |

## Pruebas del contrato

```bash
pip install -r tests/requirements.txt
```

```bash
python -m pytest -q tests/contract
```

## Regenerar matrices

Desde la raíz del repositorio:

```bash
python tools/gen_matrices.py
```

Escribe `docs/Catalogo_Errores.md`, `docs/Matriz_Endpoints.md`, `docs/Matriz_Transiciones.md` y `tests/contract/casos_negativos.csv`. Después, para llevar los códigos al backend:

```bash
python tools/gen_error_catalog.py
```

```bash
python tools/gen_permissions.py
```

La integración continua falla si `config/errors.php` o `config/permissions.php` no coinciden con lo generado.

`tools/apply_conflict_codes.py` y `tools/apply_security_fixes.py` se usaron una sola vez para aplicar revisiones al contrato. Se conservan como registro; no hace falta volver a ejecutarlos.

## Cambios al contrato

Todo cambio al contrato pasa por PR, con las pruebas del contrato en verde. Si rompe compatibilidad, se registra en el control de cambios del plan de implementación, y se copia el contrato nuevo a `restia-mobile/docs/contrato/`.
