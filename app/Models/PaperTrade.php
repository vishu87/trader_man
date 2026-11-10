<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperTrade extends Model
{
    protected $table = 'paper_trades';
    protected $guarded = [];
    public $timestamps = false;

    // timestamps are set by hand (no automatic ones), so they must be cast to come back as dates, not strings
    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];
}
