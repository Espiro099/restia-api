<?php

namespace App\Reservations;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;

/**
 * Opciones de mesas para una reserva (Decisiones_Reservations, sección 3). Orienta, pero no aparta: quien
 * aparta es confirmar, que vuelve a calcular dentro de su transacción. Una opción es válida si:
 *
 * 1. El intervalo cabe en `reservation_hours` (R-3).
 * 2. Son mesas ACTIVE: una sola, o hasta 3 del mismo `combination_group` (R-2).
 * 3. Su capacidad alcanza para el grupo.
 * 4. Ninguna está planeada en otra reserva CONFIRMED o ARRIVED_WAITING que se traslape (R-1).
 * 5. Ninguna está ocupada, si la reserva empieza dentro de los próximos 90 minutos (R-4).
 */
final class Availability
{
    public const MAX_COMBINED = 3;

    public const OCCUPIED_WINDOW_MINUTES = 90;

    /** R-3: el intervalo, en hora local, cabe en alguno de los horarios de su día. Sin horario, nada cabe. */
    public static function withinHours(Branch $branch, Carbon $start, Carbon $end): bool
    {
        $tz = $branch->timezone ?: 'America/Mexico_City';
        $localStart = $start->copy()->setTimezone($tz);
        $localEnd = $end->copy()->setTimezone($tz);
        foreach ($branch->reservation_hours ?? [] as $hours) {
            $hours = (array) $hours;
            if ((int) $hours['dayOfWeek'] !== $localStart->isoWeekday()) {
                continue;
            }
            $opens = $localStart->copy()->setTimeFromTimeString($hours['opensAt']);
            $closes = $localStart->copy()->setTimeFromTimeString($hours['closesAt']);
            // Un horario que cierra a la misma hora o antes de abrir termina al día siguiente.
            if ($closes->lessThanOrEqualTo($opens)) {
                $closes->addDay();
            }
            if ($localStart->greaterThanOrEqualTo($opens) && $localEnd->lessThanOrEqualTo($closes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opciones válidas, de la que deja menos asientos libres a la que deja más (R-2).
     *
     * @param  list<string>  $excludeTables  ObjectId de mesas que no se pueden usar, por ejemplo las de un walk-in
     * @return list<array{tables: Collection<int, RestaurantTable>, capacity: int}>
     */
    public static function options(Branch $branch, Carbon $start, Carbon $end, int $partySize, ?string $exceptReservation = null, array $excludeTables = []): array
    {
        if (! self::withinHours($branch, $start, $end)) {
            return [];
        }
        $branchId = new ObjectId($branch->getKey());
        $unavailable = [...$excludeTables, ...self::blocked($branchId, $start, $end, $exceptReservation)];
        $tables = RestaurantTable::query()->where('branch_id', $branchId)->where('status', RestaurantTable::ACTIVE)->get()
            ->reject(fn ($t) => in_array((string) $t->getKey(), $unavailable, true))
            ->sortBy('number')->values();

        $candidates = $tables->map(fn ($t) => collect([$t]))->all();
        foreach ($tables->whereNotNull('combination_group')->groupBy('combination_group') as $group) {
            $group = $group->values();
            foreach (self::combinations($group->all(), 2) as $combo) {
                $candidates[] = collect($combo);
            }
            foreach (self::combinations($group->all(), 3) as $combo) {
                $candidates[] = collect($combo);
            }
        }

        $valid = [];
        foreach ($candidates as $combo) {
            $capacity = (int) $combo->sum('capacity');
            if ($capacity >= $partySize) {
                $valid[] = ['tables' => $combo, 'capacity' => $capacity];
            }
        }
        usort($valid, fn ($a, $b) => [$a['capacity'], $a['tables']->count(), $a['tables']->min('number')]
            <=> [$b['capacity'], $b['tables']->count(), $b['tables']->min('number')]);

        return $valid;
    }

    /** ¿Estas mesas concretas sirven para esta reserva? Mismas reglas que options(). */
    public static function fits(Branch $branch, Carbon $start, Carbon $end, int $partySize, array $tableIds, ?string $exceptReservation = null): bool
    {
        $wanted = collect($tableIds)->map(fn ($id) => (string) $id)->sort()->values()->all();
        foreach (self::options($branch, $start, $end, $partySize, $exceptReservation) as $option) {
            if ($option['tables']->map(fn ($t) => (string) $t->getKey())->sort()->values()->all() === $wanted) {
                return true;
            }
        }

        return false;
    }

    /** Horarios cercanos con lugar, para cuando el pedido no cabe: hasta 5, del más cercano al más lejano. */
    public static function alternatives(Branch $branch, Carbon $start, Carbon $end, int $partySize): array
    {
        $found = [];
        foreach ([15, -15, 30, -30, 45, -45, 60, -60, 90, -90, 120, -120] as $shift) {
            $s = $start->copy()->addMinutes($shift);
            if ($s->isPast()) {
                continue;
            }
            if (self::options($branch, $s, $end->copy()->addMinutes($shift), $partySize)) {
                $found[] = $s->copy()->utc()->format('Y-m-d\TH:i:s\Z');
            }
            if (count($found) === 5) {
                break;
            }
        }

        return $found;
    }

    /** ObjectId de mesas no disponibles en el intervalo: planeadas en otra reserva o, si empieza pronto, ocupadas. @return list<string> */
    private static function blocked(ObjectId $branchId, Carbon $start, Carbon $end, ?string $exceptReservation): array
    {
        $planned = Reservation::query()->where('branch_id', $branchId)->whereIn('status', Reservation::HOLDING)
            ->where('start_at', '<', $end)->where('end_at', '>', $start)
            ->when($exceptReservation, fn ($q) => $q->where('_id', '!=', new ObjectId($exceptReservation)))
            ->get(['planned_table_ids'])->flatMap(fn ($r) => array_map('strval', $r->planned_table_ids ?? []))->all();
        $occupied = [];
        if ($start->lessThanOrEqualTo(now()->addMinutes(self::OCCUPIED_WINDOW_MINUTES))) {
            $occupied = TableAssignment::query()->where('branch_id', $branchId)->where('active', true)->get(['table_id'])
                ->map(fn ($a) => (string) $a->table_id)->all();
        }

        return array_values(array_unique([...$planned, ...$occupied]));
    }

    /** @return list<list<mixed>> */
    private static function combinations(array $items, int $size): array
    {
        if ($size === 0) {
            return [[]];
        }
        $out = [];
        foreach ($items as $i => $item) {
            foreach (self::combinations(array_slice($items, $i + 1), $size - 1) as $rest) {
                $out[] = [$item, ...$rest];
            }
        }

        return $out;
    }
}
