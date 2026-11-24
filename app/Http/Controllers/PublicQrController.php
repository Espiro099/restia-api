<?php

namespace App\Http\Controllers;

use App\Floor\Menu;
use App\Http\Middleware\AuthorizeBranch;
use App\Http\Middleware\PublicQrToken;
use App\Models\RestaurantTable;
use App\PublicQr\PublicQr;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Throttle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Public QR: página del cliente (sin cuenta) y consulta y rotación del token por el personal (QR-12). */
class PublicQrController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        return Api::data(PublicQr::context($request->attributes->get(PublicQrToken::TABLE), $request->attributes->get(PublicQrToken::BRANCH)));
    }

    /** ETag por versión del menú: no depende de la mesa (QR-7). */
    public function menu(Request $request): Response
    {
        $branch = $request->attributes->get(PublicQrToken::BRANCH);

        return Floor::withEtag($request, PublicQr::menu($branch), etag: Floor::versionEtag(Menu::version($branch)));
    }

    public function presence(Request $request): Response
    {
        $table = $request->attributes->get(PublicQrToken::TABLE);
        Throttle::hit('qr-presence|'.$table->getKey(), config('restia.public.limits.presence_per_token'));
        PublicQr::presence($table);

        return new Response('', 204);
    }

    // --- Personal ---

    public function show(Request $request, string $branchId, string $tableId): JsonResponse
    {
        return $this->staff(PublicQr::ensureToken($this->table($request, $tableId)));
    }

    public function rotate(Request $request, string $branchId, string $tableId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $table = PublicQr::rotate(AuthorizeBranch::branch($request), $request->user(), $this->table($request, $tableId), $version, $in['reason']);

        return $this->staff($table);
    }

    private function table(Request $request, string $tableId): RestaurantTable
    {
        return RestaurantTable::query()->forBranch(AuthorizeBranch::branch($request))->where('uuid', Floor::uuid($tableId))->first()
            ?? throw new NotFoundHttpException;
    }

    /** El token se puede imprimir otra vez, pero no debe quedar en cachés intermedias. */
    private function staff(RestaurantTable $table): JsonResponse
    {
        $response = Api::data(PublicQr::staffView($table));
        $response->headers->set('ETag', Floor::versionEtag((int) $table->version));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
