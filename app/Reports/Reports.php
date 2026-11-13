<?php

namespace App\Reports;

use App\Models\Branch;
use App\Models\Check;
use App\Models\CheckAdjustment;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReportRebuild;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\Support\Floor;
use App\Support\Mongo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use Throwable;

/**
 * Reportes de la sucursal (RP-1): ventas diarias y ocupación por hora. Son proyecciones: cada día se
 * recalcula completo desde los datos de origen, nunca se acumula, así que siempre se pueden reconstruir
 * (principio 2). Se actualizan desde outbox (RP-3) y `asOf` dice cuándo se calcularon.
 */
final class Reports
{
    public const CONSUMER = 'reports';

    public const METHODS = ['CASH', 'CARD_CREDIT', 'CARD_DEBIT', 'TRANSFER'];

    /** Eventos que pueden cambiar un reporte. */
    private const PREFIXES = ['payment.', 'refund.', 'check.', 'table_session.'];

    /** Lee proyecciones ya calculadas; un día sin actividad no tiene fila. */
    public static function dailySales(Branch $branch, string $from, string $to): array
    {
        return DB::connection('mongodb')->table('daily_sales')->where('branch_id', new ObjectId($branch->getKey()))
            ->where('business_date', '>=', $from)->where('business_date', '<=', $to)->orderBy('business_date')->get()
            ->map(fn ($r) => ((array) $r)['report'])->map(fn ($r) => (array) json_decode(json_encode($r), true))->values()->all();
    }

    public static function hourlyOccupancy(Branch $branch, string $from, string $to): array
    {
        return DB::connection('mongodb')->table('hourly_occupancy')->where('branch_id', new ObjectId($branch->getKey()))
            ->where('business_date', '>=', $from)->where('business_date', '<=', $to)->orderBy('business_date')->orderBy('position')->get()
            ->map(fn ($r) => (array) json_decode(json_encode(((array) $r)['report']), true))->values()->all();
    }

    // --- Actualización ---

    /** Procesa los eventos pendientes para este consumidor y recalcula los días que tocan. */
    public static function consume(int $limit = 200): int
    {
        $outbox = DB::connection('mongodb')->table('outbox_events');
        $prefixes = implode('|', array_map(fn ($p) => preg_quote($p, '/'), self::PREFIXES));
        $events = $outbox->where('handled_by', '!=', self::CONSUMER)->where('type', 'regex', new Regex('^('.$prefixes.')'))
            ->orderBy('created_at')->orderBy('_id')->limit($limit)->get()->map(fn ($e) => (array) $e);
        $days = [];
        foreach ($events as $event) {
            foreach (self::daysOf($event) as [$branchId, $date]) {
                $days["$branchId|$date"] = [$branchId, $date];
            }
        }
        foreach ($days as [$branchId, $date]) {
            if ($branch = Branch::query()->find($branchId)) {
                self::refresh($branch, $date);
            }
        }
        if ($events->isNotEmpty()) {
            $outbox->whereIn('_id', $events->map(fn ($e) => new ObjectId((string) ($e['_id'] ?? $e['id'])))->all())
                ->update(['$addToSet' => ['handled_by' => self::CONSUMER]]);
        }

        return $events->count();
    }

    /** Toma la reconstrucción pendiente más antigua y recalcula su rango. */
    public static function rebuildNext(): ?ReportRebuild
    {
        $job = ReportRebuild::query()->where('status', ReportRebuild::PENDING)->orderBy('created_at')->first();
        if (! $job || ReportRebuild::query()->where('_id', new ObjectId($job->getKey()))->where('status', ReportRebuild::PENDING)
            ->update(['status' => ReportRebuild::RUNNING, 'started_at' => now()]) === 0) {
            return null;
        }
        $branch = Branch::query()->find((string) $job->branch_id);
        try {
            for ($day = Carbon::parse($job->from); $day->toDateString() <= $job->to; $day->addDay()) {
                self::refresh($branch, $day->toDateString());
            }
            $status = ReportRebuild::COMPLETED;
        } catch (Throwable $e) {
            report($e);
            $status = ReportRebuild::FAILED;
        }
        ReportRebuild::query()->where('_id', new ObjectId($job->getKey()))
            ->update(['status' => $status, 'active' => false, 'finished_at' => now()]);

        return $job->refresh();
    }

    /** Recalcula y guarda los dos reportes de un día. Sin actividad, el día queda sin filas. */
    public static function refresh(Branch $branch, string $date): void
    {
        $sales = self::computeSales($branch, $date);
        $hours = self::computeOccupancy($branch, $date);
        $branchId = new ObjectId($branch->getKey());
        $asOf = new UTCDateTime;
        Mongo::transaction(function () use ($branchId, $date, $sales, $hours, $asOf) {
            $db = DB::connection('mongodb');
            $db->table('daily_sales')->where('branch_id', $branchId)->where('business_date', $date)->delete();
            if ($sales !== null) {
                $db->table('daily_sales')->insert(['branch_id' => $branchId, 'business_date' => $date, 'report' => $sales, 'as_of' => $asOf]);
            }
            $db->table('hourly_occupancy')->where('branch_id', $branchId)->where('business_date', $date)->delete();
            foreach ($hours as $position => $row) {
                $db->table('hourly_occupancy')->insert(['branch_id' => $branchId, 'business_date' => $date, 'position' => $position, 'report' => $row, 'as_of' => $asOf]);
            }
        });
    }

    // --- Cálculo ---

    /**
     * RP-2: la venta es por el día de negocio en que se confirmó el pago: confirmado menos reembolsado.
     * Propinas, cortesías y facturado van aparte. null si el día no tuvo ninguna actividad.
     */
    public static function computeSales(Branch $branch, string $date): ?array
    {
        [$start, $end] = self::window($branch, $date);
        $branchId = new ObjectId($branch->getKey());
        $inDay = fn ($q) => $q->where('branch_id', $branchId)->where('status', 'CONFIRMED')
            ->where('confirmed_at', '>=', $start)->where('confirmed_at', '<', $end);
        $payments = $inDay(Payment::query())->get(['method', 'amount', 'tip']);
        $refunds = $inDay(Refund::query())->get(['method', 'amount', 'tip']);
        $checks = Check::query()->where('branch_id', $branchId)->where('business_date', $date)->get(['_id', 'status']);
        $sessions = TableSession::query()->where('branch_id', $branchId)->where('business_date', $date)->where('status', '!=', TableSession::CANCELLED)
            ->get(['guest_count']);
        if ($payments->isEmpty() && $refunds->isEmpty() && $checks->isEmpty() && $sessions->isEmpty()) {
            return null;
        }
        $sum = fn (Collection $rows, string $field) => $rows->reduce(fn ($carry, $r) => bcadd($carry, (string) ($r->{$field} ?? '0'), 2), '0');
        $courtesies = CheckAdjustment::query()->whereIn('check_id', $checks->map(fn ($c) => new ObjectId($c->getKey()))->all())
            ->where('active', true)->get(['amount']);
        $byMethod = array_map(fn (string $m) => [
            'method' => $m,
            'sales' => Floor::money($sum($payments->where('method', $m), 'amount')),
            'tips' => Floor::money($sum($payments->where('method', $m), 'tip')),
            'refunds' => Floor::money($sum($refunds->where('method', $m), 'amount')),
        ], self::METHODS);

        return [
            'businessDate' => $date,
            'netSales' => Floor::money(bcsub($sum($payments, 'amount'), $sum($refunds, 'amount'), 2)),
            'refunds' => Floor::money($sum($refunds, 'amount')),
            'tips' => Floor::money(bcsub($sum($payments, 'tip'), $sum($refunds, 'tip'), 2)),
            'courtesies' => Floor::money($sum($courtesies, 'amount')),
            // Fiscal todavía no existe: nada se ha facturado.
            'invoiced' => Floor::money('0'),
            'checkCount' => $checks->where('status', 'PAID')->count(),
            'guestCount' => (int) $sessions->sum('guest_count'),
            'byMethod' => $byMethod,
            'asOf' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Por cada hora local del día de negocio: sesiones abiertas en esa hora, mesas ocupadas y personas
     * sentadas en algún momento de esa hora. Solo las horas con actividad.
     */
    public static function computeOccupancy(Branch $branch, string $date): array
    {
        [$start] = self::window($branch, $date);
        $branchId = new ObjectId($branch->getKey());
        $sessions = TableSession::query()->where('branch_id', $branchId)->where('business_date', $date)
            ->where('status', '!=', TableSession::CANCELLED)->get();
        if ($sessions->isEmpty()) {
            return [];
        }
        $assignments = TableAssignment::query()->whereIn('session_id', $sessions->map(fn ($s) => new ObjectId($s->getKey()))->all())->get()
            ->groupBy(fn ($a) => (string) $a->session_id);
        $now = now();
        $asOf = $now->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        $rows = [];
        $hourStart = Carbon::instance($start->toDateTime());
        for ($i = 0; $i < 24; $i++, $hourStart->addHour()) {
            $hourEnd = $hourStart->copy()->addHour();
            $opened = 0;
            $tables = [];
            $guests = 0;
            foreach ($sessions as $s) {
                if ($s->opened_at >= $hourStart && $s->opened_at < $hourEnd) {
                    $opened++;
                }
                $seated = false;
                foreach ($assignments->get((string) $s->getKey(), collect()) as $a) {
                    $until = $a->closed_at ?? $now;
                    if ($a->assigned_at < $hourEnd && $until >= $hourStart) {
                        $tables[(string) $a->table_id] = true;
                        $seated = true;
                    }
                }
                $guests += $seated ? (int) $s->guest_count : 0;
            }
            if ($opened || $tables) {
                $rows[] = [
                    'businessDate' => $date,
                    'hour' => (int) $hourStart->copy()->setTimezone($branch->timezone ?: 'America/Mexico_City')->format('G'),
                    'sessionsOpened' => $opened,
                    'tablesOccupied' => count($tables),
                    'guestCount' => $guests,
                    'asOf' => $asOf,
                ];
            }
        }

        return $rows;
    }

    /** Inicio y fin del día de negocio, en UTC, según el corte de la sucursal. */
    private static function window(Branch $branch, string $date): array
    {
        $cutoff = $branch->business_day_cutoff ?: config('restia.floor.business_day_cutoff');
        $start = Carbon::parse("$date $cutoff", $branch->timezone ?: 'America/Mexico_City');

        return [new UTCDateTime($start), new UTCDateTime($start->copy()->addDay())];
    }

    /** @return list<array{0: string, 1: string}> sucursal y día de negocio que toca un evento */
    private static function daysOf(array $event): array
    {
        $branch = isset($event['branch_id']) ? Branch::query()->find((string) $event['branch_id']) : null;
        if (! $branch) {
            return [];
        }
        $id = (string) $event['source_id'];
        $date = match ($event['source_type']) {
            'payment' => ($p = Payment::query()->find($id)) ? Floor::businessDate($branch, $p->confirmed_at ?? $p->created_at) : null,
            'refund' => ($r = Refund::query()->find($id)) ? Floor::businessDate($branch, $r->confirmed_at ?? $r->created_at) : null,
            'check' => Check::query()->find($id)?->business_date,
            'table_session' => TableSession::query()->find($id)?->business_date,
            default => null,
        };
        // Una cuenta o sesión también cambia los totales de su día por pagos de otro día; basta su propio día.
        $days = $date ? [[(string) $branch->getKey(), $date]] : [];
        if ($event['source_type'] === 'payment' && ($p ?? null)) {
            $checkDate = Check::query()->find((string) $p->check_id)?->business_date;
            if ($checkDate && $checkDate !== $date) {
                $days[] = [(string) $branch->getKey(), $checkDate];
            }
        }

        return $days;
    }
}
