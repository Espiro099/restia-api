<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BranchResources;
use App\Http\Middleware\AuthorizeBranch;
use App\Models\CashShift;
use App\Models\Check;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Payments;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/** Pagos y reembolsos (Decisiones_Payments). Nunca se aceptan número de tarjeta ni CVV (P-10). */
class PaymentController extends Controller
{
    use BranchResources;

    private const METHODS = ['CASH', 'CARD_CREDIT', 'CARD_DEBIT', 'TRANSFER'];

    private const REFERENCE = ['sometimes', 'string', 'min:1', 'max:120'];

    // ---------------------------------------------------------------- Pagos

    public function index(Request $request): JsonResponse
    {
        [$page, $perPage, $branch, $query] = $this->filtered($request, Payment::class, [
            'checkId' => ['check_id', Check::class], 'cashShiftId' => ['cash_shift_id', CashShift::class],
        ], [Payment::PENDING, Payment::CONFIRMED, Payment::FAILED, Payment::CANCELLED, Payment::UNKNOWN]);
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(Payments::present($branch, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function show(Request $request, string $branchId, string $paymentId): JsonResponse
    {
        [$branch, $p] = $this->find($request, Payment::class, $paymentId);

        return $this->one(Payments::present($branch, collect([$p]))[0], $p);
    }

    /** CASH se confirma al crear; los demás métodos quedan PENDING hasta registrar el resultado. */
    public function create(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'checkId' => ['required', 'string', 'uuid'],
            'cashShiftId' => ['required', 'string', 'uuid'],
            'method' => ['required', 'string', Rule::in(self::METHODS)],
        ] + $this->money('amount', true, positive: true) + $this->money('tip', false) + $this->money('amountTendered', false, positive: true));
        [$status, $data] = Payments::create(AuthorizeBranch::branch($request), $request->user(), $key, $in);

        return $this->versioned($data, $status);
    }

    public function result(Request $request, string $branchId, string $paymentId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'outcome' => ['required', 'string', Rule::in([Payment::CONFIRMED, Payment::FAILED, Payment::CANCELLED, Payment::UNKNOWN])],
            'reference' => self::REFERENCE,
            'card' => ['sometimes', 'array:brand,last4,authCode'],
            'card.brand' => ['sometimes', 'string', Rule::in(['VISA', 'MASTERCARD', 'AMEX', 'OTHER'])],
            'card.last4' => ['sometimes', 'string', 'regex:/^[0-9]{4}$/'],
            'card.authCode' => ['sometimes', 'string', 'min:1', 'max:32'],
            'note' => ['sometimes', 'string', 'max:500'],
        ]);
        [$branch, $p] = $this->find($request, Payment::class, $paymentId);
        [$status, $data] = Payments::result($branch, $request->user(), $p, $version, $key, $in);

        return $this->versioned($data, $status);
    }

    public function reconcile(Request $request, string $branchId, string $paymentId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        $in = Api::body($request, $this->reconcileRules());
        [$branch, $p] = $this->find($request, Payment::class, $paymentId);
        [$status, $data] = Payments::reconcile($branch, $request->user(), $p, $version, $key, $in);

        return $this->versioned($data, $status);
    }

    // ---------------------------------------------------------------- Reembolsos

    public function refunds(Request $request): JsonResponse
    {
        [$page, $perPage, $branch, $query] = $this->filtered($request, Refund::class, [
            'paymentId' => ['payment_id', Payment::class], 'checkId' => ['check_id', Check::class],
        ], [Refund::PENDING, Refund::CONFIRMED, Refund::FAILED, Refund::UNKNOWN]);
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(Payments::presentRefunds($branch, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function refund(Request $request, string $branchId, string $refundId): JsonResponse
    {
        [$branch, $r] = $this->find($request, Refund::class, $refundId);

        return $this->one(Payments::presentRefunds($branch, collect([$r]))[0], $r);
    }

    /** Devuelve monto y propina por el mismo método del pago. No restaura inventario. */
    public function createRefund(Request $request, string $branchId, string $paymentId): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, ['cashShiftId' => ['required', 'string', 'uuid'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        [$branch, $p] = $this->find($request, Payment::class, $paymentId);
        [$status, $data] = Payments::refund($branch, $request->user(), $p, $key, $in);

        return $this->versioned($data, $status);
    }

    public function refundResult(Request $request, string $branchId, string $refundId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'outcome' => ['required', 'string', Rule::in([Refund::CONFIRMED, Refund::FAILED, Refund::UNKNOWN])],
            'reference' => self::REFERENCE,
            'note' => ['sometimes', 'string', 'max:500'],
        ]);
        [$branch, $r] = $this->find($request, Refund::class, $refundId);
        [$status, $data] = Payments::refundResult($branch, $request->user(), $r, $version, $key, $in);

        return $this->versioned($data, $status);
    }

    public function refundReconcile(Request $request, string $branchId, string $refundId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        $in = Api::body($request, $this->reconcileRules());
        [$branch, $r] = $this->find($request, Refund::class, $refundId);
        [$status, $data] = Payments::refundReconcile($branch, $request->user(), $r, $version, $key, $in);

        return $this->versioned($data, $status);
    }

    // ---------------------------------------------------------------- Ayudas

    private function reconcileRules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in(['CONFIRMED', 'FAILED'])],
            'reference' => self::REFERENCE,
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /** Con Idempotency-Key, la repetición devuelve la respuesta original con su versión. */
    private function versioned(array $data, int $status): JsonResponse
    {
        $response = Api::data($data, $status);
        $response->headers->set('ETag', Floor::versionEtag((int) $data['version']));

        return $response;
    }

    /**
     * Lista filtrada por referencias públicas y estado.
     *
     * @param  array<string, array{0: string, 1: class-string}>  $refs  parámetro → [campo, modelo]
     */
    private function filtered(Request $request, string $model, array $refs, array $states): array
    {
        [$page, $perPage] = Api::page($request);
        $rules = ['status' => ['sometimes', 'string', Rule::in($states)], 'page' => ['sometimes'], 'perPage' => ['sometimes']];
        foreach (array_keys($refs) as $param) {
            $rules[$param] = ['sometimes', 'string', 'uuid'];
        }
        $v = Validator::make($request->query(), $rules);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $q = $v->validated();
        $branch = AuthorizeBranch::branch($request);
        $query = $model::query()->forBranch($branch);
        foreach ($refs as $param => [$field, $refModel]) {
            if (isset($q[$param])) {
                $doc = $refModel::query()->forBranch($branch)->where('uuid', strtolower($q[$param]))->first();
                $query->whereIn($field, $doc ? [new ObjectId($doc->getKey())] : []);
            }
        }
        if (isset($q['status'])) {
            $query->where('status', $q['status']);
        }

        return [$page, $perPage, $branch, $query];
    }
}
