# Ambiente de pruebas en Render

Ambiente compartido del equipo, **no de producción**: usa la base `Restia` de MongoDB Atlas, PAC simulado (no timbra ante el SAT) y los correos van al log. Las pruebas automáticas siguen corriendo contra la base local.

## Qué se publica

`render.yaml` define un servicio web, `restia-api-staging`, en el **plan gratuito**, con la imagen del `Dockerfile` (FrankenPHP con PHP 8.4). Atiende la API en `https://restia-api-staging.onrender.com` (o el nombre que asigne Render).

Limitaciones del plan gratuito:

- **Se duerme** tras unos minutos sin uso: la primera petición después tarda cerca de un minuto, y la app puede marcar error de conexión en ese intento.
- **No hay proceso de tareas** (`php artisan schedule:work`): los workers son de pago. Sin él no avanzan la cola de impresión, los avisos, el inventario, los reportes, las reservaciones ni la facturación. Sí funcionan el acceso, las mesas, los pedidos, la cocina, las cuentas, los cobros y la caja.

Para el sistema completo, en `render.yaml` cambia `plan: free` a `starter` y quita el comentario del worker `restia-tareas-staging`; Render pedirá una tarjeta. La CI ya prueba la imagen con los dos procesos.

Render pone el TLS delante; el contenedor solo habla HTTP en el puerto 8080. Con `RESTIA_FORCE_HTTPS=true` y `RESTIA_TRUSTED_PROXIES=*`, la API confía en el `X-Forwarded-Proto` del proxy de Render, manda HSTS y redirige con 308 a quien llegue por HTTP.

## Secretos

Nunca van en el repositorio ni en el chat. Render los pide al crear el Blueprint (`sync: false`):

| Variable | Servicios | Cómo obtenerla |
|---|---|---|
| `APP_KEY` | web (y worker, con **el mismo valor**, si se activa) | `php artisan key:generate --show` en tu equipo. Cifra el teléfono y el correo de los clientes: si cambia, esos datos ya no se pueden leer. |
| `MONGODB_URI` | web (y worker) | Cadena `mongodb+srv://…` de un usuario de Atlas con `readWrite` solo sobre `Restia`. |
| `RESTIA_REFRESH_KEY` | web | Cualquier texto aleatorio largo, por ejemplo `php -r "echo bin2hex(random_bytes(32));"`. Cambiarla cierra todas las sesiones de Android. |
| `APP_URL` | web (y worker) | La dirección que asigne Render, por ejemplo `https://restia-api-staging.onrender.com`. Los enlaces de contraseña y los QR la usan. |

## Pasos

1. **Atlas.** En *Network Access* agrega las IP de salida del servicio. Render las muestra en el servicio, pestaña *Connect* → *Outbound*. Mientras no existan los servicios, no las conoces: créalos primero y vuelve a este paso si el primer arranque no conecta.
2. **Render.** *New* → *Blueprint* → elige el repositorio `restia-api`, rama `main`. Render lee `render.yaml`, crea el servicio y el grupo `restia-staging`, y pide los secretos de la tabla.
3. **Primer despliegue.** Espera a que el servicio quede en *Live*. La base ya tiene las migraciones; si algún día faltan, se aplican desde un equipo con acceso a Atlas (`php artisan migrate --force` con su `MONGODB_URI`), porque el *Shell* de Render no existe en el plan gratuito.
4. **Comprobación.** `https://<tu-servicio>.onrender.com/up` responde 200.

Cada fusión a `main` vuelve a desplegar el servicio (`autoDeploy`).

## Pendientes conocidos

- **Correo.** `MAIL_MAILER=log`: los enlaces para que un empleado defina su contraseña quedan en los registros del servicio, no le llegan. Para mandarlos hace falta un proveedor SMTP.
- **Panel web.** Cuando se publique, llenar `RESTIA_CORS_ORIGINS` y `SANCTUM_STATEFUL_DOMAINS` con su dirección.
- **App Android.** Necesita una versión compilada con la dirección de este servicio, y que su `CertificatePinning` acepte su certificado.
- **Sesiones y caché** viven en el disco del contenedor: un nuevo despliegue cierra las sesiones del panel web. Las de Android no se pierden (están en MongoDB).
