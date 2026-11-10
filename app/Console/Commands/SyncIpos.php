<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AppSetting;
use App\Services\IpoAlertsFetcher;

class SyncIpos extends Command
{
    protected $signature = 'ipos:sync
        {--from=2023-01-01 : initial load only — oldest IPO start date to include}
        {--full : run the initial load again from the start}
        {--scheduled : used by the scheduler — stays idle until the initial load has been done}';

    protected $description = "Fill the 'IPO' watchlist from ipoalerts.in (one-time load from 2023, then page 1 only)";

    public function handle(): int
    {
        // The initial load takes hours and quota, so it is always a deliberate, manual run.
        if ($this->option('scheduled') && !AppSetting::value(IpoAlertsFetcher::INITIAL_DONE_KEY)) {
            $this->warn('The one-time IPO load has not been done yet — run `php artisan ipos:sync` by hand. Nothing fetched.');
            return self::SUCCESS;
        }

        $result = IpoAlertsFetcher::run((bool) $this->option('full'), $this->option('from'), fn ($line) => $this->line($line));

        $result['success'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
