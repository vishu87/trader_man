<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SymbolFib extends Model
{
    protected $table = 'symbol_fibs';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime', 'is_shown' => 'boolean'];

    public function levels()
    {
        return $this->hasMany(SymbolFibLevel::class, 'fib_id')->orderBy('kind', 'desc')->orderBy('ratio');
    }
}
