# Pruebas de contrato

**Fecha:** 2026-11-02
**Contrato:** `openapi/restia-api.yaml`

## 1. Qué existe y qué se ejecutó

| Nivel | Qué comprueba | Archivo | Estado |
|---|---|---|---|
| 1. Estática | Reglas del contrato, mínimo privilegio y exposición de datos | `tests/contract/test_contract.py` | **Ejecutada** |
| 2. Colección | Cada operación se puede invocar con una petición válida | `postman/` | **Ejecutada contra un mock** |
| 3. Conformidad | La API real responde según el contrato | Por crear | Requiere API |
| 4. Casos negativos | Cada código 409 ocurre en su escenario | `tests/contract/casos_negativos.csv` | Solo catálogo; requiere API |
| 5. Concurrencia y carga | Choques, reintentos, contención | Tarea 37 | Requiere API y staging |

**Ninguna de estas pruebas demuestra que la API funcione, porque aún no existe.** Demuestran que el contrato es coherente y que la colección lo respeta.

## 2. Ejecutar

Desde la raíz del proyecto:

```bash
pip install -r tests/requirements.txt
```

```bash
pytest tests/contract
```

Para la colección, primero se levanta un mock generado del contrato:

```bash
npx @stoplight/prism-cli@5.14.2 mock openapi/restia-api.yaml -p 4010
```

```bash
npx newman@6.2.1 run postman/Restia.postman_collection.json -e postman/Restia.local.postman_environment.json
```

El ambiente no incluye secretos: `bearerToken`, `serviceToken`, `webSession`, `qrToken` y `xsrfToken` van vacíos y deben definirse antes de ejecutar, con cualquier valor en el caso del mock.

## 3. Regenerar tras cambiar el contrato

```bash
npx openapi-to-postmanv2@4.25.0 -s openapi/restia-api.yaml -o postman/Restia.postman_collection.json -c postman/config.json -p
```

```bash
python tools/build_postman.py
```

```bash
python tools/gen_matrices.py
```

## 4. Nivel 1: qué se revisa

- Los criterios de la sección 36 en cada operación, con códigos `409` exactos.
- `If-Match` implica `400` y `VERSION_CONFLICT`; `Idempotency-Key` implica `IDEMPOTENCY_KEY_REUSED`; escribir con cookie implica CSRF.
- Sin `DELETE`, sin estados HTTP fuera de la sección 30 (más `304`) y con errores en formato uniforme.
- `reservation-ai` y `printing-agent` solo en una lista cerrada de operaciones.
- Roles sin acceso a módulos, y operaciones reservadas a gerentes o `ADMIN`.
- API pública sin datos internos, CSD que nunca se devuelve y sin datos completos de tarjeta.

**Sensibilidad comprobada:** se inyectaron 12 defectos en copias del contrato, como dar a la IA acceso al cierre de caja, exponer datos fiscales al público o quitar CSRF. Los 12 hicieron fallar la suite.

## 5. Límites de lo ejecutado

- **El mock no valida respuestas.** Prism genera valores que incumplen patrones, como `Money`, porque el contrato no trae ejemplos completos. Por eso las respuestas se comprueban en el nivel 3.
- **La colección usa datos de ejemplo.** Sirve como prueba de humo con mock; contra una API real requiere datos sembrados y un orden de ejecución.
- **`x-restia` son declaraciones.** Que el contrato diga "solo gerentes" no prueba que el backend lo cumpla; eso corresponde a los niveles 3 y 4.

## 6. Pendiente para cuando exista la API

- **Nivel 3:** recomiendo Schemathesis contra staging. No lo he probado con este contrato.
- **Nivel 4:** implementar cada fila del catálogo con su escenario, usando datos sembrados.
- **Autorización:** recorrer `Matriz_Endpoints.md` con cada rol y comprobar el `403`.
- **Concurrencia:** pagos contra cierre, doble apertura, último `ADMIN`, doble reclamo de impresión y `operations_epoch`.
- **Credenciales:** los casos CRED de D-36.
