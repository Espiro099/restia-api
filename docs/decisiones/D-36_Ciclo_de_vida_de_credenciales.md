# D-36: Ciclo de vida de credenciales y sesiones

**Estado:** APROBADA (decisiones A-1 a A-10), condicionada a la prueba técnica de la sección 12
**Fecha:** 2026-11-02
**Base:** `Restia_Documento_Maestro_Arquitectura_OpenAPI.md` (secciones 16, 17, 19 a 28, 33, 35 y 37)
**No revisado:** la adenda técnica de consolidación y el texto de D-29 a D-35, que no están disponibles.

---

## 1. Resumen

Se propone mantener lo que el documento maestro ya definió: Sanctum con cookie para Web y Bearer opaco para Android. Se agrega lo que Sanctum no trae: refresh con rotación, una sesión consultable y revocable, y el registro formal de dispositivos.

| Tema | Propuesta |
|---|---|
| Android | Access token opaco de 15 min y refresh token rotativo |
| Web | Cookie HttpOnly y CSRF, 60 min de inactividad, 16 h máximo |
| Revocación | Inmediata, porque cada petición consulta la base de datos |
| Dispositivos | Alta con código temporal; cada uno pertenece a una sucursal |
| Offline | Solo borradores de comanda; sin cobros ni operaciones sensibles |
| Permisos | Nunca viajan en el token; se evalúan en cada petición (D-12) |

---

## 2. Decisiones aprobadas

| ID | Decisión | Recomendación | Alternativa |
|---|---|---|---|
| **A-1** | Modelo de credencial Android | Sanctum + refresh propio (sección 3) | Token largo sin refresh |
| **A-2** | Duraciones | Las de la sección 5.2 | Ajustarlas a los turnos reales |
| **A-3** | Dispositivos compartidos | Permitidos, con una sola sesión activa por dispositivo | Dispositivo personal, un solo usuario |
| **A-4** | Sesiones simultáneas por usuario | 2 en Android y 3 en Web; se cierra la más antigua | Sin límite |
| **A-5** | Alta de dispositivos | Código temporal emitido por un gerente | Registro automático en el primer login |
| **A-6** | Alcance de sucursal | Un dispositivo solo opera la sucursal donde se registró | Cualquier sucursal del usuario |
| **A-7** | Offline | Solo borradores de comanda, con ventana de 12 h | Sin modo offline en v1 |
| **A-8** | Cambio al modelo físico | Nueva colección `auth_sessions` y campos en `devices` | Ninguna viable para A-1 |
| **A-9** | Retención | `auth_sessions` 180 días; `audit_log` conserva el historial | Conservar sin límite |
| **A-10** | Revocación global | `users.sessions.revoke` en al menos una sucursal donde el usuario objetivo tenga membresía | Administrador global |

### Temas que D-36 deja abiertos

- **Aprobador en dispositivo ajeno:** `VOID`, overrides (D-11) y reapertura de cuenta (D-17) requieren autorización, a veces de otra persona. Hay que definir cómo se identifica un gerente en el teléfono de un mesero. Resuelto en **D-37** (`Decisiones_Orders.md`).
- **Cambio rápido de usuario:** escribir correo y contraseña en cada cambio de turno puede ser lento en un dispositivo compartido. Un PIN rápido se evaluaría después de probar el flujo en piso.
- **MFA:** se recomienda para `ADMIN` en Web, en una versión posterior a v1.
- **Recuperación y cambio de contraseña:** quedan para el módulo Authentication. D-36 solo fija su efecto: cerrar las demás sesiones.
- **Rotación del token QR:** depende del diseño de Public QR (sección 11).

---

## 3. Alternativas evaluadas

| Criterio | A. Token largo de Sanctum | **B. Sanctum + refresh propio** | C. JWT / OAuth (Passport) |
|---|---|---|---|
| Coherencia con el documento maestro | Alta | **Alta** | Baja: cambia `bearerFormat: opaque` |
| Revocación inmediata | Sí | **Sí** | Requiere lista de revocación en BD |
| Daño si roban el token | Alto, dura semanas | **Bajo, 15 min, y se detecta el reúso** | Bajo, similar a B |
| Consulta a BD por petición | Sí | **Sí** | Se evita solo si se acepta revocación tardía |
| Código propio | Mínimo | **Moderado** | Mayor infraestructura o claves por gestionar |
| Permisos en el token | No | **No** | Tentación de incluirlos; quedarían obsoletos |

**Se recomienda B.** La ventaja principal de JWT es evitar la consulta a la base de datos, pero Restia ya la necesita para revocar al instante y validar membresía. OAuth tampoco aporta valor aquí, porque no hay aplicaciones de terceros.

---

## 4. Modelo de datos

### 4.1 Nueva colección `auth_sessions`

Representa un inicio de sesión de una persona, tanto en Android como en Web.

```text
_id                    ObjectId
uuid                   UUID público
user_id                ObjectId
client_type            ANDROID | WEB
device_id              ObjectId, solo ANDROID
status                 ACTIVE | REVOKED | EXPIRED
refresh_hash           SHA-256, solo ANDROID
previous_refresh_hash  SHA-256, solo ANDROID
previous_valid_until   UTC, solo ANDROID
refresh_expires_at     UTC, solo ANDROID
absolute_expires_at    UTC
last_activity_at       UTC
ip_created             string
created_at             UTC
revoked_at             UTC
revoked_by             ObjectId o null si fue el sistema
revocation_reason      LOGOUT | REPLACED_BY_LOGIN | SESSION_LIMIT | DEVICE_BLOCKED
                       | ADMIN_REVOKED | USER_DISABLED | PASSWORD_CHANGED | REFRESH_REUSE
purge_at               UTC, cierre de la sesión + 180 días (TTL)
version                entero
```

Índices:

```text
unique(uuid)
partial unique(device_id) where status = ACTIVE and client_type = ANDROID
(user_id, status, client_type)
(status, absolute_expires_at)
TTL(purge_at)
```

### 4.2 Campos nuevos en `devices`

Se conservan los índices actuales, `unique(device_uuid)` y `(branch_id, status, type)`.

```text
status             ACTIVE | BLOCKED | RETIRED
credential_hash    SHA-256 de la credencial del dispositivo
display_name       string
enrolled_by        ObjectId
enrolled_at        UTC
last_seen_at       UTC
version            entero
```

`BLOCKED` y `RETIRED` son finales. Si se recupera un dispositivo, se registra de nuevo y obtiene otro `device_uuid`, lo que evita reactivar una credencial que pudo quedar expuesta.

### 4.3 Otras colecciones

- **`device_enrollment_codes`:** `branch_id`, `code_hash`, `created_by`, `expires_at` (TTL), `used_at`. Índice único en `code_hash`, porque el código por sí solo identifica la sucursal. Sirve una vez y dura 15 min.
- **`personal_access_tokens`:** colección de Sanctum, más `auth_session_id`. Se permite borrarlos físicamente porque son material técnico; el historial queda en `auth_sessions` y `audit_log`.
- **`service_identities`:** identidades técnicas como `reservation-ai` y `printing-agent` (sección 11).

---

## 5. Android

### 5.1 Flujo

```text
1. Un gerente genera un código        POST /branches/{branchId}/devices/enrollment-codes
2. La app registra el dispositivo     POST /auth/mobile/enroll
3. El empleado inicia sesión          POST /auth/mobile/login
4. La app usa el access token         Authorization: Bearer <accessToken>
5. Recibe 401 y renueva una vez       POST /auth/mobile/refresh
6. Si la renovación falla             vuelve a la pantalla de login
```

En el paso 2, la app genera `deviceUuid` y envía el código. El servidor responde una sola vez con `deviceCredential`, y solo guarda su hash. Así, una contraseña robada no basta para iniciar sesión desde un dispositivo no registrado.

### 5.2 Duraciones

| Elemento | Duración | Comportamiento |
|---|---|---|
| Access token | 15 min | No se renueva por uso |
| Refresh por inactividad | 7 días | Se extiende con cada renovación |
| Sesión máxima | 30 días | Después exige contraseña |
| Gracia de rotación | 60 s | Tolera una respuesta de red perdida |
| Código de alta | 15 min | Un solo uso |
| Ventana offline | 12 h | Después, los borradores quedan solo en lectura |

### 5.3 Rotación y detección de reúso

Cada renovación emite un par nuevo e invalida el refresh anterior. El access token anterior vence en 60 s para que terminen las peticiones en curso.

| Refresh recibido | Resultado |
|---|---|
| Vigente | Rota y responde 200 |
| Anterior, dentro de los 60 s | Rota y responde 200; cubre una respuesta perdida |
| Con firma válida, pero ya reemplazado | Revoca la sesión (`REFRESH_REUSE`), audita y responde 401 |
| Con firma inválida | Responde 401 sin tocar la sesión |
| Sesión vencida o revocada | Responde 401 |
| Dispositivo no activo | Responde 403 `DEVICE_BLOCKED` |

El refresh token tiene el formato `<sessionUuid>.<aleatorio>.<HMAC>`. La firma HMAC, con una clave del servidor, permite reconocer cualquier token emitido para esa sesión sin guardar su historial. También impide que alguien fuerce la revocación de una sesión ajena conociendo solo su UUID.

Si un atacante usa un token robado dentro de la gracia, el cliente legítimo presentará después un token ya reemplazado. Eso activa la revocación, de modo que el robo se detecta aunque ocurra en esos 60 s.

Pseudocódigo del servidor:

```php
public function refresh(string $deviceUuid, string $refreshToken): TokenPair
{
    $sessionUuid = $this->verifySignatureOrFail($refreshToken); // 401 si el HMAC no coincide
    $hash = hash('sha256', $refreshToken);
    $now = now();
    $device = $this->activeDeviceOrFail($deviceUuid); // 403 DEVICE_BLOCKED

    $valid = [
        'uuid' => $sessionUuid,
        'device_id' => $device->_id,
        'status' => 'ACTIVE',
        'refresh_expires_at' => ['$gt' => $now],
        'absolute_expires_at' => ['$gt' => $now],
    ];
    $newToken = $this->signRefreshToken($sessionUuid, bin2hex(random_bytes(32)));
    $newHash = hash('sha256', $newToken);
    $expiry = $this->nextRefreshExpiry($now); // min(ahora + 7 días, absolute_expires_at)

    // La operación atómica garantiza un solo ganador si llegan dos renovaciones.
    $pair = DB::transaction(function () use ($valid, $hash, $newHash, $newToken, $expiry, $now) {
        $session = $this->sessions->findOneAndUpdate(
            $valid + ['refresh_hash' => $hash],
            ['$set' => [
                'refresh_hash' => $newHash,
                'previous_refresh_hash' => $hash,
                'previous_valid_until' => $now->copy()->addSeconds(60),
                'refresh_expires_at' => $expiry,
                'last_activity_at' => $now,
            ], '$inc' => ['version' => 1]]
        ) ?? $this->sessions->findOneAndUpdate(
            $valid + [
                'previous_refresh_hash' => $hash,
                'previous_valid_until' => ['$gt' => $now],
            ],
            ['$set' => [
                'refresh_hash' => $newHash,
                'refresh_expires_at' => $expiry,
                'last_activity_at' => $now,
            ], '$inc' => ['version' => 1]]
        );

        return $session ? $this->issueTokens($session, $newToken) : null;
    });

    if ($pair) {
        return $pair;
    }

    // La firma es válida, pero el token ya no es vigente: se trata como reúso.
    // Va fuera de la transacción anterior para que la revocación no se deshaga.
    $this->revokeIfActive($sessionUuid, 'REFRESH_REUSE');
    throw new SessionRevokedException();
}
```

### 5.4 Reglas del cliente

Ejemplo en Kotlin; el lenguaje de Android sigue pendiente (tarea 4).

```kotlin
class TokenAuthenticator(
    private val store: SecureTokenStore,
    private val authApi: AuthApi,
) : Authenticator {
    private val lock = Any()

    override fun authenticate(route: Route?, response: Response): Request? {
        if (response.request.header("X-Auth-Retry") != null) return null

        synchronized(lock) { // Solo una renovación a la vez
            val sent = response.request.header("Authorization")?.removePrefix("Bearer ")
            val current = store.accessToken()

            // Otro hilo ya renovó mientras este esperaba.
            val token = if (current != null && current != sent) current else {
                when (val result = authApi.refresh(store.deviceUuid(), store.refreshToken())) {
                    is RefreshResult.Success -> { store.save(result.tokens); result.tokens.accessToken }
                    is RefreshResult.NetworkError -> return null // Conserva la sesión y reintenta luego
                    is RefreshResult.Rejected -> { store.clearSession(); return null }
                    is RefreshResult.DeviceBlocked -> { store.wipeAll(); return null }
                }
            }

            return response.request.newBuilder()
                .header("Authorization", "Bearer $token")
                .header("X-Auth-Retry", "1")
                .build()
        }
    }
}
```

Almacenamiento:

- El access token se mantiene en memoria.
- El refresh token y la credencial del dispositivo se guardan cifrados con AES-GCM, usando una clave no exportable de Android Keystore.
- Se excluyen de los respaldos de Android.
- Los tokens nunca se escriben en logs ni reportes de fallos.

### 5.5 Dispositivos compartidos y sesiones simultáneas

- Un login en un dispositivo ocupado cierra la sesión anterior (`REPLACED_BY_LOGIN`).
- El límite por dispositivo lo garantiza un índice único. Si dos logins compiten, uno reintenta la transacción.
- El límite por usuario se comprueba durante el login. Dos logins simultáneos podrían excederlo por una sesión, y el siguiente login lo corrige. El riesgo se acepta.
- El login exige una membresía activa en la sucursal del dispositivo.

### 5.6 Pérdida o robo

```text
Gerente o administrador → POST /branches/{branchId}/devices/{deviceId}/block
  Transacción:
    devices.status = BLOCKED
    auth_sessions.status = REVOKED (DEVICE_BLOCKED)
    se eliminan los access tokens de esas sesiones
    audit_log
    outbox_events: device.blocked
  Efecto: la siguiente petición con esos tokens recibe 401.
```

Si el dispositivo perdido no tiene conexión, no se puede borrar su contenido a distancia. Por eso la caché local excluye datos de pago, datos de clientes y datos fiscales.

### 5.7 Offline

| Permitido sin conexión | Bloqueado sin conexión |
|---|---|
| Consultar menú y mesas en caché, marcados como posiblemente desactualizados | Cobros, reembolsos y operaciones de caja |
| Capturar borradores de comanda | `VOID`, overrides, reapertura de cuentas |
| | Reservaciones, facturación, reimpresiones |

Reglas de sincronización:

1. El borrador guarda desde su creación `client_order_uuid` (ya indexado como único) e `Idempotency-Key`.
2. Un borrador está ligado al usuario que lo capturó y se cifra en reposo. Otro usuario no puede verlo ni enviarlo.
3. Al volver la conexión, el servidor valida el estado actual. Una captura offline no otorga permisos ni garantiza que la mesa siga disponible.
4. Si la sesión fue revocada, el borrador espera a que el mismo usuario inicie sesión de nuevo. Si el dispositivo fue bloqueado, se elimina.
5. Después de 12 h sin conexión, los borradores quedan solo en lectura hasta sincronizar.
6. Un logout sin conexión borra tokens locales y deja la revocación pendiente. Si nunca se envía, el refresh vence a los 7 días como máximo.

---

## 6. Web

Se conserva el flujo de la sección 20 del documento maestro.

```text
GET  /sanctum/csrf-cookie
POST /api/v1/auth/login     con X-XSRF-TOKEN
→ se regenera la sesión de Laravel
→ se crea auth_sessions (WEB) y su UUID queda en la sesión
→ cada petición valida que auth_sessions siga ACTIVE y dentro del máximo de 16 h
```

| Parámetro | Valor |
|---|---|
| Cookie de sesión | `HttpOnly`, `Secure`, `SameSite=Lax` |
| CSRF | `X-XSRF-TOKEN` en `POST`, `PUT`, `PATCH`, `DELETE` |
| CORS | Orígenes explícitos, con credenciales; nunca `*` |
| Inactividad / máximo | 60 min / 16 h |

Las pantallas de cocina y barra que consultan el servidor de forma automática cuentan como actividad. El máximo de 16 h evita que queden abiertas sin límite; basta con iniciar sesión una vez por jornada.

---

## 7. Revocación

| Evento | Sesiones afectadas | Quién puede hacerlo |
|---|---|---|
| Logout | La actual | El propio usuario |
| Cerrar una sesión propia | La indicada | El propio usuario |
| Bloquear o retirar dispositivo | Las del dispositivo | `devices.manage` en esa sucursal |
| Revocar todas las de un usuario | Todas | `users.sessions.revoke`, solo `ADMIN` |
| Usuario deshabilitado | Todas | Automático |
| Cambio de contraseña | Todas menos la actual | Automático |
| Reúso de refresh | La afectada | Automático |
| Baja de membresía | Ninguna | La autorización la rechaza en la siguiente petición |

Un `MANAGER` puede cortar el acceso en su sucursal bloqueando el dispositivo o dando de baja la membresía. No puede cerrar las sesiones de esa persona en otras sucursales.

**Auditoría:**

- Generan `audit_log`: alta de dispositivo, login correcto, logout, revocaciones, bloqueo, reúso y bloqueo por intentos.
- No lo generan las renovaciones normales ni cada login fallido, para que un ataque no sature una colección append-only. Esos intentos van al log de aplicación.
- Los eventos sin sucursal usan `branch_id = null`.
- Nunca se registran contraseñas, tokens, hashes ni credenciales.

---

## 8. Contrato de API

Base `/api/v1`. Se asume la envoltura `{ "data": ..., "meta": { "requestId": ... } }`: el documento define `Meta`, pero no `data`, por lo que debe confirmarse en la tarea 23.

Todas las respuestas con tokens llevan `Cache-Control: no-store`.

| Método y ruta | Autenticación | Permiso | Respuestas |
|---|---|---|---|
| `POST /auth/login` | CSRF | Ninguno | 200, 401, 403, 422, 429 |
| `POST /auth/mobile/enroll` | Código de alta | Ninguno | 201, 401, 409, 422, 429 |
| `POST /auth/mobile/login` | Credencial de dispositivo | Membresía en la sucursal | 201, 401, 403, 422, 429 |
| `POST /auth/mobile/refresh` | Refresh token | Ninguno | 200, 401, 403, 422, 429 |
| `POST /auth/logout` | Sesión o Bearer | Ninguno | 204, 401, 403 |
| `GET /auth/me` | Sesión o Bearer | Ninguno | 200, 401 |
| `GET /auth/sessions` | Sesión o Bearer | Ninguno | 200, 401 |
| `POST /auth/sessions/{sessionId}/revoke` | Sesión o Bearer | Dueño de la sesión | 204, 401, 403, 404 |
| `POST /users/{userId}/sessions/revoke` | Sesión | `users.sessions.revoke` | 204, 401, 403, 404, 422 |
| `POST /branches/{branchId}/devices/enrollment-codes` | Sesión o Bearer | `devices.enroll` | 201, 401, 403, 404 |
| `GET /branches/{branchId}/devices` | Sesión o Bearer | `devices.read` | 200, 401, 403, 404 |
| `POST /branches/{branchId}/devices/{deviceId}/block` | Sesión o Bearer | `devices.manage` | 204, 401, 403, 404, 422 |
| `POST /branches/{branchId}/devices/{deviceId}/retire` | Sesión o Bearer | `devices.manage` | 204, 401, 403, 404, 422 |

Reglas comunes:

- **Revocar y bloquear son idempotentes:** repetir la operación responde 204. No usan `If-Match`, porque cortar un acceso no debe perder ante un cambio concurrente.
- **Login y refresh no usan `Idempotency-Key`:** el refresh ya controla sus repeticiones con la rotación, y guardar su respuesta conservaría tokens en claro.
- **Límites por IP y correo:** login con 5 intentos por minuto por correo e IP, y 20 por IP. Refresh con 30 por minuto por sesión.
- **Mensaje de login:** siempre es genérico, sin indicar si el correo existe.

### Códigos de error nuevos

```text
SESSION_REVOKED          401  Renovación rechazada; requiere login
DEVICE_NOT_ENROLLED      401  Credencial o dispositivo inválidos
ENROLLMENT_CODE_INVALID  401  Código vencido, usado o incorrecto
DEVICE_ALREADY_ENROLLED  409  El deviceUuid ya existe; generar otro y usar un código nuevo
DEVICE_BLOCKED           403  Dispositivo bloqueado o retirado; borrar datos locales
DEVICE_BRANCH_MISMATCH   403  La ruta pertenece a otra sucursal
CSRF_TOKEN_MISMATCH      403  Token CSRF ausente o inválido; Laravel usa 419 por defecto, hay que mapearlo
TOO_MANY_ATTEMPTS        429  Incluye Retry-After
```

Se usa `SESSION_REVOKED` también en caso de reúso, para no revelarle a un atacante que fue detectado. Con cualquier 401 de una ruta normal, el cliente intenta una renovación y, si falla, vuelve al login.

### Ejemplos

Login móvil:

```bash
curl -i -X POST "https://api-dev.restia.com/api/v1/auth/mobile/login" \
  -H "Content-Type: application/json" \
  -H "X-Request-ID: 0b3c1f6e-8f7a-4a51-9a3e-2d1c7e5b9a10" \
  -d '{
    "email": "mesero@ejemplo.mx",
    "password": "<contraseña>",
    "deviceUuid": "6f1e2c7a-3d44-4b1a-9c55-8e2f0a7b1d33",
    "deviceCredential": "<credencial>"
  }'
```

```http
HTTP/1.1 201 Created
Content-Type: application/json
Cache-Control: no-store
X-Request-ID: 0b3c1f6e-8f7a-4a51-9a3e-2d1c7e5b9a10
```

```json
{
  "data": {
    "session": {
      "id": "a9d4e1b2-7c60-4f38-b1de-5f6a2c9e0d47",
      "absoluteExpiresAt": "2026-12-02T18:00:00Z"
    },
    "accessToken": "<access_token>",
    "accessTokenExpiresIn": 900,
    "refreshToken": "<refresh_token>",
    "refreshTokenExpiresAt": "2026-11-09T18:00:00Z"
  },
  "meta": { "requestId": "0b3c1f6e-8f7a-4a51-9a3e-2d1c7e5b9a10" }
}
```

`accessTokenExpiresIn` se expresa en segundos para no depender del reloj del dispositivo. Los permisos se consultan en `GET /auth/me`.

Renovación:

```bash
curl -i -X POST "https://api-dev.restia.com/api/v1/auth/mobile/refresh" \
  -H "Content-Type: application/json" \
  -d '{ "deviceUuid": "6f1e2c7a-3d44-4b1a-9c55-8e2f0a7b1d33", "refreshToken": "<refresh_token>" }'
```

Renovación rechazada:

```json
{
  "error": {
    "code": "SESSION_REVOKED",
    "message": "La sesión ya no es válida. Inicia sesión de nuevo.",
    "requestId": "7c2a9d10-41be-4a67-8f03-1b6e5d2c8a94"
  }
}
```

Bloqueo de un dispositivo:

```bash
curl -i -X POST "https://api-dev.restia.com/api/v1/branches/$BRANCH_ID/devices/$DEVICE_ID/block" \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "reason": "Dispositivo extraviado" }'
```

Login Web con CORS:

```http
POST /api/v1/auth/login HTTP/1.1
Origin: https://admin.restia.com
X-XSRF-TOKEN: <token>
Cookie: XSRF-TOKEN=<token>; laravel_session=<sesión>
```

```http
HTTP/1.1 200 OK
Access-Control-Allow-Origin: https://admin.restia.com
Access-Control-Allow-Credentials: true
Set-Cookie: laravel_session=<sesión>; Path=/; Secure; HttpOnly; SameSite=Lax
Cache-Control: no-store
```

### Fragmento OpenAPI 3.0.3

```yaml
paths:
  /auth/mobile/refresh:
    post:
      tags: [Authentication]
      operationId: refreshMobileSession
      summary: Rota el refresh token y emite un access token nuevo
      security: []
      parameters:
        - $ref: '#/components/parameters/RequestId'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/MobileRefreshRequest'
      responses:
        '200':
          description: Tokens renovados
          headers:
            Cache-Control:
              schema:
                type: string
                enum: [no-store]
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/MobileTokenResponse'
        '401':
          description: Sesión inválida, vencida o revocada
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
        '403':
          description: Dispositivo bloqueado o retirado
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
        '422':
          description: Datos inválidos
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
        '429':
          description: Demasiados intentos
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'

components:
  schemas:
    MobileRefreshRequest:
      type: object
      required: [deviceUuid, refreshToken]
      additionalProperties: false
      properties:
        deviceUuid:
          $ref: '#/components/schemas/Uuid'
        refreshToken:
          type: string
          writeOnly: true
          minLength: 32

    MobileTokenResponse:
      type: object
      required: [data, meta]
      properties:
        data:
          type: object
          required:
            - session
            - accessToken
            - accessTokenExpiresIn
            - refreshToken
            - refreshTokenExpiresAt
          properties:
            session:
              type: object
              required: [id, absoluteExpiresAt]
              properties:
                id:
                  $ref: '#/components/schemas/Uuid'
                absoluteExpiresAt:
                  $ref: '#/components/schemas/Timestamp'
            accessToken:
              type: string
            accessTokenExpiresIn:
              type: integer
              minimum: 1
            refreshToken:
              type: string
            refreshTokenExpiresAt:
              $ref: '#/components/schemas/Timestamp'
        meta:
          $ref: '#/components/schemas/Meta'
```

El contrato completo de estos endpoints está en `openapi/restia-api.yaml`.

---

## 9. Pruebas de aceptación

| ID | Escenario | Resultado esperado |
|---|---|---|
| CRED-01 | Login con dispositivo activo y membresía | 201; sesión y token creados, con auditoría |
| CRED-02 | Login sin membresía en la sucursal del dispositivo | 403; sin sesión |
| CRED-03 | Credencial de dispositivo incorrecta | 401 `DEVICE_NOT_ENROLLED` |
| CRED-04 | Código de alta usado dos veces en paralelo | Una alta correcta y un 401 |
| CRED-05 | Access token vencido | 401; el cliente renueva y reintenta una vez |
| CRED-06 | Dos renovaciones simultáneas con el mismo refresh | Ambas responden 200 dentro de la gracia; queda un solo refresh válido |
| CRED-07 | Respuesta de renovación perdida; reintento antes de 60 s | 200; la sesión sigue activa |
| CRED-08 | Refresh anterior usado después de 60 s | 401; sesión `REVOKED` por `REFRESH_REUSE` |
| CRED-09 | Atacante renueva dentro de la gracia y luego lo hace el cliente | Sesión revocada en la renovación del cliente |
| CRED-10 | Dos logins simultáneos en un dispositivo | Solo una sesión `ACTIVE` |
| CRED-11 | Login que excede el límite de sesiones del usuario | Se revoca la más antigua, con auditoría |
| CRED-12 | Bloqueo de dispositivo | La siguiente petición da 401 y la renovación da 403 |
| CRED-13 | Bloqueo repetido | 204; una sola revocación auditada |
| CRED-14 | Baja de membresía con token vigente | 403 en la siguiente petición |
| CRED-15 | Ruta de otra sucursal desde el dispositivo | 403 `DEVICE_BRANCH_MISMATCH` |
| CRED-16 | Usuario deshabilitado | Todas sus sesiones revocadas |
| CRED-17 | Cambio de contraseña | Las demás sesiones revocadas; la actual sigue |
| CRED-18 | Web sin CSRF en un `POST` | 403 `CSRF_TOKEN_MISMATCH` |
| CRED-19 | Android con Bearer, sin CSRF | Aceptado |
| CRED-20 | Web tras 60 min inactiva o 16 h totales | 401 |
| CRED-21 | Sexto intento de login en un minuto | 429 con `Retry-After` |
| CRED-22 | Borrador offline enviado dos veces | Una sola comanda |
| CRED-23 | Borrador de otro usuario tras cambio de sesión | No visible ni enviable |
| CRED-24 | Revisión de `audit_log` y logs | Sin contraseñas, tokens ni hashes |
| CRED-25 | Refresh con firma inválida y UUID de una sesión ajena | 401; la sesión sigue activa |

---

## 10. Registro de cambio (sección 37)

| Campo | Contenido |
|---|---|
| **ID** | D-36 |
| **Decisión anterior** | Pendiente. Ya existían Sanctum con cookie para Web y Bearer opaco para Android (secciones 19 a 21). |
| **Nueva decisión** | Secciones 1 a 8 de este documento. El contrato completo está en `openapi/restia-api.yaml`. |
| **Motivo** | Reducir el daño de un token robado, cubrir pérdida de dispositivos, permitir revocación inmediata y definir el modo offline. |
| **Modelo de datos** | Nuevas: `auth_sessions`, `device_enrollment_codes`, `service_identities`, `personal_access_tokens`. Campos nuevos en `devices`. |
| **Estados** | Nuevos: `auth_sessions` y `devices`. Sin cambios en estados de negocio. |
| **API** | Endpoints de la sección 8, códigos de error nuevos y permisos `devices.enroll`, `devices.read`, `devices.manage` y `users.sessions.revoke`. |
| **Android** | Alta, almacenamiento seguro, renovación, borradores y borrado local. |
| **Web** | Sesión registrada en `auth_sessions`, con máximo de 16 h. |
| **Seguridad** | Rotación, detección de reúso, límites de intentos y credencial de dispositivo. |
| **Auditoría** | Eventos de la sección 7, con `branch_id = null` cuando no hay sucursal. |

---

## 11. Identidades de servicio y QR

**Servicios (IA e impresión):**

- Cada identidad vive en `service_identities`, con permisos mínimos y sucursales autorizadas.
- Su token lo emite un `ADMIN`, se muestra una sola vez y dura 90 días.
- Al rotar, el anterior sigue válido 7 días para poder desplegar sin cortes.
- Se puede revocar de inmediato. No usa refresh token.
- Permisos iniciales propuestos: `reservation-ai` limitado a disponibilidad y reservaciones; `printing-agent` limitado a reclamar y actualizar trabajos de impresión.

**QR público:**

- El token QR sigue siendo opaco y queda fuera del ciclo de empleados.
- Su rotación y su relación con la sesión activa de la mesa se definen en el módulo Public QR.

---

## 12. Verificación técnica

**Confirmado en documentación oficial:**

- Sanctum permite expiración por token, revocación individual y limpieza con `sanctum:prune-expired`.
- La revocación estándar de Sanctum borra el token; por eso el historial queda en `auth_sessions`.
- Sanctum no aparece con un flujo de refresh en la documentación consultada, por lo que esta propuesta lo implementa aparte.
- Laravel 11 dejó de recibir correcciones de seguridad el 12 de marzo de 2026. Laravel 12 las recibe hasta el 24 de febrero de 2027 y Laravel 13 hasta el 17 de marzo de 2028.

**Por comprobar con una prueba técnica antes de implementar:**

1. Compatibilidad de Sanctum y `mongodb/laravel-mongodb` con un modelo propio de `PersonalAccessToken` que use `DocumentModel`, en la versión de Laravel elegida. La documentación consultada no lo confirma para Laravel 13.
2. Almacenamiento de la sesión Web de Laravel en MongoDB.
3. Costo de que Sanctum actualice `last_used_at` en cada petición.
4. Comportamiento de `findOneAndUpdate` dentro de una transacción con reintentos ante conflictos de escritura.

**Decisión aparte sobre la versión de Laravel:** el documento maestro fija Laravel 11, que ya no tiene soporte de seguridad. Se recomienda Laravel 13 si la prueba 1 lo permite. Cambiarlo requiere su propio registro de control de cambios.

**Fuentes:** [Sanctum](https://laravel.com/framework/docs/sanctum), [notas de versión de Laravel](https://laravel.com/framework/docs/releases), [laravel-mongodb](https://github.com/mongodb/laravel-mongodb/blob/5.x/README.md).
