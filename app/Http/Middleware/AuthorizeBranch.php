<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Autorización por sucursal (D-12): sucursal de la ruta → dispositivo de esa sucursal (A-6) →
 * membresía activa → permiso del rol. Uso: `->middleware('branch:devices.read')`.
 * Va después de AuthenticateSession.
 */
class AuthorizeBranch
{
    public const BRANCH = 'branch';

    public const MEMBERSHIP = 'membership';

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $id = (string) $request->route('branchId');
        $branch = Str::isUuid($id) ? Branch::query()->where('uuid', strtolower($id))->first() : null;
        if (! $branch) {
            throw new NotFoundHttpException;
        }

        $device = AuthenticateSession::device($request);
        if ($device && (string) $device->branch_id !== (string) $branch->getKey()) {
            throw new DomainConflict('DEVICE_BRANCH_MISMATCH');
        }

        $membership = Membership::query()
            ->where('user_id', new ObjectId($request->user()->getKey()))
            ->where('branch_id', new ObjectId($branch->getKey()))
            ->where('status', Membership::ACTIVE)
            ->first();
        if (! $membership) {
            throw new DomainConflict('BRANCH_ACCESS_DENIED');
        }
        if ($permission !== null && ! $membership->can($permission)) {
            throw new DomainConflict('FORBIDDEN');
        }

        $request->attributes->set(self::BRANCH, $branch);
        $request->attributes->set(self::MEMBERSHIP, $membership);

        return $next($request);
    }

    public static function branch(Request $request): Branch
    {
        return $request->attributes->get(self::BRANCH);
    }

    public static function membership(Request $request): Membership
    {
        return $request->attributes->get(self::MEMBERSHIP);
    }
}
