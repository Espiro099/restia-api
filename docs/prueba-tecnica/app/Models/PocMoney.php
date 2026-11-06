<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class PocMoney extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'poc_money';
    protected $fillable = ['amount'];
    protected $casts = ['amount' => 'decimal:2'];
}
