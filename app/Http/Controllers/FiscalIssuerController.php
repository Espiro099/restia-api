<?php

namespace App\Http\Controllers;

use App\Fiscal\Issuers;
use App\Http\Middleware\AuthorizeAnyBranch;
use App\Http\Middleware\AuthorizeBranch;
use App\Identity\Branches;
use App\Models\FiscalIssuer;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Multipart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Emisores fiscales y su CSD (F-12, F-13). Catálogo global, solo para ADMIN, y cada ADMIN solo ve y cambia los
 * emisores que no usa ninguna sucursal fuera de las suyas (F-16).
 */
class FiscalIssuerController extends Controller
{
    /** @return array<string, list<mixed>> */
    private static function rules(): array
    {
        return [
            'legalName' => ['string', 'min:1', 'max:300'],
            'taxRegime' => ['string', 'regex:/^[0-9]{3}$/'],
            'postalCode' => ['string', 'regex:/^[0-9]{5}$/'],
            'globalPeriodicity' => ['string', Rule::in(FiscalIssuer::PERIODICITIES)],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $query = FiscalIssuer::query()->whereNotIn('_id', Issuers::outOfReach(AuthorizeAnyBranch::branches($request)))->orderBy('rfc');
        $total = (clone $query)->count();
        $rows = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data($rows->map(fn ($i) => Issuers::present($i))->values()->all(), meta: Api::pagination($page, $perPage, $total));
    }

    /** Sin Idempotency-Key: el RFC único evita duplicados. */
    public function create(Request $request): JsonResponse
    {
        $rules = array_map(fn ($r) => ['required', ...$r], self::rules());
        $rules['globalPeriodicity'][0] = 'sometimes';
        $in = Api::body($request, ['rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/u']] + $rules);

        return $this->etagged(Issuers::create($request->user(), $in), 201);
    }

    public function show(Request $request, string $fiscalIssuerId): JsonResponse
    {
        return $this->etagged($this->find($request, $fiscalIssuerId));
    }

    public function update(Request $request, string $fiscalIssuerId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, array_map(fn ($r) => ['sometimes', ...$r], self::rules()));
        if (! $in) {
            throw ValidationException::withMessages(['legalName' => 'Indica al menos un campo.']);
        }

        return $this->etagged(Issuers::update($request->user(), $this->find($request, $fiscalIssuerId), $version, $in));
    }

    public function status(Request $request, string $fiscalIssuerId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, [
            'status' => ['required', 'string', Rule::in([FiscalIssuer::ACTIVE, FiscalIssuer::INACTIVE])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->etagged(Issuers::setStatus($request->user(), $this->find($request, $fiscalIssuerId), $version, $in['status'], $in['reason']));
    }

    /** F-12: el contenido va directo al PAC; aquí no se guarda ni se registra. */
    public function csd(Request $request, string $fiscalIssuerId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Multipart::read($request, ['certificate', 'privateKey'], ['password'], 16384, 256);

        return $this->etagged(Issuers::registerCsd($request->user(), $this->find($request, $fiscalIssuerId), $version, $in['certificate'], $in['privateKey'], $in['password']));
    }

    public function assignToBranch(Request $request): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['fiscalIssuerId' => ['required', 'string', 'uuid']]);
        $branch = Issuers::assignToBranch($request->user(), AuthorizeBranch::branch($request), $version, $in['fiscalIssuerId']);
        $response = Api::data(Branches::present($branch));
        $response->headers->set('ETag', Floor::versionEtag((int) $branch->version));

        return $response;
    }

    /** Un emisor fuera del alcance del ADMIN responde igual que uno que no existe (F-16). */
    private function find(Request $request, string $id): FiscalIssuer
    {
        $issuer = FiscalIssuer::query()->where('uuid', Floor::uuid($id))->first();
        if (! $issuer || ! Issuers::inReach($issuer, AuthorizeAnyBranch::branches($request))) {
            throw new NotFoundHttpException;
        }

        return $issuer;
    }

    private function etagged(FiscalIssuer $issuer, int $status = 200): JsonResponse
    {
        $response = Api::data(Issuers::present($issuer), $status);
        $response->headers->set('ETag', Floor::versionEtag((int) $issuer->version));

        return $response;
    }
}
