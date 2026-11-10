<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SymbolFib;
use App\Services\FibService;

class RebuildFibs extends Command
{
    protected $signature = 'fibs:rebuild';

    protected $description = 'Recompute the stored levels of every saved Fibonacci swing (after the set of ratios or their meaning changed)';

    public function handle(): int
    {
        $n = 0;
        foreach (SymbolFib::all() as $fib) {
            FibService::store(
                $fib->exchange, $fib->symbol,
                ['low' => (float) $fib->low_price, 'high' => (float) $fib->high_price, 'direction' => $fib->direction],
                [
                    'label' => $fib->label, 'low_date' => $fib->low_date ? substr((string) $fib->low_date, 0, 10) : null,
                    'high_date' => $fib->high_date ? substr((string) $fib->high_date, 0, 10) : null, 'source' => $fib->source, 'is_shown' => $fib->is_shown,
                ],
                $fib->id
            );
            $n++;
        }

        $this->info("Rebuilt the levels of {$n} swing" . ($n === 1 ? '' : 's'));
        return self::SUCCESS;
    }
}
