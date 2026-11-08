<?php

namespace App\Identity;

use App\Exceptions\DomainConflict;
use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceEnrollmentCode;
use App\Models\Membership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use App\Support\RefreshTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Throwable;

/** Flujo Android de D-36, sección 5: alta, login y renovación. */
final class MobileAuth
{
    /** Sin ambigüedades al dictarlo: sin 0/O ni 1/I/L. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const CODE_LENGTH = 12;

    /** @return array{code: string, expiresAt: Carbon} */
    public static function createEnrollmentCode(Branch $branch, User $actor): array
    {
        if (! $branch->isActive()) {
            throw new DomainConflict('BRANCH_INACTIVE');
        }
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        $expiresAt = now()->addSeconds(config('restia.auth.enrollment_code_seconds'));

        Mongo::transaction(function () use ($branch, $actor, $code, $expiresAt) {
            $row = DeviceEnrollmentCode::query()->create([
                'branch_id' => $branch->getKey(),
                'code_hash' => self::codeHash($code),
                'created_by' => $actor->getKey(),
                'expires_at' => $expiresAt,
                'used_at' => null,
            ]);
            Audit::record('device_enrollment_code.created', 'device_enrollment_code', $row->getKey(), $branch->getKey(), $actor->getKey());
        });

        return ['code' => $code, 'expiresAt' => $expiresAt];
    }

    /** @return array{device: Device, credential: string} */
    public static function enroll(string $code, string $deviceUuid, string $displayName): array
    {
        $deviceUuid = strtolower($deviceUuid);
        $credential = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        try {
            return Mongo::transaction(function () use ($code, $deviceUuid, $displayName, $credential) {
                $now = now();
                $hash = self::codeHash($code);
                // Consumo atómico: si dos altas usan el mismo código, solo una lo encuentra sin usar.
                $used = DeviceEnrollmentCode::query()
                    ->where('code_hash', $hash)
                    ->whereNull('used_at')
                    ->where('expires_at', '>', $now)
                    ->update(['used_at' => $now, 'updated_at' => $now]);
                $row = $used ? DeviceEnrollmentCode::query()->where('code_hash', $hash)->first() : null;
                $branch = $row ? Branch::query()->find($row->branch_id) : null;
                if (! $branch?->isActive()) {
                    throw new DomainConflict('ENROLLMENT_CODE_INVALID');
                }
                if (Device::query()->where('device_uuid', $deviceUuid)->exists()) {
                    // Se revierte el consumo: el código sigue sirviendo con otro deviceUuid.
                    throw new DomainConflict('DEVICE_ALREADY_ENROLLED');
                }

                $device = Device::query()->create([
                    'device_uuid' => $deviceUuid,
                    'branch_id' => $branch->getKey(),
                    'type' => 'ANDROID',
                    'status' => Device::ACTIVE,
                    'credential_hash' => hash('sha256', $credential),
                    'display_name' => $displayName,
                    'enrolled_by' => $row->created_by,
                    'enrolled_at' => $now,
                    'last_seen_at' => null,
                ]);
                Audit::record('device.enrolled', 'device', $device->getKey(), $branch->getKey(), $device->getKey(), 'DEVICE', changes: [
                    'displayName' => $displayName,
                    'enrolledBy' => (string) $row->created_by,
                ]);
                Outbox::publish('device.enrolled', 'device', $device->getKey(), $branch->getKey(), ['deviceId' => $device->uuid]);

                return ['device' => $device, 'credential' => $credential];
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('DEVICE_ALREADY_ENROLLED') : $e;
        }
    }

    /** @return array<string, mixed> el cuerpo `data` de MobileTokenResponse */
    public static function login(string $email, string $password, string $deviceUuid, string $credential, string $ip): array
    {
        $device = self::deviceWithCredential($deviceUuid, $credential);
        $user = User::query()->where('normalized_email', User::normalizeEmail($email))->first();
        // Sin usuario se compara igual contra un hash, para no revelar por el tiempo si el correo existe.
        $valid = Hash::check($password, $user?->password_hash ?? self::dummyHash());
        if (! $valid || ! $user->isActive()) {
            Log::info('Login móvil rechazado', ['device' => $device->uuid, 'ip' => $ip]);
            throw new DomainConflict('AUTHENTICATION_INVALID');
        }
        // El usuario ya probó quién es: ahora sí se le dice que el dispositivo no sirve.
        if (! $device->isActive()) {
            throw new DomainConflict('DEVICE_BLOCKED');
        }
        $branch = Branch::query()->find($device->branch_id);
        $membership = Membership::query()
            ->where('user_id', new ObjectId($user->getKey()))
            ->where('branch_id', new ObjectId($device->branch_id))
            ->where('status', Membership::ACTIVE)
            ->first();
        if (! $membership || ! $branch?->isActive()) {
            throw new DomainConflict('BRANCH_ACCESS_DENIED');
        }

        // Dos logins en el mismo dispositivo chocan en el índice único parcial: el perdedor repite y
        // entonces ve la sesión del otro, que reemplaza (CRED-10).
        for ($attempt = 1; ; $attempt++) {
            try {
                return Mongo::transaction(fn () => self::openSession($user, $device, $ip));
            } catch (Throwable $e) {
                if ($attempt >= 3 || ! Mongo::isDuplicateKey($e)) {
                    throw $e;
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private static function openSession(User $user, Device $device, string $ip): array
    {
        $now = now();
        $userId = new ObjectId($user->getKey());
        $deviceId = new ObjectId($device->getKey());
        $branchId = new ObjectId($device->branch_id);
        $android = fn () => AuthSession::query()->where('status', AuthSession::ACTIVE)->where('client_type', AuthSession::ANDROID);

        foreach ($android()->where('device_id', $deviceId)->get() as $session) {
            Sessions::revoke($session, 'REPLACED_BY_LOGIN', null, $branchId);
        }

        // Límite por usuario (A-4): se cierra la más antigua. Dos logins simultáneos pueden excederlo
        // por una, y el siguiente lo corrige (riesgo aceptado en D-36, 5.5).
        $limit = (int) config('restia.auth.android_sessions_per_user');
        $others = $android()->where('user_id', $userId)->orderBy('created_at')->get();
        foreach ($others->take(max(0, $others->count() - ($limit - 1))) as $session) {
            Sessions::revoke($session, 'SESSION_LIMIT', null, $branchId);
        }

        $absolute = $now->copy()->addSeconds(config('restia.auth.session_max_seconds'));
        $uuid = (string) Str::uuid();
        $refresh = RefreshTokens::issue($uuid);
        $session = AuthSession::query()->create([
            'uuid' => $uuid,
            'user_id' => $userId,
            'client_type' => AuthSession::ANDROID,
            'device_id' => $deviceId,
            'status' => AuthSession::ACTIVE,
            'refresh_hash' => RefreshTokens::hash($refresh),
            'previous_refresh_hash' => null,
            'previous_valid_until' => null,
            'refresh_expires_at' => self::nextRefreshExpiry($now, $absolute),
            'absolute_expires_at' => $absolute,
            'last_activity_at' => $now,
            'ip_created' => $ip,
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'purge_at' => Sessions::purgeAt($absolute),
        ]);

        Device::query()->where('_id', $deviceId)->update(['last_seen_at' => $now, 'updated_at' => $now]);
        Audit::record('auth.login', 'auth_session', $session->getKey(), $branchId, $userId, changes: ['clientType' => AuthSession::ANDROID]);

        return self::tokenData($user, $session, $refresh);
    }

    /** @return array<string, mixed> */
    public static function refresh(string $deviceUuid, string $refreshToken): array
    {
        $sessionUuid = RefreshTokens::verify($refreshToken);
        if ($sessionUuid === null) {
            throw new DomainConflict('SESSION_REVOKED');
        }
        $device = Device::query()->where('device_uuid', strtolower($deviceUuid))->first();
        if (! $device) {
            throw new DomainConflict('SESSION_REVOKED');
        }
        if (! $device->isActive()) {
            throw new DomainConflict('DEVICE_BLOCKED');
        }

        $hash = RefreshTokens::hash($refreshToken);
        $newToken = RefreshTokens::issue($sessionUuid);
        $newHash = RefreshTokens::hash($newToken);

        $data = Mongo::transaction(function () use ($sessionUuid, $device, $hash, $newHash, $newToken) {
            $now = now();
            $grace = $now->copy()->addSeconds(config('restia.auth.rotation_grace_seconds'));
            $valid = fn () => AuthSession::query()
                ->where('uuid', $sessionUuid)
                ->where('device_id', new ObjectId($device->getKey()))
                ->where('status', AuthSession::ACTIVE)
                ->where('refresh_expires_at', '>', $now)
                ->where('absolute_expires_at', '>', $now);
            $session = $valid()->first();
            if (! $session) {
                return null;
            }
            $expiry = self::nextRefreshExpiry($now, $session->absolute_expires_at);
            // Vigente: rota y el anterior queda 60 s de gracia. La condición sobre el hash hace la
            // escritura atómica: si llegan dos renovaciones, solo una la cumple.
            $rotated = $valid()->where('refresh_hash', $hash)->increment('version', 1, [
                'refresh_hash' => $newHash,
                'previous_refresh_hash' => $hash,
                'previous_valid_until' => $grace,
                'refresh_expires_at' => $expiry,
                'last_activity_at' => $now,
                'updated_at' => $now,
            ]);
            // Anterior dentro de la gracia: cubre una respuesta perdida (CRED-07).
            $rotated = $rotated ?: $valid()
                ->where('previous_refresh_hash', $hash)
                ->where('previous_valid_until', '>', $now)
                ->increment('version', 1, [
                    'refresh_hash' => $newHash,
                    'refresh_expires_at' => $expiry,
                    'last_activity_at' => $now,
                    'updated_at' => $now,
                ]);
            if (! $rotated) {
                return null;
            }
            $session->refresh();
            $user = User::query()->find($session->user_id);
            if (! $user?->isActive()) {
                return null;
            }
            // Los access tokens anteriores vencen en 60 s, para que terminen las peticiones en curso.
            PersonalAccessToken::query()
                ->where('auth_session_id', new ObjectId($session->getKey()))
                ->where('expires_at', '>', $grace)
                ->update(['expires_at' => $grace]);
            Device::query()->where('_id', new ObjectId($device->getKey()))->update(['last_seen_at' => $now, 'updated_at' => $now]);

            return self::tokenData($user, $session, $newToken);
        });
        if ($data !== null) {
            return $data;
        }

        // Firma válida pero token no vigente. Va fuera de la transacción anterior para que la revocación
        // no se deshaga. Solo es reúso si la sesión seguía activa y en plazo; si no, es un 401 sin más.
        Mongo::transaction(function () use ($sessionUuid, $device) {
            $session = AuthSession::query()->where('uuid', $sessionUuid)->first();
            if ($session?->isUsable()) {
                $owner = $session->device_id ? Device::query()->find($session->device_id) : null;
                if (Sessions::revoke($session, 'REFRESH_REUSE', null, $owner ? new ObjectId($owner->branch_id) : null)) {
                    Log::warning('Reúso de refresh token: sesión revocada', ['session' => $sessionUuid, 'device' => $device->uuid]);
                }
            }
        });
        throw new DomainConflict('SESSION_REVOKED');
    }

    private static function deviceWithCredential(string $deviceUuid, string $credential): Device
    {
        $device = Device::query()->where('device_uuid', strtolower($deviceUuid))->first();
        if (! $device || ! hash_equals((string) $device->credential_hash, hash('sha256', $credential))) {
            throw new DomainConflict('DEVICE_NOT_ENROLLED');
        }

        return $device;
    }

    private static function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make(Str::random(32));
    }

    private static function nextRefreshExpiry(Carbon $now, Carbon $absolute): Carbon
    {
        $idle = $now->copy()->addSeconds(config('restia.auth.refresh_idle_seconds'));

        return $idle->lt($absolute) ? $idle : $absolute->copy();
    }

    /** @return array<string, mixed> */
    private static function tokenData(User $user, AuthSession $session, string $refresh): array
    {
        return [
            'session' => ['id' => $session->uuid, 'absoluteExpiresAt' => self::time($session->absolute_expires_at)],
            'accessToken' => Sessions::issueAccessToken($user, $session),
            'accessTokenExpiresIn' => (int) config('restia.auth.access_token_seconds'),
            'refreshToken' => $refresh,
            'refreshTokenExpiresAt' => self::time($session->refresh_expires_at),
        ];
    }

    public static function codeHash(string $code): string
    {
        // Se ignoran espacios y guiones, y da igual mayúsculas o minúsculas.
        return hash('sha256', strtoupper((string) preg_replace('/[\s-]+/', '', $code)));
    }

    public static function time(?Carbon $t): ?string
    {
        return $t?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
