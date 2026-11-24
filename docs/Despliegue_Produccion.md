# Producción en Render

Guía para publicar Restia y el sitio de La Mesa con dominio propio. Staging (`docs/Despliegue_Staging.md`) no cambia y sigue siendo el lugar para probar antes de cada despliegue.

## Cómo queda

| Pieza | Dónde | Archivo |
|---|---|---|
| Sitio de La Mesa, panel, acceso y menú del QR | `https://<dominio>` (y `www`) | `restia-web/deploy/render/produccion.yaml` |
| API para la app Android | `https://api.<dominio>` | `restia-api/deploy/render/produccion.yaml` (servicio `restia-api`) |
| Programador de tareas (impresión, avisos, inventario, reservas, facturación) | Worker `restia-tareas` | El mismo Blueprint de la API |
| Base de datos | MongoDB Atlas, clúster dedicado con respaldos | — |

El sitio reenvía `/api`, `/sanctum` y `/contrasena` a `restia-api.onrender.com`, así que el panel habla con la API desde el mismo origen: la cookie de sesión y el CSRF funcionan sin cookies de terceros. La app Android usa `api.<dominio>` directo.

Ambos Blueprints tienen `autoDeploy: false`: producción se despliega a mano, después de probar el cambio en staging.

## Cuentas y planes

- **Render:** plan **Starter** para `restia-api` y para `restia-tareas`. No se duermen, tienen *Shell* y corren `preDeployCommand`. El sitio estático es gratis.
- **Atlas:** clúster **dedicado (M10 o mayor)**. Los gratuitos y compartidos no tienen respaldos continuos ni restauración a un punto en el tiempo.
- **Dominio:** cualquier registrador. Se necesitan registros DNS para el sitio, la API y Resend.
- **Resend:** el plan gratuito alcanza para los correos de contraseña. Necesita el dominio verificado.
- **Google Cloud:** el mismo proyecto de staging (inicio de sesión con Google).
- **SW Sapien:** cuenta de producción para timbrar y CSD reales de cada emisor.

Revisa los precios vigentes de cada servicio antes de contratar.

## Pasos, en orden

### 1. Atlas
1. Crea el clúster dedicado en la **misma región** que los servicios de Render. Los Blueprints usan `virginia` (AWS `us-east-1`); si eliges otra, cámbiala en `deploy/render/produccion.yaml`.
2. *Backup*: activa **Continuous Cloud Backup** (restauración a cualquier minuto de los últimos días) y deja las instantáneas diarias.
3. *Database Access*: un usuario con `readWrite` **solo** sobre la base `restia`. Su cadena `mongodb+srv://…` es `MONGODB_URI`.
4. *Network Access*: agrega las IP de salida de Render. Están en el servicio, pestaña *Connect* → *Outbound*, y son fijas por región. No uses `0.0.0.0/0`.

### 2. API y worker
1. Render → *New* → *Blueprint* → repositorio `restia-api`, rama `main`, **Blueprint Path** `deploy/render/produccion.yaml`.
2. Render pide los secretos (`sync: false`). Los que dependen del dominio se pueden poner provisionales y corregir en el paso 4.

| Variable | Servicios | Valor |
|---|---|---|
| `APP_KEY` | los dos, **mismo valor** | `php artisan key:generate --show`. Cifra datos de clientes: si cambia, ya no se leen. Guárdala en el gestor de contraseñas. |
| `MONGODB_URI` | los dos | La del paso 1. |
| `RESTIA_REFRESH_KEY` | `restia-api` | `php -r "echo bin2hex(random_bytes(32));"`. Cambiarla cierra todas las sesiones de Android. |
| `APP_URL` | los dos | `https://api.<dominio>` |
| `RESTIA_WEB_URL`, `RESTIA_PUBLIC_URL` | los dos | `https://<dominio>` (enlaces de contraseña y QR de las mesas). |
| `RESTIA_CORS_ORIGINS` | `restia-api` | `https://<dominio>,https://www.<dominio>` |
| `SANCTUM_STATEFUL_DOMAINS` | `restia-api` | `<dominio>,www.<dominio>` (sin `https://`). |
| `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` | los dos | Ver *Correo* en `Despliegue_Staging.md`; remitente `no-responder@<dominio>`. |
| `SW_TOKEN` | los dos | Token de producción del portal de SW Sapien. |
| `ANTHROPIC_API_KEY` | `restia-api` | Asistente del sitio. Vacía, el sitio funciona sin chat. |
| `GOOGLE_CLIENT_ID` | `restia-api` | El ID de cliente Web de Google. Vacío, el botón no aparece. |

3. El primer despliegue aplica las migraciones (`preDeployCommand`). Cuando quede *Live*, abre el *Shell* de `restia-api` y crea la primera sucursal y su administrador:
   ```sh
   php artisan restia:install
   php artisan restia:menu-la-mesa --branch=<CÓDIGO>   # opcional: la carta base de 50 productos
   ```
   `restia:demo` no corre en producción.

### 3. Sitio y panel
1. Render → *New* → *Blueprint* → repositorio `restia-web`, rama `main`, **Blueprint Path** `deploy/render/produccion.yaml`.
2. Si el servicio de la API no quedó como `restia-api.onrender.com`, cambia las tres reglas `rewrite` del archivo y vuelve a desplegar.

### 4. Dominio
1. En `restia-web` → *Settings* → *Custom Domains*: agrega `<dominio>` y `www.<dominio>`.
2. En `restia-api`: agrega `api.<dominio>`.
3. En el DNS, crea los registros que indica Render. Render emite los certificados solo.
4. Corrige en Render las variables que pusiste provisionales en el paso 2 y despliega los dos servicios.
5. En Resend, verifica el dominio (registros SPF y DKIM).

### 5. Google
En Google Cloud → *Google Auth Platform*:
1. Cliente **Web**: agrega `https://<dominio>` y `https://www.<dominio>` como orígenes autorizados.
2. Cliente **Android** de producción: paquete `com.restia.mobile` y la SHA-1 de la llave de release (`restia-mobile/docs/Firma_Release.md`).
3. *Público*: mientras esté en **Prueba**, solo entran los usuarios de prueba (hasta 100). Para abrirlo a todo el personal, publica la app y completa la verificación de Google.

### 6. Facturación
1. En el panel, *Facturación* → *Emisores*: da de alta el RFC, sube el CSD real y activa el emisor.
2. Asígnalo a cada sucursal.
3. Haz una factura de prueba de bajo monto y cancélala si hace falta.

### 7. App Android
En el `local.properties` de quien compila la versión release:
```properties
restia.releaseApiBaseUrl=https://api.<dominio>/api/v1/
restia.releaseApiPins=sha256/<intermedia>,sha256/<raíz>
```
Las huellas se anclan a la intermedia y la raíz del certificado de `api.<dominio>`, como en staging, porque el certificado final cambia en cada renovación. Para sacarlas:
```sh
openssl s_client -connect api.<dominio>:443 -servername api.<dominio> -showcerts </dev/null
```
De cada certificado de la cadena, excepto el primero:
```sh
openssl x509 -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | openssl enc -base64
```
Compila y firma con `./gradlew :app:bundleRelease` (ver `Firma_Release.md`).

### 8. Comprobación
- `https://api.<dominio>/up` responde 200.
- `https://<dominio>` muestra el sitio. El mapa, las solicitudes de reserva y el chat funcionan (este último si hay llave).
- `https://<dominio>/acceso`: entra un administrador con contraseña y con Google.
- La app de producción se da de alta con un código del panel y entra.
- Una mesa completa: abrir, comandar, cocina, servir, cobrar, cerrar y limpiar.
- Un aviso de stock bajo y una reserva vencida aparecen solos: es la prueba de que el worker corre.

## Respaldos y restauración

- **Continuos (Atlas):** restauración a un minuto exacto desde *Backup* → *Restore*. Restaura primero en un clúster nuevo, revisa los datos y luego cambia `MONGODB_URI`.
- **Copia fuera de Atlas, cada semana:** con las MongoDB Database Tools, desde un equipo con acceso:
  ```sh
  mongodump --uri "<MONGODB_URI>" --db restia --gzip --archive=restia-AAAA-MM-DD.gz
  ```
  Guárdala cifrada fuera de la nube del proveedor. Para restaurar en otra base: `mongorestore --uri "<otro URI>" --gzip --archive=restia-AAAA-MM-DD.gz --nsFrom "restia.*" --nsTo "restia_restaurada.*"`.
- **La `APP_KEY` es parte del respaldo:** sin ella, los teléfonos y correos cifrados de los clientes no se pueden leer.
- **Prueba la restauración cada trimestre** en una base aparte.

## Desplegar un cambio

1. Fusiona a `main` y comprueba el cambio en staging.
2. En Render, *Manual Deploy* → *Deploy latest commit*, en este orden: `restia-api` (aplica migraciones), `restia-tareas` y `restia-web`.
3. Si algo falla: *Rollback* en el servicio afectado. Casi todas las migraciones solo agregan colecciones e índices, y con ellas la versión anterior sigue funcionando. Si el cambio trae una migración que transforma datos (como `encrypt_customer_personal_data`), revisa antes si la versión anterior la tolera y ten a la mano la restauración de Atlas.

## Límites conocidos

- **Una sola instancia web.** Las sesiones del panel y la caché de los límites de uso viven en el disco del contenedor, así que un despliegue cierra las sesiones del panel; las de Android viven en MongoDB y no se pierden. Para escalar a más de una instancia, hay que pasar sesiones y caché a un almacén compartido.
- **Notificaciones push:** fuera del alcance. Los avisos llegan por la campana mientras la app o el panel están abiertos.
