<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Candle coverage per symbol (how many daily candles, latest date) in one grouped query.
 */
class CandleStats
{
    /** @return array<string, array{daily: int, last: ?string}> keyed 'NSE:INFY' */
    public static function forSymbols(array $symbols): array
    {
        if (!$symbols) return [];

        $query = DB::table('stock_candles')
            ->select('exchange', 'symbol', DB::raw('COUNT(*) as c'), DB::raw('MAX(`date`) as last'))
            ->where('timeframe', 'daily')
            ->where(function ($q) use ($symbols) {
                foreach ($symbols as $s) {
                    $q->orWhere(fn ($w) => $w->where('exchange', $s['exchange'])->where('symbol', $s['symbol']));
                }
            })
            ->groupBy('exchange', 'symbol')->get();

        $out = [];
        foreach ($query as $r) {
            $out[$r->exchange . ':' . $r->symbol] = ['daily' => (int) $r->c, 'last' => $r->last];
        }
        return $out;
    }
}
