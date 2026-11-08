<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainConflict;
use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permiso sobre un recurso global, como las identidades de servicio: basta tenerlo en alguna sucursal
 * con membresía activa. Deja en la petición las sucursales donde se tiene, porque el recurso solo se
 * puede ver y cambiar en esas. Uso: `->middleware('anybranch:service_identities.manage')`.
 */
class AuthorizeAnyBranch
{
    public const BRANCHES = 'permitted_branches';

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $branches = Membership::query()
            ->where('user_id', new ObjectId($request->user()->getKey()))
            ->where('status', Membership::ACTIVE)
            ->get()
            ->filter(fn (Membership $m) => $m->can($permission))
            ->map(fn (Membership $m) => (string) $m->branch_id)
            ->unique()->values()->all();
        if (! $branches) {
            throw new DomainConflict('FORBIDDEN');
        }
        $request->attributes->set(self::BRANCHES, $branches);

        return $next($request);
    }

    /** @return list<string> ObjectId de cada sucursal, como texto */
    public static function branches(Request $request): array
    {
        return $request->attributes->get(self::BRANCHES);
    }
}
