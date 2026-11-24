# Inicio de sesión con Google: decisiones

**Estado:** APROBADAS (GOOGLE-1 a GOOGLE-4)
**Fecha:** 2026-11-24
**Contrato:** `openapi/restia-api.yaml` (`getGoogleLoginConfig`, `loginWebGoogle`, `loginMobileGoogle`)
**Relacionado:** D-36 (ciclo de vida de credenciales)

El personal puede entrar al panel y a la app con su cuenta de Google en lugar de la contraseña. La contraseña sigue funcionando igual; Google es otra forma de probar quién es la persona, y la sesión que se abre es la misma de D-36.

## Endpoints

| Método | Ruta | Para qué |
|---|---|---|
| GET | `/auth/google` | ¿Está encendido? Y con qué ID de cliente pedir el token |
| POST | `/auth/google` | Panel: abre la sesión Web por cookie, como `/auth/login` |
| POST | `/auth/mobile/google` | App: abre la sesión Android en un dispositivo dado de alta, como `/auth/mobile/login` |

## Decisiones aprobadas

| ID | Decisión | Recomendación aprobada | Alternativa |
|---|---|---|---|
| **GOOGLE-1** | Quién entra | Solo personal ya dado de alta y `ACTIVE`, con el mismo correo que Google marca como verificado. **No se crean cuentas.** La app sigue exigiendo dispositivo dado de alta y membresía en su sucursal | Registro abierto con Google |
| **GOOGLE-2** | Verificación | El panel usa Google Identity Services y la app Credential Manager; los dos mandan el **ID token** a la API, que lo verifica localmente: firma RS256 con las llaves públicas de Google (en caché), emisor, audiencia (el ID de cliente Web) y vigencia. Sin secreto de cliente | Flujo de código con secreto en el servidor |
| **GOOGLE-3** | Cuenta ligada | La primera vez se guarda el `sub` de Google en el usuario (índice único parcial). Desde entonces solo esa cuenta de Google entra, aunque otra use el mismo correo | Comparar siempre solo por correo |
| **GOOGLE-4** | Errores y encendido | Cualquier rechazo responde `401 AUTHENTICATION_INVALID`, igual que una contraseña mala, y no se registra ni el correo ni el token. Sin `GOOGLE_CLIENT_ID`, `GET /auth/google` dice `enabled: false`, los botones no aparecen y los POST responden 404 | Errores distintos por causa |

## Configuración

- En Google Cloud: un ID de cliente OAuth **Web** (orígenes autorizados: el dominio del panel) y uno **Android** (paquete y huella SHA-1 de la firma de la app). La API solo necesita el Web: la app lo usa como `serverClientId`, así que el token llega con esa audiencia.
- En la API: `GOOGLE_CLIENT_ID` con el ID de cliente Web.
- En el panel: la CSP permite `https://accounts.google.com/gsi/` para el script, el estilo, el marco y las conexiones del botón de Google.
- Mientras la pantalla de consentimiento esté en modo de prueba, solo entran los correos agregados como usuarios de prueba (hasta 100).
