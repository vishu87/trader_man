<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;
use App\Models\PaperTrade;
use App\Models\TradeSignal;

/**
 * Paper trading: every valid BUY signal becomes a simulated trade, settled against the stored daily candles. No real order is
 * ever placed from here — its only job is to show, over time, whether Claude's calls would have made money.
 */
class PaperTradeService
{
    public static function active(string $exchange, string $symbol): ?PaperTrade
    {
        return PaperTrade::where('exchange', $exchange)->where('symbol', $symbol)
            ->whereIn('status', ['pending', 'open'])->orderByDesc('id')->first();
    }

    /**
     * Open a paper trade for a BUY signal that passed the risk rules.
     * @param array $risk result of RiskRules::apply()
     * @return array{trade: ?PaperTrade, note: ?string}
     */
    public static function open(TradeSignal $signal, array $risk): array
    {
        if ($risk['action'] !== 'buy' || !$risk['qty']) {
            return ['trade' => null, 'note' => null];
        }

        // one live paper trade per stock — re-analysing must not stack duplicates and skew the statistics
        if ($existing = self::active($signal->exchange, $signal->symbol)) {
            return ['trade' => $existing, 'note' => "A paper trade for this stock is already {$existing->status} (#{$existing->id}), so no second one was opened"];
        }

        $trade = PaperTrade::create([
            'signal_id' => $signal->id, 'exchange' => $signal->exchange, 'symbol' => $signal->symbol,
            'entry_type' => $risk['entry_type'], 'qty' => $risk['qty'],
            'entry_price' => $risk['entry'], 'stop_loss' => $risk['stop'], 'target_price' => $risk['target'],
            'valid_days' => $risk['valid_days'], 'status' => 'pending',
            'signal_date' => $signal->created_at->toDateString(),   // only candles AFTER this day can fill it
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['trade' => $trade, 'note' => null];
    }

    /** Re-play every pending / open trade over the candles stored since its signal. Safe to call any time. */
    public static function settle(?string $exchange = null, ?string $symbol = null): int
    {
        $query = PaperTrade::whereIn('status', ['pending', 'open']);
        if ($exchange && $symbol) {
            $query->where('exchange', $exchange)->where('symbol', $symbol);
        }

        $updated = 0;
        foreach ($query->get() as $t) {
            $candles = DB::table('stock_candles')
                ->where('exchange', $t->exchange)->where('symbol', $t->symbol)->where('timeframe', 'daily')
                ->where('date', '>', $t->signal_date)->orderBy('date')->get(['date', 'open', 'high', 'low', 'close'])
                ->map(fn ($c) => ['date' => $c->date, 'open' => (float) $c->open, 'high' => (float) $c->high, 'low' => (float) $c->low, 'close' => (float) $c->close])
                ->all();

            $r = PaperTradeSimulator::simulate($t->toArray(), $candles);

            $t->fill([
                'status' => $r['status'], 'filled_on' => $r['filled_on'], 'fill_price' => $r['fill_price'],
                'closed_on' => $r['closed_on'], 'exit_price' => $r['exit_price'], 'mark_price' => $r['mark_price'],
                'pnl' => $r['pnl'], 'r_multiple' => $r['r_multiple'], 'updated_at' => now(),
            ]);
            if ($t->isDirty()) {
                $t->save();
                $updated++;
            }
        }

        return $updated;
    }

    /** The scoreboard: is following Claude's BUY calls making money? */
    public static function stats(): array
    {
        $all    = PaperTrade::all();
        $closed = $all->whereIn('status', ['stopped', 'target_hit']);
        $wins   = $closed->filter(fn ($t) => (float) $t->pnl > 0);

        return [
            'signals'    => TradeSignal::count(),
            'buys'       => $all->count(),
            'pending'    => $all->where('status', 'pending')->count(),
            'open'       => $all->where('status', 'open')->count(),
            'expired'    => $all->where('status', 'expired')->count(),   // limit never touched: neither a win nor a loss
            'closed'     => $closed->count(),
            'wins'       => $wins->count(),
            'win_rate'   => $closed->count() ? round($wins->count() / $closed->count() * 100) : null,
            'avg_r'      => $closed->count() ? round($closed->avg(fn ($t) => (float) $t->r_multiple), 2) : null,
            'realised'   => round($closed->sum(fn ($t) => (float) $t->pnl), 2),
            'unrealised' => round($all->where('status', 'open')->sum(fn ($t) => (float) $t->pnl), 2),
        ];
    }
}
