<?php

namespace App\Services\Live;

/** Starts live processing in the background, so the webhook can answer ChartInk at once. */
class Runner
{
    /** The php binary (a web request's PHP_BINARY can be Apache itself under MAMP). */
    public static function phpBin(): string
    {
        if ($env = env('PHP_BIN')) return $env;
        if (preg_match('/^php(-?\d[\d.]*)?$/', basename(PHP_BINARY))) return PHP_BINARY;
        $mamp = glob('/Applications/MAMP/bin/php/php*/bin/php') ?: [];
        rsort($mamp);
        return $mamp[0] ?? 'php';
    }

    public static function processInBackground(): void
    {
        $cmd = escapeshellarg(self::phpBin()) . ' ' . escapeshellarg(base_path('artisan')) . ' live:process-signals';
        exec('nohup ' . $cmd . ' > ' . escapeshellarg(storage_path('logs/live_process.log')) . ' 2>&1 &');
    }
}
