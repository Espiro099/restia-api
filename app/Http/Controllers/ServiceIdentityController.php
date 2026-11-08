<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateService;
use App\Http\Middleware\AuthorizeAnyBranch;
use App\Identity\ServiceIdentities;
use App\Models\ServiceIdentity;
use App\Support\Api;
use App\Support\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Identidades de servicio (D-36, sección 11; PR-12): las administra un ADMIN desde el panel Web. */
class ServiceIdentityController extends Controller
{
    private const BRANCH_IDS = [
        'branchIds' => ['required', 'array', 'list', 'min:1', 'max:100'],
        'branchIds.*' => ['string', 'uuid', 'distinct:ignore_case'],
    ];

    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $managed = array_map(fn ($id) => new ObjectId($id), AuthorizeAnyBranch::branches($request));
        $query = ServiceIdentity::query()->whereIn('branch_ids', $managed);
        $total = (clone $query)->count();
        $rows = $query->orderBy('name')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data($rows->map(fn ($s) => ServiceIdentities::present($s))->values()->all(), meta: Api::pagination($page, $perPage, $total));
    }

    public function create(Request $request): JsonResponse
    {
        $in = Api::body($request, [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'type' => ['required', 'string', Rule::in([ServiceIdentity::PRINTING_AGENT, ServiceIdentity::RESERVATION_AI])],
        ] + self::BRANCH_IDS);
        [$s, $token] = ServiceIdentities::create($request->user(), AuthorizeAnyBranch::branches($request), $in);

        return $this->secret($s, $token, 201);
    }

    public function update(Request $request, string $serviceIdentityId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, self::BRANCH_IDS);
        $s = ServiceIdentities::update($request->user(), AuthorizeAnyBranch::branches($request), $this->find($request, $serviceIdentityId), $version, $in['branchIds']);

        return $this->one($s);
    }

    public function rotate(Request $request, string $serviceIdentityId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        Api::body($request, []);
        [$s, $token] = ServiceIdentities::rotate($request->user(), $this->find($request, $serviceIdentityId), $version);

        return $this->secret($s, $token);
    }

    public function revoke(Request $request, string $serviceIdentityId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->one(ServiceIdentities::revoke($request->user(), $this->find($request, $serviceIdentityId), $version, $in['reason']));
    }

    /** La identidad del token con que se llama (ServiceAuth). */
    public function me(Request $request): JsonResponse
    {
        return $this->one(AuthenticateService::identity($request));
    }

    /** Solo se encuentra si comparte alguna sucursal con quien la administra; si no, 404. */
    private function find(Request $request, string $id): ServiceIdentity
    {
        $s = ServiceIdentity::query()->where('uuid', Floor::uuid($id))->first();
        if (! $s || ! ServiceIdentities::visible($s, AuthorizeAnyBranch::branches($request))) {
            throw new NotFoundHttpException;
        }

        return $s;
    }

    private function one(ServiceIdentity $s): JsonResponse
    {
        $response = Api::data(ServiceIdentities::present($s));
        $response->headers->set('ETag', Floor::versionEtag((int) $s->version));

        return $response;
    }

    /** El token sale una sola vez y nunca se guarda en caché (D-36, sección 8). */
    private function secret(ServiceIdentity $s, string $token, int $status = 200): JsonResponse
    {
        $response = Api::data(['identity' => ServiceIdentities::present($s), 'token' => $token], $status, noStore: true);
        $response->headers->set('ETag', Floor::versionEtag((int) $s->version));

        return $response;
    }
}
