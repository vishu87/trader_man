<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $table = 'trades';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime', 'archived_at' => 'datetime'];

    public function actions()
    {
        return $this->hasMany(TradeAction::class, 'trade_id');
    }
}
