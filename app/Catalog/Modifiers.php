<?php

namespace App\Catalog;

use App\Inventory\Ingredients;
use App\Models\Branch;
use App\Models\Modifier;
use App\Models\User;
use App\Support\Floor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/**
 * Grupos de modificadores (M-3). Las opciones viven dentro del grupo con su propio uuid; nunca se
 * borran, para que una comanda vieja siga apuntando a la opción que se pidió: solo se desactivan.
 */
final class Modifiers
{
    /** @return array<string, mixed> */
    public static function present(Branch $branch, Modifier $m): array
    {
        return [
            'id' => $m->uuid,
            'branchId' => $branch->uuid,
            'code' => $m->code,
            'name' => $m->name,
            'minSelections' => (int) $m->min_selections,
            'maxSelections' => (int) $m->max_selections,
            'options' => array_map(fn ($o) => [
                'id' => $o['uuid'],
                'name' => $o['name'],
                'priceDelta' => Floor::money($o['price_delta'] ?? '0'),
                'active' => ($o['status'] ?? 'ACTIVE') === 'ACTIVE',
                'ingredientAdjustments' => array_map(fn ($a) => ['ingredientId' => $a['ingredient_id'], 'quantity' => $a['quantity']], $o['ingredient_adjustments'] ?? []),
            ], array_values($m->options ?? [])),
            'status' => $m->status,
            'version' => (int) $m->version,
        ];
    }

    /** @param array<string, mixed> $in */
    public static function create(Branch $branch, User $actor, array $in): Modifier
    {
        self::assertIngredients($branch, $in['options']);
        $options = self::options($in['options'], []);
        self::selections((int) $in['minSelections'], (int) $in['maxSelections'], $options);

        return Catalog::create($branch, $actor, 'modifier', 'CATALOG_CODE_TAKEN', fn (ObjectId $branchId) => Modifier::query()->create([
            'branch_id' => $branchId, 'code' => $in['code'], 'name' => $in['name'], 'status' => Modifier::ACTIVE,
            'min_selections' => (int) $in['minSelections'], 'max_selections' => (int) $in['maxSelections'], 'options' => $options,
        ]));
    }

    /** @param array<string, mixed> $in */
    public static function update(Branch $branch, User $actor, Modifier $m, int $version, array $in): Modifier
    {
        if (array_key_exists('options', $in)) {
            self::assertIngredients($branch, $in['options']);
        }

        return Catalog::change($branch, $actor, $m, $version, 'modifier.updated', 'modifier', function (Modifier $fresh) use ($in) {
            $changes = [];
            if (array_key_exists('name', $in)) {
                $changes['name'] = $in['name'];
            }
            $options = array_key_exists('options', $in) ? self::options($in['options'], $fresh->options ?? []) : array_values($fresh->options ?? []);
            if (array_key_exists('options', $in)) {
                $changes['options'] = $options;
            }
            $min = (int) ($in['minSelections'] ?? $fresh->min_selections);
            $max = (int) ($in['maxSelections'] ?? $fresh->max_selections);
            self::selections($min, $max, $options);
            if (array_key_exists('minSelections', $in)) {
                $changes['min_selections'] = $min;
            }
            if (array_key_exists('maxSelections', $in)) {
                $changes['max_selections'] = $max;
            }

            return $changes;
        });
    }

    public static function setStatus(Branch $branch, User $actor, Modifier $m, int $version, string $status, string $reason): Modifier
    {
        if ($m->status === $status) {
            return $m;
        }

        return Catalog::change($branch, $actor, $m, $version, 'modifier.status_changed', 'modifier', fn () => ['status' => $status], reason: $reason);
    }

    /**
     * Lista completa de opciones. Una opción existente se conserva con su `id`; omitirla es un error,
     * porque las opciones solo se desactivan.
     *
     * @param  list<array<string, mixed>>  $input
     * @param  array<int, array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    /** Los ajustes de ingredientes de cada opción deben apuntar a ingredientes de la sucursal. */
    private static function assertIngredients(Branch $branch, array $options): void
    {
        $byField = [];
        foreach (array_values($options) as $i => $o) {
            foreach (array_values($o['ingredientAdjustments'] ?? []) as $j => $a) {
                $byField["options.$i.ingredientAdjustments.$j.ingredientId"] = $a['ingredientId'];
            }
        }
        if ($byField) {
            Ingredients::resolve($branch, $byField);
        }
    }

    private static function options(array $input, array $existing): array
    {
        $known = collect($existing)->keyBy(fn ($o) => $o['uuid']);
        $errors = [];
        $seen = [];
        $names = [];
        $out = [];
        foreach (array_values($input) as $i => $o) {
            $id = isset($o['id']) ? strtolower($o['id']) : null;
            if ($id !== null && ! $known->has($id)) {
                $errors["options.$i.id"] = 'La opción no pertenece a este modificador.';
            } elseif ($id !== null && isset($seen[$id])) {
                $errors["options.$i.id"] = 'La opción está repetida.';
            }
            if ($id !== null) {
                $seen[$id] = true;
            }
            $name = mb_strtolower(trim($o['name']));
            if (isset($names[$name])) {
                $errors["options.$i.name"] = 'Ya hay otra opción con ese nombre.';
            }
            $names[$name] = true;
            $out[] = [
                'uuid' => $id ?? (string) Str::uuid(),
                'name' => $o['name'],
                'price_delta' => Floor::decimal($o['priceDelta']['amount']),
                'status' => ($o['active'] ?? true) ? 'ACTIVE' : 'INACTIVE',
                'ingredient_adjustments' => array_map(fn ($a) => ['ingredient_id' => strtolower($a['ingredientId']), 'quantity' => $a['quantity']], $o['ingredientAdjustments'] ?? []),
            ];
        }
        if ($known->keys()->diff(array_keys($seen))->isNotEmpty()) {
            $errors['options'] = 'Incluye todas las opciones existentes; las que ya no se ofrecen se desactivan.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $options */
    private static function selections(int $min, int $max, array $options): void
    {
        $active = count(array_filter($options, fn ($o) => ($o['status'] ?? 'ACTIVE') === 'ACTIVE'));
        if ($min > $max) {
            throw ValidationException::withMessages(['minSelections' => 'No puede ser mayor que maxSelections.']);
        }
        if ($min > $active) {
            throw ValidationException::withMessages(['minSelections' => 'Hay menos opciones activas que las selecciones mínimas.']);
        }
    }
}
