<?php

namespace App\Services\Scanner;

use Illuminate\Support\Facades\DB;
use App\Services\KiteService;

/**
 * Downloads the candles the backtester needs from Kite, for every symbol the scanner has flagged:
 *   - 5-minute candles from 6 days before the first signal on (entries / exits are simulated on these; the days before give indicators their history)
 *   - daily candles from 900 days before the first signal (liquidity, and the daily / monthly characteristics of the signal quality tab)
 * Only what is missing is fetched, so running it again after a new upload / the next day is quick.
 *
 * Kite allows about 3 historical requests a second; this paces itself under that.
 */
class HistoryFetcher
{
    const DAILY_LOOKBACK_DAYS = 900;    // ~3 years of daily candles before the first signal: daily and monthly characteristics (monthly Bollinger needs ~21 months)
    const INTRADAY_LOOKBACK_DAYS = 6;   // 5-minute candles from this many days before the first signal, so the RSI / VWAP / SMA have history on the first day
    const INTRADAY_CHUNK_DAYS = 55;     // Kite's limit for 5-minute data is 100 days per request
    const PAUSE_MICROS        = 380000; // ≈ 2.6 requests / second
    const LOCK_MAX_AGE        = 3600;

    public static function lockFile(): string   { return storage_path('app/scanner_fetch.lock'); }
    public static function statusFile(): string { return storage_path('app/scanner_fetch.json'); }
    public static function logFile(): string    { return storage_path('logs/scanner_fetch.log'); }

    public static function isRunning(): bool
    {
        return file_exists(self::lockFile()) && filemtime(self::lockFile()) > time() - self::LOCK_MAX_AGE;
    }

    public static function status(): array
    {
        $s = is_file(self::statusFile()) ? json_decode((string) file_get_contents(self::statusFile()), true) : null;
        $s = is_array($s) ? $s : ['total' => 0, 'done' => 0, 'failed' => [], 'finished_at' => null];
        $s['running'] = self::isRunning();
        return $s;
    }

    /** The php binary to start the background job with (a web request's PHP_BINARY can be Apache itself under MAMP). */
    public static function phpBin(): string
    {
        if ($env = env('PHP_BIN')) return $env;
        if (preg_match('/^php(-?\d[\d.]*)?$/', basename(PHP_BINARY))) return PHP_BINARY;
        $mamp = glob('/Applications/MAMP/bin/php/php*/bin/php') ?: [];
        rsort($mamp);
        return $mamp[0] ?? 'php';
    }

    /** @return array{started: bool, message: string} */
    public static function startInBackground(?string $from = null, ?string $to = null): array
    {
        if (self::isRunning()) return ['started' => false, 'message' => 'A candle download is already running'];
        $has = DB::table('scanner_signals')->when($from && $to, fn ($q) => $q->whereBetween('trade_date', [$from, $to]))->exists();
        if (!$has) return ['started' => false, 'message' => 'There are no scanner signals in that period'];

        touch(self::lockFile());   // take the lock here so a second click cannot start a duplicate
        file_put_contents(self::statusFile(), json_encode(['total' => 0, 'done' => 0, 'failed' => [], 'current' => null, 'started_at' => now()->toDateTimeString(), 'finished_at' => null]));

        $shell = escapeshellarg(self::phpBin()) . ' ' . escapeshellarg(base_path('artisan')) . ' scanner:fetch-history' . ($from && $to ? ' --from=' . escapeshellarg($from) . ' --to=' . escapeshellarg($to) : '') . ' > ' . escapeshellarg(self::logFile())
               . ' 2>&1; rm -f ' . escapeshellarg(self::lockFile());
        exec('nohup sh -c ' . escapeshellarg($shell) . ' > /dev/null 2>&1 &');

        return ['started' => true, 'message' => 'Downloading candles from Kite in the background'];
    }

    public static function symbols(?string $from = null, ?string $to = null): array
    {
        return DB::table('scanner_signals')->when($from && $to, fn ($q) => $q->whereBetween('trade_date', [$from, $to]))->select('exchange', 'symbol')->distinct()->orderBy('symbol')->get()->map(fn ($r) => ['exchange' => $r->exchange, 'symbol' => $r->symbol])->all();
    }

    // ─── the job ─────────────────────────────────────────────────────────────

    const TAIL_DAYS = 10;      // keep this many days after the last signal, so holds / next-day exits have candles
    const CLUSTER_GAP = 45;    // signals more than this many days apart are fetched as separate periods

    /**
     * @param callable|null $log   fn(string $line)
     * @param object|null   $kite  a Kite client (tests pass a fake with getHistoricalData / getInstruments)
     * @param string[]|null $only  fetch just these symbols
     * @param string|null   $from  with $to: only the signals of that period (Y-m-d)
     * @return array{symbols: int, fetched_5min: int, fetched_day: int, candles: int, failed: array}
     */
    public static function run(?callable $log = null, $kite = null, ?array $only = null, ?string $from = null, ?string $to = null): array
    {
        $log = $log ?: function (string $l) {};
        $kite = $kite ?: KiteService::client();

        $symbols = self::symbols($from, $to);
        if ($only) $symbols = array_values(array_filter($symbols, fn ($s) => in_array($s['symbol'], $only, true)));
        if (!$symbols) throw new \RuntimeException('There are no scanner signals to fetch candles for');

        // each symbol's own signal dates: only the days around them are fetched (a 2022 set must not pull four years of 5-minute data)
        $dates = [];
        foreach (DB::table('scanner_signals')->when($from && $to, fn ($q) => $q->whereBetween('trade_date', [$from, $to]))->select('symbol', 'trade_date')->distinct()->orderBy('trade_date')->get() as $r) {
            $dates[$r->symbol][] = substr((string) $r->trade_date, 0, 10);
        }

        self::syncInstruments($kite, $log);
        $tokens = DB::table('scanner_instruments')->pluck('instrument_token', DB::raw("CONCAT(exchange, ':', symbol)"))->all();

        $out = ['symbols' => count($symbols), 'fetched_5min' => 0, 'fetched_day' => 0, 'candles' => 0, 'failed' => []];
        $status = ['total' => count($symbols), 'done' => 0, 'failed' => [], 'current' => null, 'started_at' => now()->toDateTimeString(), 'finished_at' => null];
        $save = fn () => @file_put_contents(self::statusFile(), json_encode($status));
        $save();

        $now = date('Y-m-d H:i:s');

        foreach ($symbols as $s) {
            $key = $s['exchange'] . ':' . $s['symbol'];
            $status['current'] = $s['symbol'];

            if (!isset($tokens[$key])) {
                $out['failed'][] = ['symbol' => $s['symbol'], 'reason' => 'not in the Kite instrument list (renamed or delisted?)'];
                $status['failed'] = $out['failed']; $status['done']++; $save();
                continue;
            }
            $token = (int) $tokens[$key];
            $d = $dates[$s['symbol']] ?? [];

            try {
                // the periods to fetch: [timeframe, interval, from, to]
                $jobs = [];
                foreach (self::clusters($d) as [$a, $b]) {
                    $end = min($now, date('Y-m-d', strtotime($b . ' +' . self::TAIL_DAYS . ' days')) . ' 23:59:59');
                    // daily: a lookback before the period (liquidity needs the sessions before the signal), 5-minute: the period itself
                    $jobs[] = ['day', 'day', date('Y-m-d', strtotime($a . ' -' . self::DAILY_LOOKBACK_DAYS . ' days')) . ' 00:00:00', $end];
                    $jobs[] = ['5min', '5minute', date('Y-m-d', strtotime($a . ' -' . self::INTRADAY_LOOKBACK_DAYS . ' days')) . ' 09:00:00', $end];
                }

                foreach ($jobs as [$tf, $interval, $from, $to]) {
                    $n = self::fetchRange($kite, $s, $token, $tf, $interval, self::resumeFrom($s, $tf, $from, $to), $to);
                    $out['candles'] += $n;
                    $out['fetched_' . $tf] += $n > 0 ? 1 : 0;
                }
            } catch (\Throwable $e) {
                $out['failed'][] = ['symbol' => $s['symbol'], 'reason' => mb_substr($e->getMessage(), 0, 160)];
                $log("  {$s['symbol']}: " . $e->getMessage());
            }

            $status['failed'] = $out['failed']; $status['done']++; $save();
            $log(sprintf('[%d/%d] %s', $status['done'], $status['total'], $s['symbol']));
        }

        $status['current'] = null; $status['finished_at'] = now()->toDateTimeString(); $save();

        return $out;
    }

    /** Sorted dates → [[first, last], …]: runs of signals less than CLUSTER_GAP days apart. */
    public static function clusters(array $dates): array
    {
        $out = [];
        foreach ($dates as $d) {
            $n = count($out);
            if ($n && (strtotime($d) - strtotime($out[$n - 1][1])) / 86400 <= self::CLUSTER_GAP) $out[$n - 1][1] = $d;
            else $out[] = [$d, $d];
        }
        return $out;
    }

    /** Where to start within [from, to]: from the start, or — when the start is already stored — just the last stored day. */
    private static function resumeFrom(array $s, string $tf, string $from, string $to): string
    {
        $base = fn () => DB::table('scanner_candles')->where('exchange', $s['exchange'])->where('symbol', $s['symbol'])->where('timeframe', $tf)->whereBetween('candle_at', [$from, $to]);
        $min = $base()->min('candle_at');
        if ($min && substr($min, 0, 10) <= date('Y-m-d', strtotime(substr($from, 0, 10) . ' +5 days'))) {
            return substr($base()->max('candle_at'), 0, 10) . ' 00:00:00';   // it may have been a part-day
        }
        return $from;
    }

    /** Fetch [from, to] in windows Kite accepts, store the candles; returns how many were new. */
    private static function fetchRange($kite, array $s, int $token, string $tf, string $interval, string $from, string $to): int
    {
        $new = 0;
        $cursor = strtotime($from);
        $end    = strtotime($to);
        $window = $tf === '5min' ? self::INTRADAY_CHUNK_DAYS : 1500;

        while ($cursor < $end) {
            $chunkEnd = min($end, $cursor + $window * 86400);
            $candles  = self::request($kite, $token, $interval, date('Y-m-d H:i:s', $cursor), date('Y-m-d H:i:s', $chunkEnd));

            $rows = [];
            foreach ($candles as $c) {
                $at = $c->date instanceof \DateTimeInterface ? $c->date->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime((string) $c->date));
                if ($tf === 'day') $at = substr($at, 0, 10) . ' 00:00:00';
                $rows[] = ['exchange' => $s['exchange'], 'symbol' => $s['symbol'], 'timeframe' => $tf, 'candle_at' => $at,
                           'open' => round((float) $c->open, 2), 'high' => round((float) $c->high, 2), 'low' => round((float) $c->low, 2),
                           'close' => round((float) $c->close, 2), 'volume' => (int) $c->volume];
            }
            foreach (array_chunk($rows, 500) as $chunk) $new += DB::table('scanner_candles')->insertOrIgnore($chunk);

            $cursor = $chunkEnd;
        }

        return $new;
    }

    /** One request, paced; retried a few times because Kite sometimes answers with an error page / rate limit instead of candles. */
    private static function request($kite, int $token, string $interval, string $from, string $to): array
    {
        for ($attempt = 1; ; $attempt++) {
            usleep(self::PAUSE_MICROS);
            try {
                $r = $kite->getHistoricalData($token, $interval, $from, $to);
                return is_array($r) ? $r : [];
            } catch (\Throwable $e) {
                if ($attempt >= 4) throw new \RuntimeException('Kite did not return candles after 4 tries: ' . $e->getMessage());
                sleep($attempt * 2);
            }
        }
    }

    /** NSE equities and their tokens, refreshed once a day. */
    public static function syncInstruments($kite, ?callable $log = null): int
    {
        $stamp = DB::table('scanner_instruments')->max('updated_at');
        if ($stamp && substr($stamp, 0, 10) === date('Y-m-d')) return 0;

        $list = $kite->getInstruments('NSE');
        $now  = now()->toDateTimeString();
        $rows = [];
        foreach ($list as $i) {
            $i = (object) (array) $i;
            if (($i->instrument_type ?? '') !== 'EQ' || ($i->segment ?? '') !== 'NSE') continue;
            $rows[] = ['exchange' => 'NSE', 'symbol' => (string) $i->tradingsymbol, 'instrument_token' => (int) $i->instrument_token,
                       'name' => mb_substr((string) ($i->name ?? ''), 0, 120) ?: null, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('scanner_instruments')->upsert($chunk, ['exchange', 'symbol'], ['instrument_token', 'name', 'updated_at']);
        }
        if ($log) $log('Instrument list: ' . count($rows) . ' NSE equities');

        return count($rows);
    }
}
