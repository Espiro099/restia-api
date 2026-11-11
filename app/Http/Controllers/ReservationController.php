<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthorizeBranch;
use App\Models\Membership;
use App\Models\Reservation;
use App\Reservations\Availability;
use App\Reservations\Reservations;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Reservas del personal (R-1 a R-12). Las de `reservation-ai` y `policy` llegan con el módulo de IA. */
class ReservationController extends Controller
{
    private const PHONE = 'regex:/^\+[1-9][0-9]{7,14}$/';

    private const STATUSES = ['PENDING', 'CONFIRMED', 'ARRIVED_WAITING', 'CHECKED_IN', 'COMPLETED', 'CANCELLED', 'NO_SHOW'];

    public function availability(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'startAt' => ['required', 'date'], 'endAt' => ['required', 'date'], 'partySize' => ['required', 'integer', 'min:1', 'max:200'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $branch = AuthorizeBranch::branch($request);
        $start = Carbon::parse($request->query('startAt'))->utc();
        $end = Carbon::parse($request->query('endAt'))->utc();
        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['endAt' => 'Debe ser posterior a startAt.']);
        }
        $party = (int) $request->query('partySize');
        $options = Availability::options($branch, $start, $end, $party);

        return Api::data([
            'available' => $options !== [],
            'tableOptions' => Reservations::presentOptions($options),
            'alternativeStartTimes' => $options ? [] : Availability::alternatives($branch, $start, $end, $party),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $v = Validator::make($request->query(), [
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)], 'from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date'],
            'page' => ['sometimes'], 'perPage' => ['sometimes'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $branch = AuthorizeBranch::branch($request);
        $query = Reservation::query()->forBranch($branch);
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('from')) {
            $query->where('end_at', '>', Carbon::parse($request->query('from')));
        }
        if ($request->query('to')) {
            $query->where('start_at', '<', Carbon::parse($request->query('to')));
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('start_at')->orderBy('_id')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data($rows->map(fn ($r) => Reservations::present($branch, $r))->values()->all(), meta: Api::pagination($page, $perPage, $total));
    }

    public function create(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'startAt' => ['required', 'string', 'date'], 'endAt' => ['required', 'string', 'date'],
            'partySize' => ['required', 'integer', 'min:1', 'max:200'],
            'customer' => ['required', 'array:customerId,name,phone'],
            'customer.customerId' => ['sometimes', 'nullable', 'string', 'uuid'],
            'customer.name' => ['required', 'string', 'min:1', 'max:120'],
            'customer.phone' => ['required', 'string', self::PHONE],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $branch = AuthorizeBranch::branch($request);
        [$status, $data] = Reservations::create($branch, $request->user(), $key, $in, fn (Reservation $r) => Reservations::present($branch, $r));

        return Api::data($data, $status);
    }

    public function show(Request $request, string $branchId, string $reservationId): JsonResponse
    {
        $branch = AuthorizeBranch::branch($request);
        $r = $this->find($request, $reservationId);

        return $this->etagged(Reservations::present($branch, $r), (int) $r->version);
    }

    public function update(Request $request, string $branchId, string $reservationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'startAt' => ['sometimes', 'string', 'date'], 'endAt' => ['sometimes', 'string', 'date'],
            'partySize' => ['sometimes', 'integer', 'min:1', 'max:200'], 'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'plannedTableIds' => ['sometimes', 'array', 'list', 'min:1', 'max:3', 'distinct'], 'plannedTableIds.*' => ['string', 'uuid'],
        ]);
        if (! $in) {
            throw ValidationException::withMessages(['startAt' => 'Indica al menos un campo.']);
        }

        return $this->run($request, $reservationId, fn ($branch, $r, $present) => Reservations::update($branch, $request->user(), $r, $version, $key, $in, $present));
    }

    public function confirm(Request $request, string $branchId, string $reservationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        // `verification` es para reservation-ai (IA-4); el personal no la necesita.
        $in = Api::body($request, ['verification' => ['sometimes', 'array:confirmationCode,phone']]);

        return $this->run($request, $reservationId, fn ($branch, $r, $present) => Reservations::confirm($branch, $request->user(), $r, $version, $key, $in, $present));
    }

    public function arrive(Request $request, string $branchId, string $reservationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);

        return $this->run($request, $reservationId, fn ($branch, $r, $present) => Reservations::arrive($branch, $request->user(), $r, $version, $key, $present));
    }

    public function checkIn(Request $request, string $branchId, string $reservationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'tableIds' => ['required', 'array', 'list', 'min:1', 'max:20', 'distinct'], 'tableIds.*' => ['string', 'uuid'],
            'guestCount' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'overridePriority' => ['sometimes', 'boolean'], 'overrideReason' => ['sometimes', 'string', 'min:3', 'max:500'],
        ]);
        $canOverride = $this->membership($request)?->can('reservations.override_priority') ?? false;

        return $this->run($request, $reservationId, fn ($branch, $r, $present) => Reservations::checkIn($branch, $request->user(), $canOverride, $r, $version, $key, $in, $present));
    }

    public function cancel(Request $request, string $branchId, string $reservationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500'], 'verification' => ['sometimes', 'array:confirmationCode,phone']]);

        return $this->run($request, $reservationId, fn ($branch, $r, $present) => Reservations::cancel($branch, $request->user(), $r, $version, $key, ['reason' => $in['reason']], $present));
    }

    public function noShow(Request $request, string $branchId, string $reservationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);

        return $this->run($request, $reservationId, fn ($branch, $r, $present) => Reservations::noShow($branch, $request->user(), $r, $version, $key, $present));
    }

    public function queue(Request $request): JsonResponse
    {
        return Api::data(Reservations::queue(AuthorizeBranch::branch($request)));
    }

    public function lookup(Request $request): JsonResponse
    {
        $in = Api::body($request, ['confirmationCode' => ['required', 'string', 'min:6', 'max:16'], 'phone' => ['required', 'string', self::PHONE]]);
        $branch = AuthorizeBranch::branch($request);
        $r = Reservations::lookup($branch, $request->user(), $in['confirmationCode'], $in['phone']) ?? throw new NotFoundHttpException;

        return Api::data(Reservations::present($branch, $r));
    }

    private function run(Request $request, string $reservationId, \Closure $operation): JsonResponse
    {
        $branch = AuthorizeBranch::branch($request);
        [$status, $data] = $operation($branch, $this->find($request, $reservationId), fn (Reservation $r) => Reservations::present($branch, $r));

        return $this->etagged($data, (int) $data['version'], $status);
    }

    private function find(Request $request, string $reservationId): Reservation
    {
        return Reservation::query()->forBranch(AuthorizeBranch::branch($request))->where('uuid', Floor::uuid($reservationId))->first()
            ?? throw new NotFoundHttpException;
    }

    private function membership(Request $request): ?Membership
    {
        return Membership::query()->where('user_id', new ObjectId($request->user()->getKey()))
            ->where('branch_id', new ObjectId(AuthorizeBranch::branch($request)->getKey()))->where('status', Membership::ACTIVE)->first();
    }

    private function etagged(array $data, int $version, int $status = 200): JsonResponse
    {
        $response = Api::data($data, $status);
        $response->headers->set('ETag', Floor::versionEtag($version));

        return $response;
    }
}
