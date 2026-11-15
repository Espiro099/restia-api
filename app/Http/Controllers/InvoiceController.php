<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainConflict;
use App\Fiscal\Files;
use App\Fiscal\Invoices;
use App\Http\Middleware\AuthorizeBranch;
use App\Identity\MobileAuth;
use App\Models\Check;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Facturas nominativas PUE (Decisiones_Fiscal). */
class InvoiceController extends Controller
{
    /** @return array<string, list<mixed>> */
    private static function receiverRules(): array
    {
        return [
            'receiver' => ['required', 'array:rfc,legalName,taxRegime,postalCode,cfdiUse'],
            'receiver.rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/u'],
            'receiver.legalName' => ['required', 'string', 'min:1', 'max:300'],
            'receiver.taxRegime' => ['required', 'string', 'regex:/^[0-9]{3}$/'],
            'receiver.postalCode' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            'receiver.cfdiUse' => ['required', 'string', 'regex:/^[A-Z0-9]{3,4}$/'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $q = Validator::make($request->query(), [
            'checkId' => ['sometimes', 'uuid'], 'status' => ['sometimes', 'string', 'max:40'], 'type' => ['sometimes', Rule::in(['INGRESO', 'EGRESO'])],
            'scope' => ['sometimes', Rule::in(['NOMINATIVE', 'GLOBAL'])], 'creditNoteRequired' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date'], 'page' => ['sometimes'], 'perPage' => ['sometimes'],
        ])->validate();
        $branch = AuthorizeBranch::branch($request);
        $query = Invoice::query()->where('branch_id', new ObjectId($branch->getKey()));
        if (isset($q['checkId'])) {
            $check = Check::query()->where('branch_id', new ObjectId($branch->getKey()))->where('uuid', strtolower($q['checkId']))->first();
            $query->where('check_id', $check ? new ObjectId($check->getKey()) : new ObjectId);
        }
        foreach (['status' => 'status', 'type' => 'type', 'scope' => 'scope'] as $param => $field) {
            if (isset($q[$param])) {
                $query->where($field, $q[$param]);
            }
        }
        if (isset($q['creditNoteRequired'])) {
            $query->where('credit_note_required', in_array($q['creditNoteRequired'], ['true', '1'], true));
        }
        if (isset($q['from'])) {
            $query->where('created_at', '>=', Carbon::parse($q['from']));
        }
        if (isset($q['to'])) {
            $query->where('created_at', '<', Carbon::parse($q['to']));
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(Invoices::presentMany($rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function create(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, ['checkId' => ['required', 'string', 'uuid'], 'satPaymentMethod' => ['sometimes', 'string', Rule::in(['PUE', 'PPD'])],
            'replacesInvoiceId' => ['sometimes', 'string', 'uuid']] + self::receiverRules());
        // PPD y complementos quedan fuera de v1; la sustitución (motivo 01) llega con la cancelación.
        if (($in['satPaymentMethod'] ?? 'PUE') === 'PPD') {
            throw ValidationException::withMessages(['satPaymentMethod' => 'Por ahora solo se factura PUE: la cuenta ya pagada.']);
        }
        if (isset($in['replacesInvoiceId'])) {
            throw ValidationException::withMessages(['replacesInvoiceId' => 'La factura sustituta todavía no está disponible.']);
        }
        unset($in['satPaymentMethod']);
        [$status, $data] = Invoices::create(AuthorizeBranch::branch($request), $request->user(), $key, $in);

        return $this->versioned(Api::data($data, $status), $data);
    }

    public function show(Request $request, string $branchId, string $invoiceId): JsonResponse
    {
        return $this->etagged($this->find($request, $invoiceId));
    }

    public function update(Request $request, string $branchId, string $invoiceId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, self::receiverRules());

        return $this->etagged(Invoices::update($request->user(), $this->find($request, $invoiceId), $version, $in['receiver']));
    }

    public function discard(Request $request, string $branchId, string $invoiceId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->etagged(Invoices::discard($request->user(), $this->find($request, $invoiceId), $version, $in['reason']));
    }

    public function stamp(Request $request, string $branchId, string $invoiceId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        Api::body($request, []);
        [$status, $data] = Invoices::requestStamp(AuthorizeBranch::branch($request), $request->user(), $this->find($request, $invoiceId), $version, $key);

        return $this->versioned(Api::data($data, $status), $data);
    }

    public function reconcile(Request $request, string $branchId, string $invoiceId): JsonResponse
    {
        Api::body($request, []);

        return $this->etagged(Invoices::requestReconcile($request->user(), $this->find($request, $invoiceId)), 202);
    }

    public function events(Request $request, string $branchId, string $invoiceId): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $invoice = $this->find($request, $invoiceId);
        $query = DB::connection('mongodb')->table('invoice_events')->where('invoice_id', new ObjectId($invoice->getKey()));
        $total = (clone $query)->count();
        $rows = collect($query->orderBy('created_at')->orderBy('_id')->skip(($page - 1) * $perPage)->take($perPage)->get())->map(fn ($e) => (array) $e);
        $actors = User::query()->whereIn('_id', $rows->pluck('actor_id')->filter()->values()->all())->get()->mapWithKeys(fn ($u) => [(string) $u->getKey() => $u->uuid]);

        return Api::data($rows->map(fn ($e) => [
            'invoiceId' => $invoice->uuid,
            'fromStatus' => $e['from_status'],
            'toStatus' => $e['to_status'],
            'actorId' => $e['actor_id'] ? $actors->get((string) $e['actor_id']) : null,
            'providerCode' => $e['provider_code'],
            'createdAt' => MobileAuth::time(Carbon::instance($e['created_at']->toDateTime())),
        ])->values()->all(), meta: Api::pagination($page, $perPage, $total));
    }

    public function xml(Request $request, string $branchId, string $invoiceId): Response
    {
        return $this->file($this->find($request, $invoiceId), 'xml', 'application/xml');
    }

    public function pdf(Request $request, string $branchId, string $invoiceId): Response
    {
        return $this->file($this->find($request, $invoiceId), 'pdf', 'application/pdf');
    }

    /** Solo con folio fiscal. Datos personales del receptor: sin caché. */
    private function file(Invoice $invoice, string $kind, string $type): Response
    {
        $path = $invoice->fiscal_uuid ? (((array) $invoice->files)[$kind] ?? null) : null;
        if (! $path) {
            throw new DomainConflict('INVOICE_INVALID_STATE');
        }
        $content = Files::disk()->get($path) ?? throw new NotFoundHttpException;
        $name = trim(($invoice->series ?? '').'-'.($invoice->folio ?? ''), '-') ?: $invoice->fiscal_uuid;

        return response($content, 200, [
            'Content-Type' => $type, 'Content-Disposition' => "attachment; filename=\"factura-$name.$kind\"", 'Cache-Control' => 'no-store',
        ]);
    }

    private function find(Request $request, string $id): Invoice
    {
        return Invoice::query()->where('branch_id', new ObjectId(AuthorizeBranch::branch($request)->getKey()))->where('uuid', Floor::uuid($id))->first()
            ?? throw new NotFoundHttpException;
    }

    private function etagged(Invoice $invoice, int $status = 200): JsonResponse
    {
        $response = Api::data(Invoices::present($invoice), $status);
        $response->headers->set('ETag', Floor::versionEtag((int) $invoice->version));

        return $response;
    }

    private function versioned(JsonResponse $response, array $data): JsonResponse
    {
        $response->headers->set('ETag', Floor::versionEtag((int) $data['version']));

        return $response;
    }
}
