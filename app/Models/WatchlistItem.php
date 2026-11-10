<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchlistItem extends Model
{
    protected $table = 'watchlist_items';
    protected $guarded = [];

    protected $casts = ['listing_date' => 'date'];
}
