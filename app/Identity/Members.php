<?php

namespace App\Identity;

use App\Exceptions\DomainConflict;
use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Empleados por sucursal y usuarios (Decisiones_Modulos_Restantes, sección 2). ADMIN gestiona todo;
 * MANAGER solo WAITER y KITCHEN; nadie se cambia a sí mismo; cada sucursal conserva un ADMIN (U-4).
 * Las bajas no borran: la membresía queda REVOKED y el usuario INACTIVE (U-5).
 */
final class Members
{
    /** Roles que un MANAGER puede dar, cambiar o revocar. */
    private const MANAGER_ROLES = ['WAITER', 'KITCHEN'];

    public static function presentUser(User $u): array
    {
        return ['id' => $u->uuid, 'email' => $u->email, 'name' => $u->name, 'status' => $u->status, 'version' => (int) $u->version];
    }

    public static function present(Branch $branch, Membership $m, ?User $user = null): array
    {
        $user ??= User::query()->find((string) $m->user_id);

        return [
            'user' => self::presentUser($user),
            'branchId' => $branch->uuid,
            'role' => $m->role,
            'active' => $m->status === Membership::ACTIVE,
            'version' => (int) $m->version,
        ];
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: int} */
    public static function list(Branch $branch, ?string $role, ?bool $active, int $page, int $perPage): array
    {
        $query = Membership::query()->where('branch_id', new ObjectId($branch->getKey()));
        if ($role) {
            $query->where('role', $role);
        }
        if ($active !== null) {
            $query->where('status', $active ? Membership::ACTIVE : Membership::REVOKED);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at')->orderBy('_id')->skip(($page - 1) * $perPage)->take($perPage)->get();
        $users = User::query()->whereIn('_id', $rows->map(fn ($m) => new ObjectId((string) $m->user_id))->all())->get()->keyBy(fn ($u) => (string) $u->getKey());

        return [$rows->map(fn (Membership $m) => self::present($branch, $m, $users->get((string) $m->user_id))), $total];
    }

    /** @return array{0: Membership, 1: User} */
    public static function find(Branch $branch, string $userUuid): array
    {
        $user = User::query()->where('uuid', strtolower($userUuid))->first();
        $m = $user ? Membership::query()->where('branch_id', new ObjectId($branch->getKey()))->where('user_id', new ObjectId($user->getKey()))->first() : null;

        return $m ? [$m, $user] : throw new NotFoundHttpException;
    }

    /**
     * U-1: crea la membresía, y el usuario si su correo no existe (queda INVITED hasta definir su
     * contraseña). El enlace solo se devuelve si esta petición creó al usuario (hallazgo 11). Una
     * membresía revocada se reactiva en el mismo documento.
     *
     * @return array{0: Membership, 1: User, 2: array{url: string, expiresAt: Carbon}|null}
     */
    public static function add(Branch $branch, User $actor, Membership $actorMembership, array $in): array
    {
        self::assertCanManageRole($actorMembership, $in['role']);
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        try {
            return Mongo::transaction(function () use ($branchId, $actorId, $in) {
                Branch::query()->where('_id', $branchId)->increment('operations_epoch');
                if (! Branch::query()->find($branchId)?->isActive()) {
                    throw new DomainConflict('BRANCH_INACTIVE');
                }
                $email = User::normalizeEmail($in['email']);
                $user = User::query()->where('normalized_email', $email)->first();
                $created = false;
                if (! $user) {
                    $user = User::query()->create([
                        'email' => trim($in['email']), 'normalized_email' => $email, 'name' => $in['name'],
                        'password_hash' => null, 'status' => User::INVITED,
                    ]);
                    $created = true;
                }
                $userId = new ObjectId($user->getKey());
                $m = Membership::query()->where('branch_id', $branchId)->where('user_id', $userId)->first();
                if ($m?->status === Membership::ACTIVE) {
                    throw new DomainConflict('MEMBERSHIP_ALREADY_EXISTS');
                }
                if ($m) {
                    Membership::query()->where('_id', new ObjectId($m->getKey()))
                        ->increment('version', 1, ['status' => Membership::ACTIVE, 'role' => $in['role'], 'revoked_at' => null, 'updated_at' => now()]);
                    $m->refresh();
                } else {
                    $m = Membership::query()->create(['user_id' => $userId, 'branch_id' => $branchId, 'role' => $in['role'], 'status' => Membership::ACTIVE]);
                }
                $setup = $created ? Passwords::issue($user, Passwords::SETUP) : null;
                Audit::record('member.created', 'user', $user->getKey(), $branchId, $actorId, changes: ['role' => $in['role'], 'userCreated' => $created]);
                Outbox::publish('member.created', 'user', $user->getKey(), $branchId, ['userId' => $user->uuid, 'role' => $in['role']]);

                return [$m, $user, $setup];
            });
        } catch (Throwable $e) {
            // Dos altas simultáneas del mismo correo o de la misma membresía: la otra ganó.
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('MEMBERSHIP_ALREADY_EXISTS') : $e;
        }
    }

    public static function changeRole(Branch $branch, User $actor, Membership $actorMembership, Membership $m, User $user, int $version, string $role): Membership
    {
        self::assertNotSelf($actor, $user);
        self::assertCanManageRole($actorMembership, $m->role);
        self::assertCanManageRole($actorMembership, $role);

        return self::changeMembership($branch, $actor, $m, $version, function (Membership $fresh) use ($user, $role) {
            if (User::query()->find($user->getKey())->status === User::INACTIVE) {
                throw new DomainConflict('USER_INACTIVE');
            }
            if ($fresh->status !== Membership::ACTIVE) {
                throw new NotFoundHttpException;
            }

            return [['role' => $role], 'member.role_changed', ['before' => ['role' => $fresh->role], 'after' => ['role' => $role]], null, $role !== 'ADMIN'];
        });
    }

    /** Efecto en la siguiente petición: cada una revalida membresía y permiso (D-12). */
    public static function revoke(Branch $branch, User $actor, Membership $actorMembership, Membership $m, User $user, int $version, string $reason): Membership
    {
        self::assertNotSelf($actor, $user);
        self::assertCanManageRole($actorMembership, $m->role);

        return self::changeMembership($branch, $actor, $m, $version, function (Membership $fresh) use ($reason) {
            if ($fresh->status !== Membership::ACTIVE) {
                return [[], null, [], $reason, false];
            }

            return [['status' => Membership::REVOKED, 'revoked_at' => now()], 'member.revoked', ['role' => $fresh->role], $reason, true];
        });
    }

    // --- Usuario global ---

    /**
     * Las operaciones sobre el usuario alcanzan todas sus sucursales: el actor debe ser ADMIN en cada
     * una (A-10). Las sucursales revocadas cuentan, porque el historial sigue ahí.
     */
    public static function assertAdminOfAll(User $actor, User $target): void
    {
        $targetBranches = Membership::query()->where('user_id', new ObjectId($target->getKey()))->pluck('branch_id')->map(fn ($id) => (string) $id)->unique();
        $adminOf = Membership::query()->where('user_id', new ObjectId($actor->getKey()))->where('status', Membership::ACTIVE)->where('role', 'ADMIN')
            ->pluck('branch_id')->map(fn ($id) => (string) $id)->all();
        if ($targetBranches->isEmpty() || $targetBranches->contains(fn ($b) => ! in_array($b, $adminOf, true))) {
            throw new DomainConflict('FORBIDDEN');
        }
    }

    /** U-5: INACTIVE en todas sus sucursales y sin sesiones; nunca el propio ni el último ADMIN de una sucursal. */
    public static function deactivate(User $actor, User $user, int $version, string $reason): User
    {
        self::assertNotSelf($actor, $user);
        self::assertAdminOfAll($actor, $user);

        return self::changeUser($actor, $user, $version, [User::ACTIVE, User::INVITED], User::INACTIVE, 'user.deactivated', $reason, function (ObjectId $userId) {
            foreach (Membership::query()->where('user_id', $userId)->where('status', Membership::ACTIVE)->where('role', 'ADMIN')->get() as $m) {
                self::assertAnotherAdmin(new ObjectId((string) $m->branch_id), $userId);
            }
        });
    }

    public static function activate(User $actor, User $user, int $version): User
    {
        self::assertAdminOfAll($actor, $user);

        // Sin contraseña todavía, vuelve a INVITED: activo, pero sin poder entrar hasta definirla.
        return self::changeUser($actor, $user, $version, [User::INACTIVE], $user->password_hash ? User::ACTIVE : User::INVITED, 'user.activated', null);
    }

    /** A-10: basta tener users.sessions.revoke en una sucursal donde el usuario tenga membresía. */
    public static function revokeSessions(User $actor, User $user, string $reason, string $why = 'REVOKED_BY_ADMIN'): int
    {
        $count = 0;
        foreach (AuthSession::query()->where('user_id', new ObjectId($user->getKey()))->where('status', AuthSession::ACTIVE)->get() as $s) {
            $count += Sessions::revoke($s, $why, new ObjectId($actor->getKey())) ? 1 : 0;
        }
        Audit::record('user.sessions_revoked', 'user', $user->getKey(), null, new ObjectId($actor->getKey()), reason: $reason, changes: ['sessions' => $count]);
        Outbox::publish('user.sessions_revoked', 'user', $user->getKey(), null, ['userId' => $user->uuid]);

        return $count;
    }

    // --- Internos ---

    private static function assertNotSelf(User $actor, User $target): void
    {
        if ((string) $actor->getKey() === (string) $target->getKey()) {
            throw new DomainConflict('FORBIDDEN');
        }
    }

    private static function assertCanManageRole(Membership $actorMembership, string $role): void
    {
        $allowed = $actorMembership->role === 'ADMIN' || ($actorMembership->role === 'MANAGER' && in_array($role, self::MANAGER_ROLES, true));
        if (! $allowed) {
            throw new DomainConflict('FORBIDDEN');
        }
    }

    /** Otro ADMIN activo, con usuario activo, en la sucursal. */
    private static function assertAnotherAdmin(ObjectId $branchId, ObjectId $userId): void
    {
        $others = Membership::query()->where('branch_id', $branchId)->where('role', 'ADMIN')->where('status', Membership::ACTIVE)
            ->where('user_id', '!=', $userId)->pluck('user_id')->map(fn ($id) => new ObjectId((string) $id))->all();
        if (! User::query()->whereIn('_id', $others)->where('status', User::ACTIVE)->exists()) {
            throw new DomainConflict('LAST_BRANCH_ADMIN');
        }
    }

    /**
     * Cambio de membresía con If-Match en una transacción que escribe primero el epoch de la sucursal:
     * dos cambios simultáneos sobre los ADMIN de una sucursal chocan y el segundo ve al primero.
     * $work devuelve [cambios, evento, auditoría, motivo, ¿revisa que quede otro ADMIN?].
     */
    private static function changeMembership(Branch $branch, User $actor, Membership $m, int $version, \Closure $work): Membership
    {
        $branchId = new ObjectId($branch->getKey());
        Mongo::transaction(function () use ($branchId, $actor, $m, $version, $work) {
            Branch::query()->where('_id', $branchId)->increment('operations_epoch');
            $fresh = Membership::query()->find($m->getKey());
            if ((int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            [$changes, $event, $audit, $reason, $leavesAdmin] = $work($fresh);
            if (! $event) {
                return;
            }
            if ($fresh->role === 'ADMIN' && $leavesAdmin) {
                self::assertAnotherAdmin($branchId, new ObjectId((string) $fresh->user_id));
            }
            if (! $fresh->updateIfVersion($version, $changes)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record($event, 'user', (string) $fresh->user_id, $branchId, new ObjectId($actor->getKey()), reason: $reason, changes: $audit);
            Outbox::publish($event, 'user', (string) $fresh->user_id, $branchId, ['userId' => User::query()->find((string) $fresh->user_id)->uuid]);
        });

        return $m->refresh();
    }

    private static function changeUser(User $actor, User $user, int $version, array $from, string $to, string $event, ?string $reason, ?\Closure $check = null): User
    {
        Mongo::transaction(function () use ($actor, $user, $version, $from, $to, $event, $reason, $check) {
            $userId = new ObjectId($user->getKey());
            // Los epoch de sus sucursales: choca con un cambio de rol o una baja simultánea.
            Branch::query()->whereIn('_id', Membership::query()->where('user_id', $userId)->pluck('branch_id')->map(fn ($id) => new ObjectId((string) $id))->all())
                ->increment('operations_epoch');
            $fresh = User::query()->find($user->getKey());
            if ((int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            if (! in_array($fresh->status, $from, true)) {
                return;
            }
            $check && $check($userId);
            if (! $fresh->updateIfVersion($version, ['status' => $to])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            if ($to === User::INACTIVE) {
                foreach (AuthSession::query()->where('user_id', $userId)->where('status', AuthSession::ACTIVE)->get() as $s) {
                    Sessions::revoke($s, 'USER_DISABLED', new ObjectId($actor->getKey()));
                }
            }
            Audit::record($event, 'user', $fresh->getKey(), null, new ObjectId($actor->getKey()), reason: $reason);
            Outbox::publish($event, 'user', $fresh->getKey(), null, ['userId' => $fresh->uuid]);
        });

        return $user->refresh();
    }
}
