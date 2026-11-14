<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Laravel\Sanctum\HasApiTokens;

/** Identidad global del empleado (D-08). Su acceso a cada sucursal vive en Membership. */
class User extends Document implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens, HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    /** Dado de alta sin contraseña todavía: no puede entrar hasta usar su enlace (U-2). */
    public const INVITED = 'INVITED';

    protected $table = 'users';

    protected $hidden = ['password_hash', 'normalized_email', 'remember_token'];

    public function getAuthPasswordName()
    {
        return 'password_hash';
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Sin "recordarme": la sesión Web la controla auth_sessions (D-36). */
    public function getRememberTokenName()
    {
        return '';
    }
}
