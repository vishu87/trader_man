<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\KiteService;

class RefreshKiteToken extends Command
{
    protected $signature = 'kite:refresh-token';

    protected $description = 'Fetch the Kite access token from the token link and store it in app_settings when it differs from the stored one (scheduled hourly)';

    public function handle(): int
    {
        $r = KiteService::refreshAccessToken();
        $r['success'] ? $this->info($r['message']) : $this->error($r['message']);
        return $r['success'] ? self::SUCCESS : self::FAILURE;
    }
}
