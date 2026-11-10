<?php

namespace App\Services\StockScanner;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Watchlist;
use App\Models\WatchlistItem;

/**
 * The Stock Scanner: runs one of the fixed, coded scanners over the symbols of our own watchlists, on the candles stored in the
 * database (nothing is fetched here — sync the candles first; the evening cron does it too).
 */
class StockScanner
{
    const LIST_FLOOR = 50;     // scores below this are not even listed (the page filters from there up: 75 / 80 / …)
    const HISTORY_YEARS = 5;   // monthly candles loaded per symbol (the RSI comparison looks at every earlier month)

    /** The scanners on offer. Add a class with describe() / evaluate() and list it here. */
    public static function scanners(): array
    {
        return [MonthlyBollingerBreakout::class];
    }

    public static function find(string $key): ?string
    {
        foreach (self::scanners() as $c) if ($c::KEY === $key) return $c;
        return null;
    }

    /** One entry per exchange:symbol of the chosen watchlist ('all' or an id): name, the lists it is in, its best star rating. */
    public static function universe($watchlistId = 'all'): array
    {
        $names = Watchlist::pluck('name', 'id');
        $q = WatchlistItem::query();
        if ($watchlistId !== 'all' && $watchlistId !== null && $watchlistId !== '') $q->where('watchlist_id', (int) $watchlistId);

        $out = [];
        foreach ($q->get() as $i) {
            $k = $i->exchange . ':' . $i->symbol;
            $out[$k] ??= ['key' => $k, 'exchange' => $i->exchange, 'symbol' => $i->symbol, 'name' => $i->name, 'lists' => [], 'rating' => null];
            $out[$k]['lists'][] = $names[$i->watchlist_id] ?? '—';
            if ($i->rating !== null) $out[$k]['rating'] = max((int) $out[$k]['rating'], (int) $i->rating);
        }
        return $out;
    }

    /** Monthly candles, oldest first, for the universe: ['NSE:INFY' => [row, ...]] */
    public static function monthly(array $universe): array
    {
        $by = [];
        foreach ($universe as $u) $by[$u['exchange']][] = $u['symbol'];

        $since = date('Y-m-d', strtotime('-' . self::HISTORY_YEARS . ' years'));
        $out = [];
        foreach ($by as $exchange => $symbols) {
            foreach (array_chunk($symbols, 400) as $chunk) {
                $rows = DB::table('stock_candles')->where('exchange', $exchange)->where('timeframe', 'monthly')->whereIn('symbol', $chunk)
                    ->where('date', '>=', $since)->orderBy('symbol')->orderBy('date')
                    ->get(['symbol', 'date', 'open', 'high', 'low', 'close', 'volume', 'bb_high']);
                foreach ($rows as $r) {
                    $out[$exchange . ':' . $r->symbol][] = [
                        'date' => $r->date, 'open' => (float) $r->open, 'high' => (float) $r->high, 'low' => (float) $r->low, 'close' => (float) $r->close,
                        'volume' => (int) $r->volume, 'bb_high' => $r->bb_high !== null ? (float) $r->bb_high : null,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * @return array{results: array, scanned: int, no_data: int, no_candles: int, matched: int, floor: int}  results = symbols scoring at least LIST_FLOOR
     */
    public static function run(string $scannerClass, $watchlistId = 'all', array $params = []): array
    {
        $universe = self::universe($watchlistId);
        $monthly  = self::monthly($universe);

        $results = []; $noData = 0; $noCandles = 0;
        foreach ($universe as $k => $u) {
            if (empty($monthly[$k])) { $noCandles++; continue; }
            $r = $scannerClass::evaluate($monthly[$k], $params);
            if ($r['status'] === 'no_data') { $noData++; continue; }
            if ($r['score'] < self::LIST_FLOOR) continue;
            $results[] = ['exchange' => $u['exchange'], 'symbol' => $u['symbol'], 'name' => $u['name'], 'lists' => array_values(array_unique($u['lists'])), 'rating' => $u['rating']] + $r['facts'];
        }

        // latest daily close + its date for the matches only (the "price now", and how fresh it is)
        foreach ($results as &$m) {
            $d = DB::table('stock_candles')->where('exchange', $m['exchange'])->where('symbol', $m['symbol'])->where('timeframe', 'daily')->orderByDesc('date')->first(['date', 'close']);
            $m['price'] = $d ? round((float) $d->close, 2) : null;
            $m['price_date'] = $d->date ?? null;
        }
        unset($m);

        return ['results' => $results, 'scanned' => count($universe), 'no_data' => $noData, 'no_candles' => $noCandles, 'matched' => count($results), 'floor' => self::LIST_FLOOR, 'params' => $params];
    }

    /** One symbol's full check list (why it is, or is not, in the results). */
    public static function check(string $scannerClass, string $exchange, string $symbol, array $params = []): array
    {
        $rows = self::monthly([['exchange' => $exchange, 'symbol' => $symbol]])[$exchange . ':' . $symbol] ?? [];
        if (!$rows) return ['status' => 'no_data', 'score' => null, 'passed' => 0, 'total' => 0, 'checks' => [], 'facts' => null, 'message' => "No monthly candles stored for {$exchange}:{$symbol} — is it in a watchlist, and have its candles been synced?"];
        return $scannerClass::evaluate($rows, $params);
    }

    /**
     * How fresh the stored candles are for the universe: symbols whose last successful sync is older than the last market close.
     * @return array{total: int, stale: int, last_synced: ?string, since: string}
     */
    public static function freshness(array $universe, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $close = $now->copy()->setTime(15, 35, 0);
        if ($now->lt($close)) $close->subDay();
        while ($close->isWeekend()) $close->subDay();

        $synced = [];
        foreach (DB::table('stock_candle_sync')->get(['exchange', 'symbol', 'synced_at']) as $s) $synced[$s->exchange . ':' . $s->symbol] = $s->synced_at;

        $stale = 0; $last = null;
        foreach ($universe as $k => $u) {
            $at = $synced[$k] ?? null;
            if ($at === null || $at < $close->toDateTimeString()) $stale++;
            if ($at !== null && ($last === null || $at > $last)) $last = $at;
        }
        return ['total' => count($universe), 'stale' => $stale, 'last_synced' => $last, 'since' => $close->toDateTimeString()];
    }
}
