<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CandleSyncService;

class SyncCandles extends Command
{
    protected $signature = 'candles:sync {--symbol= : only these symbols (comma separated) — always refetched} {--force : refetch even symbols that are already up to date} {--full : download the whole history again, not just the days after the last stored candle}';

    protected $description = 'Fetch daily + weekly candles (with indicators) from Kite for the portfolio and watchlists';

    public function handle(): int
    {
        if (!is_file(CandleSyncService::pythonBin())) {
            $this->error('Python environment not found: ' . CandleSyncService::pythonBin());
            return self::FAILURE;
        }

        passthru(CandleSyncService::command($this->option('symbol'), (bool) $this->option('force'), (bool) $this->option('full')), $code);

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }
}
