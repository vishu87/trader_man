<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * When a symbol's chart was last viewed in the chart popup. One row per exchange:symbol, so the same chart counts as
 * viewed whether it was opened from the Portfolio or from a Watchlist. Dates are in the app timezone (Asia/Kolkata).
 */
class ChartView extends Model
{
    protected $table = 'chart_views';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['last_viewed_at' => 'datetime'];

    /** @return array<string, string> 'NSE:INFY' => '2026-11-07' (the DATE it was last viewed) */
    public static function lastViewedMap(): array
    {
        return static::all()->mapWithKeys(fn ($v) => [$v->exchange . ':' . $v->symbol => $v->last_viewed_at->toDateString()])->all();
    }
}
