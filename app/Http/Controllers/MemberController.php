<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthorizeBranch;
use App\Identity\Members;
use App\Identity\MobileAuth;
use App\Models\Membership;
use App\Support\Api;
use App\Support\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Empleados de una sucursal (U-1, U-4, U-5). */
class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $v = Validator::make($request->query(), [
            'role' => ['sometimes', 'string', Rule::in(Membership::ROLES)], 'active' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'page' => ['sometimes'], 'perPage' => ['sometimes'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $active = $request->query('active') === null ? null : in_array($request->query('active'), ['true', '1'], true);
        [$rows, $total] = Members::list(AuthorizeBranch::branch($request), $request->query('role'), $active, $page, $perPage);

        return Api::data($rows->values()->all(), meta: Api::pagination($page, $perPage, $total));
    }

    /** Sin Idempotency-Key: el índice único de membresía evita duplicados. El enlace se muestra una sola vez. */
    public function create(Request $request): JsonResponse
    {
        $in = Api::body($request, [
            'email' => ['required', 'string', 'email', 'max:254'], 'name' => ['required', 'string', 'min:1', 'max:120'],
            'role' => ['required', 'string', Rule::in(Membership::ROLES)],
        ]);
        $branch = AuthorizeBranch::branch($request);
        [$m, $user, $setup] = Members::add($branch, $request->user(), AuthorizeBranch::membership($request), $in);
        $data = ['member' => Members::present($branch, $m, $user)];
        if ($setup) {
            $data += ['setupUrl' => $setup['url'], 'setupExpiresAt' => MobileAuth::time($setup['expiresAt'])];
        }

        return Api::data($data, 201, noStore: true);
    }

    public function show(Request $request, string $branchId, string $userId): JsonResponse
    {
        $branch = AuthorizeBranch::branch($request);
        [$m, $user] = Members::find($branch, Floor::uuid($userId));

        return $this->etagged(Members::present($branch, $m, $user), (int) $m->version);
    }

    public function update(Request $request, string $branchId, string $userId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['role' => ['required', 'string', Rule::in(Membership::ROLES)]]);
        $branch = AuthorizeBranch::branch($request);
        [$m, $user] = Members::find($branch, Floor::uuid($userId));
        $m = Members::changeRole($branch, $request->user(), AuthorizeBranch::membership($request), $m, $user, $version, $in['role']);

        return $this->etagged(Members::present($branch, $m, $user), (int) $m->version);
    }

    public function revoke(Request $request, string $branchId, string $userId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $branch = AuthorizeBranch::branch($request);
        [$m, $user] = Members::find($branch, Floor::uuid($userId));
        $m = Members::revoke($branch, $request->user(), AuthorizeBranch::membership($request), $m, $user, $version, $in['reason']);

        return $this->etagged(Members::present($branch, $m, $user), (int) $m->version);
    }

    private function etagged(array $data, int $version): JsonResponse
    {
        $response = Api::data($data);
        $response->headers->set('ETag', Floor::versionEtag($version));

        return $response;
    }
}
