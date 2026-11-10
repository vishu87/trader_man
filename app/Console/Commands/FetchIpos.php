<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AppSetting;
use App\Services\IpoFetcher;

class FetchIpos extends Command
{
    protected $signature = 'ipos:fetch
        {--pages= : how many pages to fetch (default: every page on the first run, then 2)}
        {--full : run the initial load again}
        {--scheduled : used by the scheduler — never starts the big initial load by itself}';

    protected $description = "Fetch recent IPOs from Screener into the 'IPO' watchlist (initial load once, then the newest 2 pages)";

    public function handle(): int
    {
        // The scheduler must never kick off the large one-off load unattended: that is a deliberate manual run.
        if ($this->option('scheduled') && !AppSetting::value(IpoFetcher::INITIAL_DONE_KEY)) {
            $this->warn('Initial IPO load has not been done yet — run `php artisan ipos:fetch` once by hand. Nothing fetched.');
            return self::SUCCESS;
        }

        $result = IpoFetcher::run(
            $this->option('pages') ? (int) $this->option('pages') : null,
            (bool) $this->option('full'),
            fn ($line) => $this->line($line),
        );

        $result['success'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
