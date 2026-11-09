<?php

namespace App\Identity;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\ServiceIdentity;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Throwable;

/**
 * Identidades de servicio (D-36, sección 11; PR-12). El token se muestra una sola vez y solo se guarda
 * su SHA-256. Dura 90 días; al rotar, el anterior vale 7 días más; revocar borra ambos hashes.
 *
 * Son un recurso global, pero cada una está autorizada para ciertas sucursales: quien la administra
 * solo la ve si comparte alguna, y solo puede agregar o quitar sucursales donde tiene el permiso.
 */
final class ServiceIdentities
{
    private const PREFIX = 'rsv_';

    /** @return array<string, mixed> */
    public static function present(ServiceIdentity $s): array
    {
        $uuids = Branch::query()->whereIn('_id', self::oids($s->branch_ids ?? []))->get()
            ->mapWithKeys(fn ($b) => [(string) $b->getKey() => $b->uuid]);

        return [
            'id' => $s->uuid,
            'name' => $s->name,
            'type' => $s->type,
            'branchIds' => collect($s->branch_ids ?? [])->map(fn ($id) => $uuids->get((string) $id))->filter()->values()->all(),
            'status' => $s->status,
            'tokenExpiresAt' => MobileAuth::time($s->token_expires_at),
            'previousTokenExpiresAt' => MobileAuth::time($s->previous_token_expires_at),
            'lastUsedAt' => MobileAuth::time($s->last_used_at),
            'version' => (int) $s->version,
        ];
    }

    /** Visible para quien administra alguna de sus sucursales. */
    public static function visible(ServiceIdentity $s, array $managed): bool
    {
        return (bool) array_intersect(array_map('strval', $s->branch_ids ?? []), $managed);
    }

    /**
     * @param  list<string>  $managed  sucursales donde el actor tiene service_identities.manage
     * @param  array{name: string, type: string, branchIds: list<string>}  $in
     * @return array{0: ServiceIdentity, 1: string}
     */
    public static function create(User $actor, array $managed, array $in): array
    {
        $branches = self::branches($in['branchIds'], [], $managed);
        $token = self::newToken();

        try {
            $s = Mongo::transaction(function () use ($actor, $in, $branches, $token) {
                $s = ServiceIdentity::query()->create([
                    'name' => $in['name'],
                    'type' => $in['type'],
                    'branch_ids' => $branches,
                    'status' => ServiceIdentity::ACTIVE,
                    'token_hash' => self::hash($token),
                    'token_expires_at' => now()->addDays(config('restia.service.token_days')),
                    'previous_token_hash' => null,
                    'previous_token_expires_at' => null,
                    'last_used_at' => null,
                    'created_by' => new ObjectId($actor->getKey()),
                ]);
                Audit::record('service_identity.created', 'service_identity', $s->getKey(), actorId: new ObjectId($actor->getKey()), changes: [
                    'after' => ['name' => $s->name, 'type' => $s->type, 'branch_ids' => array_map('strval', $branches)],
                ]);
                Outbox::publish('service_identity.created', 'service_identity', $s->getKey(), null, ['serviceIdentityId' => $s->uuid]);

                return $s;
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('SERVICE_IDENTITY_NAME_TAKEN') : $e;
        }

        return [$s, $token];
    }

    /** @return array{0: ServiceIdentity, 1: string} */
    public static function rotate(User $actor, ServiceIdentity $s, int $version): array
    {
        $token = self::newToken();
        Mongo::transaction(function () use ($actor, $s, $version, $token) {
            $fresh = self::fresh($s, $version);
            $now = now();
            // El anterior vale 7 días más, pero nunca más allá de su propio vencimiento.
            $grace = $now->copy()->addDays(config('restia.service.rotation_grace_days'));
            $current = $fresh->token_expires_at;
            $changes = [
                'token_hash' => self::hash($token),
                'token_expires_at' => $now->copy()->addDays(config('restia.service.token_days')),
                'previous_token_hash' => $fresh->token_hash,
                'previous_token_expires_at' => $current && $current->lt($grace) ? $current : $grace,
            ];
            if (! $fresh->updateIfVersion($version, $changes)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('service_identity.rotated', 'service_identity', $fresh->getKey(), actorId: new ObjectId($actor->getKey()), changes: [
                'after' => ['token_expires_at' => MobileAuth::time($fresh->token_expires_at), 'previous_token_expires_at' => MobileAuth::time($fresh->previous_token_expires_at)],
            ]);
            Outbox::publish('service_identity.rotated', 'service_identity', $fresh->getKey(), null, ['serviceIdentityId' => $fresh->uuid]);
        });

        return [$s->refresh(), $token];
    }

    /** Repetirla no tiene efecto: la identidad ya está revocada. */
    public static function revoke(User $actor, ServiceIdentity $s, int $version, string $reason): ServiceIdentity
    {
        if ($s->status === ServiceIdentity::REVOKED) {
            return $s;
        }
        Mongo::transaction(function () use ($actor, $s, $version, $reason) {
            $fresh = self::fresh($s, $version);
            $changes = [
                'status' => ServiceIdentity::REVOKED,
                'token_hash' => null,
                'previous_token_hash' => null,
                'previous_token_expires_at' => null,
                'revoked_at' => now(),
                'revoked_by' => new ObjectId($actor->getKey()),
            ];
            if (! $fresh->updateIfVersion($version, $changes)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('service_identity.revoked', 'service_identity', $fresh->getKey(), actorId: new ObjectId($actor->getKey()), reason: $reason, changes: [
                'before' => ['status' => ServiceIdentity::ACTIVE], 'after' => ['status' => ServiceIdentity::REVOKED],
            ]);
            Outbox::publish('service_identity.revoked', 'service_identity', $fresh->getKey(), null, ['serviceIdentityId' => $fresh->uuid]);
        });

        return $s->refresh();
    }

    /**
     * Reemplaza las sucursales autorizadas. Una sucursal retirada deja de aceptar el token de inmediato,
     * porque cada petición del agente revisa que su sucursal siga en la lista.
     *
     * @param  list<string>  $managed
     * @param  list<string>  $branchUuids
     */
    public static function update(User $actor, array $managed, ServiceIdentity $s, int $version, array $branchUuids): ServiceIdentity
    {
        Mongo::transaction(function () use ($actor, $managed, $s, $version, $branchUuids) {
            $fresh = self::fresh($s, $version);
            $before = array_map('strval', $fresh->branch_ids ?? []);
            $after = self::branches($branchUuids, $before, $managed);
            $removed = array_diff($before, array_map('strval', $after));
            if (array_diff($removed, $managed)) {
                throw ValidationException::withMessages(['branchIds' => 'No puedes retirar una sucursal que no administras.']);
            }
            if ($before === array_map('strval', $after)) {
                return;
            }
            if (! $fresh->updateIfVersion($version, ['branch_ids' => $after])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('service_identity.updated', 'service_identity', $fresh->getKey(), actorId: new ObjectId($actor->getKey()), changes: [
                'before' => ['branch_ids' => $before], 'after' => ['branch_ids' => array_map('strval', $after)],
            ]);
            Outbox::publish('service_identity.updated', 'service_identity', $fresh->getKey(), null, ['serviceIdentityId' => $fresh->uuid]);
        });

        return $s->refresh();
    }

    /**
     * Identidad activa dueña del token, actual o anterior en su gracia. Anota el último uso a lo más
     * una vez por minuto y sin subir `version`: es telemetría, no un cambio de la identidad.
     */
    public static function authenticate(string $token): ?ServiceIdentity
    {
        if (! str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $hash = self::hash($token);
        $now = now();
        $s = ServiceIdentity::query()->where('status', ServiceIdentity::ACTIVE)
            ->where(fn ($q) => $q->where('token_hash', $hash)->orWhere('previous_token_hash', $hash))
            ->first();
        if (! $s) {
            return null;
        }
        $current = hash_equals((string) $s->token_hash, $hash);
        $until = $current ? $s->token_expires_at : $s->previous_token_expires_at;
        if (! $until || $until->lte($now)) {
            return null;
        }
        if (! $s->last_used_at || $s->last_used_at->lt($now->copy()->subMinute())) {
            ServiceIdentity::query()->where('_id', $s->getKey())->update(['last_used_at' => $now]);
            $s->last_used_at = Carbon::instance($now);
        }

        return $s;
    }

    /** La identidad con la versión esperada y todavía activa. */
    private static function fresh(ServiceIdentity $s, int $version): ServiceIdentity
    {
        $fresh = ServiceIdentity::query()->find($s->getKey());
        if ((int) $fresh->version !== $version) {
            throw new DomainConflict('VERSION_CONFLICT');
        }
        if ($fresh->status !== ServiceIdentity::ACTIVE) {
            throw new DomainConflict('SERVICE_IDENTITY_REVOKED');
        }

        return $fresh;
    }

    /**
     * Sucursales pedidas, como ObjectId. Cada una debe existir; las que no estaban antes deben ser
     * de las que administra el actor.
     *
     * @param  list<string>  $uuids
     * @param  list<string>  $current
     * @param  list<string>  $managed
     * @return list<ObjectId>
     */
    private static function branches(array $uuids, array $current, array $managed): array
    {
        $uuids = array_map('strtolower', $uuids);
        $found = Branch::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $errors = [];
        foreach ($uuids as $i => $uuid) {
            $branch = $found->get($uuid);
            $id = $branch ? (string) $branch->getKey() : null;
            if (! $branch || (! in_array($id, $current, true) && ! in_array($id, $managed, true))) {
                $errors["branchIds.$i"] = 'La sucursal no existe o no la administras.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_map(fn ($uuid) => new ObjectId($found->get($uuid)->getKey()), $uuids);
    }

    /** @return list<ObjectId> */
    private static function oids(array $ids): array
    {
        return array_map(fn ($id) => $id instanceof ObjectId ? $id : new ObjectId((string) $id), $ids);
    }

    private static function newToken(): string
    {
        return self::PREFIX.bin2hex(random_bytes(32));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
