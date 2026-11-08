<?php

namespace App\Catalog;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Document;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Closure;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Throwable;

/**
 * Altas y cambios del catálogo (Menu) y de las mesas. Cada escritura va en una transacción con su
 * auditoría y su evento; las del menú suben además `branches.menu_version`, el ETag de GET /menu (M-9).
 */
final class Catalog
{
    /**
     * Crea un documento. El índice único (branch_id, code o number) resuelve dos altas simultáneas.
     *
     * @param  Closure(ObjectId): Document  $make
     */
    public static function create(Branch $branch, User $actor, string $entity, string $taken, Closure $make, bool $menu = true): Document
    {
        if (! $branch->isActive()) {
            throw new DomainConflict('BRANCH_INACTIVE');
        }
        $branchId = new ObjectId($branch->getKey());

        return self::unique($taken, fn () => Mongo::transaction(function () use ($branchId, $actor, $entity, $make, $menu) {
            $doc = $make($branchId);
            Audit::record("$entity.created", $entity, $doc->getKey(), $branchId, new ObjectId($actor->getKey()), changes: ['after' => self::plain($doc->getAttributes())]);
            self::publish("$entity.created", $entity, $doc, $branchId, $menu);

            return $doc;
        }));
    }

    /**
     * Cambio condicionado a `version` (If-Match). $work recibe el documento recién leído dentro de la
     * transacción y devuelve los campos que cambian; puede lanzar los 409 de la operación. Si no cambia
     * nada, responde el documento tal cual: repetir un cambio de estado no tiene efecto.
     *
     * @param  Closure(Document): array<string, mixed>  $work
     * @param  (Closure(Document, ObjectId): array{before: mixed, after: mixed})|null  $after  escrituras de otras
     *                                                                                         colecciones, en la misma transacción; devuelve lo que cambió para la auditoría
     */
    public static function change(Branch $branch, User $actor, Document $doc, ?int $version, string $event, string $entity, Closure $work, ?string $reason = null, bool $menu = true, ?string $taken = null, ?Closure $after = null): Document
    {
        $branchId = new ObjectId($branch->getKey());

        $run = fn () => Mongo::transaction(function () use ($branchId, $actor, $doc, $version, $event, $entity, $work, $reason, $menu, $after) {
            $fresh = $doc::query()->find($doc->getKey());
            if ($version !== null && (int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            $changes = $work($fresh);
            $before = array_intersect_key($fresh->getAttributes(), $changes);
            $changed = array_filter($changes, fn ($v, $k) => self::plain($v) != self::plain($before[$k] ?? null), ARRAY_FILTER_USE_BOTH);
            if (! $changed && $after === null) {
                return;
            }
            $expected = (int) $fresh->version;
            if (! $fresh->updateIfVersion($expected, $changed)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            $audit = ['before' => self::plain(array_intersect_key($before, $changed)), 'after' => self::plain($changed)];
            if ($after) {
                $extra = $after($fresh, $branchId);
                $audit = ['before' => $audit['before'] + self::plain($extra['before']), 'after' => $audit['after'] + self::plain($extra['after'])];
            }
            Audit::record($event, $entity, $fresh->getKey(), $branchId, new ObjectId($actor->getKey()), reason: $reason, changes: $audit);
            self::publish($event, $entity, $fresh, $branchId, $menu);
        });

        $taken ? self::unique($taken, $run) : $run();

        return $doc->refresh();
    }

    /** El evento lleva el id público con la clave de la entidad, por ejemplo `productId`. */
    private static function publish(string $event, string $entity, Document $doc, ObjectId $branchId, bool $menu): void
    {
        Outbox::publish($event, $entity, $doc->getKey(), $branchId, [Str::camel($entity).'Id' => $doc->uuid]);
        if ($menu) {
            Branch::query()->where('_id', $branchId)->increment('menu_version');
        }
    }

    private static function unique(string $code, Closure $run): mixed
    {
        try {
            return $run();
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict($code) : $e;
        }
    }

    /** Valores comparables y legibles en la auditoría: ObjectId y Decimal128 como texto. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::plain(...), $value),
            $value instanceof \Stringable, $value instanceof ObjectId => (string) $value,
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            default => $value,
        };
    }
}
