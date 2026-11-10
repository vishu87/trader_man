<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\KiteService;
use App\Services\ZerodhaTrades;

class SyncZerodhaTrades extends Command
{
    protected $signature = 'zerodha:sync-trades {--refresh-token : first fetch today\'s Kite access token from the token link} {--dry-run : show what would be saved, save nothing}';

    protected $description = 'Save today\'s executions from Zerodha Kite into the Zerodha Trades list (Kite only returns the current trading day)';

    public function handle(): int
    {
        if ($this->option('refresh-token')) {
            $token = KiteService::refreshAccessToken();
            $this->line($token['message']);
            if (!$token['success']) return self::FAILURE;
        }

        try {
            $res = ZerodhaTrades::syncToday((bool) $this->option('dry-run'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            Log::warning('zerodha:sync-trades failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $dry = $this->option('dry-run') ? ' (dry run — nothing saved)' : '';

        if ($res['fetched'] === 0) {
            $this->info('Kite has no executions for today' . $dry);
            return self::SUCCESS;
        }

        $this->info("Kite returned {$res['fetched']} execution(s): {$res['added']} new, {$res['duplicates']} already stored, {$res['conflicts']} differing, {$res['skipped']} unreadable" . $dry);
        foreach ($res['conflict_list'] as $line) $this->warn('  ' . $line);
        foreach ($res['skipped_list'] as $s)     $this->warn("  execution #{$s['line']}: {$s['reason']}");

        return self::SUCCESS;
    }
}
