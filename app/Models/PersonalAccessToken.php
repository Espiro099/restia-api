<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use MongoDB\Laravel\Eloquent\DocumentModel;

/** Access token de Sanctum en MongoDB, ligado a su auth_session (D-36, 4.3). Material técnico: se borra. */
class PersonalAccessToken extends SanctumToken
{
    use DocumentModel;

    protected $connection = 'mongodb';

    protected $table = 'personal_access_tokens';

    protected $primaryKey = '_id';

    protected $keyType = 'string';

    /** Sanctum solo declara name, token, abilities y expires_at: sin esto, la sesión y el dueño no se guardan. */
    protected $fillable = ['tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'auth_session_id', 'expires_at'];
}
