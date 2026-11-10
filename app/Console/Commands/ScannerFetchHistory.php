<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Scanner\HistoryFetcher;

class ScannerFetchHistory extends Command
{
    protected $signature = 'scanner:fetch-history {--symbol= : only these symbols (comma separated)} {--from= : only signals from this date (Y-m-d)} {--to= : …to this date}';

    protected $description = 'Download 5-minute and daily candles from Kite for every symbol in the scanner signals (only what is missing)';

    public function handle(): int
    {
        try {
            $only = $this->option('symbol') ? array_map('trim', explode(',', strtoupper($this->option('symbol')))) : null;
            $res = HistoryFetcher::run(fn ($line) => $this->line($line), null, $only, $this->option('from') ?: null, $this->option('to') ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            // let the page show why instead of an empty progress bar
            @file_put_contents(HistoryFetcher::statusFile(), json_encode(['total' => 0, 'done' => 0, 'failed' => [], 'error' => $e->getMessage(), 'finished_at' => now()->toDateTimeString()]));
            return self::FAILURE;
        }

        $this->info("{$res['symbols']} symbols, {$res['candles']} new candles, " . count($res['failed']) . ' failed');
        foreach ($res['failed'] as $f) $this->warn("  {$f['symbol']}: {$f['reason']}");

        return self::SUCCESS;
    }
}
