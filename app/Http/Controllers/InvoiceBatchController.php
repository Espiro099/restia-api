<?php

namespace App\Http\Controllers;

use App\Fiscal\GlobalInvoices;
use App\Http\Middleware\AuthorizeBranch;
use App\Models\InvoiceBatch;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Lotes de factura global (F-6, F-7). La factura del lote se timbra y consulta en InvoiceController. */
class InvoiceBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $q = Validator::make($request->query(), [
            'status' => ['sometimes', Rule::in([InvoiceBatch::ACTIVE, InvoiceBatch::SUPERSEDED])], 'page' => ['sometimes'], 'perPage' => ['sometimes'],
        ])->validate();
        $query = InvoiceBatch::query()->where('branch_id', new ObjectId(AuthorizeBranch::branch($request)->getKey()));
        if (isset($q['status'])) {
            $query->where('status', $q['status']);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(GlobalInvoices::presentMany($rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function create(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, ['periodFrom' => ['required', 'date_format:Y-m-d'], 'periodTo' => ['required', 'date_format:Y-m-d']]);
        [$status, $data] = GlobalInvoices::create(AuthorizeBranch::branch($request), $request->user(), $key, $in);

        return $this->versioned(Api::data($data, $status), $data);
    }

    public function show(Request $request, string $branchId, string $batchId): JsonResponse
    {
        $batch = $this->find($request, $batchId);

        return $this->versioned(Api::data(GlobalInvoices::present($batch)), ['version' => $batch->version]);
    }

    public function exclude(Request $request, string $branchId, string $batchId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'checkIds' => ['required', 'array', 'min:1', 'max:50'], 'checkIds.*' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        [$status, $data] = GlobalInvoices::exclude(AuthorizeBranch::branch($request), $request->user(), $this->find($request, $batchId), $version, $key, $in);

        return $this->versioned(Api::data($data, $status), $data);
    }

    private function find(Request $request, string $id): InvoiceBatch
    {
        return InvoiceBatch::query()->where('branch_id', new ObjectId(AuthorizeBranch::branch($request)->getKey()))->where('uuid', Floor::uuid($id))->first()
            ?? throw new NotFoundHttpException;
    }

    private function versioned(JsonResponse $response, array $data): JsonResponse
    {
        $response->headers->set('ETag', Floor::versionEtag((int) $data['version']));

        return $response;
    }
}
