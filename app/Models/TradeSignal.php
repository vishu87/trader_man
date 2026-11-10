<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeSignal extends Model
{
    protected $table = 'trade_signals';
    protected $guarded = [];
    public $timestamps = false;

    // timestamps are set by hand (no automatic ones), so they must be cast to come back as dates, not strings
    protected $casts = ['created_at' => 'datetime'];
}
