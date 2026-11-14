<?php

namespace App\Identity;

use App\Exceptions\DomainConflict;
use App\Models\AuthSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use App\Support\Throttle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Contraseñas (U-2). Un enlace de un solo uso, guardado como hash, sirve para la primera contraseña
 * (72 h, lo ve quien da de alta) y para la recuperación por correo (60 min). Ni el enlace ni la
 * contraseña llegan a la auditoría ni a los logs.
 */
final class Passwords
{
    public const SETUP = 'SETUP';

    public const RESET = 'RESET';

    private const MINUTES = [self::SETUP => 72 * 60, self::RESET => 60];

    /** Regla de contraseña nueva: 12 a 128 caracteres y, si está activado, no filtrada (sección 30). */
    public static function rule(): Password
    {
        $rule = Password::min(12)->max(128);

        return config('restia.auth.check_breached_passwords') ? $rule->uncompromised() : $rule;
    }

    /**
     * Genera un enlace e invalida los anteriores del usuario. El token va en el fragmento de la URL
     * (`#`), así no llega a logs de acceso ni a Referer.
     *
     * @return array{url: string, expiresAt: Carbon}
     */
    public static function issue(User $user, string $purpose): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = now()->addMinutes(self::MINUTES[$purpose]);
        $db = DB::connection('mongodb')->table('password_setup_tokens');
        $userId = new ObjectId($user->getKey());
        $db->where('user_id', $userId)->whereNull('used_at')->update(['used_at' => new UTCDateTime(now()), 'superseded' => true]);
        $db->insert([
            'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'purpose' => $purpose,
            'expires_at' => new UTCDateTime($expiresAt), 'used_at' => null, 'created_at' => new UTCDateTime(now()),
        ]);

        return ['url' => rtrim(config('restia.web.url'), '/').'/contrasena#'.$token, 'expiresAt' => $expiresAt];
    }

    /** Enlace para un usuario, generado por un ADMIN: revoca todas sus sesiones. */
    public static function setupLink(User $actor, User $user): array
    {
        if ($user->status === User::INACTIVE) {
            throw new DomainConflict('USER_INACTIVE');
        }

        return Mongo::transaction(function () use ($actor, $user) {
            $link = self::issue($user, self::SETUP);
            foreach (AuthSession::query()->where('user_id', new ObjectId($user->getKey()))->where('status', AuthSession::ACTIVE)->get() as $s) {
                Sessions::revoke($s, 'PASSWORD_RESET', new ObjectId($actor->getKey()));
            }
            Audit::record('user.password_setup_link_created', 'user', $user->getKey(), null, new ObjectId($actor->getKey()));
            Outbox::publish('user.password_reset_requested', 'user', $user->getKey(), null, ['userId' => $user->uuid]);

            return $link;
        });
    }

    /**
     * Siempre responde igual, exista o no el correo, para no revelar quién trabaja aquí. Con límite
     * por correo y por IP.
     */
    public static function forgot(string $email, string $ip): void
    {
        Throttle::hit('password-forgot|'.User::normalizeEmail($email), 3);
        Throttle::hit('password-forgot-ip|'.$ip, 10);
        $user = User::query()->where('normalized_email', User::normalizeEmail($email))->first();
        if (! $user || $user->status === User::INACTIVE) {
            return;
        }
        $link = Mongo::transaction(function () use ($user) {
            $link = self::issue($user, self::RESET);
            Audit::record('user.password_reset_requested', 'user', $user->getKey(), null, new ObjectId($user->getKey()));
            Outbox::publish('user.password_reset_requested', 'user', $user->getKey(), null, ['userId' => $user->uuid]);

            return $link;
        });
        try {
            Mail::raw("Hola {$user->name}:\n\nPara definir una contraseña nueva en Restia, abre este enlace en la próxima hora:\n\n{$link['url']}\n\nSi no lo pediste, ignora este correo.",
                fn ($m) => $m->to($user->email)->subject('Restia: recupera tu contraseña'));
        } catch (\Throwable $e) {
            // Sin correo el enlace no llega, pero la respuesta no lo revela; queda en el log, sin el token.
            Log::warning('No se pudo enviar el correo de recuperación', ['user' => $user->uuid, 'error' => $e->getMessage()]);
        }
    }

    /** Primera contraseña o recuperación: el token sirve una vez y revoca todas las sesiones. */
    public static function reset(string $token, string $password, string $ip): void
    {
        Throttle::hit('password-reset|'.$ip, 10);
        self::validate($password);
        Mongo::transaction(function () use ($token, $password) {
            $db = DB::connection('mongodb')->table('password_setup_tokens');
            $row = $db->where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', new UTCDateTime(now()))->first();
            $row = $row ? (array) $row : null;
            $user = $row ? User::query()->find((string) $row['user_id']) : null;
            if (! $user || $user->status === User::INACTIVE) {
                throw new DomainConflict('PASSWORD_TOKEN_INVALID');
            }
            $used = $db->where('token_hash', hash('sha256', $token))->whereNull('used_at')->update(['used_at' => new UTCDateTime(now())]);
            if ($used === 0) {
                throw new DomainConflict('PASSWORD_TOKEN_INVALID');
            }
            User::query()->where('_id', new ObjectId($user->getKey()))->increment('version', 1, [
                'password_hash' => Hash::make($password), 'status' => User::ACTIVE, 'updated_at' => now(),
            ]);
            foreach (AuthSession::query()->where('user_id', new ObjectId($user->getKey()))->where('status', AuthSession::ACTIVE)->get() as $s) {
                Sessions::revoke($s, 'PASSWORD_RESET', new ObjectId($user->getKey()));
            }
            Audit::record('user.password_changed', 'user', $user->getKey(), null, new ObjectId($user->getKey()), changes: ['via' => $row['purpose']]);
            Outbox::publish('user.password_changed', 'user', $user->getKey(), null, ['userId' => $user->uuid]);
        });
    }

    /** D-36: exige la actual, conserva la sesión en curso y revoca las demás. */
    public static function change(User $user, ?AuthSession $current, string $currentPassword, string $newPassword): void
    {
        Throttle::hit('password-change|'.$user->getKey(), 5);
        if (! Hash::check($currentPassword, (string) $user->password_hash)) {
            throw ValidationException::withMessages(['currentPassword' => 'La contraseña actual no es correcta.']);
        }
        self::validate($newPassword, 'newPassword');
        Mongo::transaction(function () use ($user, $current, $newPassword) {
            User::query()->where('_id', new ObjectId($user->getKey()))->increment('version', 1, ['password_hash' => Hash::make($newPassword), 'updated_at' => now()]);
            $others = AuthSession::query()->where('user_id', new ObjectId($user->getKey()))->where('status', AuthSession::ACTIVE)
                ->when($current, fn ($q) => $q->where('_id', '!=', new ObjectId($current->getKey())))->get();
            foreach ($others as $s) {
                Sessions::revoke($s, 'PASSWORD_CHANGED', new ObjectId($user->getKey()));
            }
            Audit::record('user.password_changed', 'user', $user->getKey(), null, new ObjectId($user->getKey()), changes: ['via' => 'CHANGE', 'sessionsRevoked' => $others->count()]);
            Outbox::publish('user.password_changed', 'user', $user->getKey(), null, ['userId' => $user->uuid]);
        });
    }

    private static function validate(string $password, string $field = 'newPassword'): void
    {
        $v = Validator::make([$field => $password], [$field => ['required', 'string', self::rule()]]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
    }
}
