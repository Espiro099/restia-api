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

Empleados y contraseñas (U-1 a U-5, A-10):

| Operación | Ruta | Permiso |
|---|---|---|
| Empleados de la sucursal | `GET /branches/{branchId}/members`, `GET .../{userId}` | `members.read` |
| Alta, cambio de rol y baja | `POST /branches/{branchId}/members`, `PATCH .../{userId}`, `POST .../{userId}/revoke` | `members.manage` |
| Enlace para definir la contraseña | `POST /users/{userId}/password-setup-link` | `users.manage` |
| Desactivar y reactivar | `POST /users/{userId}/deactivate`, `/activate` | `users.manage` |
| Revocar todas sus sesiones | `POST /users/{userId}/sessions/revoke` | `users.sessions.revoke` |
| Cambiar la propia, recuperar y definir | `POST /auth/password/change`, `/auth/password/forgot`, `/auth/password/reset` | Sesión propia, ninguno, enlace |

- **Alta:** crea el usuario si el correo no existe, en estado `INVITED`, y devuelve una sola vez un enlace de 72 h para definir la contraseña. Con la contraseña definida pasa a `ACTIVE`. Si el correo ya existe, solo agrega la membresía, sin enlace.
- **Quién gestiona a quién (U-4):** MANAGER solo da de alta, cambia o revoca a WAITER y KITCHEN. Nadie se cambia a sí mismo, y cada sucursal conserva un ADMIN activo (`LAST_BRANCH_ADMIN`).
- **Usuario global:** desactivar, reactivar y generar el enlace exigen ser ADMIN en todas las sucursales del usuario. Revocar sus sesiones basta con el permiso en una de ellas. Desactivar revoca sus sesiones.
- **Contraseñas:**
  - Mínimo 12 caracteres y, con `RESTIA_CHECK_BREACHED_PASSWORDS`, se rechazan las filtradas (Have I Been Pwned por k-anonimato; apagado en pruebas).
  - Los enlaces son de un solo uso, se guardan como hash y llevan el token en el fragmento de la URL (`RESTIA_WEB_URL/contrasena#<token>`).
  - "Olvidé mi contraseña" siempre responde 204 y manda un enlace de 60 minutos por correo.
  - Cambiar la propia revoca las demás sesiones; definirla con un enlace las revoca todas.
- **Página `/contrasena`:** mientras no exista el panel web, el backend sirve una página mínima para definir la contraseña con el enlace o pedir uno nuevo por correo.
  - Lee el token del fragmento, lo quita de la barra de direcciones y solo llama a `/auth/password/reset` y `/auth/password/forgot`.
  - Usa un CSP estricto con *nonce* por petición, sin cookies, sin caché, sin Referer y sin indexar.
  - `RESTIA_WEB_URL` toma `APP_URL` por omisión, así los enlaces llevan a esta página. Cuando exista el panel, basta con apuntarlo ahí.

Falta: login Web con cookie y CSRF y gestión de sucursales.

Cada ruta autenticada pasa por dos middleware:

- `auth.session`: Bearer de Sanctum, y además sesión `ACTIVE` y en plazo, usuario activo y, en Android, dispositivo activo. Se revisa en cada petición, así que revocar corta el acceso al instante.
- `branch:<permiso>`: sucursal de la ruta, que el dispositivo sea de esa sucursal (A-6), membresía activa y permiso del rol.

Los permisos por rol están en `config/permissions.php`, generado por `tools/gen_permissions.py` desde `docs/Matriz_Endpoints.md` y la tabla de permisos de `Decisiones_Orders.md`. Las duraciones y límites de D-36 están en `config/restia.php`.

## Sucursales

| Operación | Ruta | Permiso |
|---|---|---|
| Sucursales propias | `GET /branches` | Membresía activa |
| Consulta | `GET /branches/{branchId}` | Membresía activa en la sucursal |
| Alta | `POST /branches` | `branches.create` en alguna sucursal |
| Cambios | `PATCH /branches/{branchId}` | `branches.update` |
| Activar y desactivar | `POST /branches/{branchId}/activate`, `/deactivate` | `branches.manage_status` |

- **Lectura:** en Android, `GET /branches` devuelve solo la sucursal del dispositivo.
- **Alta (B-2):** quien da de alta una sucursal queda como su ADMIN. La primera se crea con `restia:install`.
- **Lo que no cambia:** el código y la moneda.
- **Zona horaria y corte del día:** solo cambian sin operación activa (`BRANCH_HAS_ACTIVE_OPERATIONS`), porque definen `business_date`.
- **Reservas:** el horario (`reservationHours`) y la tolerancia de no presentado se configuran aquí.
- **Desactivar:** no hay borrado, solo desactivación. Se rechaza si hay sesiones de mesa abiertas, turnos de caja abiertos o en cierre, pagos sin resolver o reservas futuras confirmadas.

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

## Pagos y reembolsos

Decisiones_Payments. El empleado registra el resultado de la terminal (P-1); nunca se guarda número de tarjeta ni CVV, solo marca, últimos 4 y autorización (P-10).

| Operación | Ruta |
|---|---|
| Pagos: lista, detalle, cobrar | `GET`, `POST /branches/{branchId}/payments`, `GET .../payments/{paymentId}` |
| Resultado de la terminal y conciliación | `POST .../payments/{paymentId}/result`, `.../reconcile` |
| Reembolsos | `POST .../payments/{paymentId}/refunds`, `GET .../refunds`, `GET .../refunds/{refundId}` |
| Resultado y conciliación de un reembolso | `POST .../refunds/{refundId}/result`, `.../reconcile` |

- **Cobrar:** en un turno `OPEN`. El efectivo se confirma al crear y calcula el cambio con `amountTendered`; tarjeta y transferencia quedan `PENDING` (P-2). La propina va aparte y no entra en el total (P-6).
- **Saldo reservado:** un pago nuevo solo cabe en `total − pagado − pendiente` (`PAYMENT_EXCEEDS_BALANCE`, P-3). Varios pagos dividen una cuenta.
- **Estado de la cuenta:** el primer pago la pasa a `REQUESTED`; luego `PARTIALLY_PAID` o `PAID`.
- **UNKNOWN:** si no se vio el resultado; también un `PENDING` de más de 15 minutos. Bloquea nuevos pagos en esa cuenta (`PAYMENT_REQUIRES_RECONCILIATION`) hasta que un gerente lo concilie con motivo (P-4, P-5).
- **Reembolso:** del pago completo, con monto y propina, por el mismo método y en un turno abierto (P-7, P-9, CA-7). El efectivo se confirma al crear. Uno activo por pago (`REFUND_ALREADY_EXISTS`); un `FAILED` permite otro. Con todo lo pagado reembolsado, la cuenta queda `REFUNDED` y puede pasar sus unidades a otra cuenta para cobrarlas de nuevo (P-8).

## Caja

Decisiones_Cash. El turno es de la caja, no de una persona (CA-2), y solo gerentes la operan; el mesero ve los turnos abiertos sin montos, para saber dónde cobrar (CA-13).

| Operación | Ruta |
|---|---|
| Cajas | `GET`, `POST /branches/{branchId}/cash-registers`, `GET`, `PATCH .../{cashRegisterId}`, `POST .../{cashRegisterId}/status` |
| Turnos: lista, detalle, abrir | `GET`, `POST .../cash-shifts`, `GET .../cash-shifts/{cashShiftId}` |
| Resumen y movimientos | `GET .../{cashShiftId}/summary`, `GET`, `POST .../{cashShiftId}/movements` |
| Cierre en dos pasos | `POST .../{cashShiftId}/start-closing`, `.../cancel-closing`, `.../close` |

- **Abrir:** un turno activo por caja. Si el fondo difiere de lo que dejó el turno anterior, exige `openingDifferenceReason` (CA-9).
- **Movimientos:** `DEPOSIT` y `WITHDRAWAL`, inmutables y con motivo, solo en `OPEN` (CA-5).
- **Esperado:** fondo + efectivo cobrado con propina + depósitos − retiros − efectivo devuelto con propina (CA-6). Tarjeta y transferencia se reportan por método.
- **Cerrar:** `CLOSING` frena operaciones nuevas y se puede cancelar. `close` exige que no haya pagos ni reembolsos `PENDING` o `UNKNOWN`. Toda diferencia pide motivo; si supera la tolerancia de la caja, el turno queda con `reviewRequired` y se publica `cash_shift.difference_detected` (CA-8). El resumen queda congelado (CA-10).
- **Turno olvidado:** a las 24 horas se publica `cash_shift.forgotten`; no se cierra solo (CA-11).

## Cuentas

Decisiones_Checks. Cada unidad nueva va a la cuenta `OPEN` más reciente de la sesión, a `targetCheckId` si se indica, o a una cuenta nueva si no hay ninguna `OPEN`.

| Operación | Ruta |
|---|---|
| Lista y detalle | `GET /branches/{branchId}/checks`, `GET .../checks/{checkId}` |
| Cuenta vacía, para dividir | `POST .../checks` |
| Mover unidades a otra cuenta de la sesión | `POST .../checks/{checkId}/units/move` |
| Solicitar, reabrir, cancelar | `POST .../{checkId}/request`, `.../reopen`, `.../cancel` |
| Cortesía y su reversión | `POST .../{checkId}/adjustments`, `.../adjustments/{adjustmentId}/revert` |

- **Totales:** se recalculan en cada cambio. El precio incluye IVA; la base y el impuesto se calculan por unidad, con redondeo a 2 decimales. Las unidades canceladas o anuladas no se cobran.
- **Mover:** `If-Match` en el origen y `targetCheckVersion` del destino, que debe estar `OPEN` y en la misma sesión. La cortesía viaja con la unidad.
- **Solicitar:** `REQUESTED` ya no recibe unidades y la mesa pasa a `CUENTA_SOLICITADA`.
- **Reabrir y cortesías:** ADMIN y MANAGER directo; el mesero con `X-Approval-Token` (`CHECK_REOPEN` ligado a la cuenta, `CHECK_ADJUSTMENT` ligado a la unidad o a la cortesía). Si la cuenta llega a 0 con unidades, queda `PAID`.
- **Cierre de la sesión:** cancela las cuentas sin unidades que se cobren y deja `PAID` las de total 0. Las demás deben estar `PAID`, `CANCELLED` o `REFUNDED` (`SESSION_HAS_OPEN_CHECKS`, T-4).
- **Pagos:** un pago confirmado congela las unidades de su cuenta (`ORDER_UNIT_PAID`, C-2); uno en curso bloquea mover, reabrir y dar cortesías.

## Inventario

Decisiones_Inventory. El stock inicial se carga con un ajuste o con una recepción.

| Operación | Ruta |
|---|---|
| Ingredientes | `GET`, `POST /branches/{branchId}/ingredients`, `GET`, `PATCH .../ingredients/{ingredientId}`, `POST .../{ingredientId}/status` |
| Bloqueo alimentario, sin `If-Match` | `POST .../ingredients/{ingredientId}/food-safety` |
| Recetas de un producto | `GET`, `POST .../products/{productId}/recipes`, `GET .../recipes/{recipeVersion}` |
| Movimientos: consulta, ajuste y desperdicio | `GET`, `POST .../inventory-movements` |
| Recepciones | `GET`, `POST .../inventory-receipts`, `GET .../inventory-receipts/{receiptId}` |
| Conteos físicos | `GET`, `POST .../inventory-counts`, `GET .../{countId}`, `PUT .../{countId}/lines`, `POST .../{countId}/apply`, `POST .../{countId}/cancel` |

- **Recetas:** cada publicación es una versión nueva; la anterior queda `SUPERSEDED`. El `If-Match` es la versión del producto.
- **START** suma la receta vigente y los ajustes de ingredientes de las opciones elegidas, y crea un movimiento `CONSUMPTION` por unidad e ingrediente en la misma transacción. No escribe en el ingrediente: `php artisan restia:inventory`, cada 5 s en el scheduler, aplica los movimientos a `stock` (I-1). `--rebuild` recalcula el stock desde los movimientos.
- **Sin stock:** `START` responde `INVENTORY_INSUFFICIENT` con los faltantes. Con un token `INVENTORY_OVERRIDE` de un gerente (`POST /approvals`, con el mismo motivo en la transición) sigue y el stock puede quedar negativo. Si el gerente se aprueba a sí mismo, el movimiento queda con `selfApproved`.
- **Bloqueo alimentario:** frena `START` con `INVENTORY_BLOCKED_FOOD_SAFETY` y ningún token lo salta. Cocina bloquea; solo ADMIN y MANAGER desbloquean.
- **Ingrediente en uso:** no se desactiva si una receta vigente lo usa (`INGREDIENT_IN_USE`, con los productos).
- **Stock bajo:** al bajar del mínimo se publica `ingredient.low_stock`; marcar el producto como agotado sigue siendo manual (I-10).
- **Recepciones (I-6, I-9):** inmutables, con proveedor en texto libre y costo unitario por línea. Cada línea crea un movimiento `RECEIPT`; un error se corrige con un ajuste.
- **Conteos (I-7):** uno `OPEN` por sucursal (`INVENTORY_COUNT_ALREADY_OPEN`). `PUT .../lines` reemplaza la lista completa; lo esperado de cada línea es la suma de los movimientos hasta su `countedAt`, no el stock proyectado. Al aplicar se recalcula y se crea un `COUNT_ADJUSTMENT` por cada diferencia, así que lo consumido después de contar se conserva. Aplicar y cancelar quedan para ADMIN y MANAGER (`inventory.apply_count`).

## Impresión

Decisiones_Printing y D-36, sección 11. Un agente local consulta trabajos hacia afuera, sin puertos abiertos.

| Operación | Ruta |
|---|---|
| Identidades de servicio: lista, alta, sucursales, rotar, revocar | `GET`, `POST /service-identities`, `PATCH .../{serviceIdentityId}`, `POST .../rotate`, `POST .../revoke` |
| La identidad del token (ServiceAuth) | `GET /service-identities/me` |
| Impresoras | `GET`, `POST /branches/{branchId}/printers`, `GET`, `PATCH .../printers/{printerId}`, `POST .../{printerId}/status` |
| Rutas de impresión | `GET`, `POST .../print-stations`, `GET`, `PATCH .../print-stations/{printStationId}`, `POST .../{printStationId}/status` |
| Agentes | `GET`, `POST .../print-agents`, `GET .../print-agents/{printAgentId}`, `POST .../{printAgentId}/status` |
| Configuración y latido del agente (ServiceAuth) | `GET /print-agent/config`, `POST /print-agent/heartbeat` |
| Trabajos: lista, detalle, intentos, pedir uno | `GET`, `POST .../print-jobs`, `GET .../print-jobs/{printJobId}`, `GET .../attempts` |
| Trabajos: reintentar, resolver, cancelar, reimprimir | `POST .../print-jobs/{printJobId}/retry`, `.../resolve`, `.../cancel`, `.../reprint` |
| Reclamar, empezar y reportar (ServiceAuth) | `POST /print-agent/jobs/claim`, `POST /print-agent/attempts/{attemptId}/start`, `.../result` |

- **Token de servicio:** empieza con `rsv_`, se muestra una sola vez y solo se guarda su SHA-256. Dura 90 días; al rotar, el anterior vale 7 días más. Revocar corta los dos de inmediato.
- **Quién administra identidades:** quien tiene `service_identities.manage` (ADMIN) en alguna sucursal. Solo ve las identidades que comparten una sucursal con él, y solo agrega o quita sucursales que administra.
- **Agente:** una identidad `PRINTING_AGENT` autorizada para la sucursal; una identidad admite un solo agente. Si el agente se desactiva, o su sucursal sale de la identidad, sus rutas responden 403.
- **Latido:** guarda `lastSeenAt`, la versión del agente y si cada impresora está en línea, sin subir `version`, para no romper el `If-Match` del panel.
- **Impresoras:** `NETWORK` lleva `host` y `port` (9100 por omisión); `USB`, el nombre local. No se desactivan con trabajos `CLAIMED` o `PRINTING` (`PRINTER_HAS_ACTIVE_JOBS`).
- **Rutas:** `KITCHEN_TICKET` y `VOID_NOTICE` necesitan una estación de producción. Una impresora inactiva no recibe rutas (`PRINTER_INACTIVE`).
- **Trabajos automáticos:** `php artisan restia:printing` lee outbox y crea una comanda por cada ruta activa de cada estación con `order.created`, y un aviso con `order_unit.voided`. Si todo lo de una estación se cancela antes de imprimir, su comanda pendiente se cancela. Sin ruta, publica `print_job.route_missing`; la comanda nunca se bloquea.
- **A petición:** `TEST` va directo a la impresora que se prueba (`printing.manage`). `PRE_CHECK` (cuenta no cancelada), `PAYMENT_RECEIPT` (pago `CONFIRMED`) y `CASH_SHIFT_REPORT` (turno, exige además `cash.read`) salen por la ruta indicada en `printStationId` o por la primera activa de su tipo; sin ruta, `PRINT_ROUTE_NOT_CONFIGURED`. El documento se arma al pedirlo y no cambia. El recibo lleva la leyenda de que no es comprobante fiscal y, de la tarjeta, solo la marca. El reporte de un turno abierto sale marcado como parcial.
- **Plazos:** el mismo comando devuelve a `PENDING` un reclamo vencido en `CLAIMED`, deja en `UNKNOWN` uno vencido en `PRINTING`, pasa a `FAILED` una comanda sin imprimir tras 15 min (`PRINT_JOB_EXPIRED`) y publica `print_agent.offline` tras 2 min sin latido. El scheduler lo corre cada 5 s: en local, `php artisan schedule:work`.
- **Reintentos:** `NOT_SENT` reintenta solo, hasta 3 intentos con espera creciente; después queda `FAILED`. `UNKNOWN` nunca se reintenta solo: una persona lo resuelve.
- **Reimpresión:** trabajo nuevo con el contenido original; el agente lo recibe con la marca `*** REIMPRESIÓN ***`.

### Probar la impresión sin impresora

El agente simulado habla con la API por HTTP, igual que el agente real, y muestra cada documento en la terminal. Con una sucursal cargada con `restia:install` y `restia:demo`:

```bash
php artisan restia:demo-printing --branch=CENTRO
```

Crea la identidad de servicio, el agente y una impresora con su ruta de comandas por estación, y muestra el token una sola vez. Después, cada uno en su propia terminal:

```bash
php artisan serve
```

```bash
php artisan schedule:work
```

```powershell
$env:RESTIA_AGENT_TOKEN = "<token>"; php artisan restia:print-agent-sim
```

Para mandar una comanda sin la app, `php artisan restia:demo-order --branch=CENTRO` abre una mesa libre y pide un producto de cada estación (`--table=N` usa una mesa ya abierta). En unos segundos sale en el agente.

Para probar la app Android contra este backend, después de `restia:demo --staff` y `restia:demo-printing`:

```bash
php artisan restia:demo-mobile --branch=CENTRO
```

Abre un turno en la primera caja activa (o crea "Caja principal"), crea la ruta `CAJA` para la precuenta, el recibo y el reporte de caja, e imprime un código de alta para el dispositivo, porque sin login Web no hay otra forma de obtenerlo. Se puede repetir: solo crea lo que falta y da otro código. Los pasos del lado de la app están en el README de `restia-mobile`.

Fallas con `--fail`: `not-sent` (la impresora no recibe nada; se reintenta sola hasta 3 veces), `uncertain` (se corta a media impresión; queda `UNKNOWN`), `crash` (el agente se cae sin reportar; queda `UNKNOWN` al vencer el plazo) y `random` (falla con la probabilidad de `--fail-rate`, 0.3 por omisión). `--once` hace una sola vuelta. Ninguno de estos comandos corre en producción.

## Clientes (fase 6)

| Operación | Ruta | Permiso |
|---|---|---|
| Alta | `POST /branches/{branchId}/customers` | `customers.create` |
| Búsqueda por teléfono o nombre | `POST /branches/{branchId}/customers/search` | `customers.read` |
| Consulta y edición | `GET`, `PATCH /branches/{branchId}/customers/{customerId}` | `customers.read`, `customers.update` |
| Anonimizar | `POST /branches/{branchId}/customers/{customerId}/anonymize` | `customers.anonymize` (ADMIN) |

- **Visibilidad (CU-2):** cada sucursal ve solo los clientes con perfil en ella. Registrar el mismo teléfono en otra sucursal crea otra identidad.
- **Datos:** el perfil guarda notas, preferencias y visitas; un check-in de reserva suma una visita.
- **Búsqueda (CU-3):** va por `POST` para que el teléfono no quede en URLs. No muestra a los clientes anonimizados.
- **Auditoría:** nunca guarda el dato completo. Solo deja la inicial del nombre, los últimos 4 dígitos del teléfono, el dominio del correo y si cambiaron las notas.
- **Notas:** `null` o vacío las borra.
- **Anonimizar (CU-4):** reemplaza nombre, teléfono, correo, notas y preferencias, también en las copias de sus reservas. Las facturas no cambian. **Falta validar el alcance con el área legal.**

## Reservas (fase 6)

| Operación | Ruta | Permiso |
|---|---|---|
| Disponibilidad | `GET /reservations/availability?startAt=&endAt=&partySize=` | `reservations.availability` |
| Alta (PENDING) | `POST /reservations` | `reservations.create` |
| Lista, consulta y edición | `GET /reservations`, `GET`, `PATCH /reservations/{reservationId}` | `reservations.read`, `reservations.update` |
| Confirmar, llegada, cancelar y no presentado | `POST /reservations/{reservationId}/confirm`, `/arrive`, `/cancel`, `/no-show` | el de cada acción |
| Sentar y abrir la sesión | `POST /reservations/{reservationId}/check-in` | `reservations.check_in` |
| Fila de espera | `GET /reservations/waiting-queue` | `reservations.read` |
| Buscar por código y teléfono | `POST /reservations/lookup` | `reservations.lookup` |

- **Mesas (R-1, R-2):** solo `CONFIRMED` y `ARRIVED_WAITING` apartan mesas. Confirmar elige la opción que deja menos asientos libres: una mesa, o hasta 3 del mismo `combinationGroup`. No toma mesas planeadas en otra reserva que se traslape, ni mesas ocupadas si la reserva empieza en menos de 90 minutos. Sin lugar, responde `RESERVATION_NOT_AVAILABLE`.
- **Horario (R-3):** sin `reservation_hours` en la sucursal no se aceptan reservas. `restia:demo` la deja de 12:00 a 23:00 todos los días; la gestión de sucursales todavía no existe.
- **Mesa con reserva próxima (R-5):** abrirla en los 60 minutos previos responde `TABLE_RESERVED_SOON`, con la hora de la reserva. Con `acknowledgeReservation` se abre y la reserva se replanea en otra mesa; si no hay, queda con `planConflict`.
- **Prioridad (R-8):** al sentar, si otra reserva llegó antes y cabe en esas mesas, responde `RESERVATION_PRIORITY_CONFLICT`. Saltarla exige `reservations.override_priority` (ADMIN y MANAGER) y un motivo, que queda auditado.
- **Sesión (R-9):** cuando la sesión pasa a limpieza, la reserva queda `COMPLETED`. Si se cancela, la reserva vuelve a la espera.
- **`restia:reservations`, cada minuto:**
  - Cancela las `PENDING` que llegan a su hora (R-7).
  - Marca `NO_SHOW` las confirmadas sin llegada, pasada la tolerancia (R-6).
  - **Borra nombre, teléfono y notas 12 meses después del fin de la reserva**, según la retención decidida el 2026-11-10.
- **Datos borrados:** una reserva con los datos personales borrados devuelve el nombre como "Cliente anonimizado" y `customer.phone` en `null` (`ReservationCustomerRecord` en el contrato).
- **Fase 8 (IA):** `reservation-ai`, `policy` y los códigos `RESERVATION_AI_*` llegan con el módulo de IA.

## QR público (fase 6)

La página del cliente solo ve el menú y avisa que hay alguien en la mesa (QR-1). Nunca ve comandas, cuenta ni estado (QR-2).

| Operación | Ruta | Credencial |
|---|---|---|
| Restaurante y número de mesa | `GET /public/context` | `X-QR-Token` |
| Menú público, con `ETag` | `GET /public/menu` | `X-QR-Token` |
| Señal de presencia | `POST /public/presence` (204) | `X-QR-Token` |
| Token y URL de una mesa | `GET /branches/{branchId}/tables/{tableId}/qr` | `tables.qr_read` (ADMIN y MANAGER) |
| Rotar el token | `POST /branches/{branchId}/tables/{tableId}/qr/rotate` | `tables.qr_rotate`, con `If-Match` y motivo |

- **El token:** 256 bits en base64url. Se genera con la mesa; las mesas anteriores lo recibieron en la migración. Identifica la mesa, no la sesión (QR-3).
  - El QR codifica `RESTIA_PUBLIC_URL/m#<token>`.
  - La auditoría nunca lo guarda.
- **Rotar (QR-4):** el token anterior sirve 7 días más. Una segunda rotación dentro de ese plazo lo invalida de inmediato.
- **Token inválido o vencido, mesa `INACTIVE` o sucursal inactiva:** siempre `401 QR_TOKEN_INVALID`, sin más detalle.
- **Menú (QR-7):** sin códigos, descripciones internas ni ids de sucursal. Los agotados aparecen marcados.
- **Presencia (QR-8):** un documento por mesa que vence a los 10 minutos (índice TTL), sin datos del visitante.
  - Una mesa libre con señal vigente se ve como `POSIBLE_OCUPACION`.
  - En mantenimiento responde 204 pero no registra nada.
- **Límites (QR-9):** 60 lecturas por minuto por IP, 6 señales por minuto por mesa y 20 tokens inválidos por minuto por IP. Al excederlos, `429 TOO_MANY_ATTEMPTS`.
- **Respuestas:** llevan `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` y `Cache-Control: private, no-cache`.
- **Pendiente de despliegue (QR-11):** dominio propio, CORS solo para ese dominio y la página web misma.

## Reportes (fase 6)

| Operación | Ruta | Permiso |
|---|---|---|
| Ventas diarias | `GET /branches/{branchId}/reports/daily-sales?from=&to=` | `reports.read` (ADMIN y MANAGER) |
| Ocupación por hora | `GET /branches/{branchId}/reports/hourly-occupancy?from=&to=` | `reports.read` |
| Reconstruir un rango | `POST /branches/{branchId}/reports/rebuilds` (202) | `reports.rebuild` (ADMIN) |
| Estado de la reconstrucción | `GET /branches/{branchId}/reports/rebuilds/{rebuildId}` | `reports.rebuild` |

- **Son proyecciones (RP-3).** `restia:reports`, cada 15 s en el programador, toma de outbox los eventos de pagos, reembolsos, cuentas y sesiones, y recalcula completo cada día de negocio que tocan. Nunca acumula, así que reconstruir da lo mismo. `asOf` indica cuándo se calculó.
- **Venta (RP-2):** por el día de negocio en que se confirmó el pago: confirmado menos reembolsado.
  - Propinas (netas de reembolsos), cortesías y facturado van aparte. Facturado es 0 hasta que existan las facturas de Fiscal.
  - `checkCount` cuenta las cuentas `PAID` del día y `guestCount`, las personas de las sesiones no canceladas.
- **Ocupación:** por cada hora local del día de negocio, cuenta:
  - Las sesiones abiertas en esa hora.
  - Las mesas ocupadas en algún momento de la hora.
  - Las personas de esas sesiones.

  Solo aparecen las horas con actividad, y un día sin actividad no tiene filas.
- **Rangos:** la lectura acepta hasta 92 días y la reconstrucción hasta 366. Solo puede haber una reconstrucción activa por sucursal (`REPORT_REBUILD_IN_PROGRESS`).

## Auditoría (fase 6)

| Operación | Ruta | Permiso |
|---|---|---|
| Bitácora de una sucursal | `GET /branches/{branchId}/audit-log` | `audit.read` (ADMIN y MANAGER) |
| Bitácora global | `GET /audit-log` | `audit.read_global` (ADMIN) |

- `from` y `to` son obligatorios, con un rango de hasta 31 días. Filtros opcionales: `entityType`, `entityId`, `actorId` y `action`. Ordena del más reciente al más antiguo.
- La global cubre las sucursales donde se tiene el permiso, más lo que no es de ninguna sucursal, como las identidades de servicio.
- `audit_log` guarda ObjectIds y la API solo expone UUID públicos. Sucursal, actor y entidad se traducen al leer; cualquier ObjectId que quede en `changes` se quita.
- Cada entrada nueva lleva su propio `uuid`. Las anteriores reciben uno estable, derivado de su `_id`.
- Antes, el catálogo copiaba el documento completo (con `_id`, `branch_id` y fechas en milisegundos) y el alta de dispositivo registraba `DEVICE` como actor. Ahora el catálogo guarda una copia sin ids y con fechas ISO, y el alta queda a nombre del gerente que emitió el código. La lectura corrige las entradas viejas.

## Fiscal (fase 7)

Todavía no hay PAC elegido. Por eso, la API habla con el PAC a través de la interfaz `App\Fiscal\Pac\Pac` (F-1), y por ahora el único conector es el **PAC simulado** (`RESTIA_PAC=simulated`, el valor por omisión). Ese conector no corre en producción. Cuando se elija proveedor, se agrega su conector y la API no cambia. Falta validar el ciclo completo en el ambiente de pruebas del PAC y con contabilidad. PPD y los complementos de pago quedan fuera de v1.

**Emisores**

Emisores (`fiscal.manage_issuers`, solo ADMIN):

| Operación | Ruta |
|---|---|
| Alta, lectura y cambios | `/fiscal-issuers` |
| Activar o desactivar | `POST /fiscal-issuers/{id}/status` |
| Cargar el CSD | `PUT /fiscal-issuers/{id}/csd`, multipart con `certificate`, `privateKey` y `password` |
| Asignar emisor a la sucursal | `PUT /branches/{id}/fiscal-issuer` |

- **RFC:** no cambia. Un RFC distinto es otro emisor (`FISCAL_ISSUER_RFC_TAKEN`).
- **Estado inicial:** un emisor nace inactivo. Para activarlo necesita un CSD vigente; si no lo tiene, responde `FISCAL_ISSUER_NOT_CONFIGURED`. Solo se asigna a una sucursal un emisor activo, y la asignación aplica a facturas nuevas.
- **CSD (F-12):**
  - El certificado, la llave y la contraseña van al PAC fuera de la transacción. Restia guarda solo la referencia, la serie y la vigencia.
  - Nada de eso queda en la base, la auditoría ni la respuesta.
  - El PAC simulado revisa lo mismo que revisaría un PAC: que el certificado se pueda leer y esté vigente, que sea del RFC del emisor, y que la llave abra con la contraseña y corresponda al certificado.
  - Si algo falla, responde `CSD_INVALID`. Si el PAC rechaza la operación por otro motivo, responde `FISCAL_OPERATION_FAILED` con su código.
- **Certificados de prueba:** están en `tests/Fixtures/csd`. Son autofirmados y no son CSD del SAT.

## Pruebas del backend

Con el replica set local arrancado:

```bash
php artisan test
```

Usan la base `restia_test` y la borran al empezar; nunca tocan `restia`.

### Escenario de la fase 5 contra la API real

`tools/escenario_fase5.py` recorre el criterio de salida de la fase 5 por HTTP, como la app: abrir mesa, comandar, cocina, cobro mixto, cierre y limpieza, corte de caja y reporte. En el camino lanza 7 choques en paralelo:

1. Dos empleados abren la misma mesa.
2. La misma comanda llega tres veces con la misma clave.
3. Cocina empieza la unidad que el mesero cancela.
4. Dos cobros completos de la misma cuenta.
5. El mismo cobro con tarjeta repetido con la misma clave.
6. Dos confirmaciones de limpieza de la misma mesa.
7. Un cobro mientras el gerente inicia el corte.

En cada choque exige que gane una sola petición y que la otra reciba su código. Al final, que las ventas y propinas de cada método y el efectivo esperado coincidan con los pagos. `php artisan serve` atiende una petición a la vez, así que los choques necesitan varios servidores:

```bash
php artisan serve --port=8001
```

```bash
php artisan serve --port=8002
```

```bash
php artisan serve --port=8003
```

```bash
python tools/escenario_fase5.py --ports 8001,8002,8003 --php php
```

Necesita la sucursal de `restia:install`, `restia:demo --staff` y `restia:demo-printing`, `RESTIA_DEMO_PASSWORD` en el `.env` y 3 mesas libres. Cada corrida tiene estos efectos:
- Da de alta 3 dispositivos e inicia sesión con `mesero@`, `cocina@` y `gerente@demo.mx`. Cada usuario admite 2 sesiones Android, así que puede cerrar la del emulador.
- Cierra el turno abierto y deja otro abierto al terminar.

En el choque 7, el corte sale con un retraso al azar para que unas corridas gane el cobro y otras el corte. Conviene correrlo varias veces. Termina con código 0 si todo cuadra.

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
