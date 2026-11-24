# Sitio público de La Mesa: decisiones del contrato

**Estado:** APROBADAS (SITE-1 a SITE-4)
**Fecha:** 2026-11-23
**Contrato:** `openapi/restia-api.yaml`, etiqueta `Public Site`

La página pública de La Mesa (`/` en el sitio web) muestra las sucursales en un minimapa, la más cercana primero, permite pedir una reserva y tiene un asistente que responde dudas. No hay cuenta ni cookies: las cinco operaciones van sin autenticación, con límites por IP.

## Endpoints

| Método | Ruta | Para qué |
|---|---|---|
| GET | `/public/site/branches` | Sucursales activas con dirección, teléfono, punto en el mapa y horario |
| GET | `/public/site/branches/{code}/availability` | Horarios con lugar para una fecha y un grupo |
| POST | `/public/site/reservation-requests` | Envía una solicitud de reserva (con `Idempotency-Key`) |
| POST | `/public/site/reservation-requests/lookup` | Consulta una solicitud por folio y teléfono |
| POST | `/public/site/assistant` | Pregunta al asistente |

## Decisiones aprobadas

| ID | Decisión | Recomendación aprobada | Alternativa |
|---|---|---|---|
| **SITE-1** | Sucursales y mapa | Se exponen por código, sin UUID. El punto lo pone el administrador (B-8). La distancia se calcula en el navegador: la ubicación de la persona nunca llega a la API. Mapa con Leaflet y teselas de OpenStreetMap; "Cómo llegar" abre Google Maps por URL, sin llave | Geocodificar en la API o usar Google Maps embebido con llave |
| **SITE-2** | Reservas desde el sitio | **Solicitud**, no reserva directa: crea una reserva `PENDING` con `source: WEB` y folio. No aparta mesas (R-1), vence sola si nadie la confirma (R-7) y el personal la confirma o cancela desde la app o el panel. Solo se ofrecen horarios con lugar; al enviar se vuelve a revisar | Reserva confirmada al instante, con mesa asignada |
| **SITE-3** | Asistente | Responde dudas de cartas, sucursales y horarios con los datos de la API. Para reservar **solo propone** los datos con la herramienta `proponer_reserva`; la página los muestra en el formulario y la persona toca «Enviar solicitud». No guarda la conversación | Que el asistente cree la solicitud por su cuenta |
| **SITE-4** | Abuso | Límites por IP y por operación: 60 lecturas, 3 solicitudes, 10 consultas y 8 preguntas por minuto. Máximo 2 solicitudes activas por teléfono y sucursal. Folio con otro teléfono responde 404, igual que uno que no existe | CAPTCHA o verificación por SMS |

## Reglas de la solicitud (SITE-2)

- Horarios cada 30 minutos dentro del horario de reservas, con al menos 60 minutos de antelación, para hoy y hasta 60 días.
- Se aparta lo que dura una reserva normal: 90 minutos. Grupos de 1 a 12; para más, el sitio sugiere llamar.
- El teléfono se escribe a 10 dígitos y se guarda en E.164 (`+52…`), igual que los capturados por el personal, para que la búsqueda y el límite por teléfono funcionen con ambos.
- La auditoría queda con actor `SYSTEM` y `source: WEB`; el outbox publica `reservation.created`, así que la campana del personal la avisa como cualquier otra.
- La respuesta solo trae folio, estado, sucursal, fecha, hora, personas y nombre. Nunca mesas ni notas internas.

## Asistente (SITE-3)

- Modelo: Claude por la API de Anthropic (`ANTHROPIC_API_KEY`, `RESTIA_ASSISTANT_MODEL`, por omisión `claude-haiku-5-5`). Sin clave, `meta.assistant` es `false` y el sitio oculta el chat; lo demás funciona igual.
- Las instrucciones y el contexto de sucursales y cartas van primero y en caché; la fecha del día va después del bloque en caché para no invalidarlo.
- La página manda la conversación completa (hasta 16 turnos de 600 caracteres), alternando cliente y asistente y terminando en el cliente. Solo las preguntas válidas cuentan para el límite.
- La propuesta (`reservationDraft`) se limpia: lo que no tiene el formato esperado llega en `null` para que la persona lo complete. La validación real es la de la solicitud.
- Si la API de Claude no responde, el sitio recibe `503 SERVICE_BUSY` y lo dice; el formulario de reserva sigue disponible.

## Privacidad

- Sin cookies ni identificadores de visitante. La ubicación se usa solo en el navegador para ordenar las sucursales.
- Las conversaciones no se guardan. Los datos de la solicitud (nombre y teléfono) se tratan como los de cualquier reserva y se anonimizan con el cliente.
