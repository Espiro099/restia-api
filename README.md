# restia-api

Backend de Restia: API REST en Laravel 13 con MongoDB. Es la fuente única del contrato que usan `restia-mobile` y `restia-web`.

**Estado:** el contrato y las decisiones están aprobados; el código del backend aún no existe.

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

Escribe `docs/Catalogo_Errores.md`, `docs/Matriz_Endpoints.md`, `docs/Matriz_Transiciones.md` y `tests/contract/casos_negativos.csv`.

`tools/apply_conflict_codes.py` y `tools/apply_security_fixes.py` se usaron una sola vez para aplicar revisiones al contrato. Se conservan como registro; no hace falta volver a ejecutarlos.

## Cambios al contrato

Todo cambio al contrato pasa por PR, con las pruebas del contrato en verde. Si rompe compatibilidad, se registra en el control de cambios del plan de implementación, y se copia el contrato nuevo a `restia-mobile/docs/contrato/`.
