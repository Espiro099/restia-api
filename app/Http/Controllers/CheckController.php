<?php

namespace App\Http\Controllers;

use App\Checks\Checks;
use App\Http\Controllers\Concerns\BranchResources;
use App\Http\Middleware\AuthorizeBranch;
use App\Models\Branch;
use App\Models\Check;
use App\Models\TableSession;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/** Cuentas de una sesión (Decisiones_Checks): dividir, solicitar, reabrir, cancelar y cortesías. */
class CheckController extends Controller
{
    use BranchResources;

    private const REASON = ['required', 'string', 'min:3', 'max:500'];

    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $v = Validator::make($request->query(), [
            'sessionId' => ['sometimes', 'string', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in([Check::OPEN, Check::REQUESTED, Check::PARTIALLY_PAID, Check::PAID, Check::CANCELLED, Check::REFUNDED])],
            'businessDate' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'page' => ['sometimes'], 'perPage' => ['sometimes'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $q = $v->validated();
        $branch = AuthorizeBranch::branch($request);
        $query = Check::query()->forBranch($branch);
        if (isset($q['sessionId'])) {
            $session = TableSession::query()->forBranch($branch)->where('uuid', strtolower($q['sessionId']))->first();
            $query->whereIn('session_id', $session ? [new ObjectId($session->getKey())] : []);
        }
        foreach (['status' => 'status', 'businessDate' => 'business_date'] as $param => $field) {
            if (isset($q[$param])) {
                $query->where($field, $q[$param]);
            }
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at')->orderBy('number')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(Checks::present($branch, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function show(Request $request, string $branchId, string $checkId): JsonResponse
    {
        [$branch, $check] = $this->find($request, Check::class, $checkId);

        return $this->checkResponse($branch, $check);
    }

    public function create(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, ['sessionId' => ['required', 'string', 'uuid'], 'label' => ['sometimes', 'nullable', 'string', 'max:60']]);
        [$status, $data] = Checks::create(AuthorizeBranch::branch($request), $request->user(), $key, $in);
        $response = Api::data($data, $status);
        $response->headers->set('ETag', Floor::versionEtag((int) $data['version']));

        return $response;
    }

    public function moveUnits(Request $request, string $branchId, string $checkId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, [
            'unitIds' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'unitIds.*' => ['string', 'uuid', 'distinct:ignore_case'],
            'targetCheckId' => ['required', 'string', 'uuid'],
            'targetCheckVersion' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ]);
        [$branch, $check] = $this->find($request, Check::class, $checkId);

        return $this->checkResponse($branch, Checks::moveUnits($branch, $request->user(), $check, $version, $in));
    }

    public function request(Request $request, string $branchId, string $checkId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        Api::body($request, []);
        [$branch, $check] = $this->find($request, Check::class, $checkId);

        return $this->checkResponse($branch, Checks::request($branch, $request->user(), $check, $version));
    }

    /** Con checks.reopen directo; sin él, con X-Approval-Token de la acción CHECK_REOPEN (C-9). */
    public function reopen(Request $request, string $branchId, string $checkId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => self::REASON]);
        [$branch, $check] = $this->find($request, Check::class, $checkId);
        $check = Checks::reopen($branch, $request->user(), AuthorizeBranch::membership($request), $check, $version, $in['reason'], $this->token($request));

        return $this->checkResponse($branch, $check);
    }

    public function cancel(Request $request, string $branchId, string $checkId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => self::REASON]);
        [$branch, $check] = $this->find($request, Check::class, $checkId);

        return $this->checkResponse($branch, Checks::cancel($branch, $request->user(), $check, $version, $in['reason']));
    }

    /** Cortesía de una unidad (C-7). El token de CHECK_ADJUSTMENT va ligado a la unidad. */
    public function adjust(Request $request, string $branchId, string $checkId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, [
            'unitId' => ['required', 'string', 'uuid'],
            'type' => ['required', 'string', Rule::in(['COURTESY'])],
            'reason' => self::REASON,
        ]);
        [$branch, $check] = $this->find($request, Check::class, $checkId);
        $check = Checks::courtesy($branch, $request->user(), AuthorizeBranch::membership($request), $check, $version, $in['unitId'], $in['reason'], $this->token($request));

        return $this->checkResponse($branch, $check, 201);
    }

    /** El token de CHECK_ADJUSTMENT va ligado a la cortesía que se revierte. */
    public function revert(Request $request, string $branchId, string $checkId, string $adjustmentId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => self::REASON]);
        [$branch, $check] = $this->find($request, Check::class, $checkId);
        $check = Checks::revertCourtesy($branch, $request->user(), AuthorizeBranch::membership($request), $check, $version, $adjustmentId, $in['reason'], $this->token($request));

        return $this->checkResponse($branch, $check);
    }

    private function token(Request $request): ?string
    {
        return $request->headers->get('X-Approval-Token');
    }

    private function checkResponse(Branch $branch, Check $check, int $status = 200): JsonResponse
    {
        return $this->one(Checks::present($branch, collect([$check]))[0], $check, $status);
    }
}
