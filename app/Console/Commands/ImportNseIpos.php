<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Watchlist;
use App\Services\NseIpoCsvImporter;

class ImportNseIpos extends Command
{
    protected $signature = 'ipos:import-nse
        {file : path to the NSE "IPO Past Issue" CSV}
        {--list=4 : watchlist id to load into}
        {--from=2023-01-01 : ignore IPOs listed before this date}
        {--update : also refresh the name / listing date of symbols already in the list (default: add only)}
        {--dry-run : show what would happen, save nothing}';

    protected $description = "Load NSE's past-issue IPO CSV into a watchlist (symbol, company name, listing date)";

    public function handle(): int
    {
        $list = Watchlist::find($this->option('list'));
        if (!$list) {
            $this->error('No watchlist with id ' . $this->option('list'));
            return self::FAILURE;
        }

        try {
            if ($this->option('dry-run')) {
                $parsed = NseIpoCsvImporter::parse($this->argument('file'), $this->option('from'));
                $result = ['read' => $parsed['read'], 'skipped' => $parsed['skipped'], 'added' => count($parsed['rows']), 'updated' => 0, 'existing' => 0, 'estimated' => $parsed['estimated']];
            } else {
                $result = NseIpoCsvImporter::import($this->argument('file'), $list, $this->option('from'), (bool) $this->option('update'));
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->line("Target watchlist: #{$list->id} {$list->name}");
        $this->line("Rows read: {$result['read']}");

        $byReason = collect($result['skipped'])->groupBy(fn ($s) => preg_replace('/\d{4}-\d{2}-\d{2}/', '…', $s['reason']));
        foreach ($byReason as $reason => $items) {
            $shown = $items->take(12)->map(fn ($s) => $s['symbol'])->implode(', ');
            $this->line(sprintf('  skipped %3d — %s%s', $items->count(), $reason, $items->count() <= 12 ? " ({$shown})" : " (e.g. {$shown} …)"));
        }

        if ($result['estimated']) {
            $this->line(sprintf('  estimated listing date (issue end + 3 days) for %d — %s', count($result['estimated']), implode(', ', $result['estimated'])));
        }

        $verb = $this->option('dry-run') ? 'would be added' : 'added';
        $this->info("{$result['added']} {$verb}" . ($this->option('dry-run') ? '' : ", {$result['updated']} updated") . ' — ' . count($result['skipped']) . ' skipped');

        return self::SUCCESS;
    }
}
