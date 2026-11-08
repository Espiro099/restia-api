<?php

namespace App\Printing;

use App\Catalog\Catalog;
use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Printer;
use App\Models\PrintStation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/**
 * Rutas de impresión (PR-2): qué documentos salen por qué impresora. La comanda y el aviso de anulación
 * se enrutan por estación de producción; los demás tipos, solo por tipo. Un cambio afecta solo a
 * trabajos nuevos: los existentes se mueven con un reintento.
 */
final class PrintStations
{
    /**
     * @param  Collection<int, PrintStation>  $routes
     * @return list<array<string, mixed>>
     */
    public static function present(Branch $branch, Collection $routes): array
    {
        $printers = Printer::query()->whereIn('_id', $routes->map(fn ($r) => new ObjectId((string) $r->printer_id))->all())->get()
            ->mapWithKeys(fn ($p) => [(string) $p->getKey() => $p->uuid]);
        $stationIds = $routes->pluck('station_id')->filter()->map(fn ($id) => new ObjectId((string) $id))->values()->all();
        $stations = Station::query()->whereIn('_id', $stationIds)->get()->mapWithKeys(fn ($s) => [(string) $s->getKey() => $s->uuid]);

        return $routes->map(fn (PrintStation $r) => [
            'id' => $r->uuid,
            'branchId' => $branch->uuid,
            'code' => $r->code,
            'name' => $r->name,
            'stationId' => $r->station_id ? $stations->get((string) $r->station_id) : null,
            'jobTypes' => array_values($r->job_types ?? []),
            'printerId' => $printers->get((string) $r->printer_id),
            'status' => $r->status,
            'version' => (int) $r->version,
        ])->values()->all();
    }

    /** @param array<string, mixed> $in */
    public static function create(Branch $branch, User $actor, array $in): PrintStation
    {
        $station = isset($in['stationId']) ? self::station($branch, $in['stationId']) : null;
        self::assertStationFor($in['jobTypes'], $station !== null);
        $printer = Printers::find($branch, $in['printerId'], 'printerId');

        return Catalog::create($branch, $actor, 'print_station', 'CATALOG_CODE_TAKEN', function (ObjectId $branchId) use ($in, $station, $printer) {
            self::assertPrinterActive($printer);

            return PrintStation::query()->create([
                'branch_id' => $branchId, 'code' => $in['code'], 'name' => $in['name'],
                'station_id' => $station ? new ObjectId($station->getKey()) : null, 'job_types' => array_values($in['jobTypes']),
                'printer_id' => new ObjectId($printer->getKey()), 'status' => PrintStation::ACTIVE,
            ]);
        }, menu: false);
    }

    /** @param array<string, mixed> $in */
    public static function update(Branch $branch, User $actor, PrintStation $r, int $version, array $in): PrintStation
    {
        $printer = array_key_exists('printerId', $in) ? Printers::find($branch, $in['printerId'], 'printerId') : null;

        return Catalog::change($branch, $actor, $r, $version, 'print_station.updated', 'print_station', function (PrintStation $fresh) use ($in, $printer) {
            $changes = [];
            if (array_key_exists('name', $in)) {
                $changes['name'] = $in['name'];
            }
            if (array_key_exists('jobTypes', $in)) {
                self::assertStationFor($in['jobTypes'], $fresh->station_id !== null);
                $changes['job_types'] = array_values($in['jobTypes']);
            }
            if ($printer) {
                self::assertPrinterActive($printer);
                $changes['printer_id'] = new ObjectId($printer->getKey());
            }

            return $changes;
        }, menu: false);
    }

    public static function setStatus(Branch $branch, User $actor, PrintStation $r, int $version, string $status, string $reason): PrintStation
    {
        if ($r->status === $status) {
            return $r;
        }

        return Catalog::change($branch, $actor, $r, $version, 'print_station.status_changed', 'print_station', fn () => ['status' => $status], reason: $reason, menu: false);
    }

    /** La comanda y el aviso de anulación se imprimen donde se prepara: sin estación no hay a dónde mandarlos. */
    private static function assertStationFor(array $jobTypes, bool $hasStation): void
    {
        if (! $hasStation && array_intersect($jobTypes, PrintStation::STATION_TYPES)) {
            throw ValidationException::withMessages(['stationId' => 'KITCHEN_TICKET y VOID_NOTICE necesitan una estación de producción.']);
        }
    }

    private static function station(Branch $branch, string $uuid): Station
    {
        return Station::query()->forBranch($branch)->where('uuid', strtolower($uuid))->first()
            ?? throw ValidationException::withMessages(['stationId' => 'La estación no existe en esta sucursal.']);
    }

    /** Se vuelve a leer dentro de la transacción: una impresora inactiva no recibe rutas nuevas. */
    private static function assertPrinterActive(Printer $printer): void
    {
        if (! Printer::query()->where('_id', $printer->getKey())->where('status', Printer::ACTIVE)->exists()) {
            throw new DomainConflict('PRINTER_INACTIVE');
        }
    }
}
