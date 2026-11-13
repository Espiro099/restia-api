<?php

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Http\Middleware\AuthorizeAnyBranch;
use App\Http\Middleware\AuthorizeBranch;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/** Bitácora de auditoría (AU-1): ADMIN y MANAGER por sucursal; la global, solo ADMIN. */
class AuditController extends Controller
{
    public function branch(Request $request): JsonResponse
    {
        return $this->search($request, [new ObjectId(AuthorizeBranch::branch($request)->getKey())], false);
    }

    /** En las sucursales donde se tiene audit.read_global, más lo que no es de ninguna sucursal. */
    public function global(Request $request): JsonResponse
    {
        $branches = array_map(fn ($id) => new ObjectId($id), AuthorizeAnyBranch::branches($request));

        return $this->search($request, $branches, true);
    }

    /** @param list<ObjectId> $branches */
    private function search(Request $request, array $branches, bool $global): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $v = Validator::make($request->query(), [
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'entityType' => ['sometimes', 'string', 'max:60'],
            'entityId' => ['sometimes', 'string', 'uuid'],
            'actorId' => ['sometimes', 'string', 'uuid'],
            'action' => ['sometimes', 'string', 'max:80'],
            'page' => ['sometimes'], 'perPage' => ['sometimes'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $filter = array_diff_key($v->validated(), ['page' => 0, 'perPage' => 0]);
        [$rows, $total] = AuditLog::search($branches, $global, $filter, $page, $perPage);

        return Api::data($rows, meta: Api::pagination($page, $perPage, $total));
    }
}
