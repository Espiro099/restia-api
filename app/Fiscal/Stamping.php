<?php

namespace App\Fiscal;

use App\Fiscal\Pac\Pac;
use App\Fiscal\Pac\PacFailure;
use App\Fiscal\Pac\StampRequest;
use App\Fiscal\Pac\StampResult;
use App\Models\FiscalIssuer;
use App\Models\Invoice;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;
use Throwable;

/**
 * Proceso interno del timbrado (F-2, F-11). Toma una factura en PENDING_STAMP con un plazo de ocupación,
 * le asigna folio y habla con el PAC fuera de cualquier transacción. Si el proceso se cae a la mitad, al
 * vencer el plazo la factura queda en UNKNOWN: nunca se reenvía a ciegas.
 */
final class Stamping
{
    public const LEASE_SECONDS = 120;

    public const BATCH = 20;

    /** @return array{stamped: int, failed: int, unknown: int, expired: int, reconciled: int} */
    public static function run(): array
    {
        $counts = ['stamped' => 0, 'failed' => 0, 'unknown' => 0, 'expired' => 0, 'reconciled' => 0];
        $counts['expired'] = self::expireLeases();
        for ($i = 0; $i < self::BATCH && ($invoice = self::claim()); $i++) {
            $counts[self::stamp($invoice)]++;
        }
        for ($i = 0; $i < self::BATCH && ($invoice = self::claimReconcile()); $i++) {
            self::reconcile($invoice);
            $counts['reconciled']++;
        }

        return $counts;
    }

    /** STAMPING con el plazo vencido: el proceso que la tenía se cayó y no se sabe si el PAC timbró. */
    private static function expireLeases(): int
    {
        $count = 0;
        foreach (Invoice::query()->where('status', Invoice::STAMPING)->where('lease_until', '<', now())->get() as $invoice) {
            $count += self::finish($invoice, Invoice::UNKNOWN, ['last_error' => ['code' => 'LEASE_EXPIRED', 'message' => 'El timbrado quedó sin respuesta.']],
                'invoice.stamp_unknown') ? 1 : 0;
        }

        return $count;
    }

    /** Transición condicional PENDING_STAMP → STAMPING: si dos procesos compiten, solo uno la toma. */
    private static function claim(): ?Invoice
    {
        $doc = DB::connection('mongodb')->getCollection('invoices')->findOneAndUpdate(
            ['status' => Invoice::PENDING_STAMP],
            ['$set' => ['status' => Invoice::STAMPING, 'lease_until' => new UTCDateTime(now()->addSeconds(self::LEASE_SECONDS)), 'updated_at' => new UTCDateTime(now())], '$inc' => ['version' => 1]],
            ['sort' => ['updated_at' => 1], 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        if (! $doc) {
            return null;
        }
        $invoice = Invoice::query()->find((string) $doc['_id']);
        Invoices::event(new ObjectId($invoice->getKey()), Invoice::PENDING_STAMP, Invoice::STAMPING, null);

        return $invoice;
    }

    private static function stamp(Invoice $invoice): string
    {
        $invoice = self::withFolio($invoice);
        try {
            $result = app(Pac::class)->stamp(self::request($invoice));
        } catch (PacFailure $e) {
            self::finish($invoice, Invoice::STAMP_FAILED, ['last_error' => ['code' => $e->providerCode, 'message' => $e->getMessage()]],
                'invoice.stamp_failed', $e->providerCode);

            return 'failed';
        } catch (Throwable $e) {
            report($e);
            self::finish($invoice, Invoice::UNKNOWN, ['last_error' => ['code' => 'PAC_UNREACHABLE', 'message' => 'No se sabe si el PAC timbró.']], 'invoice.stamp_unknown');

            return 'unknown';
        }
        self::stamped($invoice, $result);

        return 'stamped';
    }

    private static function stamped(Invoice $invoice, StampResult $result): bool
    {
        $files = Files::store($invoice, $result);

        return self::finish($invoice, Invoice::STAMPED, [
            'fiscal_uuid' => $result->fiscalUuid, 'stamped_at' => $result->stampedAt->toMutable(), 'files' => $files, 'last_error' => null,
        ], 'invoice.stamped');
    }

    /** Consulta pedida por un gerente sobre una factura en UNKNOWN. */
    private static function claimReconcile(): ?Invoice
    {
        $doc = DB::connection('mongodb')->getCollection('invoices')->findOneAndUpdate(
            ['status' => Invoice::UNKNOWN, 'reconcile_requested_at' => ['$ne' => null]],
            ['$set' => ['reconcile_requested_at' => null]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );

        return $doc ? Invoice::query()->find((string) $doc['_id']) : null;
    }

    /** F-11: STAMPED si el PAC lo timbró, STAMP_FAILED si nunca lo recibió, UNKNOWN si tampoco lo sabe. */
    private static function reconcile(Invoice $invoice): void
    {
        try {
            $result = app(Pac::class)->find($invoice->uuid);
        } catch (Throwable $e) {
            report($e);
            Invoices::event(new ObjectId($invoice->getKey()), Invoice::UNKNOWN, Invoice::UNKNOWN, null, 'PAC_UNREACHABLE');
            Outbox::publish('invoice.reconciled', 'invoice', $invoice->getKey(), $invoice->branch_id, ['invoiceId' => $invoice->uuid, 'status' => Invoice::UNKNOWN]);

            return;
        }
        $result
            ? self::stamped($invoice, $result)
            : self::finish($invoice, Invoice::STAMP_FAILED, ['last_error' => ['code' => 'NOT_STAMPED', 'message' => 'El PAC no tiene este comprobante; se puede timbrar de nuevo.']],
                'invoice.stamp_failed');
        Outbox::publish('invoice.reconciled', 'invoice', $invoice->getKey(), $invoice->branch_id, ['invoiceId' => $invoice->uuid, 'status' => $invoice->refresh()->status]);
    }

    /**
     * Folio del emisor y la serie: contador atómico fuera de la transacción (sección 5); puede dejar
     * huecos. Se guarda antes de llamar al PAC para que un reintento o una reconciliación usen el mismo.
     */
    private static function withFolio(Invoice $invoice): Invoice
    {
        if ($invoice->folio) {
            return $invoice;
        }
        $series = (string) config('restia.fiscal.series.'.strtolower($invoice->type), 'A');
        $counter = DB::connection('mongodb')->getCollection('invoice_counters')->findOneAndUpdate(
            ['issuer_id' => new ObjectId((string) $invoice->issuer_id), 'series' => $series],
            ['$inc' => ['next_folio' => 1]],
            ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        Invoice::query()->where('_id', $invoice->getKey())->whereNull('folio')->update(['series' => $series, 'folio' => (string) $counter['next_folio']]);

        return $invoice->refresh();
    }

    private static function request(Invoice $invoice): StampRequest
    {
        $issuer = FiscalIssuer::query()->find((string) $invoice->issuer_id);

        return new StampRequest(
            reference: $invoice->uuid,
            type: $invoice->type,
            issuer: ['rfc' => $issuer->rfc, 'legalName' => $issuer->legal_name, 'taxRegime' => $issuer->tax_regime, 'postalCode' => $issuer->postal_code,
                'csdRef' => (string) $issuer->csd_provider_ref],
            receiver: $invoice->receiver ? (array) $invoice->receiver : null,
            series: (string) $invoice->series,
            folio: (string) $invoice->folio,
            paymentMethod: $invoice->sat_payment_method,
            paymentForm: $invoice->sat_payment_form,
            currency: $invoice->currency,
            lines: Invoices::lines($invoice),
            subtotal: bcadd((string) $invoice->subtotal, '0', 2),
            taxTotal: bcadd((string) $invoice->tax_total, '0', 2),
            total: bcadd((string) $invoice->total, '0', 2),
            issuedAt: CarbonImmutable::now(),
        );
    }

    /** Cierra el intento si la factura sigue en el estado que tomó este proceso. */
    private static function finish(Invoice $invoice, string $to, array $changes, string $event, ?string $providerCode = null): bool
    {
        $from = $invoice->status === Invoice::UNKNOWN ? Invoice::UNKNOWN : Invoice::STAMPING;

        return (bool) Mongo::transaction(function () use ($invoice, $from, $to, $changes, $event, $providerCode) {
            $fresh = Invoice::query()->where('_id', $invoice->getKey())->where('status', $from)->first();
            if (! $fresh) {
                return false;
            }
            Invoices::transition($fresh, $to, $changes + ['lease_until' => null], null, $providerCode);
            if ($to === Invoice::STAMP_FAILED || $to === Invoice::UNKNOWN) {
                Audit::record($event, 'invoice', $fresh->getKey(), $fresh->branch_id, null, changes: ['providerCode' => $providerCode]);
            }
            Outbox::publish($event, 'invoice', $fresh->getKey(), $fresh->branch_id, ['invoiceId' => $fresh->uuid]);

            return true;
        });
    }
}
