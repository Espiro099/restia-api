<?php

namespace App\Http\Controllers;

use App\Cash\Cash;
use App\Cash\Summary;
use App\Exceptions\DomainConflict;
use App\Http\Controllers\Concerns\BranchResources;
use App\Http\Middleware\AuthorizeBranch;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\User;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Caja (Decisiones_Cash). Solo gerentes la operan; el mesero, con cash.read_open, ve los turnos abiertos
 * sin montos, para saber dónde cobrar (CA-13).
 */
class CashController extends Controller
{
    use BranchResources;

    private const REASON = ['required', 'string', 'min:3', 'max:500'];

    // ---------------------------------------------------------------- Cajas

    public function registers(Request $request): JsonResponse
    {
        $amounts = $this->canRead($request);

        return $this->list($request, CashRegister::class, ['code'], fn (Branch $b, $rows) => $rows->map(fn ($r) => Cash::presentRegister($b, $r, $amounts))->values()->all());
    }

    public function register(Request $request, string $branchId, string $cashRegisterId): JsonResponse
    {
        $amounts = $this->canRead($request);
        [$branch, $r] = $this->find($request, CashRegister::class, $cashRegisterId);

        return $this->one(Cash::presentRegister($branch, $r, $amounts), $r);
    }

    public function createRegister(Request $request): JsonResponse
    {
        $in = Api::body($request, [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{2,32}$/'],
            'name' => ['required', 'string', 'min:1', 'max:120'],
        ] + $this->money('differenceTolerance', false));
        $branch = AuthorizeBranch::branch($request);
        $r = Cash::createRegister($branch, $request->user(), $in);

        return $this->one(Cash::presentRegister($branch, $r, true), $r, 201);
    }

    public function updateRegister(Request $request, string $branchId, string $cashRegisterId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->patchBody($request, ['name' => ['sometimes', 'string', 'min:1', 'max:120']] + $this->money('differenceTolerance', false));
        [$branch, $r] = $this->find($request, CashRegister::class, $cashRegisterId);
        $r = Cash::updateRegister($branch, $request->user(), $r, $version, $in);

        return $this->one(Cash::presentRegister($branch, $r, true), $r);
    }

    public function registerStatus(Request $request, string $branchId, string $cashRegisterId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->statusBody($request, [CashRegister::ACTIVE, CashRegister::INACTIVE]);
        [$branch, $r] = $this->find($request, CashRegister::class, $cashRegisterId);
        $r = Cash::setRegisterStatus($branch, $request->user(), $r, $version, $in['status'], $in['reason']);

        return $this->one(Cash::presentRegister($branch, $r, true), $r);
    }

    // ---------------------------------------------------------------- Turnos

    /** Con solo cash.read_open, la lista se limita a turnos OPEN y omite los montos. Del más reciente al más antiguo. */
    public function shifts(Request $request): JsonResponse
    {
        $amounts = $this->canRead($request);
        [$page, $perPage] = Api::page($request);
        $v = Validator::make($request->query(), [
            'cashRegisterId' => ['sometimes', 'string', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in([CashShift::OPEN, CashShift::CLOSING, CashShift::CLOSED])],
            'businessDate' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'reviewRequired' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            'page' => ['sometimes'], 'perPage' => ['sometimes'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $q = $v->validated();
        $branch = AuthorizeBranch::branch($request);
        $query = CashShift::query()->forBranch($branch);
        if (! $amounts) {
            $query->where('status', CashShift::OPEN);
        }
        if (isset($q['cashRegisterId'])) {
            $register = CashRegister::query()->forBranch($branch)->where('uuid', strtolower($q['cashRegisterId']))->first();
            $query->whereIn('cash_register_id', $register ? [new ObjectId($register->getKey())] : []);
        }
        foreach (['status' => 'status', 'businessDate' => 'business_date'] as $param => $field) {
            if (isset($q[$param])) {
                $query->where($field, $q[$param]);
            }
        }
        if (isset($q['reviewRequired'])) {
            $query->where('review_required', $q['reviewRequired'] === 'true');
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('opened_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(Cash::presentShifts($branch, $rows, $amounts), meta: Api::pagination($page, $perPage, $total));
    }

    /** Con solo cash.read_open, un turno que no está OPEN responde 404. */
    public function shift(Request $request, string $branchId, string $cashShiftId): JsonResponse
    {
        $amounts = $this->canRead($request);
        [$branch, $s] = $this->find($request, CashShift::class, $cashShiftId);
        if (! $amounts && $s->status !== CashShift::OPEN) {
            throw new NotFoundHttpException;
        }

        return $this->shiftResponse($branch, $s, $amounts);
    }

    public function summary(Request $request, string $branchId, string $cashShiftId): JsonResponse
    {
        [, $s] = $this->find($request, CashShift::class, $cashShiftId);

        return Api::data(Summary::of($s));
    }

    public function open(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'cashRegisterId' => ['required', 'string', 'uuid'],
            'openingDifferenceReason' => ['sometimes', 'string', 'min:3', 'max:500'],
        ] + $this->money('openingAmount', true));
        $branch = AuthorizeBranch::branch($request);
        [$status, $data] = Cash::open($branch, $request->user(), $key, $in, fn (CashShift $s) => Cash::presentShifts($branch, collect([$s]), true)[0]);

        return $this->etag(Api::data($data, $status), $data['version']);
    }

    public function movements(Request $request, string $branchId, string $cashShiftId): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        [$branch, $s] = $this->find($request, CashShift::class, $cashShiftId);
        $query = CashMovement::query()->where('cash_shift_id', new ObjectId($s->getKey()));
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at')->orderBy('_id')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data($rows->map(fn ($m) => $this->movement($branch, $s, $m))->values()->all(), meta: Api::pagination($page, $perPage, $total));
    }

    public function createMovement(Request $request, string $branchId, string $cashShiftId): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'type' => ['required', 'string', Rule::in([CashMovement::DEPOSIT, CashMovement::WITHDRAWAL])],
            'reason' => self::REASON,
        ] + $this->money('amount', true, positive: true));
        [$branch, $s] = $this->find($request, CashShift::class, $cashShiftId);
        [$status, $data] = Cash::move($branch, $request->user(), $s, $key, $in, fn (CashMovement $m) => $this->movement($branch, $s, $m));

        return Api::data($data, $status);
    }

    public function startClosing(Request $request, string $branchId, string $cashShiftId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        Api::body($request, []);
        [$branch, $s] = $this->find($request, CashShift::class, $cashShiftId);

        return $this->shiftResponse($branch, Cash::startClosing($branch, $request->user(), $s, $version), true);
    }

    public function cancelClosing(Request $request, string $branchId, string $cashShiftId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => self::REASON]);
        [$branch, $s] = $this->find($request, CashShift::class, $cashShiftId);

        return $this->shiftResponse($branch, Cash::cancelClosing($branch, $request->user(), $s, $version, $in['reason']), true);
    }

    public function close(Request $request, string $branchId, string $cashShiftId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        $in = Api::body($request, ['differenceReason' => ['sometimes', 'string', 'min:3', 'max:500']]
            + $this->money('countedCash', true) + $this->money('cashLeftInDrawer', true));
        [$branch, $s] = $this->find($request, CashShift::class, $cashShiftId);
        [$status, $data] = Cash::close($branch, $request->user(), $s, $version, $key, $in, fn (CashShift $x) => Cash::presentShifts($branch, collect([$x]), true)[0]);

        return $this->etag(Api::data($data, $status), $data['version']);
    }

    // ---------------------------------------------------------------- Ayudas

    /** cash.read ve montos; cash.read_open, solo turnos abiertos sin montos; sin ninguno, 403. */
    private function canRead(Request $request): bool
    {
        $membership = AuthorizeBranch::membership($request);
        if ($membership->can('cash.read')) {
            return true;
        }
        if ($membership->can('cash.read_open')) {
            return false;
        }
        throw new DomainConflict('FORBIDDEN');
    }

    private function shiftResponse(Branch $branch, CashShift $s, bool $amounts): JsonResponse
    {
        return $this->one(Cash::presentShifts($branch, collect([$s]), $amounts)[0], $s);
    }

    private function etag(JsonResponse $response, int $version): JsonResponse
    {
        $response->headers->set('ETag', Floor::versionEtag($version));

        return $response;
    }

    /** @return array<string, mixed> */
    private function movement(Branch $branch, CashShift $s, CashMovement $m): array
    {
        return [
            'id' => $m->uuid,
            'branchId' => $branch->uuid,
            'cashShiftId' => $s->uuid,
            'type' => $m->type,
            'amount' => Floor::money($m->amount),
            'reason' => $m->reason,
            'createdBy' => User::query()->find((string) $m->created_by)?->uuid,
            'createdAt' => MobileAuth::time($m->created_at),
        ];
    }
}
