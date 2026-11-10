<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LiveSignal;
use App\Services\Live\BrokerFactory;
use App\Services\Live\SignalProcessor;

class LiveProcessSignals extends Command
{
    protected $signature = 'live:process-signals {--no-wait : do one pass and stop}';

    protected $description = 'Live trading: act on the signals that have arrived (started by the webhook; live:tick also does it as a safety net). An alert is acted on once its 5-minute candle has closed, so this waits for that.';

    const MAX_WAIT_SECONDS = 420;   // an alert waits at most to the end of its candle plus a few minutes

    public function handle(): int
    {
        // one waiting process at a time: it picks up every alert that arrives while it runs
        $lock = fopen(storage_path('framework/live_process.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $this->line('Another process is already waiting for candles'); return self::SUCCESS; }

        $total = 0;
        $until = time() + self::MAX_WAIT_SECONDS;
        try {
            $processor = new SignalProcessor(BrokerFactory::make());
            do {
                $total += $processor->processNew();
                if ($this->option('no-wait') || !LiveSignal::where('status', 'new')->exists()) break;
                sleep(5);
            } while (time() < $until);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->info("{$total} signal(s) processed");
        return self::SUCCESS;
    }
}
