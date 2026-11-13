<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\AuthorizeBranch;
use App\Identity\Branches;
use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Membership;
use App\Support\Api;
use App\Support\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/** Sucursales (Decisiones_Branches, B-2). */
class BranchController extends Controller
{
    private const HOUR = 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    /** @return array<string, list<mixed>> */
    private static function rules(): array
    {
        return [
            'name' => ['string', 'min:1', 'max:120'],
            'timezone' => ['string', 'max:64', 'timezone:all'],
            'businessDayCutoff' => ['string', self::HOUR],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['array:line1,line2,city,state,postalCode,country'],
            'address.line1' => ['required_with:address', 'string', 'min:1', 'max:200'],
            'address.line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address.city' => ['required_with:address', 'string', 'min:1', 'max:100'],
            'address.state' => ['required_with:address', 'string', 'min:1', 'max:100'],
            'address.postalCode' => ['required_with:address', 'string', 'min:1', 'max:16'],
            'address.country' => ['required_with:address', 'string', 'regex:/^[A-Z]{2}$/'],
            'reservationHours' => ['array', 'list', 'max:21'],
            'reservationHours.*' => ['array:dayOfWeek,opensAt,closesAt'],
            'reservationHours.*.dayOfWeek' => ['required', 'integer', 'min:1', 'max:7'],
            'reservationHours.*.opensAt' => ['required', 'string', self::HOUR],
            'reservationHours.*.closesAt' => ['required', 'string', self::HOUR],
            'reservationNoShowToleranceMinutes' => ['integer', 'min:0', 'max:240'],
            'reservationAiEnabled' => ['boolean'],
            'reservationAiMaxPartySize' => ['integer', 'min:1', 'max:200'],
        ];
    }

    /** Las de membresía activa; en Android, solo la sucursal del dispositivo. */
    public function index(Request $request): JsonResponse
    {
        $ids = Membership::query()->where('user_id', new ObjectId($request->user()->getKey()))->where('status', Membership::ACTIVE)
            ->pluck('branch_id')->map(fn ($id) => (string) $id)->all();
        $session = AuthenticateSession::session($request);
        if ($session->client_type === AuthSession::ANDROID) {
            $device = Device::query()->find((string) $session->device_id);
            $ids = array_values(array_intersect($ids, [(string) $device?->branch_id]));
        }
        $rows = Branch::query()->whereIn('_id', array_map(fn ($id) => new ObjectId($id), $ids))->orderBy('code')->get();

        return Api::data($rows->map(fn ($b) => Branches::present($b))->values()->all());
    }

    public function show(Request $request): JsonResponse
    {
        return $this->etagged(AuthorizeBranch::branch($request));
    }

    /** Sin Idempotency-Key: el código único evita duplicados. */
    public function create(Request $request): JsonResponse
    {
        $rules = array_map(fn ($r) => ['sometimes', ...$r], self::rules());
        foreach (['name', 'timezone', 'businessDayCutoff', 'address'] as $required) {
            $rules[$required] = ['required', ...self::rules()[$required]];
        }
        $in = Api::body($request, ['code' => ['required', 'string', 'regex:/^[A-Z0-9-]{2,16}$/'], 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/']] + $rules);
        $branch = Branches::create($request->user(), $in);

        return $this->etagged($branch, 201);
    }

    public function update(Request $request): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, array_map(fn ($r) => ['sometimes', ...$r], self::rules()));
        if (! $in) {
            throw ValidationException::withMessages(['name' => 'Indica al menos un campo.']);
        }

        return $this->etagged(Branches::update($request->user(), AuthorizeBranch::branch($request), $version, $in));
    }

    public function deactivate(Request $request): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->etagged(Branches::deactivate($request->user(), AuthorizeBranch::branch($request), $version, $in['reason']));
    }

    public function activate(Request $request): JsonResponse
    {
        $version = Floor::ifMatch($request);
        Api::body($request, []);

        return $this->etagged(Branches::activate($request->user(), AuthorizeBranch::branch($request), $version));
    }

    private function etagged(Branch $branch, int $status = 200): JsonResponse
    {
        $response = Api::data(Branches::present($branch), $status);
        $response->headers->set('ETag', Floor::versionEtag((int) $branch->version));

        return $response;
    }
}
