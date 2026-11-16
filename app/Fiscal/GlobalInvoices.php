<?php

namespace App\Fiscal;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Check;
use App\Models\FiscalIssuer;
use App\Models\Invoice;
use App\Models\InvoiceAllocation;
use App\Models\InvoiceBatch;
use App\Models\User;
use App\Support\Audit;
use App\Support\Floor;
use App\Support\Idempotency;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/**
 * Factura global (F-6, F-7). Un lote reúne las cuentas PAID del periodo sin factura nominativa; su factura
 * se timbra con la operación stamp de siempre, a nombre del público en general.
 *
 * Excluir cuentas sigue el orden del SAT: la global se cancela con motivo 04 y el lote de reemplazo, con
 * las cuentas que quedan, se timbra solo cuando la original está CANCELLED. Hasta entonces la original
 * conserva todas las cuentas; al cancelarse, las que quedan pasan a la de reemplazo y las excluidas quedan
 * libres para su factura nominativa.
 */
final class GlobalInvoices
{
    /**
     * @param  Collection<int, InvoiceBatch>  $batches
     * @return list<array<string, mixed>>
     */
    public static function presentMany(Collection $batches): array
    {
        $uuids = fn (string $model, Collection $ids) => $ids->isEmpty() ? collect() : $model::query()
            ->whereIn('_id', $ids->map(fn ($id) => new ObjectId((string) $id))->unique()->values()->all())
            ->get()->mapWithKeys(fn ($d) => [(string) $d->getKey() => $d->uuid]);
        $branches = $uuids(Branch::class, $batches->pluck('branch_id'));
        $issuers = $uuids(FiscalIssuer::class, $batches->pluck('issuer_id'));
        $invoices = $uuids(Invoice::class, $batches->pluck('invoice_id'));
        $others = $uuids(InvoiceBatch::class, $batches->pluck('superseded_by')->filter());
        $checks = $uuids(Check::class, $batches->flatMap(fn (InvoiceBatch $b) => self::ids($b->check_ids)));

        return $batches->map(fn (InvoiceBatch $b) => [
            'id' => $b->uuid,
            'branchId' => $branches->get((string) $b->branch_id),
            'issuerId' => $issuers->get((string) $b->issuer_id),
            'periodicity' => $b->periodicity,
            'periodFrom' => $b->period_from,
            'periodTo' => $b->period_to,
            'checkIds' => array_map(fn ($id) => $checks->get((string) $id), self::ids($b->check_ids)),
            'invoiceId' => $invoices->get((string) $b->invoice_id),
            'status' => $b->status,
            'supersededBy' => $b->superseded_by ? $others->get((string) $b->superseded_by) : null,
            'version' => (int) $b->version,
        ])->values()->all();
    }

    public static function present(InvoiceBatch $batch): array
    {
        return self::presentMany(collect([$batch]))[0];
    }

    // ---------------------------------------------------------------- Alta

    /**
     * Lote y factura global en borrador con las cuentas PAID del periodo (por `paid_at`, en la zona de la
     * sucursal) que no tienen factura activa. Las REFUNDED ya no están PAID; los reembolsos parciales se
     * descuentan. El índice parcial de asignaciones impide que una cuenta entre en dos facturas.
     *
     * @param  array{periodFrom: string, periodTo: string}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function create(Branch $branch, User $actor, string $key, array $in): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return Idempotency::run($branchId, $key, $actorId, 'createInvoiceBatch', Idempotency::hash('createInvoiceBatch', $in),
            function () use ($branch, $branchId, $actorId, $in) {
                $issuer = Invoices::issuerOf(Branch::query()->find($branchId));
                $periodicity = $issuer->global_periodicity ?: 'MONTHLY';
                [$from, $until, $global] = self::period($branch, $periodicity, $in['periodFrom'], $in['periodTo']);
                $checks = Check::query()->where('branch_id', $branchId)->where('status', Check::PAID)->whereNull('active_invoice_id')
                    ->where('paid_at', '>=', $from)->where('paid_at', '<', $until)->orderBy('paid_at')->orderBy('_id')->get();
                [$checks, $lines] = self::tickets($checks);
                if ($checks->isEmpty()) {
                    throw new DomainConflict('INVOICE_BATCH_EMPTY');
                }
                $batch = self::open($branchId, $issuer, $actorId, $periodicity, $in['periodFrom'], $in['periodTo'], $global, $checks, $lines);
                foreach ($checks as $check) {
                    self::allocate($batch->invoice_id, $check);
                }
                Audit::record('invoice_batch.created', 'invoice_batch', $batch->getKey(), $branchId, $actorId, changes: [
                    'periodFrom' => $in['periodFrom'], 'periodTo' => $in['periodTo'], 'checkIds' => $checks->pluck('uuid')->values()->all(),
                ]);
                Outbox::publish('invoice_batch.created', 'invoice_batch', $batch->getKey(), $branchId, ['batchId' => $batch->uuid]);

                return [201, self::present($batch), new ObjectId($batch->getKey())];
            });
    }

    /**
     * El periodo cabe en uno de la periodicidad del emisor y ya empezó. Devuelve el inicio, el fin
     * exclusivo y la información global del CFDI (periodicidad, meses y año del SAT).
     *
     * @return array{0: Carbon, 1: Carbon, 2: array{periodicity: string, months: string, year: string}}
     */
    private static function period(Branch $branch, string $periodicity, string $periodFrom, string $periodTo): array
    {
        $zone = $branch->timezone ?: 'America/Mexico_City';
        $from = Carbon::parse($periodFrom, $zone)->startOfDay();
        $to = Carbon::parse($periodTo, $zone)->startOfDay();
        $fail = fn (string $message) => throw ValidationException::withMessages(['periodTo' => $message]);
        if ($to->lt($from)) {
            $fail('El periodo termina antes de empezar.');
        }
        if ($from->gt(Carbon::now($zone)->startOfDay())) {
            $fail('El periodo todavía no empieza.');
        }
        $days = (int) $from->diffInDays($to) + 1;
        $fits = match ($periodicity) {
            'DAILY' => $days === 1,
            'WEEKLY' => $days <= 7 && $from->isSameMonth($to),
            'BIWEEKLY' => $days <= 16 && $from->isSameMonth($to),
            'BIMONTHLY' => $from->year === $to->year && intdiv($from->month - 1, 2) === intdiv($to->month - 1, 2),
            default => $from->isSameMonth($to),
        };
        if (! $fits) {
            $fail("El periodo no corresponde a la periodicidad $periodicity del emisor.");
        }
        // c_Meses: 01 a 12, o 13 a 18 para cada bimestre.
        $months = $periodicity === 'BIMONTHLY' ? (string) (13 + intdiv($from->month - 1, 2)) : $from->format('m');

        return [$from->utc(), $to->addDay()->utc(), [
            'periodicity' => InvoiceBatch::SAT_PERIODICITY[$periodicity], 'months' => $months, 'year' => (string) $from->year,
        ]];
    }

    /**
     * Un concepto por cuenta y tasa, como pide la guía de la global: clave 01010101, unidad ACT y el número
     * de cuenta como identificador. Una cuenta con todo reembolsado no entra.
     *
     * @param  Collection<int, Check>  $checks
     * @return array{0: Collection<int, Check>, 1: list<array<string, string>>}
     */
    private static function tickets(Collection $checks): array
    {
        $included = collect();
        $lines = [];
        $problems = [];
        foreach ($checks as $check) {
            try {
                $ticket = self::ticket($check);
            } catch (DomainConflict) {
                $problems[] = '#'.$check->number;

                continue;
            }
            if ($ticket) {
                $included->push($check);
                array_push($lines, ...$ticket);
            }
        }
        if ($problems) {
            throw ValidationException::withMessages(['periodFrom' => 'Estas cuentas no se pueden facturar: les faltan datos fiscales o el importe no cuadra ('
                .implode(', ', $problems).'). Corrígelas antes de crear la global.']);
        }

        return [$included, $lines];
    }

    /** @return list<array<string, string>>|null */
    private static function ticket(Check $check): ?array
    {
        [$concepts, $subtotal, $tax] = Invoices::concepts($check);
        $total = bcadd((string) $check->total, '0', 2);
        if (bccomp(bcadd($subtotal, $tax, 2), $total, 2) !== 0) {
            throw new DomainConflict('INVOICE_CHECK_NOT_ELIGIBLE');
        }
        $net = bcsub($total, bcadd((string) ($check->refunded_total ?? '0'), '0', 2), 2);
        if (bccomp($net, '0', 2) <= 0) {
            return null;
        }
        $groups = [];
        foreach ($concepts as $c) {
            $k = $c['taxObject'].'|'.$c['taxRate'];
            $groups[$k] ??= ['taxObject' => $c['taxObject'], 'taxRate' => $c['taxRate'], 'base' => '0', 'tax' => '0'];
            $groups[$k]['base'] = bcadd($groups[$k]['base'], $c['amount'], 2);
            $groups[$k]['tax'] = bcadd($groups[$k]['tax'], $c['taxAmount'], 2);
        }
        $refunded = bccomp($net, $total, 2) !== 0;
        // Sin reembolso, base e impuesto son los de la cuenta (C-6). Con reembolso parcial, lo que queda se
        // reparte entre las tasas, la última absorbe el redondeo y el impuesto sale del importe con IVA.
        $left = $net;
        $keys = array_keys($groups);
        $lines = [];
        foreach ($keys as $n => $k) {
            $g = $groups[$k];
            if (! $refunded) {
                [$base, $taxAmount] = [$g['base'], $g['tax']];
            } else {
                $gross = bcadd($g['base'], $g['tax'], 2);
                $gross = $n === count($keys) - 1 ? $left : self::round(bcdiv(bcmul($gross, $net, 10), $total, 10));
                $left = bcsub($left, $gross, 2);
                $taxAmount = $g['taxObject'] === '02'
                    ? self::round(bcdiv(bcmul($gross, $g['taxRate'], 10), bcadd('1', $g['taxRate'], 10), 10)) : '0.00';
                $base = bcsub($gross, $taxAmount, 2);
            }
            $lines[] = [
                'description' => 'Venta', 'satProductCode' => '01010101', 'satUnitCode' => 'ACT', 'identification' => (string) $check->number,
                'quantity' => '1', 'unitValue' => $base, 'amount' => $base, 'taxObject' => $g['taxObject'], 'taxRate' => $g['taxRate'], 'taxAmount' => $taxAmount,
            ];
        }

        return $lines;
    }

    private static function round(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }

    /**
     * Crea el lote ACTIVE y su factura en borrador, sin asignar cuentas.
     *
     * @param  Collection<int, Check>  $checks
     * @param  list<array<string, string>>  $lines
     */
    private static function open(ObjectId $branchId, FiscalIssuer $issuer, ObjectId $actorId, string $periodicity, string $periodFrom, string $periodTo,
        array $global, Collection $checks, array $lines, ?InvoiceBatch $replaces = null): InvoiceBatch
    {
        $subtotal = $tax = '0';
        foreach ($lines as $l) {
            $subtotal = bcadd($subtotal, $l['amount'], 2);
            $tax = bcadd($tax, $l['taxAmount'], 2);
        }
        $checkIds = $checks->map(fn (Check $c) => new ObjectId($c->getKey()))->values()->all();
        $invoice = Invoice::query()->create([
            'branch_id' => $branchId, 'issuer_id' => new ObjectId($issuer->getKey()), 'type' => 'INGRESO', 'scope' => 'GLOBAL',
            'status' => Invoice::DRAFT, 'check_id' => null, 'batch_id' => null, 'receiver' => null, 'global_info' => $global,
            'sat_payment_method' => 'PUE', 'sat_payment_form' => Invoices::paymentFormOf($checkIds), 'series' => null, 'folio' => null,
            'currency' => Floor::CURRENCY, 'lines' => $lines, 'subtotal' => Floor::decimal($subtotal), 'tax_total' => Floor::decimal($tax),
            'total' => Floor::decimal(bcadd($subtotal, $tax, 2)), 'fiscal_uuid' => null, 'stamped_at' => null, 'credit_note_required' => false,
            'last_error' => null, 'lease_until' => null, 'created_by' => $actorId, 'replaces_invoice_id' => null, 'replacement_invoice_id' => null,
            'cancellation' => null,
        ]);
        $invoiceId = new ObjectId($invoice->getKey());
        $batch = InvoiceBatch::query()->create([
            'branch_id' => $branchId, 'issuer_id' => new ObjectId($issuer->getKey()), 'periodicity' => $periodicity, 'period_from' => $periodFrom,
            'period_to' => $periodTo, 'check_ids' => $checkIds, 'invoice_id' => $invoiceId, 'status' => InvoiceBatch::ACTIVE, 'superseded_by' => null,
            'replaces_batch_id' => $replaces ? new ObjectId($replaces->getKey()) : null, 'excluded_check_ids' => [], 'created_by' => $actorId,
        ]);
        Invoice::query()->where('_id', $invoiceId)->update(['batch_id' => new ObjectId($batch->getKey())]);
        Invoices::event($invoiceId, null, Invoice::DRAFT, $actorId);

        return $batch;
    }

    private static function allocate(ObjectId|string $invoiceId, Check $check): void
    {
        $invoiceId = $invoiceId instanceof ObjectId ? $invoiceId : new ObjectId($invoiceId);
        $checkId = new ObjectId($check->getKey());
        InvoiceAllocation::query()->create([
            'branch_id' => new ObjectId((string) $check->branch_id), 'check_id' => $checkId, 'invoice_id' => $invoiceId, 'active' => true, 'closed_at' => null,
        ]);
        Check::query()->where('_id', $checkId)->update(['active_invoice_id' => $invoiceId]);
    }

    // ---------------------------------------------------------------- Exclusión (F-7)

    /**
     * Cancela la global con motivo 04 y crea el lote de reemplazo con las cuentas que quedan (si queda
     * alguna). Responde 202 con el lote de reemplazo, o con el original si ya no queda ninguna cuenta.
     *
     * @param  array{checkIds: list<string>, reason: string}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function exclude(Branch $branch, User $actor, InvoiceBatch $batch, int $version, string $key, array $in): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $hash = Idempotency::hash('excludeChecksFromBatch', ['batchId' => $batch->uuid, 'version' => $version] + $in);

        return Idempotency::run($branchId, $key, $actorId, 'excludeChecksFromBatch', $hash, function () use ($branchId, $actorId, $batch, $version, $in) {
            $fresh = InvoiceBatch::query()->find($batch->getKey());
            if ((int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            $invoice = Invoice::query()->find((string) $fresh->invoice_id);
            if ($fresh->status !== InvoiceBatch::ACTIVE || ! in_array($invoice->status, Invoice::CANCELLABLE, true) || $invoice->replacement_invoice_id) {
                throw new DomainConflict('INVOICE_INVALID_STATE', ['invoiceStatus' => $invoice->status]);
            }
            $inBatch = array_map('strval', self::ids($fresh->check_ids));
            $excluded = Check::query()->where('branch_id', $branchId)->whereIn('uuid', array_map('strtolower', $in['checkIds']))->get();
            if ($excluded->count() !== count(array_unique(array_map('strtolower', $in['checkIds'])))
                || $excluded->contains(fn (Check $c) => ! in_array((string) $c->getKey(), $inBatch, true))) {
                throw ValidationException::withMessages(['checkIds' => 'Alguna cuenta no está en este lote.']);
            }
            $excludedIds = $excluded->map(fn (Check $c) => (string) $c->getKey())->all();
            $remaining = Check::query()->whereIn('_id', array_map(fn ($id) => new ObjectId($id), array_values(array_diff($inBatch, $excludedIds))))
                ->orderBy('paid_at')->orderBy('_id')->get();
            [$remaining, $lines] = self::tickets($remaining);
            $replacement = null;
            if ($remaining->isNotEmpty()) {
                $issuer = FiscalIssuer::query()->find((string) $fresh->issuer_id);
                $replacement = self::open($branchId, $issuer, $actorId, $fresh->periodicity, $fresh->period_from, $fresh->period_to,
                    (array) $invoice->global_info, $remaining, $lines, $fresh);
            }
            if (! $fresh->updateIfVersion($version, [
                'status' => InvoiceBatch::SUPERSEDED, 'superseded_by' => $replacement ? new ObjectId($replacement->getKey()) : null,
                'excluded_check_ids' => $excluded->map(fn (Check $c) => new ObjectId($c->getKey()))->values()->all(),
            ])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Invoices::markCancellation($invoice, $branchId, $actorId, '04', $in['reason']);
            Audit::record('invoice_batch.superseded', 'invoice_batch', $fresh->getKey(), $branchId, $actorId, reason: $in['reason'], changes: [
                'excludedCheckIds' => $excluded->pluck('uuid')->values()->all(), 'replacementBatchId' => $replacement?->uuid,
            ]);
            Outbox::publish('invoice_batch.superseded', 'invoice_batch', $fresh->getKey(), $branchId,
                ['batchId' => $fresh->uuid, 'replacementBatchId' => $replacement?->uuid]);
            $result = $replacement ?? $fresh->refresh();

            return [202, self::present($result), new ObjectId($result->getKey())];
        });
    }

    // ---------------------------------------------------------------- Ganchos del ciclo de la factura

    /** La global de reemplazo se timbra solo con la original ya cancelada ante el SAT (orden del motivo 04). */
    public static function assertStampable(Invoice $invoice): void
    {
        $batch = InvoiceBatch::query()->find((string) $invoice->batch_id);
        if (! $batch?->replaces_batch_id) {
            return;
        }
        $original = InvoiceBatch::query()->find((string) $batch->replaces_batch_id);
        $originalInvoice = $original ? Invoice::query()->find((string) $original->invoice_id) : null;
        if ($originalInvoice?->status !== Invoice::CANCELLED) {
            throw new DomainConflict('INVOICE_INVALID_STATE', ['reason' => 'ORIGINAL_GLOBAL_NOT_CANCELLED', 'originalStatus' => $originalInvoice?->status]);
        }
    }

    /** Descartar el borrador libera sus cuentas y deja el lote sin vigencia. Corre en la transacción del descarte. */
    public static function discarded(Invoice $invoice): void
    {
        Invoices::release($invoice);
        self::retire($invoice);
    }

    /**
     * La global quedó CANCELLED: suelta todas sus cuentas y, si hay un reemplazo vigente, le pasa las que
     * no se excluyeron. Corre en la transacción que la deja CANCELLED.
     */
    public static function cancelled(Invoice $invoice): void
    {
        Invoices::release($invoice);
        $batch = InvoiceBatch::query()->find((string) $invoice->batch_id);
        $replacement = $batch?->superseded_by ? InvoiceBatch::query()->find((string) $batch->superseded_by) : null;
        $replacementInvoice = $replacement ? Invoice::query()->find((string) $replacement->invoice_id) : null;
        if ($replacementInvoice && $replacementInvoice->status !== Invoice::DISCARDED) {
            foreach (Check::query()->whereIn('_id', self::ids($replacement->check_ids))->whereNull('active_invoice_id')->get() as $check) {
                self::allocate($replacementInvoice->getKey(), $check);
            }
        }
        self::retire($invoice);
    }

    /** El lote de una global que ya no existe deja de estar vigente. */
    private static function retire(Invoice $invoice): void
    {
        InvoiceBatch::query()->where('_id', new ObjectId((string) $invoice->batch_id))->where('status', InvoiceBatch::ACTIVE)
            ->increment('version', 1, ['status' => InvoiceBatch::SUPERSEDED, 'updated_at' => now()]);
    }

    /** @return list<ObjectId> */
    private static function ids(mixed $ids): array
    {
        return array_values(array_map(fn ($id) => $id instanceof ObjectId ? $id : new ObjectId((string) $id), (array) ($ids ?? [])));
    }
}
