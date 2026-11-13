<?php

namespace App\Audit;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Document;
use App\Models\OrderItemUnit;
use App\Models\RestaurantTable;
use App\Models\ServiceIdentity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Ramsey\Uuid\Uuid;

/**
 * Consulta de audit_log (AU-1): solo lectura, por rango de hasta 31 días, del más reciente al más antiguo.
 * La bitácora guarda ObjectIds; la API solo expone UUID públicos, así que cada referencia se traduce aquí.
 */
final class AuditLog
{
    public const MAX_DAYS = 31;

    /** Tipos cuyo modelo no se deduce del nombre. */
    private const MODELS = [
        'table' => RestaurantTable::class,
        'order_unit' => OrderItemUnit::class,
        'cash_register' => CashRegister::class,
    ];

    /** Espacio de nombres para el id de las entradas escritas antes de que tuvieran uuid. */
    private const LEGACY_NAMESPACE = '6f1b7c2e-2a4d-4f3e-9a51-3c8f0d4b7e21';

    /**
     * @param  list<ObjectId>  $branches  sucursales que se pueden ver
     * @param  array<string, string>  $filter
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public static function search(array $branches, bool $global, array $filter, int $page, int $perPage): array
    {
        $from = Carbon::parse($filter['from']);
        $to = Carbon::parse($filter['to']);
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['to' => 'Debe ser posterior a from.']);
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'El rango no puede pasar de '.self::MAX_DAYS.' días.']);
        }

        $query = DB::connection('mongodb')->table('audit_log')
            ->where('created_at', '>=', new UTCDateTime($from))
            ->where('created_at', '<=', new UTCDateTime($to));
        // La consulta global agrega lo que no es de ninguna sucursal, como las identidades de servicio.
        $global
            ? $query->where(fn ($q) => $q->whereIn('branch_id', $branches)->orWhereNull('branch_id'))
            : $query->whereIn('branch_id', $branches);
        foreach (['entityType' => 'entity_type', 'action' => 'action'] as $param => $field) {
            if (isset($filter[$param])) {
                $query->where($field, $filter[$param]);
            }
        }
        if (isset($filter['entityId'])) {
            $ids = self::entityObjectIds($filter['entityId'], $filter['entityType'] ?? null);
            $query->whereIn('entity_id', $ids);
        }
        if (isset($filter['actorId'])) {
            $query->whereIn('actor_id', self::actorObjectIds($filter['actorId']));
        }

        $total = (clone $query)->count();
        $rows = collect($query->orderBy('created_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get())
            ->map(fn ($r) => (array) $r);

        return [self::present($rows), $total];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private static function present(Collection $rows): array
    {
        $branches = self::uuids(Branch::class, $rows->pluck('branch_id'));
        $actors = self::uuids(User::class, $rows->where('actor_type', 'USER')->pluck('actor_id'))
            + self::uuids(ServiceIdentity::class, $rows->where('actor_type', 'SERVICE')->pluck('actor_id'));
        $entities = [];
        foreach ($rows->groupBy('entity_type') as $type => $group) {
            $model = self::model((string) $type);
            $entities[$type] = $model ? self::uuids($model, $group->pluck('entity_id')) : [];
        }

        return $rows->map(fn (array $r) => [
            // El constructor de consultas entrega el _id como `id`.
            'id' => $r['uuid'] ?? Uuid::uuid5(self::LEGACY_NAMESPACE, (string) ($r['_id'] ?? $r['id']))->toString(),
            'branchId' => $branches[(string) $r['branch_id']] ?? null,
            // Entradas viejas registraron DEVICE, que el contrato no tiene.
            'actorType' => in_array($r['actor_type'] ?? null, ['USER', 'SERVICE'], true) ? $r['actor_type'] : 'SYSTEM',
            'actorId' => $actors[(string) ($r['actor_id'] ?? '')] ?? null,
            'action' => $r['action'],
            'entityType' => $r['entity_type'],
            'entityId' => $entities[$r['entity_type']][(string) ($r['entity_id'] ?? '')] ?? null,
            'reason' => $r['reason'] ?? null,
            'changes' => isset($r['changes']) ? self::clean(self::plain($r['changes'])) : null,
            'requestId' => $r['request_id'] ?? null,
            'conversationId' => $r['conversation_id'] ?? null,
            'createdAt' => Carbon::instance($r['created_at']->toDateTime())->utc()->format('Y-m-d\TH:i:s\Z'),
        ])->values()->all();
    }

    /** @return class-string<Document>|null */
    private static function model(string $type): ?string
    {
        $class = self::MODELS[$type] ?? 'App\\Models\\'.Str::studly($type);

        return class_exists($class) && is_subclass_of($class, Document::class) ? $class : null;
    }

    /**
     * @param  class-string<Document>  $model
     * @return array<string, string> ObjectId como texto → uuid
     */
    private static function uuids(string $model, Collection $ids): array
    {
        $ids = $ids->filter()->map(fn ($id) => $id instanceof ObjectId ? $id : new ObjectId((string) $id))->unique(fn ($id) => (string) $id)->values()->all();
        if (! $ids) {
            return [];
        }

        return $model::query()->whereIn('_id', $ids)->get(['_id', 'uuid'])
            ->filter(fn ($m) => $m->uuid)
            ->mapWithKeys(fn ($m) => [(string) $m->getKey() => $m->uuid])->all();
    }

    /** @return list<ObjectId> */
    private static function entityObjectIds(string $uuid, ?string $type): array
    {
        $models = $type ? array_filter([self::model($type)]) : self::knownModels();

        return collect($models)->flatMap(fn ($model) => $model::query()->where('uuid', strtolower($uuid))->get(['_id'])->map(fn ($m) => new ObjectId($m->getKey())))
            ->values()->all();
    }

    /** @return list<ObjectId> */
    private static function actorObjectIds(string $uuid): array
    {
        return collect([User::class, ServiceIdentity::class])
            ->flatMap(fn ($model) => $model::query()->where('uuid', strtolower($uuid))->get(['_id'])->map(fn ($m) => new ObjectId($m->getKey())))
            ->values()->all();
    }

    /** @return list<class-string<Document>> */
    private static function knownModels(): array
    {
        $types = DB::connection('mongodb')->table('audit_log')->distinct('entity_type')->get()->all();

        return array_values(array_unique(array_filter(array_map(fn ($t) => self::model((string) $t), $types))));
    }

    /** BSON a tipos simples. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof UTCDateTime => Carbon::instance($value->toDateTime())->utc()->format('Y-m-d\TH:i:s\Z'),
            $value instanceof ObjectId => null,
            is_object($value) => self::plain((array) $value),
            is_array($value) => array_map(self::plain(...), $value),
            default => $value,
        };
    }

    /**
     * Sin identificadores internos: la API nunca expone ObjectIds. Algunas entradas copian el documento
     * completo (`_id`, `branch_id`…) como texto; esas llaves se quitan.
     */
    private static function clean(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $idKey = is_string($k) && ($k === '_id' || str_ends_with($k, '_id') || str_ends_with($k, '_ids'));
            // Cualquier ObjectId en texto, o lista de ellos, se quita sin importar la llave.
            $internal = self::isObjectIdText($v) || (is_array($v) && $v !== [] && array_is_list($v) && array_filter($v, fn ($x) => ! self::isObjectIdText($x)) === []);
            if ($internal || ($idKey && $v === null)) {
                continue;
            }
            // Fechas que una versión anterior guardó como milisegundos en texto.
            if (is_string($k) && str_ends_with($k, '_at') && is_string($v) && preg_match('/^\d{13}$/', $v)) {
                $v = Carbon::createFromTimestampMs((int) $v)->utc()->format('Y-m-d\TH:i:s\Z');
            }
            $out[$k] = self::clean($v);
        }

        return array_is_list($value) ? array_values($out) : $out;
    }

    private static function isObjectIdText(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{24}$/', $v) === 1;
    }
}
