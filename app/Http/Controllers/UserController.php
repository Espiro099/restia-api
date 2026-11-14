<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\AuthorizeAnyBranch;
use App\Identity\Members;
use App\Identity\MobileAuth;
use App\Identity\Passwords;
use App\Models\Membership;
use App\Models\User;
use App\Support\Api;
use App\Support\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Usuario global (U-2, U-5, A-10) y contraseñas. */
class UserController extends Controller
{
    public function setupLink(Request $request, string $userId): JsonResponse
    {
        $user = $this->find($request, $userId);
        Members::assertAdminOfAll($request->user(), $user);
        $link = Passwords::setupLink($request->user(), $user);

        return Api::data(['setupUrl' => $link['url'], 'expiresAt' => MobileAuth::time($link['expiresAt'])], 201, noStore: true);
    }

    public function deactivate(Request $request, string $userId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->etagged(Members::deactivate($request->user(), $this->find($request, $userId), $version, $in['reason']));
    }

    public function activate(Request $request, string $userId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        Api::body($request, []);

        return $this->etagged(Members::activate($request->user(), $this->find($request, $userId), $version));
    }

    /** A-10: basta el permiso en una sucursal donde el usuario tenga membresía. */
    public function revokeSessions(Request $request, string $userId): Response
    {
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $user = $this->find($request, $userId);
        Members::revokeSessions($request->user(), $user, $in['reason']);

        return response()->noContent();
    }

    public function changePassword(Request $request): Response
    {
        $in = Api::body($request, ['currentPassword' => ['required', 'string', 'max:128'], 'newPassword' => ['required', 'string', 'max:128']]);
        Passwords::change($request->user(), AuthenticateSession::session($request), $in['currentPassword'], $in['newPassword']);

        return response()->noContent();
    }

    /** Siempre 204, exista o no el correo. */
    public function forgotPassword(Request $request): Response
    {
        $in = Api::body($request, ['email' => ['required', 'string', 'email', 'max:254']]);
        Passwords::forgot($in['email'], (string) $request->ip());

        return response()->noContent();
    }

    public function resetPassword(Request $request): Response
    {
        $in = Api::body($request, ['token' => ['required', 'string', 'min:32', 'max:256'], 'newPassword' => ['required', 'string', 'max:128']]);
        Passwords::reset($in['token'], $in['newPassword'], (string) $request->ip());

        return response()->noContent();
    }

    /**
     * Solo usuarios con membresía en alguna sucursal donde el actor tiene el permiso de la ruta: los
     * demás responden 404, sin revelar que existen.
     */
    private function find(Request $request, string $userId): User
    {
        $user = User::query()->where('uuid', Floor::uuid($userId))->first() ?? throw new NotFoundHttpException;
        $permitted = array_map(fn ($id) => new ObjectId($id), AuthorizeAnyBranch::branches($request));
        $shared = Membership::query()->where('user_id', new ObjectId($user->getKey()))->whereIn('branch_id', $permitted)->exists();

        return $shared ? $user : throw new NotFoundHttpException;
    }

    private function etagged(User $user): JsonResponse
    {
        $response = Api::data(Members::presentUser($user));
        $response->headers->set('ETag', Floor::versionEtag((int) $user->version));

        return $response;
    }
}
