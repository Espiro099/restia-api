<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;

/** Identidad técnica (D-36, sección 11): agente de impresión o IA de reservaciones. Guarda solo hashes del token. */
class ServiceIdentity extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const REVOKED = 'REVOKED';

    protected $hidden = ['token_hash', 'previous_token_hash'];

    public const PRINTING_AGENT = 'PRINTING_AGENT';

    public const RESERVATION_AI = 'RESERVATION_AI';

    protected $table = 'service_identities';

    protected $casts = [
        'token_expires_at' => 'datetime',
        'previous_token_expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
