<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Live\SignalDeduper;

class LiveDedupeSignals extends Command
{
    protected $signature = 'live:dedupe-signals {--dry-run : only count what would be moved} {--delete : delete the repeats instead of moving them to the duplicates table}';

    protected $description = 'Live signals: keep ONE signal per stock per day (the one triggered first) and move every later repeat to live_signal_duplicates (or delete it). Never touches signals that are live or were bought.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = SignalDeduper::run(!$dry, (bool) $this->option('delete'));

        $this->line('Alerts treated as enabled (compared with each other): ' . (implode(', ', $r['enabled_alerts']) ?: '—'));
        $this->line("Stock-days with repeats: {$r['groups']}   repeat signals found: {$r['repeats']}");
        if ($r['kept_active'] || $r['kept_bought']) $this->line("Left alone: {$r['kept_active']} still live (new / watching / waiting), {$r['kept_bought']} bought");
        foreach ($r['by_alert'] as $a => $n) $this->line("  {$a}: {$n}");
        foreach ($r['sample'] as $s) $this->line("  e.g. {$s['day']} {$s['symbol']} {$s['alert']} {$s['at']} — repeat of {$s['first_alert']} {$s['first_at']}");
        $this->info($dry ? "Dry run: {$r['moved']} would be " . ($this->option('delete') ? 'deleted' : 'moved to the duplicates table') . '. Run without --dry-run to do it.'
                         : "{$r['moved']} repeat signal(s) " . ($r['deleted'] ? 'deleted' : 'moved to the duplicates table') . '.');
        return self::SUCCESS;
    }
}
