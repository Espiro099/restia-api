<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateSession;
use App\Identity\MobileAuth;
use App\Identity\Sessions;
use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Membership;
use App\Support\Api;
use App\Support\Audit;
use App\Support\Mongo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** La cuenta propia: /auth/logout, /auth/me, /auth/sessions y /auth/sessions/{id}/revoke. */
class AccountController extends Controller
{
    public function logout(Request $request): Response
    {
        $session = AuthenticateSession::session($request);
        Mongo::transaction(function () use ($request, $session) {
            $actor = new ObjectId($request->user()->getKey());
            if (Sessions::revoke($session, 'LOGOUT', $actor, self::branchOf($request))) {
                Audit::record('auth.logout', 'auth_session', $session->getKey(), self::branchOf($request), $actor);
            }
        });

        return Api::noContent();
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = Membership::query()
            ->where('user_id', new ObjectId($user->getKey()))
            ->where('status', Membership::ACTIVE)
            ->get();
        $branches = Branch::query()
            ->whereIn('_id', $memberships->map(fn ($m) => new ObjectId($m->branch_id))->all())
            ->where('status', Branch::ACTIVE)
            ->get()->keyBy(fn ($b) => (string) $b->getKey());

        $data = $memberships
            ->filter(fn ($m) => $branches->has((string) $m->branch_id))
            ->sortBy(fn ($m) => $branches[(string) $m->branch_id]->name)
            ->map(fn ($m) => [
                'branch' => ['id' => $branches[(string) $m->branch_id]->uuid, 'name' => $branches[(string) $m->branch_id]->name],
                'role' => $m->role,
                'permissions' => $m->permissions(),
            ])->values()->all();

        $current = AuthenticateSession::session($request);

        return Api::data([
            'user' => ['id' => $user->uuid, 'name' => $user->name, 'email' => $user->email],
            'memberships' => $data,
            'session' => self::present($current, $current),
        ]);
    }

    public function sessions(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $filter = $request->validate(['status' => ['sometimes', 'string', Rule::in(['ACTIVE', 'REVOKED', 'EXPIRED'])]]);
        $now = now();
        $query = AuthSession::query()->where('user_id', new ObjectId($request->user()->getKey()));
        // EXPIRED no se guarda: es una sesión ACTIVE con algún vencimiento pasado.
        match ($filter['status'] ?? null) {
            'ACTIVE' => $query->where('status', 'ACTIVE')->where('absolute_expires_at', '>', $now)
                ->where(fn ($q) => $q->where('client_type', 'WEB')->orWhere('refresh_expires_at', '>', $now)),
            'EXPIRED' => $query->where('status', 'ACTIVE')->where(fn ($q) => $q->where('absolute_expires_at', '<=', $now)
                ->orWhere(fn ($q) => $q->where('client_type', 'ANDROID')->where('refresh_expires_at', '<=', $now))),
            'REVOKED' => $query->where('status', 'REVOKED'),
            default => null,
        };
        $total = (clone $query)->count();
        $current = AuthenticateSession::session($request);
        $items = $query->orderBy('created_at', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();
        $devices = Device::query()->whereIn('_id', $items->pluck('device_id')->filter()->map(fn ($id) => new ObjectId($id))->values()->all())
            ->get()->keyBy(fn ($d) => (string) $d->getKey());

        return Api::data(
            $items->map(fn ($s) => self::present($s, $current, $devices[(string) $s->device_id] ?? null))->all(),
            meta: Api::pagination($page, $perPage, $total),
        );
    }

    public function revoke(Request $request, string $sessionId): Response
    {
        // Una sesión ajena responde igual que una inexistente.
        $session = Str::isUuid($sessionId)
            ? AuthSession::query()->where('uuid', strtolower($sessionId))->where('user_id', new ObjectId($request->user()->getKey()))->first()
            : null;
        if (! $session) {
            throw new NotFoundHttpException;
        }
        Mongo::transaction(fn () => Sessions::revoke($session, 'LOGOUT', new ObjectId($request->user()->getKey()), self::branchOf($request)));

        return Api::noContent();
    }

    /** @return array<string, mixed> */
    public static function present(AuthSession $s, AuthSession $current, ?Device $device = null): array
    {
        if ($s->device_id && ! $device) {
            $device = Device::query()->find($s->device_id);
        }

        return [
            'id' => $s->uuid,
            'clientType' => $s->client_type,
            'status' => $s->publicStatus(),
            'current' => $s->uuid === $current->uuid,
            'deviceId' => $device?->uuid,
            'createdAt' => MobileAuth::time($s->created_at),
            'lastActivityAt' => MobileAuth::time($s->last_activity_at),
            'absoluteExpiresAt' => MobileAuth::time($s->absolute_expires_at),
            'revokedAt' => MobileAuth::time($s->revoked_at),
        ];
    }

    private static function branchOf(Request $request): ?ObjectId
    {
        $device = AuthenticateSession::device($request);

        return $device ? new ObjectId($device->branch_id) : null;
    }
}
