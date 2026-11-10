<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveOrder extends Model
{
    protected $table = 'live_orders';
    protected $guarded = [];
    public $timestamps = false;
}
