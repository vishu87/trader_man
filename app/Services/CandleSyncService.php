<?php

namespace App\Services;

/**
 * Runs python/candle_storage.py — either in the foreground (artisan command / scheduler)
 * or detached in the background (the "Sync candles" button on the Charts page).
 */
class CandleSyncService
{
    // a lock older than this is assumed to be left behind by a crashed run
    const LOCK_MAX_AGE = 1800;

    public static function pythonBin(): string
    {
        return config('services.candles.python') ?: base_path('python/venv/bin/python');
    }

    public static function script(): string
    {
        return base_path('python/candle_storage.py');
    }

    public static function lockFile(): string
    {
        return storage_path('app/candle_sync.lock');
    }

    public static function logFile(): string
    {
        return storage_path('logs/candle_sync.log');
    }

    /**
     * Shell command for one sync run. $symbols: comma separated, or null for everything.
     * A run only fetches the days after what is already stored; $full downloads the whole history again
     * (naming symbols does that too — it is the way to repair one). $quick: named symbols only fetch what is new since the last stored day.
     */
    public static function command(?string $symbols = null, bool $force = false, bool $full = false, bool $quick = false): string
    {
        $cmd = 'PYTHONWARNINGS=ignore ' . escapeshellarg(self::pythonBin()) . ' ' . escapeshellarg(self::script());
        if ($symbols) {
            $cmd .= ' --symbol ' . escapeshellarg($symbols);   // naming symbols always refetches them
        }
        if ($force) {
            $cmd .= ' --force';
        }
        if ($full) {
            $cmd .= ' --full';
        }
        if ($quick && $symbols && !$full) {
            $cmd .= ' --incremental';   // named symbols, but only the days after the last stored one (fast: today's candle)
        }
        return $cmd;
    }

    public static function isRunning(): bool
    {
        $lock = self::lockFile();
        return file_exists($lock) && filemtime($lock) > time() - self::LOCK_MAX_AGE;
    }

    /** @return array{started: bool, message: string} */
    public static function startInBackground(?string $symbols = null, bool $force = false, bool $quick = false): array
    {
        if (self::isRunning()) {
            return ['started' => false, 'message' => 'A candle sync is already running'];
        }
        if (!is_file(self::pythonBin())) {
            return ['started' => false, 'message' => 'Python environment not found: ' . self::pythonBin() . ' (see python/requirements.txt)'];
        }

        // Take the lock HERE, before launching: if the detached shell created it, there would be a gap in which a
        // second click (or the status poll) sees "not running" and starts a duplicate run.
        touch(self::lockFile());

        $lock = escapeshellarg(self::lockFile());
        $log  = escapeshellarg(self::logFile());

        // run, then always release the lock — detached from the web request
        $shell = self::command($symbols, $force, false, $quick) . ' > ' . $log . ' 2>&1; rm -f ' . $lock;
        exec('nohup sh -c ' . escapeshellarg($shell) . ' > /dev/null 2>&1 &');

        return ['started' => true, 'message' => 'Candle sync started'];
    }

    /** @return array{running: bool, log: string} last lines of the latest run's output */
    public static function status(): array
    {
        $log = is_file(self::logFile()) ? trim((string) shell_exec('tail -n 6 ' . escapeshellarg(self::logFile()))) : '';
        return ['running' => self::isRunning(), 'log' => $log];
    }
}
