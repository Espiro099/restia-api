# Resultados de la prueba técnica

**Fecha:** 2026-11-02
**Código:** `poc/` (desechable; no es la base de `restia-api`)

**Conclusión:** Laravel 13 se mantiene (SEC-2), y el patrón de concurrencia del contrato funciona. Hay que ajustar el manejo de reintentos: es la principal enseñanza.

## 1. Entorno

| Componente | Versión |
|---|---|
| Laravel | 13.39.0 |
| PHP | 8.4.27 NTS, portátil |
| Sanctum | 4.3.3 |
| `laravel-mongodb` / `mongodb/mongodb` | 5.12.0 / 2.5.0 |
| Extensión `mongodb` de PHP | 2.5.5 |
| MongoDB | 8.3.4, **replica set de un solo nodo**, en Windows |

La prueba corrió en un proceso de MongoDB aparte, con otro puerto y datos propios. Tu MongoDB, tu XAMPP y tu sistema no se modificaron.

## 2. Resultados

| # | Prueba | Resultado | Evidencia |
|---|---|---|---|
| 1 | Laravel 13, `laravel-mongodb` y Sanctum, con `PersonalAccessToken` propio | **Pasa** | Token válido `200`; inválido, vencido, revocado o ausente `401`; hash guardado; limpieza de vencidos |
| 2 | Sesión Web en MongoDB con CSRF | **Pasa** | Sin CSRF `419`; clave mala `422`; login `200`; la sesión queda en MongoDB; tras logout `401` |
| 3 | Costo de `last_used_at` | **Pasa** | Unos 8 ms por petición en esta máquina, y es una sola escritura |
| 4 | `findOneAndUpdate` en transacción con reintentos | **Pasa** | 30 rondas con 8 procesos: siempre un solo ganador, 659 intentos para 240 operaciones, sin errores |
| 5 | Dos transacciones sobre el mismo documento chocan | **Pasa** | Ver sección 3 |
| 6 | Índice único parcial en transacción | **Pasa, con hallazgo** | Ver sección 4 |
| 7 | Contención de `operations_epoch` | **Falla a saturación; pasa a tasas de operación** | Ver sección 5 |
| 8 | `Decimal128` conserva la escala | **Pasa** | `10.50` se mantiene en MongoDB, Eloquent y JSON; `0.10 + 0.20 = 0.30` exacto |
| 9 | Parámetros `status[$ne]=x` | **Pasa con validación; el riesgo es real** | Sin validar devolvió 2 usuarios en vez de 1; con la regla `string`, `422` |

## 3. Prueba 5: la más importante

Seis procesos intentan apartar el mismo lugar, en 40 rondas:

| Variante | Dobles apartados | Reservas | Intentos |
|---|---|---|---|
| Sin `operations_epoch` (control) | **40 de 40** | 240 | 240 |
| Con `operations_epoch` | **0** | 40 | 651 |

El control prueba que el riesgo existe y que la prueba lo detecta: leer e insertar en una transacción no basta. Con la escritura previa del `epoch`, nunca hubo doble apartado, y quien reintentó sí vio el resultado del otro: 200 veces recibió "ocupado".

## 4. Prueba 6: hallazgo sobre errores

El índice único siempre dejó pasar uno por mesa, y un duplicado revirtió toda la transacción. Pero el error que recibe el perdedor depende de los reintentos:

| Intentos | Duplicado (`E11000`) | Conflicto de escritura |
|---|---|---|
| 1, el valor por omisión de Laravel | 1 | **149** |
| 5 | 142 | 8 |
| 20 | 150 | 0 |

Con el valor por omisión, casi nadie recibiría `409 TABLE_ALREADY_ASSIGNED`, sino un error genérico. Por eso la capa común debe reintentar, y solo traducir a `409` el duplicado.

## 5. Prueba 7: contención

**A saturación**, con procesos sin pausa, no cumplió el criterio que fijé (menos de 1 % agotados con 4 procesos): dio 6.3 %, y 32.8 % con 16. El rendimiento se mantuvo en unas 255 operaciones por segundo, porque el documento las serializa. Ese escenario no se parece a un restaurante, así que repetí con tasas de operación, con 8 procesos y una sola sucursal:

| Tasa objetivo (ops/s) | Intentos | Espera aleatoria | Lograda | p95 | Reintentos por op. | Agotados |
|---|---|---|---|---|---|---|
| 2 | 5 | No | 2.2 | 19 ms | 0 | 0 % |
| 10 | 5 | No | 9.5 | 19 ms | 0.04 | 0 % |
| 50 | 5 | No | 44 | 36 ms | 0.43 | 0.68 % |
| 100 | 5 | No | 70 | 66 ms | 1.22 | 4.76 % |
| 50 | 5 | Sí | 41 | 101 ms | 0.55 | 0.49 % |
| 100 | 5 | Sí | 57 | 179 ms | 1.06 | 2.91 % |
| 200 | 10 | Sí | 61 | 331 ms | 1.35 | 0.81 % |

**Lectura:** el `epoch` es por sucursal y solo lo escriben aperturas, cambios de mesa y reservas. Estimo que una sucursal hace bastante menos de una de estas operaciones por segundo, aun en hora pico, y hasta 10 por segundo no hubo ningún agotado. **Esa estimación es mía: hay que confirmarla con datos reales.** El límite aparece muy por encima, pero existe.

## 6. Qué cambia

**En el contrato (ya aplicado):** toda operación en transacción declara `503 SERVICE_BUSY`, porque la prueba muestra que cualquiera puede agotar reintentos, no solo las del `epoch`.

**Reglas para la capa común:**

- Nunca usar `transaction()` sin parámetros: reintentar hasta 10 veces, con espera aleatoria.
- Clasificar por la etiqueta `TransientTransactionError` del error, no por su texto.
- `E11000` se traduce al `409` del módulo; reintentos agotados, a `503 SERVICE_BUSY`.
- Validar tipo escalar en todo filtro. La prueba 9 mostró que sin esto la inyección funciona.

**En tu equipo:** XAMPP tiene PHP 8.2, y Laravel 13 exige 8.3 o superior. Para desarrollar hace falta otra versión; la portátil de esta prueba sirve de referencia.

## 7. Lo que esta prueba no cubre

- **Tres nodos:** con confirmación por mayoría y red real, cada transacción tarda más, y la capacidad será menor. Hay que repetir las pruebas 5 y 7 en staging.
- **Versión de producción:** MongoDB 8.3 es la instalada aquí. Hay que repetir con la versión que se use en producción.
- **Servidor real:** `artisan serve` atiende una petición a la vez y arranca Laravel en cada una. Los tiempos HTTP de la prueba 3 solo sirven para comparar.
- **Fallas:** caída del nodo primario y reconexión.
- **Flujo completo de D-36:** refresh con rotación, detección de reúso, dispositivos y sesiones con `auth_sessions`.
- **Lectura de TTL:** el comportamiento de vencimiento no se midió.
