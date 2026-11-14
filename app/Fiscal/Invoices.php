<?php

namespace App\Fiscal;

use App\Checks\Totals;
use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\Check;
use App\Models\FiscalIssuer;
use App\Models\Invoice;
use App\Models\InvoiceAllocation;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Floor;
use App\Support\Idempotency;
use App\Support\Mongo;
use App\Support\Outbox;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Throwable;

/**
 * Facturas nominativas PUE (F-3, F-4, F-10). Una cuenta pagada, una factura activa. Los conceptos salen
 * de lo copiado en la cuenta al comandar (M-7), sin propina ni unidades en cortesía (F-9), y no se editan.
 * El timbrado lo hace un proceso interno (Stamping): aquí solo se solicita.
 */
final class Invoices
{
    /** Forma de pago del SAT por método de Restia (Decisiones_Fiscal, sección 4). */
    public const PAYMENT_FORMS = ['CASH' => '01', 'TRANSFER' => '03', 'CARD_CREDIT' => '04', 'CARD_DEBIT' => '28'];

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array<string, mixed>>
     */
    public static function presentMany(Collection $invoices): array
    {
        $refs = fn (string $field, string $model) => $model::query()
            ->whereIn('_id', $invoices->pluck($field)->filter()->map(fn ($id) => new ObjectId((string) $id))->unique()->values()->all())
            ->get()->mapWithKeys(fn ($d) => [(string) $d->getKey() => $d->uuid]);
        $branches = $refs('branch_id', Branch::class);
        $issuers = $refs('issuer_id', FiscalIssuer::class);
        $checks = $refs('check_id', Check::class);

        return $invoices->map(fn (Invoice $i) => self::shape($i, $branches, $issuers, $checks))->values()->all();
    }

    public static function present(Invoice $invoice): array
    {
        return self::presentMany(collect([$invoice]))[0];
    }

    private static function shape(Invoice $i, Collection $branches, Collection $issuers, Collection $checks): array
    {
        $currency = $i->currency;

        return [
            'id' => $i->uuid,
            'branchId' => $branches->get((string) $i->branch_id),
            'issuerId' => $issuers->get((string) $i->issuer_id),
            'type' => $i->type,
            'scope' => $i->scope,
            'status' => $i->status,
            'checkId' => $i->check_id ? $checks->get((string) $i->check_id) : null,
            'batchId' => null,
            'replacesInvoiceId' => null,
            'relatedInvoiceId' => null,
            'refundId' => null,
            'receiver' => $i->receiver ? (array) $i->receiver : null,
            'satPaymentMethod' => $i->sat_payment_method,
            'satPaymentForm' => $i->sat_payment_form,
            'series' => $i->series,
            'folio' => $i->folio,
            'lines' => array_map(fn ($l) => [
                'description' => $l['description'], 'satProductCode' => $l['satProductCode'], 'satUnitCode' => $l['satUnitCode'],
                'quantity' => $l['quantity'], 'unitValue' => $l['unitValue'], 'amount' => Floor::money($l['amount'], $currency),
                'taxObject' => $l['taxObject'], 'taxRate' => $l['taxRate'], 'taxAmount' => Floor::money($l['taxAmount'], $currency),
            ], self::lines($i)),
            'subtotal' => Floor::money($i->subtotal, $currency),
            'taxTotal' => Floor::money($i->tax_total, $currency),
            'total' => Floor::money($i->total, $currency),
            'fiscalUuid' => $i->fiscal_uuid,
            'stampedAt' => MobileAuth::time($i->stamped_at),
            'cancellation' => null,
            'creditNoteRequired' => (bool) $i->credit_note_required,
            'lastError' => $i->last_error ? (array) $i->last_error : null,
            'createdAt' => MobileAuth::time($i->created_at),
            'version' => (int) $i->version,
        ];
    }

    /** @return list<array<string, string>> */
    public static function lines(Invoice $i): array
    {
        return array_map(fn ($l) => (array) $l, json_decode(json_encode($i->lines ?? []), true));
    }

    // ---------------------------------------------------------------- Alta

    /**
     * F-3 y F-4: borrador PUE de una cuenta PAID con total mayor a cero. Escribe en la cuenta para chocar
     * con mover unidades o dar una cortesía al mismo tiempo; el índice parcial de asignaciones deja pasar
     * una sola factura activa.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function create(Branch $branch, User $actor, string $key, array $in): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        try {
            return Idempotency::run($branchId, $key, $actorId, 'createInvoice', Idempotency::hash('createInvoice', $in), function () use ($branchId, $actorId, $in) {
                $check = Check::query()->where('branch_id', $branchId)->where('uuid', strtolower($in['checkId']))->first()
                    ?? throw ValidationException::withMessages(['checkId' => 'La cuenta no existe en esta sucursal.']);
                $issuer = self::issuerOf(Branch::query()->find($branchId));
                if ($check->status !== Check::PAID || bccomp((string) $check->total, '0', 2) <= 0) {
                    throw new DomainConflict('INVOICE_CHECK_NOT_ELIGIBLE', ['checkStatus' => $check->status]);
                }
                if ($check->active_invoice_id) {
                    throw new DomainConflict('INVOICE_CHECK_ALREADY_INVOICED');
                }
                [$lines, $subtotal, $tax] = self::concepts($check);
                $total = bcadd($subtotal, $tax, 2);
                // Sin cortesías parciales (C-7), lo facturado es exactamente lo que se cobró.
                if (bccomp($total, (string) $check->total, 2) !== 0) {
                    throw new DomainConflict('INVOICE_CHECK_NOT_ELIGIBLE', ['reason' => 'TOTAL_MISMATCH']);
                }
                $invoice = Invoice::query()->create([
                    'branch_id' => $branchId, 'issuer_id' => new ObjectId($issuer->getKey()), 'type' => 'INGRESO', 'scope' => 'NOMINATIVE',
                    'status' => Invoice::DRAFT, 'check_id' => new ObjectId($check->getKey()), 'receiver' => $in['receiver'],
                    'sat_payment_method' => 'PUE', 'sat_payment_form' => self::paymentForm($check), 'series' => null, 'folio' => null,
                    'currency' => Floor::CURRENCY, 'lines' => $lines, 'subtotal' => Floor::decimal($subtotal),
                    'tax_total' => Floor::decimal($tax), 'total' => Floor::decimal($total), 'fiscal_uuid' => null, 'stamped_at' => null,
                    'credit_note_required' => false, 'last_error' => null, 'lease_until' => null, 'created_by' => $actorId,
                ]);
                $invoiceId = new ObjectId($invoice->getKey());
                InvoiceAllocation::query()->create([
                    'branch_id' => $branchId, 'check_id' => new ObjectId($check->getKey()), 'invoice_id' => $invoiceId, 'active' => true, 'closed_at' => null,
                ]);
                Check::query()->where('_id', $check->getKey())->update(['active_invoice_id' => $invoiceId]);
                self::event($invoiceId, null, Invoice::DRAFT, $actorId);
                Audit::record('invoice.created', 'invoice', $invoiceId, $branchId, $actorId, changes: ['checkId' => $check->uuid, 'total' => $total]);

                return [201, self::present($invoice), $invoiceId];
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('INVOICE_CHECK_ALREADY_INVOICED') : $e;
        }
    }

    /** Emisor de la sucursal, activo y con CSD vigente. */
    public static function issuerOf(Branch $branch): FiscalIssuer
    {
        $issuer = $branch->fiscal_issuer_id ? FiscalIssuer::query()->find((string) $branch->fiscal_issuer_id) : null;

        return $issuer?->canIssue() ? $issuer : throw new DomainConflict('FISCAL_ISSUER_NOT_CONFIGURED');
    }

    /**
     * Un concepto por partida, con las unidades que se cobran y sin cortesía. Base e impuesto por unidad,
     * igual que en la cuenta (C-6), para que la suma cuadre con `check.total`.
     *
     * @return array{0: list<array<string, string>>, 1: string, 2: string}
     */
    private static function concepts(Check $check): array
    {
        $groups = [];
        $missing = [];
        foreach (Totals::lines($check) as $line) {
            if (! $line['billed'] || $line['adjustment']) {
                continue;
            }
            $item = $line['item'];
            $fiscal = $item->fiscal_snapshot ? (array) json_decode(json_encode($item->fiscal_snapshot), true) : null;
            if (! $fiscal || empty($fiscal['sat_product_code'])) {
                $missing[] = $item->name_snapshot;

                continue;
            }
            $id = (string) $item->getKey();
            $groups[$id] ??= ['item' => $item, 'fiscal' => $fiscal, 'price' => $line['price'], 'tax' => $line['tax'], 'count' => 0];
            $groups[$id]['count']++;
        }
        if ($missing) {
            throw new DomainConflict('INVOICE_CHECK_NOT_ELIGIBLE', ['reason' => 'PRODUCT_WITHOUT_FISCAL_DATA', 'products' => array_values(array_unique($missing))]);
        }
        $lines = [];
        $subtotal = $taxTotal = '0';
        foreach ($groups as $g) {
            $base = bcsub($g['price'], $g['tax'], 2);
            $amount = bcmul($base, (string) $g['count'], 2);
            $tax = bcmul($g['tax'], (string) $g['count'], 2);
            $options = array_map(fn ($m) => (string) ((array) $m)['name'], json_decode(json_encode($g['item']->modifiers_snapshot ?? []), true));
            $lines[] = [
                'description' => mb_substr($g['item']->name_snapshot.($options ? ' ('.implode(', ', $options).')' : ''), 0, 1000),
                'satProductCode' => (string) $g['fiscal']['sat_product_code'], 'satUnitCode' => (string) $g['fiscal']['sat_unit_code'],
                'quantity' => (string) $g['count'], 'unitValue' => $base, 'amount' => $amount,
                'taxObject' => (string) $g['fiscal']['tax_object'], 'taxRate' => (string) $g['fiscal']['tax_rate'], 'taxAmount' => $tax,
            ];
            $subtotal = bcadd($subtotal, $amount, 2);
            $taxTotal = bcadd($taxTotal, $tax, 2);
        }

        return [$lines, $subtotal, $taxTotal];
    }

    /** La del método con mayor importe confirmado (criterio provisional para pagos mixtos). */
    private static function paymentForm(Check $check): string
    {
        $byMethod = [];
        foreach (Payment::query()->where('check_id', new ObjectId($check->getKey()))->where('status', Payment::CONFIRMED)->get() as $p) {
            $byMethod[$p->method] = bcadd($byMethod[$p->method] ?? '0', (string) $p->amount, 2);
        }
        uasort($byMethod, fn ($a, $b) => bccomp($b, $a, 2));

        return self::PAYMENT_FORMS[array_key_first($byMethod) ?? 'CASH'] ?? '01';
    }

    // ---------------------------------------------------------------- Cambios

    /** Corrige receptor o uso de CFDI. STAMP_FAILED vuelve a DRAFT; los conceptos no se tocan. */
    public static function update(User $actor, Invoice $invoice, int $version, array $receiver): Invoice
    {
        return self::change($actor, $invoice, $version, function (Invoice $fresh) use ($receiver) {
            if (! in_array($fresh->status, Invoice::EDITABLE, true)) {
                throw new DomainConflict('INVOICE_INVALID_STATE');
            }

            return [['receiver' => $receiver, 'status' => Invoice::DRAFT, 'last_error' => null], 'invoice.updated', null, null];
        });
    }

    /** F-10: un borrador o un timbrado fallido nunca existió ante el SAT; se descarta y la cuenta queda libre. */
    public static function discard(User $actor, Invoice $invoice, int $version, string $reason): Invoice
    {
        return self::change($actor, $invoice, $version, function (Invoice $fresh) use ($reason) {
            if ($fresh->status === Invoice::DISCARDED) {
                return [[], null, null, null];
            }
            if (! in_array($fresh->status, Invoice::EDITABLE, true)) {
                throw new DomainConflict('INVOICE_INVALID_STATE');
            }
            self::release($fresh);

            return [['status' => Invoice::DISCARDED], 'invoice.discarded', null, $reason];
        });
    }

    /**
     * F-2: deja la factura en PENDING_STAMP y responde 202; el proceso interno habla con el PAC. Una
     * factura en UNKNOWN nunca se reenvía: se reconcilia (F-11).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function requestStamp(Branch $branch, User $actor, Invoice $invoice, int $version, string $key): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return Idempotency::run($branchId, $key, $actorId, 'stampInvoice', Idempotency::hash('stampInvoice', ['invoiceId' => $invoice->uuid, 'version' => $version]),
            function () use ($actorId, $invoice, $version) {
                $fresh = self::fresh($invoice, $version);
                if ($fresh->status === Invoice::UNKNOWN) {
                    throw new DomainConflict('FISCAL_STATUS_UNKNOWN');
                }
                if (! in_array($fresh->status, Invoice::EDITABLE, true)) {
                    throw new DomainConflict('INVOICE_INVALID_STATE');
                }
                // El emisor pudo desactivarse o vencer su CSD desde que se creó el borrador.
                $issuer = FiscalIssuer::query()->find((string) $fresh->issuer_id);
                if (! $issuer?->canIssue()) {
                    throw new DomainConflict('FISCAL_ISSUER_NOT_CONFIGURED');
                }
                self::transition($fresh, Invoice::PENDING_STAMP, ['last_error' => null], $actorId);
                Outbox::publish('invoice.stamp_requested', 'invoice', $fresh->getKey(), $fresh->branch_id, ['invoiceId' => $fresh->uuid]);

                return [202, self::present($fresh->refresh()), new ObjectId($fresh->getKey())];
            });
    }

    /** F-11: marca la consulta; el proceso interno pregunta al PAC por nuestra referencia. */
    public static function requestReconcile(User $actor, Invoice $invoice): Invoice
    {
        $updated = Invoice::query()->where('_id', $invoice->getKey())->where('status', Invoice::UNKNOWN)->update(['reconcile_requested_at' => now()]);
        if ($updated === 0) {
            throw new DomainConflict('INVOICE_INVALID_STATE');
        }
        Audit::record('invoice.reconcile_requested', 'invoice', $invoice->getKey(), $invoice->branch_id, new ObjectId($actor->getKey()));

        return $invoice->refresh();
    }

    // ---------------------------------------------------------------- Ayudas

    /** Libera la cuenta: cierra la asignación y borra la factura activa de la cuenta. */
    public static function release(Invoice $invoice): void
    {
        $now = now();
        InvoiceAllocation::query()->where('invoice_id', new ObjectId($invoice->getKey()))->where('active', true)->update(['active' => false, 'closed_at' => $now]);
        Check::query()->where('_id', new ObjectId((string) $invoice->check_id))->where('active_invoice_id', new ObjectId($invoice->getKey()))
            ->update(['active_invoice_id' => null]);
    }

    /** Cambia el estado subiendo la versión y deja el evento. Debe correr en una transacción. */
    public static function transition(Invoice $invoice, string $to, array $changes, ?ObjectId $actorId, ?string $providerCode = null, array $details = []): void
    {
        $from = $invoice->status;
        Invoice::query()->where('_id', $invoice->getKey())->increment('version', 1, ['status' => $to, 'updated_at' => now()] + $changes);
        self::event(new ObjectId($invoice->getKey()), $from, $to, $actorId, $providerCode, $details);
    }

    public static function event(ObjectId $invoiceId, ?string $from, string $to, ?ObjectId $actorId, ?string $providerCode = null, array $details = []): void
    {
        DB::connection('mongodb')->table('invoice_events')->insert([
            'invoice_id' => $invoiceId, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actorId,
            'provider_code' => $providerCode, 'details' => $details ?: null, 'created_at' => new UTCDateTime(now()),
        ]);
    }

    private static function fresh(Invoice $invoice, int $version): Invoice
    {
        $fresh = Invoice::query()->find($invoice->getKey());
        if ((int) $fresh->version !== $version) {
            throw new DomainConflict('VERSION_CONFLICT');
        }

        return $fresh;
    }

    /** If-Match, evento si cambia el estado, auditoría; en una transacción. $work devuelve [cambios, acción, auditoría, motivo]. */
    private static function change(User $actor, Invoice $invoice, int $version, Closure $work): Invoice
    {
        $actorId = new ObjectId($actor->getKey());
        Mongo::transaction(function () use ($actorId, $invoice, $version, $work) {
            $fresh = self::fresh($invoice, $version);
            [$changes, $action, $audit, $reason] = $work($fresh);
            if (! $action) {
                return;
            }
            $before = $fresh->status;
            if (! $fresh->updateIfVersion($version, $changes)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            if (isset($changes['status']) && $changes['status'] !== $before) {
                self::event(new ObjectId($fresh->getKey()), $before, $changes['status'], $actorId);
            }
            Audit::record($action, 'invoice', $fresh->getKey(), $fresh->branch_id, $actorId, reason: $reason, changes: $audit ?? []);
        });

        return $invoice->refresh();
    }
}
