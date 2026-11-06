<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $connection = 'mongodb';
    protected $table = 'users';
    protected $fillable = ['email', 'name', 'password', 'status'];
    protected $hidden = ['password', 'remember_token'];
}
