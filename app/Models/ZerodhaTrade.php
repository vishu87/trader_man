<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZerodhaTrade extends Model
{
    protected $table = 'zerodha_trades';
    protected $guarded = [];
    public $timestamps = false;
}
