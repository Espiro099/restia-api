<?php

namespace App\Printing;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItemUnit;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\Station;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

/**
 * Trabajos automáticos desde outbox (PR-3): una comanda por estación con `order.created`, un aviso con
 * `order_unit.voided`, y la cancelación de la comanda pendiente si todo lo de esa estación se canceló
 * antes de imprimirse (PR-10).
 *
 * Outbox entrega al menos una vez y lo leen varios consumidores, así que cada evento se marca como
 * atendido por impresión en `handled_by`, y la clave derivada con `unique(idempotency_key)` evita
 * duplicados si el mismo evento se procesa dos veces.
 */
final class Dispatcher
{
    public const CONSUMER = 'printing';

    private const EVENTS = ['order.created', 'order_unit.voided', 'order_unit.cancelled'];

    /** @return int eventos atendidos */
    public static function run(int $limit = 100): int
    {
        $outbox = DB::connection('mongodb')->table('outbox_events');
        $events = $outbox->whereIn('type', self::EVENTS)->where('handled_by', '!=', self::CONSUMER)
            ->orderBy('created_at')->orderBy('_id')->limit($limit)->get();
        foreach ($events as $event) {
            $event = (array) $event;
            Mongo::transaction(function () use ($event) {
                match ($event['type']) {
                    'order.created' => self::kitchenTickets($event),
                    'order_unit.voided' => self::voidNotice($event),
                    'order_unit.cancelled' => self::cancelTickets($event),
                };
                DB::connection('mongodb')->table('outbox_events')->where('id', $event['id'])
                    ->update(['$addToSet' => ['handled_by' => self::CONSUMER]]);
            });
        }

        return count($events);
    }

    private static function kitchenTickets(array $event): void
    {
        $order = Order::query()->find((string) $event['source_id']);
        $branch = $order ? Branch::query()->find((string) $order->branch_id) : null;
        if (! $branch) {
            return;
        }
        // Lo cancelado antes de procesar el evento ya no se imprime.
        $units = OrderItemUnit::query()->where('order_id', new ObjectId($order->getKey()))
            ->whereNotIn('status', [OrderItemUnit::CANCELLED, OrderItemUnit::VOIDED])->orderBy('order_item_id')->orderBy('unit_number')->get();
        foreach ($units->groupBy(fn ($u) => (string) $u->station_id) as $stationId => $rows) {
            $station = Station::query()->find($stationId);
            self::routed($branch, 'KITCHEN_TICKET', $station, $order, function (PrintStation $route, string $key) use ($branch, $order, $station, $rows) {
                self::create($branch, 'KITCHEN_TICKET', $route, $station, 'order', $order, $key, Documents::kitchenTicket($branch, $order, $station, $rows), [
                    'expires_at' => now()->addMinutes((int) ($branch->print_ticket_expiry_minutes ?: config('restia.printing.ticket_expiry_minutes'))),
                ]);
            });
        }
    }

    private static function voidNotice(array $event): void
    {
        $unit = OrderItemUnit::query()->find((string) $event['source_id']);
        $order = $unit ? Order::query()->find((string) $unit->order_id) : null;
        $branch = $order ? Branch::query()->find((string) $order->branch_id) : null;
        if (! $branch) {
            return;
        }
        $station = Station::query()->find((string) $unit->station_id);
        self::routed($branch, 'VOID_NOTICE', $station, $unit, function (PrintStation $route, string $key) use ($branch, $order, $station, $unit) {
            self::create($branch, 'VOID_NOTICE', $route, $station, 'order_unit', $unit, $key, Documents::voidNotice($branch, $order, $station, $unit));
        });
    }

    /** PR-10: si en esa estación ya no queda nada por preparar de la comanda, su comanda pendiente no sale. */
    private static function cancelTickets(array $event): void
    {
        $unit = OrderItemUnit::query()->find((string) $event['source_id']);
        if (! $unit) {
            return;
        }
        $live = OrderItemUnit::query()->where('order_id', new ObjectId((string) $unit->order_id))->where('station_id', new ObjectId((string) $unit->station_id))
            ->whereNotIn('status', [OrderItemUnit::CANCELLED, OrderItemUnit::VOIDED])->exists();
        if ($live) {
            return;
        }
        $jobs = PrintJob::query()->where('type', 'KITCHEN_TICKET')->where('source_type', 'order')->where('source_id', new ObjectId((string) $unit->order_id))
            ->where('station_id', new ObjectId((string) $unit->station_id))->where('status', PrintJob::PENDING)->get();
        foreach ($jobs as $job) {
            if ($job->updateIfVersion((int) $job->version, ['status' => PrintJob::CANCELLED, 'reason' => 'Toda la comanda de la estación se canceló antes de imprimirse.'])) {
                Audit::record('print_job.cancelled', 'print_job', $job->getKey(), (string) $job->branch_id, changes: ['before' => ['status' => PrintJob::PENDING], 'after' => ['status' => PrintJob::CANCELLED]]);
            }
        }
    }

    /**
     * Un trabajo por cada ruta activa del tipo para esa estación: así una comanda puede salir en cocina
     * y en el pase. Sin ruta no hay impresora a la cual asignar el trabajo, así que en lugar de un
     * trabajo queda la alerta `print_job.route_missing`; la comanda nunca se bloquea (PR-11).
     */
    private static function routed(Branch $branch, string $type, ?Station $station, mixed $source, \Closure $create): void
    {
        $routes = $station ? PrintStation::query()->forBranch($branch)->where('station_id', new ObjectId($station->getKey()))
            ->where('job_types', $type)->where('status', PrintStation::ACTIVE)->orderBy('code')->get() : collect();
        if ($routes->isEmpty()) {
            Outbox::publish('print_job.route_missing', 'print_station', $station?->getKey() ?? $source->getKey(), $branch->getKey(), [
                'type' => $type, 'stationId' => $station?->uuid, 'sourceId' => $source->uuid,
            ]);

            return;
        }
        foreach ($routes as $route) {
            $create($route, "$type:{$source->uuid}:{$route->uuid}");
        }
    }

    private static function create(Branch $branch, string $type, PrintStation $route, Station $station, string $sourceType, mixed $source, string $key, array $document, array $extra = []): void
    {
        if (PrintJob::query()->where('idempotency_key', $key)->exists()) {
            return;
        }
        PrintJobs::make(new ObjectId($branch->getKey()), [
            'type' => $type, 'printer_id' => new ObjectId((string) $route->printer_id), 'print_station_id' => new ObjectId($route->getKey()),
            'station_id' => new ObjectId($station->getKey()), 'source_type' => $sourceType, 'source_id' => new ObjectId($source->getKey()),
            'source_uuid' => $source->uuid, 'document' => $document, 'idempotency_key' => $key,
        ] + $extra);
    }
}
