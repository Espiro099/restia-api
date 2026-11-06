# Guía para desarrollar la base de datos de Restia en MongoDB

**Fecha:** 2026-11-04
**Para:** quien construya `restia-api`, en Laravel 13 con `mongodb/laravel-mongodb`
**Estado de la base:** aún no existe. Esta guía dice cómo construirla.

## 1. Cómo leer esta guía

Reúne en un solo lugar lo que estaba repartido entre el documento maestro y las decisiones de cada módulo. Ante una diferencia, manda la decisión del módulo.

| Marca | Significado |
|---|---|
| Sin marca | Aprobado en el documento maestro o en una decisión |
| † | **Propuesto en esta guía:** el documento nombra la colección o el campo, pero no lo define. Confírmalo al implementar |

**El código de las secciones 4 a 6 sí se ejecutó** contra MongoDB 8.3.4 en un solo nodo, con Laravel 13.39, `laravel-mongodb` 5.12 y PHP 8.4: 19 comprobaciones, incluidos 6 procesos en paralelo sobre 20 mesas, con un solo ganador por mesa. Está en `poc/guia/`. Faltan una prueba con tres nodos y una con carga real (sección 12).

**Sobre los nombres de clase:** los nombres de colección están aprobados. Los de las clases son propuesta, salvo `User` y `PersonalAccessToken`, que siguen la convención de Laravel.

## 2. Preparar el entorno

**MongoDB debe ser un replica set**, incluso en local, porque las transacciones no funcionan sin él. Un solo nodo sirve para desarrollar. Tampoco uses tu instalación de datos reales: crea otra carpeta y otro puerto.

```bash
mongod --port 27117 --bind_ip 127.0.0.1 --replSet rs0 --dbpath ./datos-mongo
```

La primera vez, inicia el replica set desde `mongosh`, que se instala aparte de MongoDB:

```bash
mongosh --port 27117 --eval "rs.initiate({_id:'rs0',members:[{_id:0,host:'127.0.0.1:27117'}]})"
```

Instala el paquete. Requiere la extensión `mongodb` de PHP y PHP 8.3 o superior:

```bash
composer require mongodb/laravel-mongodb laravel/sanctum
```

En `.env`:

```text
DB_CONNECTION=mongodb
MONGODB_URI=mongodb://127.0.0.1:27117/?replicaSet=rs0
MONGODB_DATABASE=restia
```

En `config/database.php`, dentro de `connections`:

```php
'mongodb' => [
    'driver' => 'mongodb',
    'dsn' => env('MONGODB_URI'),
    'database' => env('MONGODB_DATABASE', 'restia'),
],
```

## 3. Reglas que aplican a todo

| Tema | Regla | Por qué |
|---|---|---|
| Identificador interno | `_id` es `ObjectId`; nunca sale de la API | D-22 |
| Identificador público | `uuid`, con índice único | D-22 |
| Referencias | Entre documentos, por `ObjectId`: `branch_id`, `user_id` | Sección 16 del documento maestro y D-36 |
| Sucursal | Todo documento de una sucursal lleva `branch_id`, y casi todo índice empieza por él | D-26 |
| Fechas | UTC, en `created_at` y `updated_at` en todo documento | D-23 |
| Día operativo | `business_date`, calculado con `business_day_cutoff` de la sucursal | B-6 |
| Dinero y cantidades | `Decimal128`; nunca `float`. La API los expone como strings | D-24 |
| Versión | Entero `version` en lo modificable | D-28 |
| Nombres | Colecciones y campos en `snake_case`; la API en `camelCase` | Contrato |
| Borrado | Lo financiero, fiscal, de inventario y auditoría nunca se borra | Principio 3 |
| Copias | Lo vendido guarda nombre, precio, impuestos y datos fiscales de ese momento | M-7, O-2 |

### Referenciar o incrustar

| Caso | Decisión | Ejemplo |
|---|---|---|
| Se consulta o cambia por separado | Colección propia con referencia | `order_item_units`: la cocina la cambia una por una |
| Solo existe dentro del padre y cambia con él | Incrustado | `modifiers.options[]`, `inventory_counts.lines[]` |
| Debe conservar el valor histórico | Copia incrustada | `order_items.fiscal_snapshot`, `reservations.customer_snapshot` |
| Tiene índice único propio | Colección propia | `check_allocations`, `table_assignments` |

D-07 fija que las comandas son tres colecciones: `orders.items[]` no es fuente operativa.

### Seis errores fáciles de cometer

1. **Una referencia buscada como string no encuentra nada, y no da error.** Solo `_id` se convierte solo. Declara el cast `ObjectId` y consulta con `new ObjectId(...)`.
2. **`transaction()` sin intentos:** con el valor por omisión, en la prueba 149 de 150 perdedores recibieron un conflicto genérico en lugar del duplicado.
3. **`status[$ne]=x` en un filtro:** sin validar tipo, Laravel lo pasa como operador. En la prueba devolvió todos los usuarios. Valida siempre `string`.
4. **Índice único sobre un campo opcional:** los documentos sin el campo chocan entre sí como `null`. Usa un índice parcial.
5. **Leer y luego insertar en una transacción no evita el duplicado:** sin `epoch`, hubo 40 dobles apartados de 40 (sección 7).
6. **`getCollection()` dentro de una transacción queda fuera de ella:** no lleva la sesión, así que esa escritura no se deshace si la transacción falla. Escribe con modelos o con `DB::table()`. Lo comprueba `tests/Feature/MongoTest.php`.

## 4. Migraciones e índices

MongoDB crea las colecciones solo, pero los índices no. Cada uno va en una migración.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('table_assignments', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'table_id' => 1], 'una_asignacion_activa_por_mesa', options: [
                'partialFilterExpression' => ['active' => true],
            ]);
            $c->index(['branch_id' => 1, 'session_id' => 1, 'active' => 1]);
        });

        Schema::connection('mongodb')->create('tables', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'number' => 1]);
            $c->unique('qr_token');
            // Parcial: las mesas que nunca rotaron no tienen este campo.
            $c->unique('previous_qr_token', 'qr_anterior', options: [
                'partialFilterExpression' => ['previous_qr_token' => ['$type' => 'string']],
            ]);
        });

        Schema::connection('mongodb')->create('idempotency_records', function (Blueprint $c) {
            $c->unique(['branch_id' => 1, 'key' => 1]);
            $c->expire('expires_at', 0);
        });
    }

    public function down(): void
    {
        foreach (['table_assignments', 'tables', 'idempotency_records'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
```

Crea cada índice antes de cargar datos: uno único falla si ya hay duplicados.

**Crea todas las colecciones en sus migraciones.** No dejes que una nazca con la primera escritura dentro de una transacción. La prueba técnica las creó siempre por adelantado, así que el otro caso no está probado.

## 5. Clases base

Cada colección tiene una clase en `app/Models`, salvo las de bajo nivel, que usan el constructor de consultas: `audit_log`, `outbox_events` e `idempotency_records`.

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use MongoDB\BSON\ObjectId as BsonObjectId;
use MongoDB\Laravel\Eloquent\Model;

abstract class Document extends Model
{
    use HasPublicUuid;

    protected $connection = 'mongodb';
    protected $guarded = ['_id'];

    /** Solo `_id` se convierte solo: cualquier otra referencia se consulta como ObjectId explícito. */
    public function scopeForBranch($query, Branch|string $branch)
    {
        return $query->where('branch_id', new BsonObjectId($branch instanceof Branch ? $branch->getKey() : $branch));
    }
}
```

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** El `_id` nunca sale de la API: hacia afuera solo existe `uuid` (D-22). */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
```

```php
<?php

namespace App\Models\Concerns;

/** Concurrencia optimista: la API la expone como `version`, `ETag` e `If-Match` (D-28). */
trait HasVersion
{
    public static function bootHasVersion(): void
    {
        static::creating(function ($model) {
            $model->version ??= 1;
        });
    }

    /** Devuelve false si otra operación cambió el documento: la API responde 409 VERSION_CONFLICT. */
    public function updateIfVersion(int $expected, array $changes): bool
    {
        $changes['updated_at'] = now();
        $modified = static::query()
            ->where('_id', $this->getKey())
            ->where('version', $expected)
            ->increment('version', 1, $changes);

        if ($modified === 0) {
            return false;
        }
        $this->refresh();

        return true;
    }
}
```

### Una clase de ejemplo

Por cada campo especial, un cast:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

class Payment extends Document
{
    use HasVersion;

    protected $table = 'payments';
    protected $casts = ['branch_id' => ObjectId::class, 'amount' => 'decimal:2', 'tip' => 'decimal:2', 'confirmed_at' => 'datetime'];
}
```

| Tipo en MongoDB | Cast | Se comprobó |
|---|---|---|
| `ObjectId` | `ObjectId::class` | Sí |
| `Decimal128`, dinero | `'decimal:2'` | Sí: sale como `"10.50"` |
| `Decimal128`, cantidad | `'decimal:4'` | No se ejecutó; sigue el mismo mecanismo |
| Fecha | `'datetime'` | Sí: se guarda como `UTCDateTime` |

**Campos que no deben salir:** decláralos en `$hidden`, como `qr_token` y `activity_epoch`. Los hashes de token y contraseña van ahí también.

## 6. Transacciones

```php
<?php

namespace App\Support;

use App\Exceptions\ServiceBusy;
use Closure;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\RuntimeException;
use Throwable;

final class Mongo
{
    private const ATTEMPTS = 10;

    /** Transacción con reintentos y espera aleatoria. Nunca usar transaction() sin intentos. */
    public static function transaction(Closure $work): mixed
    {
        $attempt = 0;
        try {
            return DB::connection('mongodb')->transaction(function () use ($work, &$attempt) {
                if ($attempt++ > 0) {
                    usleep(random_int(0, (int) min(200_000, 5_000 * 2 ** $attempt)));
                }

                return $work();
            }, self::ATTEMPTS);
        } catch (Throwable $e) {
            if (self::isTransient($e)) {
                throw new ServiceBusy(previous: $e);
            }
            throw $e;
        }
    }

    public static function isTransient(Throwable $e): bool
    {
        return $e instanceof RuntimeException && $e->hasErrorLabel('TransientTransactionError');
    }

    public static function isDuplicateKey(Throwable $e): bool
    {
        if ($e instanceof BulkWriteException) {
            foreach ($e->getWriteResult()->getWriteErrors() as $error) {
                if ($error->getCode() === 11000) {
                    return true;
                }
            }
        }

        return $e->getCode() === 11000;
    }
}
```

### Caso completo: abrir una mesa

Reúne casi todas las reglas: idempotencia, `epoch`, validación de estado, índice parcial, auditoría y outbox.

```php
<?php

namespace App\Actions;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\Support\Mongo;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use Throwable;

final class OpenTableSession
{
    /** @param list<string> $tableUuids La API recibe UUID; dentro de la base se referencia por ObjectId. */
    public function run(string $branchUuid, array $tableUuids, int $guests, string $userId, string $idempotencyKey): array
    {
        $hash = hash('sha256', json_encode([$tableUuids, $guests]));

        try {
            return Mongo::transaction(function () use ($branchUuid, $tableUuids, $guests, $userId, $idempotencyKey, $hash) {
                $db = DB::connection('mongodb');
                $branch = Branch::where('uuid', $branchUuid)->first() ?? throw new DomainConflict('BRANCH_INACTIVE');
                $branchId = new ObjectId($branch->getKey());
                $actorId = new ObjectId($userId);

                // 1. Idempotencia: misma clave y contenido devuelve el resultado original (CON-1).
                $previous = $db->table('idempotency_records')
                    ->where('branch_id', $branchId)->where('key', $idempotencyKey)->first();
                if ($previous) {
                    $same = $previous->request_hash === $hash && (string) $previous->actor_id === $userId;

                    return $same ? json_decode($previous->response_body, true)
                        : throw new DomainConflict('IDEMPOTENCY_KEY_REUSED');
                }

                // 2. Primero se escribe el epoch: serializa con desactivar sucursal o poner una mesa en mantenimiento (T-9).
                Branch::where('_id', $branch->getKey())->increment('operations_epoch');
                if ($branch->status !== 'ACTIVE') {
                    throw new DomainConflict('BRANCH_INACTIVE');
                }

                // 3. Estado actual, ya dentro de la transacción.
                $tables = RestaurantTable::forBranch($branch)->whereIn('uuid', $tableUuids)->get();
                if ($tables->count() !== count($tableUuids) || $tables->contains(fn ($t) => $t->status !== 'ACTIVE')) {
                    throw new DomainConflict('TABLE_NOT_AVAILABLE');
                }

                // 4. Escrituras. El índice parcial deja una sola asignación activa por mesa.
                $session = TableSession::create([
                    'branch_id' => $branch->getKey(), 'status' => 'ACTIVE', 'guest_count' => $guests,
                    'responsible_user_id' => $userId, 'membership_version' => 1, 'activity_epoch' => 0, 'opened_at' => now(),
                ]);
                foreach ($tables as $table) {
                    TableAssignment::create([
                        'branch_id' => $branch->getKey(), 'table_id' => $table->getKey(), 'session_id' => $session->getKey(),
                        'phase' => 'SEATED', 'active' => true,
                    ]);
                }

                // 5. Auditoría, outbox e idempotencia en la misma transacción. Hacia afuera, solo UUID.
                $result = ['id' => $session->uuid, 'status' => 'ACTIVE', 'version' => 1];
                $db->table('audit_log')->insert(['branch_id' => $branchId, 'action' => 'table_session.opened', 'entity_id' => $session->uuid, 'actor_id' => $actorId, 'created_at' => now()]);
                $db->table('outbox_events')->insert(['branch_id' => $branchId, 'type' => 'table_session.opened', 'source_id' => $session->uuid, 'status' => 'PENDING', 'next_attempt_at' => now(), 'created_at' => now()]);
                $db->table('idempotency_records')->insert([
                    'branch_id' => $branchId, 'key' => $idempotencyKey, 'actor_id' => $actorId, 'operation_id' => 'openTableSession',
                    'request_hash' => $hash, 'status_code' => 201, 'response_body' => json_encode($result), 'resource_id' => $session->uuid,
                    'created_at' => now(), 'expires_at' => now()->addHours(72),
                ]);

                return $result;
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('TABLE_ALREADY_ASSIGNED') : $e;
        }
    }
}
```

| Situación probada | Resultado |
|---|---|
| Misma clave y mismos datos | Devuelve la respuesta original |
| Misma clave con otros datos u otro usuario | `IDEMPOTENCY_KEY_REUSED` |
| Mesa ya ocupada, con otra clave | `TABLE_ALREADY_ASSIGNED`, sin dejar rastro |
| 6 procesos sobre 20 mesas, 120 intentos | 20 aperturas, 100 rechazos, sin huérfanas |

**No contactes servicios externos dentro de una transacción:** ni PAC, ni correo, ni impresoras. Se hace después, leyendo `outbox_events`.

## 7. Patrones de concurrencia

Elige según lo que se quiere proteger:

| Patrón | Cuándo | Dónde se usa |
|---|---|---|
| `version` con actualización condicional | Dos personas editan el mismo documento | Casi todo lo editable: `If-Match` |
| Índice único parcial | Solo puede haber uno activo | Una asignación por mesa, un turno por caja, un reembolso por pago |
| `epoch`: escribir un documento común primero | La regla depende de varios documentos y ningún índice la expresa | `branches.operations_epoch`, `table_sessions.activity_epoch`, `cash_shifts.activity_epoch` |
| `findOneAndUpdate` condicional | Entregar un trabajo a un solo proceso | Reclamo de impresión, timbrado |
| Contador con `$inc` | Secuencia sin transacción | `invoice_counters` |

**Por qué existe `epoch`:** MongoDB aísla por instantánea, así que dos transacciones pueden leer "no hay reserva" e insertar las dos. Hacer que ambas escriban primero en el mismo documento las hace chocar, y quien reintenta ve el dato nuevo.

| Prueba | Dobles apartados |
|---|---|
| Sin `epoch` | 40 de 40 |
| Con `epoch` | 0 |

**`epoch` no sube `version`:** si cada cobro cambiara la versión del turno, nadie podría iniciar el cierre en plena operación por `VERSION_CONFLICT`.

### Qué escribe en cada `epoch`

| Campo | Lo escriben |
|---|---|
| `branches.operations_epoch` | Abrir sesión, agregar mesas, cambiar estado de una mesa, desactivar sucursal, confirmar, editar o sentar una reserva, abrir un turno, desactivar una caja |
| `table_sessions.activity_epoch` | Crear comanda, crear cuenta, cerrar sesión |
| `cash_shifts.activity_epoch` | Crear pago, reembolso o movimiento; iniciar el cierre |

**Reintentos:** hasta 10, con espera aleatoria. Con esa regla, a 200 operaciones por segundo se agotó el 0.81 %. Hasta 10 por segundo, ninguno, incluso con 5 intentos y sin espera.

## 8. Colecciones

**Tipo:**
- **Estado:** cambia durante su vida; no se borra.
- **Inmutable:** solo se agrega.
- **Proyección:** se puede reconstruir.
- **Técnica:** material temporal; se puede borrar.

Todas llevan además `_id`, `created_at` y `updated_at`. En los campos solo se listan los específicos.

### Identidad y acceso

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `branches` | `Branch` | Estado | `uuid`, `code`, `name`, `status`, `timezone`, `currency`, `business_day_cutoff`, `phone`, `address`, `reservation_hours`, `reservation_no_show_tolerance_minutes`, `reservation_ai_enabled`, `reservation_ai_max_party_size`, `fiscal_issuer_id`, `operations_epoch`, `menu_version`, `version` | `unique(uuid)`, `unique(code)`, `status` |
| `users` | `User` | Estado | `uuid`, `email`, `normalized_email`, `name`, `password_hash`, `status`, `version` | `unique(uuid)`, `unique(normalized_email)`, `status` |
| `user_branch_memberships` | `Membership` | Estado | `uuid`, `user_id`, `branch_id`, `role`, `status`, `version` | `unique(user_id, branch_id)`, `(branch_id, status)`, `(user_id, status)` |
| `devices` | `Device` | Estado | `device_uuid`, `branch_id`, `type`, `status`, `credential_hash`, `display_name`, `enrolled_by`, `enrolled_at`, `last_seen_at`, `version` | `unique(device_uuid)`, `(branch_id, status, type)` |
| `auth_sessions` | `AuthSession` | Estado, con TTL | `uuid`, `user_id`, `client_type`, `device_id`, `status`, `refresh_hash`, `previous_refresh_hash`, `previous_valid_until`, `refresh_expires_at`, `absolute_expires_at`, `last_activity_at`, `ip_created`, `revoked_at`, `revoked_by`, `revocation_reason`, `purge_at`, `version` | `unique(uuid)`, `partial unique(device_id) where status=ACTIVE and client_type=ANDROID`, `(user_id, status, client_type)`, `(status, absolute_expires_at)`, `TTL(purge_at)` |
| `device_enrollment_codes` | `DeviceEnrollmentCode` | Técnica | `branch_id`, `code_hash`, `created_by`, `expires_at`, `used_at` | `unique(code_hash)`, `TTL(expires_at)` |
| `personal_access_tokens` | `PersonalAccessToken` | Técnica | Los de Sanctum, más `auth_session_id` | Los de Sanctum, y † `unique(token)` |
| `service_identities` | `ServiceIdentity` | Estado | `uuid`, `name`, `type`, `branch_ids`, `status`, † `token_hash`, † `previous_token_hash`, `token_expires_at`, `previous_token_expires_at`, `last_used_at`, `version` | `unique(uuid)`, `unique(name)` |
| `password_setup_tokens` | `PasswordSetupToken` | Técnica | `user_id`, `token_hash`, `purpose`, `expires_at`, `used_at` | `unique(token_hash)`, `TTL(expires_at)` |
| `approval_grants` | `ApprovalGrant` | Técnica | `token_hash`, `branch_id`, `action`, `resource_ids`, `requested_by`, `approved_by`, `reason`, `expires_at`, `used_at` | `unique(token_hash)`, `TTL(expires_at)` |

`PersonalAccessToken` hereda de la clase de Sanctum y usa el trait `DocumentModel`, con `$primaryKey = '_id'` y `$keyType = 'string'`. Se registra con `Sanctum::usePersonalAccessTokenModel()`. Eso pasó la prueba técnica.

### Mesas y sesiones

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `tables` | `RestaurantTable` | Estado | `uuid`, `branch_id`, `number`, `capacity`, `zone`, `status`, `combination_group`, `qr_token`, `previous_qr_token`, `previous_qr_expires_at`, `version` | `unique(uuid)`, `unique(branch_id, number)`, `status`, `unique(qr_token)`, `partial unique(previous_qr_token)` |
| `table_sessions` | `TableSession` | Estado | `uuid`, `branch_id`, `status`, `guest_count`, `responsible_user_id`, `reservation_id`, `membership_version`, `business_date`, `opened_by`, `opened_at`, `close_requested_at`, `closed_at`, `cancel_reason`, `activity_epoch`, `version` | `unique(uuid)`, `(branch_id, status, created_at)`, † `(branch_id, business_date, status)` |
| `table_assignments` | `TableAssignment` | Estado | † `uuid`, `branch_id`, `session_id`, `table_id`, `active`, `phase`, † `assigned_at`, † `closed_at` | `partial unique(branch_id, table_id) where active=true`, `(branch_id, session_id, active)`, `(branch_id, table_id, active)` |
| `table_presence_signals` | `TablePresenceSignal` | Técnica | `branch_id`, `table_id`, `last_seen_at`, `expires_at` | `(branch_id, table_id, expires_at)`, `(branch_id, table_id)`, `TTL(expires_at)` |
| `table_cleaning_confirmations` | `TableCleaningConfirmation` | Estado | `branch_id`, `session_id`, `table_id`, `session_membership_version`, `status`, `confirmed_by`, `confirmed_at` | `unique(branch_id, session_id, table_id, session_membership_version)`, `(branch_id, session_id, status)` |

`active` se mantiene en `true` hasta confirmar la limpieza, para que el índice siga bloqueando la mesa. El estado operativo de la mesa, como `LIBRE`, se calcula en cada lectura y no se guarda.

### Menú

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `products` | `Product` | Estado | `uuid`, `branch_id`, `code`, `name`, `description`, `category`, `display_order`, `price`, `status`, `availability`, `modifier_ids`, `current_recipe_version`, `fiscal`, `image_url`, `version` | `unique(uuid)`, `unique(branch_id, code)`, `(branch_id, status, category)` |
| `modifiers` | `Modifier` | Estado | `uuid`, `branch_id`, `code`, `name`, `min_selections`, `max_selections`, `options[]`, `status`, `version` | `unique(uuid)`, `unique(branch_id, code)` |
| `recipes` | `Recipe` | Inmutable | `uuid`, `branch_id`, `product_id`, `recipe_version`, `status`, `items[]`, `created_by` | `unique(branch_id, product_id, recipe_version)` |
| `stations` | `Station` | Estado | `uuid`, `branch_id`, `code`, `name`, `status`, `version` | `unique(uuid)`, `unique(branch_id, code)` |
| `product_preparation_routes` | `ProductPreparationRoute` | Estado | `branch_id`, `product_id`, `station_id`, `priority` | `unique(branch_id, product_id, station_id)` |

Una receta publicada no se modifica: se crea otra versión y la anterior pasa a `SUPERSEDED` (D-13).

### Comandas

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `orders` | `Order` | Estado | `uuid`, `branch_id`, `session_id`, `client_order_uuid`, `status`, `total`, `business_date`, `captured_at`, `created_by`, `version` | `unique(uuid)`, `unique(branch_id, client_order_uuid)`, `(branch_id, session_id, created_at)`, `(branch_id, status, created_at)`, † `(branch_id, business_date, status)` |
| `order_items` | `OrderItem` | Estado | `uuid`, `branch_id`, `order_id`, `product_id`, `name_snapshot`, `quantity`, `unit_price`, `modifiers_snapshot`, `fiscal_snapshot`, `notes`, `version` | `unique(uuid)`, `(branch_id, order_id)` |
| `order_item_units` | `OrderItemUnit` | Estado | `uuid`, `branch_id`, `order_id`, `order_item_id`, `unit_number`, `status`, `station_id`, `recipe_version`, `reason`, `preparing_at`, `ready_at`, `delivered_at`, `cancelled_at`, `voided_at`, `version` | `unique(uuid)`, `unique(branch_id, order_id, order_item_id, unit_number)`, `(branch_id, order_item_id, status)`, `(branch_id, status, station_id, created_at)` |

`orders.status` es un resumen: se actualiza en la misma transacción, pero la verdad está en las unidades.

### Cuentas, pagos y caja

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `checks` | `Check` | Estado | `uuid`, `branch_id`, `session_id`, `number`, `label`, `status`, `subtotal`, `tax_total`, `adjustment_total`, `total`, `paid_total`, `pending_total`, `refunded_total`, `tip_total`, `balance`, `requested_at`, `paid_at`, `active_invoice_id`, `business_date`, `version` | `unique(uuid)`, `unique(branch_id, session_id, number)`, `(branch_id, session_id, status)`, `(branch_id, status, created_at)`, † `(branch_id, business_date, status)` |
| `check_allocations` | `CheckAllocation` | Estado | `branch_id`, `check_id`, `order_item_unit_id`, `active`, `created_by`, `closed_at` | `partial unique(branch_id, order_item_unit_id) where active=true`, `(branch_id, check_id, active)` |
| `check_adjustments` | `CheckAdjustment` | Estado | `uuid`, `branch_id`, `order_item_unit_id`, `type`, `amount`, `reason`, `approved_by`, `active`, `created_by` | `partial unique(branch_id, order_item_unit_id) where active=true` |
| `payments` | `Payment` | Estado | `uuid`, `branch_id`, `check_id`, `cash_shift_id`, `method`, `status`, `amount`, `tip`, `amount_tendered`, `change`, `card`, `reference`, `idempotency_key`, `created_by`, `confirmed_at`, `reconciled_by`, `reconcile_reason`, `version` | `unique(uuid)`, `unique(branch_id, idempotency_key)`, `(branch_id, check_id, status)`, `(branch_id, cash_shift_id, status)`, `(branch_id, status, created_at)` |
| `refunds` | `Refund` | Estado | `uuid`, `branch_id`, `payment_id`, `check_id`, `cash_shift_id`, `status`, `active`, `amount`, `tip`, `reference`, `reason`, `created_by`, `confirmed_at`, `reconciled_by`, `version` | `unique(uuid)`, `partial unique(branch_id, payment_id) where active=true`, `(branch_id, status, created_at)`, `(branch_id, cash_shift_id, status)`, † `(branch_id, check_id)` |
| `cash_registers` | `CashRegister` | Estado | `uuid`, `branch_id`, `code`, `name`, `status`, `difference_tolerance`, `version` | `unique(uuid)`, `unique(branch_id, code)` |
| `cash_shifts` | `CashShift` | Estado | `uuid`, `branch_id`, `cash_register_id`, `status`, `active`, `business_date`, `opening_amount`, `opening_difference`, `opening_difference_reason`, `opened_by`, `opened_at`, `closing_started_at`, `closed_by`, `closed_at`, `expected_cash`, `counted_cash`, `difference`, `difference_reason`, `cash_left_in_drawer`, `review_required`, `closing_summary`, `activity_epoch`, `idempotency_key`, `version` | `unique(uuid)`, `unique(branch_id, idempotency_key)`, `partial unique(branch_id, cash_register_id) where active=true`, `(branch_id, cash_register_id, status)`, `(branch_id, status, opened_at)`, † `(branch_id, business_date)`, † `partial (branch_id) where review_required=true` |
| `cash_movements` | `CashMovement` | Inmutable | `uuid`, `branch_id`, `cash_shift_id`, `type`, `amount`, `reason`, `idempotency_key`, `created_by` | `unique(uuid)`, `unique(branch_id, idempotency_key)`, `(branch_id, cash_shift_id, created_at)` |

`card` guarda marca, últimos 4 y autorización. **Nunca el número completo ni el CVV** (P-10).

### Inventario

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `ingredients` | `Ingredient` | Estado | `uuid`, `branch_id`, `code`, `name`, `base_unit`, `min_stock`, `stock_actual`, `stock_as_of`, `status`, `food_safety_block`, `version` | `unique(uuid)`, `unique(branch_id, code)` |
| `inventory_movements` | `InventoryMovement` | Inmutable | `uuid`, `branch_id`, `ingredient_id`, `type`, `quantity`, `source_type`, `source_id`, `recipe_version`, `reason`, `override`, `approved_by`, `self_approved`, `idempotency_key`, `projected_at`, `created_by` | `unique(uuid)`, `unique(branch_id, idempotency_key)`, `(branch_id, ingredient_id, created_at)`, `(branch_id, source_type, source_id)`, `(projected_at)` parcial para pendientes, † `(branch_id, type, created_at)`, † `partial (branch_id, created_at) where self_approved=true` |
| `inventory_receipts` | `InventoryReceipt` | Inmutable | `uuid`, `branch_id`, `supplier_name`, `reference`, `lines[]`, `received_at`, `created_by` | `unique(uuid)` |
| `inventory_counts` | `InventoryCount` | Estado | `uuid`, `branch_id`, `status`, `notes`, `lines[]`, `created_by`, `applied_at`, `version` | `unique(uuid)`, `partial unique(branch_id) where status=OPEN` |

`stock_actual` es una proyección que un proceso actualiza después de la transacción (I-1), y `inventory_movements` es la verdad. Por eso `START` nunca escribe en el ingrediente.

### Reservaciones y clientes

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `reservations` | `Reservation` | Estado | `uuid`, `branch_id`, `status`, `start_at`, `end_at`, `party_size`, `customer_id`, `customer_snapshot`, `notes`, `source`, `confirmation_code`, `planned_table_ids`, `plan_conflict`, `arrival_registered_at`, `session_id`, `cancel_reason`, † `idempotency_key`, `version` | `unique(uuid)`, `unique(branch_id, idempotency_key)`, `unique(branch_id, confirmation_code)`, `(branch_id, status, start_at)`, `(branch_id, start_at, status)`, `(branch_id, status, arrival_registered_at)`, `(branch_id, customer_snapshot.phone, status)` |
| `customers` | `Customer` | Estado | `uuid`, `name`, `phone`, `email`, `anonymized_at`, `version` | † `unique(uuid)`, `(phone)` no único |
| `customer_branch_profiles` | `CustomerBranchProfile` | Estado | `customer_id`, `branch_id`, `notes`, `preferences`, `visit_count`, `last_visit_at` | `unique(customer_id, branch_id)`, `(branch_id, updated_at)` |

El traslape de reservas no se puede expresar con un índice: lo resuelve `operations_epoch`.

### Fiscal

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `fiscal_issuers` | `FiscalIssuer` | Estado | `uuid`, `rfc`, `legal_name`, `tax_regime`, `postal_code`, `global_periodicity`, `csd`, `status`, `version` | `unique(uuid)`, `unique(rfc)` |
| `invoices` | `Invoice` | Estado | `uuid`, `branch_id`, `issuer_id`, `type`, `scope`, `status`, `check_id`, `batch_id`, `replaces_invoice_id`, `related_invoice_id`, `refund_id`, `receiver`, `cfdi_use`, `sat_payment_method`, `sat_payment_form`, `series`, `folio`, `lines[]`, `subtotal`, `tax_total`, `total`, `fiscal_uuid`, `stamped_at`, `cancellation`, `credit_note_required`, `last_error`, `lease_until`, `files`, `idempotency_key`, `version` | `unique(uuid)`, `unique(branch_id, idempotency_key)`, `(branch_id, status, created_at)`, `(branch_id, check_id)`, `unique(fiscal_uuid)` cuando exista, † `partial (branch_id) where credit_note_required=true`, † `(branch_id, batch_id)` |
| `invoice_batches` | `InvoiceBatch` | Estado | `uuid`, `branch_id`, `issuer_id`, `periodicity`, `period_from`, `period_to`, `check_ids`, `invoice_id`, `status`, `superseded_by`, `idempotency_key`, `version` | `unique(uuid)`, `unique(branch_id, idempotency_key)` |
| `invoice_source_allocations` | `InvoiceSourceAllocation` | Estado | `branch_id`, `check_id`, `invoice_id`, `active`, `closed_at` | `partial unique(branch_id, check_id) where active=true` |
| `invoice_events` | `InvoiceEvent` | Inmutable | `invoice_id`, `from_status`, `to_status`, `actor`, `provider_code`, `details` | `(invoice_id, created_at)` |
| `invoice_payment_complements` | `InvoicePaymentComplement` | Estado | `uuid`, `branch_id`, `invoice_id`, `payment_id`, `status`, `amount`, `fiscal_uuid`, `due_at`, `lease_until`, `last_error`, `version` | `unique(uuid)`, `unique(payment_id)`, † `(invoice_id)`, † `(status, due_at)` |
| `invoice_counters` | `InvoiceCounter` | Técnica | `issuer_id`, `series`, `next_folio` | `unique(issuer_id, series)` |

`csd` solo guarda `provider_ref`, `serial` y `valid_until`: el certificado lo custodia el PAC (F-12). Lo fiscal sigue sujeto a validación contable.

### Impresión

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `printers` | `Printer` | Estado | `uuid`, `branch_id`, `agent_id`, `code`, `name`, `connection`, `paper_width`, `status`, `version` | `unique(uuid)`, `unique(branch_id, code)` |
| `print_stations` | `PrintStation` | Estado | `uuid`, `branch_id`, `code`, `name`, `station_id`, `job_types`, `printer_id`, `status`, `version` | `unique(uuid)`, `unique(branch_id, code)` |
| `print_agents` | `PrintAgent` | Estado | `uuid`, `branch_id`, `service_identity_id`, `name`, `status`, `agent_version`, `last_seen_at`, `version` | `unique(uuid)`, † `unique(service_identity_id)` |
| `print_jobs` | `PrintJob` | Estado | `uuid`, `branch_id`, `type`, `status`, `priority`, `printer_id`, `print_station_id`, `source_type`, `source_id`, `original_job_id`, `reason`, `document`, `attempts`, `next_attempt_at`, `claimed_agent_id`, `lease_until`, `expires_at`, `printed_at`, `last_error`, `idempotency_key`, `created_by`, `version` | `unique(uuid)`, `unique(idempotency_key)`, `(branch_id, printer_id, status, priority, created_at)`, † `(branch_id, status, created_at)`, `(source_type, source_id)`, `(claimed_agent_id)` |
| `print_job_attempts` | `PrintJobAttempt` | Inmutable | `uuid`, `job_id`, `agent_id`, `status`, `claimed_at`, `started_at`, `finished_at`, `error_code` | `unique(uuid)`, `(job_id, started_at)` |

### Transversales

| Colección | Clase | Tipo | Campos | Índices |
|---|---|---|---|---|
| `audit_log` | Sin clase | Inmutable | `branch_id`, † `actor_type`, `actor_id`, † `action`, `entity_type`, `entity_id`, † `reason`, † `changes`, † `request_id`, `conversation_id` | `(branch_id, entity_type, entity_id, created_at)`, `(branch_id, actor_id, created_at)`, † `(branch_id, created_at)` |
| `outbox_events` | Sin clase | Estado | † `branch_id`, † `type`, `source_type`, `source_id`, † `payload`, `status`, † `attempts`, `next_attempt_at` | `(status, next_attempt_at)`, `(branch_id, source_type, source_id)` |
| `idempotency_records` | Sin clase | Técnica | `branch_id`, `key`, `actor_id`, `operation_id`, `request_hash`, `status_code`, `response_body`, `resource_id`, `expires_at` | `unique(branch_id, key)`, `TTL(expires_at)` |
| `daily_sales` | `DailySales` | Proyección | `branch_id`, `business_date`, † `net_sales`, † `refunds`, † `tips`, † `courtesies`, † `invoiced`, † `check_count`, † `guest_count`, † `by_method`, `as_of` | `unique(branch_id, business_date)` |
| `hourly_occupancy` | `HourlyOccupancy` | Proyección | `branch_id`, `business_date`, `hour`, † `sessions_opened`, † `tables_occupied`, † `guest_count`, `as_of` | `unique(branch_id, business_date, hour)` |
| `report_rebuilds` | `ReportRebuild` | Técnica | `uuid`, `branch_id`, `from`, `to`, `status`, † `finished_at` | † `unique(uuid)`, † `partial unique(branch_id) where status in PENDING,RUNNING` |

**Fuera de v1:** `loyalty_ledger` y `calls`, porque no tienen reglas definidas (X-1). No las crees.

## 9. Auditoría, outbox e idempotencia

Toda operación sigue el principio 7 del documento maestro, en este orden:

```text
autenticar → autorizar → validar estado → validar versión → validar dominio
→ transacción: fuente de verdad + auditoría + outbox + idempotencia
→ commit → procesos asíncronos
```

- **Auditoría y outbox van en la misma transacción.** Si la operación se revierte, no queda rastro.
- **La auditoría nunca guarda secretos,** y lo sensible se enmascara al escribir, no al leer.
- **Outbox:** un proceso lee `(status, next_attempt_at)` y publica. Entrega al menos una vez, así que quien consume debe ser idempotente.
- **Idempotencia (CON-1):** se guarda en la misma transacción, 72 horas. Una operación que falla no deja registro y se puede reintentar. Las que devuelven secretos no usan `Idempotency-Key`.

## 10. Proyecciones

Son datos derivados que se pueden reconstruir (principio 2):

| Proyección | Fuente | Cómo se mantiene |
|---|---|---|
| `ingredients.stock_actual` | `inventory_movements` | Un proceso aplica los que tienen `projected_at = null`, con `$inc` |
| `orders.status` | `order_item_units` | En la misma transacción |
| Totales de `checks` | Unidades, asignaciones y pagos | En la misma transacción |
| `daily_sales`, `hourly_occupancy` | Pagos, reembolsos y sesiones | Desde outbox; reconstrucción manual con `report_rebuilds` |

Si el proceso de stock se detiene, las validaciones pierden exactitud: necesita una alerta.

## 11. Retención y borrado

| Caso | Regla |
|---|---|
| Financiero, fiscal, inventario, auditoría | Nunca se borra (principio 3) |
| Reservas, comandas, cuentas | Cambian de estado; no se borran |
| Cliente | Se anonimiza (CU-4); las facturas no cambian |
| `auth_sessions` | TTL a 180 días del cierre (A-9) |
| `personal_access_tokens` | Se borran: el historial queda en `auth_sessions` |
| Códigos, tokens y aprobaciones | TTL por `expires_at` |
| `table_presence_signals` | TTL a 10 minutos |
| `idempotency_records` | TTL a 72 horas |

**El TTL no borra al instante:** el código debe comprobar `expires_at` y no confiar en que el documento ya no exista.

**Pendiente de definir con el área legal:** la retención de datos personales.

## 12. Cómo agregar una colección

1. Confirma sus campos en esta guía y en la decisión del módulo.
2. Crea la migración con sus índices, incluidos los únicos y los parciales.
3. Crea la clase: hereda de `Document`, con `HasVersion` si se edita, y con casts para `ObjectId`, dinero y fechas.
4. Oculta en `$hidden` el `_id`, los hashes y los campos internos.
5. Filtra siempre por sucursal con `forBranch`.
6. Escribe con `Mongo::transaction()`, con auditoría y outbox.
7. Traduce errores: `E11000` al `409` del contrato, reintentos agotados a `503`, versión vieja a `VERSION_CONFLICT`.
8. Prueba por lo menos esto:
   - Aislamiento: un ID de otra sucursal responde `404`.
   - Concurrencia: varios procesos en paralelo, no solo pruebas secuenciales.
   - Serialización: lo que devuelve la API contra `openapi/restia-api.yaml`.
   - Consultas: `explain` para confirmar que usan el índice.

### Orden recomendado

Sigue las dependencias del [plan de implementación](Plan_Implementacion.md):

1. Capa común, `branches`, `users`, `user_branch_memberships`, `devices`, `auth_sessions`, `audit_log`, `outbox_events`, `idempotency_records`.
2. `tables` y sesiones; catálogo de menú e `ingredients`; comandas; impresión.
3. Cuentas, caja, pagos e inventario completo.
4. Reservaciones, clientes y reportes.
5. Fiscal.

## 13. Lo que todavía no está comprobado

- **Tres nodos:** con confirmación por mayoría y red real, cada transacción tarda más. Hay que repetir las pruebas de concurrencia en staging.
- **Versión de producción:** se probó con MongoDB 8.3.4 en Windows. Hay que repetir con la versión que se use en producción.
- **Índices marcados †:** son estimados. Confírmalos con `explain` y carga real, porque cada índice encarece las escrituras.
- **Búsqueda de clientes por nombre:** no tiene índice directo, porque el nombre está en `customers` y la sucursal en su perfil (CU-3). Hay que resolverlo al implementar.
- **Caídas:** cambio de nodo primario y reconexión.
- **TTL:** no se midió cuánto tarda en borrar.

## 14. Referencias

- [Documento maestro actualizado](Restia_Documento_Maestro_v2.md), secciones 4, 16 y 17.
- [Contrato OpenAPI](../openapi/restia-api.yaml).
- [Resultados de la prueba técnica](Resultados_Prueba_Tecnica.md).
- [Revisión de consistencia](Revision_Consistencia.md): CON-1 e índices.
- [Credenciales (D-36)](decisiones/D-36_Ciclo_de_vida_de_credenciales.md), sección 4.
- `Decisiones_*.md`: reglas de cada módulo.
