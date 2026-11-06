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
php artisan serve
```

`mongo-local.ps1` arranca MongoDB como replica set de un nodo en el puerto **27018**, con sus datos en `.mongo-data/`. No toca un MongoDB que ya corra como servicio en el 27017. Para detenerlo: `.\scripts\mongo-local.ps1 -Stop`. Si `php` no está en el PATH, pasa `-Php <ruta a php.exe>`.

La API responde en `http://127.0.0.1:8000/api/v1`, y `GET /up` indica si la aplicación está viva.

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

La integración continua falla si `config/errors.php` no coincide con el catálogo.

`tools/apply_conflict_codes.py` y `tools/apply_security_fixes.py` se usaron una sola vez para aplicar revisiones al contrato. Se conservan como registro; no hace falta volver a ejecutarlos.

## Cambios al contrato

Todo cambio al contrato pasa por PR, con las pruebas del contrato en verde. Si rompe compatibilidad, se registra en el control de cambios del plan de implementación, y se copia el contrato nuevo a `restia-mobile/docs/contrato/`.
