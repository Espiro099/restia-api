<?php

namespace App\Identity;

use App\Models\AuthSession;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

/** Creación y revocación de sesiones. Todo método de escritura se llama dentro de Mongo::transaction(). */
final class Sessions
{
    /** Revoca si sigue activa. Idempotente: devuelve false si ya estaba cerrada. Audita solo el cambio. */
    public static function revoke(AuthSession $session, string $reason, ?ObjectId $actorId, ?ObjectId $branchId = null): bool
    {
        $now = now();
        $changed = AuthSession::query()
            ->where('_id', new ObjectId($session->getKey()))
            ->where('status', AuthSession::ACTIVE)
            ->increment('version', 1, [
                'status' => AuthSession::REVOKED,
                'revoked_at' => $now,
                'revoked_by' => $actorId,
                'revocation_reason' => $reason,
                'purge_at' => self::purgeAt($now),
                'updated_at' => $now,
            ]);
        if ($changed === 0) {
            return false;
        }
        self::deleteTokens($session);
        Audit::record('auth_session.revoked', 'auth_session', $session->getKey(), $branchId, $actorId, reason: $reason, changes: [
            'clientType' => $session->client_type,
        ]);

        return true;
    }

    /** Los access tokens son material técnico: se borran y la siguiente petición recibe 401. */
    public static function deleteTokens(AuthSession $session): void
    {
        PersonalAccessToken::query()->where('auth_session_id', new ObjectId($session->getKey()))->delete();
    }

    /** Emite un access token de Sanctum de 15 minutos ligado a la sesión. Devuelve el texto plano. */
    public static function issueAccessToken(User $user, AuthSession $session): string
    {
        $plain = Str::random(48);
        $token = PersonalAccessToken::query()->create([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => (string) $user->getKey(),
            'name' => strtolower($session->client_type),
            'token' => hash('sha256', $plain),
            'abilities' => ['*'],
            'auth_session_id' => new ObjectId($session->getKey()),
            'expires_at' => now()->addSeconds(config('restia.auth.access_token_seconds')),
        ]);

        return $token->getKey().'|'.$plain;
    }

    /** El historial se purga 180 días después del cierre (A-9). */
    public static function purgeAt(Carbon $closedAt): Carbon
    {
        return $closedAt->copy()->addDays(config('restia.auth.session_retention_days'));
    }
}
