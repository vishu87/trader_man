<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZerodhaImport extends Model
{
    protected $table = 'zerodha_imports';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];
}
