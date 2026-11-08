<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthorizeBranch;
use App\Identity\Devices;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\Device;
use App\Support\Api;
use App\Support\Throttle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** /branches/{branchId}/devices: códigos de alta, lista, bloqueo y retiro. */
class DeviceController extends Controller
{
    public function enrollmentCode(Request $request): JsonResponse
    {
        Throttle::hit('enrollment-codes|'.$request->user()->getKey(), config('restia.auth.limits.enrollment_codes_per_user'));
        $code = MobileAuth::createEnrollmentCode(AuthorizeBranch::branch($request), $request->user());

        return Api::data(['code' => $code['code'], 'expiresAt' => MobileAuth::time($code['expiresAt'])], 201, noStore: true);
    }

    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $filter = $request->validate(['status' => ['sometimes', 'string', Rule::in([Device::ACTIVE, Device::BLOCKED, Device::RETIRED])]]);
        $query = Device::query()->forBranch(AuthorizeBranch::branch($request));
        if (isset($filter['status'])) {
            $query->where('status', $filter['status']);
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('enrolled_at', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        $branch = AuthorizeBranch::branch($request)->uuid;

        return Api::data($items->map(fn ($d) => self::present($d, $branch))->all(), meta: Api::pagination($page, $perPage, $total));
    }

    public function block(Request $request, string $branchId, string $deviceId): Response
    {
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);
        Devices::block(AuthorizeBranch::branch($request), $this->device($request, $deviceId), $request->user(), $in['reason']);

        return Api::noContent();
    }

    public function retire(Request $request, string $branchId, string $deviceId): Response
    {
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);
        Devices::retire(AuthorizeBranch::branch($request), $this->device($request, $deviceId), $request->user(), $in['reason']);

        return Api::noContent();
    }

    /** Un dispositivo de otra sucursal responde 404, igual que uno inexistente. */
    private function device(Request $request, string $id): Device
    {
        $device = Str::isUuid($id)
            ? Device::query()->forBranch(AuthorizeBranch::branch($request))->where('uuid', strtolower($id))->first()
            : null;

        return $device ?? throw new NotFoundHttpException;
    }

    /** @return array<string, mixed> */
    public static function present(Device $d, ?string $branchUuid = null): array
    {
        return [
            'id' => $d->uuid,
            'branchId' => $branchUuid ?? Branch::query()->find($d->branch_id)?->uuid,
            'type' => $d->type,
            'status' => $d->status,
            'displayName' => $d->display_name,
            'enrolledAt' => MobileAuth::time($d->enrolled_at),
            'lastSeenAt' => MobileAuth::time($d->last_seen_at),
            'version' => (int) $d->version,
        ];
    }
}
