<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;
use MongoDB\Laravel\Eloquent\DocumentModel;

class PocToken extends PersonalAccessToken
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $table = 'personal_access_tokens';
    protected $primaryKey = '_id';
    protected $keyType = 'string';

    /** Solo para medir la prueba 3 sin la escritura de last_used_at. */
    public function save(array $options = [])
    {
        if (request()->hasHeader('X-Poc-Skip') && array_keys($this->getDirty()) === ['last_used_at']) {
            return true;
        }

        return parent::save($options);
    }
}
