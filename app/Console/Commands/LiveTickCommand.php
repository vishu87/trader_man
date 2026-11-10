<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Live\BrokerFactory;
use App\Services\Live\LiveTick;

class LiveTickCommand extends Command
{
    protected $signature = 'live:tick';

    protected $description = 'Live trading, once a minute: process waiting signals, update orders, stop / target / time exits';

    public function handle(): int
    {
        try {
            $r = (new LiveTick(BrokerFactory::make()))->run();
        } catch (\Throwable $e) {
            $this->error('live:tick failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!$r['market_open']) { $this->line('Market closed'); return self::SUCCESS; }
        $this->info("[{$r['mode']}] signals {$r['signals']}, orders updated {$r['orders_updated']}, cancelled {$r['cancelled']}, exits " . ($r['exits'] ? implode('; ', $r['exits']) : 'none') . ", extra buys " . ($r['averages'] ? implode('; ', $r['averages']) : 'none'));
        foreach (array_merge($r['stuck'], $r['attention']) as $line) $this->warn('  ' . $line);

        return self::SUCCESS;
    }
}
