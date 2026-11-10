<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeAction extends Model
{
    protected $table = 'trade_actions';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];
}
