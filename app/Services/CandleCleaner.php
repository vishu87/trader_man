<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Removes stored candles of symbols nothing refers to any more.
 *
 * A symbol is IN USE (its candles are kept) when it is in the portfolio (any bucket, owned or tracking), in any watchlist,
 * or has a real trade or a paper trade (any status — the Trade Signals page still draws charts for closed ones, and live ones settle against them).
 * Only candles (and the "last synced" bookkeeping) are removed; Fibonacci swings, chart-view dates and signals are left alone.
 */
class CandleCleaner
{
    /** @return array<string, true> 'NSE:INFY' => true for every symbol that must keep its candles */
    public static function inUse(): array
    {
        $keep = [];
        foreach (DB::table('portfolio_holdings')->select('exchange', 'zerodha_symbol as symbol')->distinct()->get() as $r) $keep[$r->exchange . ':' . $r->symbol] = true;
        foreach (DB::table('watchlist_items')->select('exchange', 'symbol')->distinct()->get() as $r)                       $keep[$r->exchange . ':' . $r->symbol] = true;
        foreach (DB::table('paper_trades')->select('exchange', 'symbol')->distinct()->get() as $r)                          $keep[$r->exchange . ':' . $r->symbol] = true;
        foreach (DB::table('trades')->select('exchange', 'symbol')->distinct()->get() as $r)                                $keep[$r->exchange . ':' . $r->symbol] = true;
        return $keep;
    }

    /** @return array<int, array{exchange: string, symbol: string, rows: int}> stored symbols that nothing uses, biggest first */
    public static function unused(): array
    {
        $keep = self::inUse();
        $out = [];

        foreach (DB::table('stock_candles')->select('exchange', 'symbol', DB::raw('COUNT(*) as n'))->groupBy('exchange', 'symbol')->get() as $r) {
            if (!isset($keep[$r->exchange . ':' . $r->symbol])) {
                $out[] = ['exchange' => $r->exchange, 'symbol' => $r->symbol, 'rows' => (int) $r->n];
            }
        }

        usort($out, fn ($a, $b) => $b['rows'] <=> $a['rows'] ?: strcmp($a['symbol'], $b['symbol']));
        return $out;
    }

    /**
     * Delete the candles (and sync bookkeeping) of every unused symbol.
     * The "unused" list is worked out again here, so a symbol added to a watchlist since the preview is never deleted.
     *
     * @return array{symbols: int, rows: int}
     */
    public static function clear(): array
    {
        $symbols = self::unused();
        $rows = 0;

        foreach ($symbols as $s) {
            DB::transaction(function () use ($s, &$rows) {
                $rows += DB::table('stock_candles')->where('exchange', $s['exchange'])->where('symbol', $s['symbol'])->delete();
                DB::table('stock_candle_sync')->where('exchange', $s['exchange'])->where('symbol', $s['symbol'])->delete();
            });
        }

        return ['symbols' => count($symbols), 'rows' => $rows];
    }
}
