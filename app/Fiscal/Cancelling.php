<?php

namespace App\Fiscal;

use App\Fiscal\Pac\CancelRequest;
use App\Fiscal\Pac\CancelResult;
use App\Fiscal\Pac\Pac;
use App\Fiscal\Pac\PacFailure;
use App\Models\FiscalIssuer;
use App\Models\Invoice;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;
use Throwable;

/**
 * Proceso interno de la cancelación. Envía las solicitudes en CANCELLATION_REQUESTED y consulta las que
 * esperan respuesta del receptor (CANCELLATION_IN_PROGRESS) cada `cancellation_poll_seconds`.
 *
 * A diferencia del timbrado, pedir dos veces la cancelación del mismo folio fiscal no duplica nada en
 * el SAT. Por eso, si el PAC no responde o el proceso se cae, la solicitud se repite más tarde en lugar
 * de quedar en UNKNOWN.
 */
final class Cancelling
{
    public const LEASE_SECONDS = 120;

    public const RETRY_SECONDS = 60;

    public const BATCH = 20;

    /** @return array{cancelled: int, in_progress: int, rejected: int, retry: int} */
    public static function run(): array
    {
        $counts = ['cancelled' => 0, 'in_progress' => 0, 'rejected' => 0, 'retry' => 0];
        for ($i = 0; $i < self::BATCH && ($invoice = self::claim()); $i++) {
            $counts[self::process($invoice)]++;
        }

        return $counts;
    }

    /** Toma una solicitud vencida y aplaza la siguiente revisión: si el proceso se cae, se repite. */
    private static function claim(): ?Invoice
    {
        $doc = DB::connection('mongodb')->getCollection('invoices')->findOneAndUpdate(
            ['status' => ['$in' => [Invoice::CANCELLATION_REQUESTED, Invoice::CANCELLATION_IN_PROGRESS]], 'cancel_next_at' => ['$lte' => new UTCDateTime(now())]],
            ['$set' => ['cancel_next_at' => new UTCDateTime(now()->addSeconds(self::LEASE_SECONDS))]],
            ['sort' => ['cancel_next_at' => 1], 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );

        return $doc ? Invoice::query()->find((string) $doc['_id']) : null;
    }

    private static function process(Invoice $invoice): string
    {
        $pac = app(Pac::class);
        $request = self::request($invoice);
        try {
            $result = $invoice->status === Invoice::CANCELLATION_REQUESTED ? $pac->cancel($request) : $pac->cancellationStatus($request);
        } catch (PacFailure $e) {
            // El SAT no admitió la solicitud: el CFDI sigue vigente y se puede volver a intentar.
            self::finish($invoice, Invoice::CANCELLATION_REJECTED, null, ['code' => $e->providerCode, 'message' => $e->getMessage()], $e->providerCode);

            return 'rejected';
        } catch (Throwable $e) {
            report($e);
            Invoice::query()->where('_id', $invoice->getKey())->where('status', $invoice->status)->update([
                'cancel_next_at' => new UTCDateTime(now()->addSeconds(self::RETRY_SECONDS)),
                'last_error' => ['code' => 'PAC_UNREACHABLE', 'message' => 'El PAC no respondió; se vuelve a intentar.'],
            ]);
            Invoices::event(new ObjectId($invoice->getKey()), $invoice->status, $invoice->status, null, 'PAC_UNREACHABLE');

            return 'retry';
        }

        return match ($result->status) {
            CancelResult::CANCELLED => self::finish($invoice, Invoice::CANCELLED, $result->satStatus) ? 'cancelled' : 'retry',
            CancelResult::REJECTED => self::finish($invoice, Invoice::CANCELLATION_REJECTED, $result->satStatus) ? 'rejected' : 'retry',
            default => self::waiting($invoice, $result->satStatus),
        };
    }

    /** El receptor tiene tres días hábiles: se vuelve a consultar más tarde. */
    private static function waiting(Invoice $invoice, ?string $satStatus): string
    {
        $next = new UTCDateTime(now()->addSeconds((int) config('restia.fiscal.cancellation_poll_seconds', 900)));
        $changes = ['cancel_next_at' => $next, 'cancellation.sat_status' => $satStatus, 'last_error' => null];
        Mongo::transaction(function () use ($invoice, $changes) {
            $fresh = Invoice::query()->where('_id', $invoice->getKey())->where('status', $invoice->status)->first();
            if (! $fresh) {
                return;
            }
            if ($fresh->status === Invoice::CANCELLATION_REQUESTED) {
                Invoices::transition($fresh, Invoice::CANCELLATION_IN_PROGRESS, $changes, null);
            } else {
                Invoice::query()->where('_id', $fresh->getKey())->update($changes);
            }
        });

        return 'in_progress';
    }

    /** CANCELLED libera la cuenta si la factura todavía la tenía (con motivo 01 ya pasó a la sustituta). */
    private static function finish(Invoice $invoice, string $to, ?string $satStatus, ?array $error = null, ?string $providerCode = null): bool
    {
        return (bool) Mongo::transaction(function () use ($invoice, $to, $satStatus, $error, $providerCode) {
            $fresh = Invoice::query()->where('_id', $invoice->getKey())->where('status', $invoice->status)->first();
            if (! $fresh) {
                return false;
            }
            Invoices::transition($fresh, $to, ['cancel_next_at' => null, 'cancellation.sat_status' => $satStatus, 'last_error' => $error], null, $providerCode);
            $event = $to === Invoice::CANCELLED ? 'invoice.cancelled' : 'invoice.cancellation_rejected';
            if ($to === Invoice::CANCELLED) {
                Invoices::release($fresh);
            }
            Audit::record($event, 'invoice', $fresh->getKey(), $fresh->branch_id, null, changes: ['satStatus' => $satStatus, 'providerCode' => $providerCode]);
            Outbox::publish($event, 'invoice', $fresh->getKey(), $fresh->branch_id, ['invoiceId' => $fresh->uuid, 'satStatus' => $satStatus]);

            return true;
        });
    }

    private static function request(Invoice $invoice): CancelRequest
    {
        $issuer = FiscalIssuer::query()->find((string) $invoice->issuer_id);
        $cancellation = (array) $invoice->cancellation;
        $replacement = isset($cancellation['replacement_invoice_id']) ? Invoice::query()->find((string) $cancellation['replacement_invoice_id']) : null;

        return new CancelRequest(
            fiscalUuid: (string) $invoice->fiscal_uuid,
            issuerRfc: $issuer->rfc,
            csdRef: (string) $issuer->csd_provider_ref,
            receiverRfc: ((array) $invoice->receiver)['rfc'] ?? 'XAXX010101000',
            total: bcadd((string) $invoice->total, '0', 2),
            reasonCode: $cancellation['reason_code'],
            replacementFiscalUuid: $replacement?->fiscal_uuid,
        );
    }
}
