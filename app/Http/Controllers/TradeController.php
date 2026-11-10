<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\AppSetting;
use App\Models\PaperTrade;
use App\Models\PortfolioBucket;
use App\Models\PortfolioHolding;
use App\Models\TradeSignal;
use App\Models\WatchlistItem;
use App\Services\KiteService;
use App\Services\Trading\PaperTradeService;
use App\Services\Trading\RiskRules;
use App\Services\Trading\SignalAnalyst;
use App\Services\Trading\SignalContext;

class TradeController extends Controller
{
    // re-clicking "Analyse" within this window shows the signal already made instead of paying for another one
    const REUSE_MINUTES = 15;

    // ─── Everything the Trade panel shows for one symbol ─────────────────────

    public function state(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));
        if ($off = $this->switchedOff()) return $off;

        [$exchange, $symbol, $error] = $this->symbolFrom($request);
        if ($error) return $error;

        PaperTradeService::settle($exchange, $symbol);   // bring this stock's paper trade up to date first

        $quote = KiteService::getLtps([$exchange . ':' . $symbol]);
        $ltp   = $quote['ltps'][$exchange . ':' . $symbol] ?? KiteService::NO_LTP;

        $paper = PaperTrade::where('exchange', $exchange)->where('symbol', $symbol)->orderByDesc('id')->first();

        return Response::json([
            'success'        => true,
            'ltp'            => $ltp,
            'max_loss'       => AppSetting::maxLossPerTrade(),
            'holding'        => $this->holding($exchange, $symbol),
            'plan'           => $this->plan($exchange, $symbol),
            'signals'        => TradeSignal::where('exchange', $exchange)->where('symbol', $symbol)->orderByDesc('id')->limit(5)->get()->map(fn ($s) => $this->signalPayload($s))->all(),
            'paper_trade'    => $paper ? $this->paperPayload($paper) : null,
            'stats'          => PaperTradeService::stats(),
            'api_key_set'    => SignalAnalyst::keySet(),
            'key_name'       => SignalAnalyst::keyName(),
            'provider'       => SignalAnalyst::providerLabel(),
            'model'          => SignalAnalyst::modelName(),
            'analyses_today' => TradeSignal::where('created_at', '>=', today())->count(),
            'max_per_day'    => config('services.ai.max_per_day'),
        ], 200);
    }

    // ─── Ask the AI ──────────────────────────────────────────────────────────

    public function analyse(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));
        if ($off = $this->switchedOff()) return $off;
        set_time_limit(240);   // a thoughtful answer can take a minute

        [$exchange, $symbol, $error] = $this->symbolFrom($request);
        if ($error) return $error;

        // only stocks you actually track: an analysis costs money, so it is not open to any symbol
        $holding = $this->holding($exchange, $symbol);
        $item    = WatchlistItem::where('exchange', $exchange)->where('symbol', $symbol)->first();
        if (!$holding && !$item && !PortfolioHolding::where('exchange', $exchange)->where('zerodha_symbol', $symbol)->exists()) {
            return $this->fail("{$exchange}:{$symbol} is not in your portfolio or any watchlist");
        }

        if (!SignalAnalyst::keySet()) {
            return $this->fail(SignalAnalyst::keyName() . ' is not set. Add it to .env (and save the file); if the config is cached run `php artisan config:clear`.');
        }

        // a recent answer is reused rather than paid for twice (unless the user insists)
        if (!$request->boolean('force')) {
            $recent = TradeSignal::where('exchange', $exchange)->where('symbol', $symbol)
                ->where('created_at', '>=', now()->subMinutes(self::REUSE_MINUTES))->orderByDesc('id')->first();
            if ($recent) {
                return Response::json(['success' => true, 'reused' => true, 'signal' => $this->signalPayload($recent), 'paper_trade' => $this->latestPaper($exchange, $symbol), 'message' => 'Analysed ' . $recent->created_at->diffForHumans() . ' — showing that one'], 200);
            }
        }

        $cap = (int) config('services.ai.max_per_day');
        if ($cap > 0 && TradeSignal::where('created_at', '>=', today())->count() >= $cap) {
            return $this->fail("Daily limit of {$cap} analyses reached (AI_MAX_ANALYSES_PER_DAY) — it resets tomorrow");
        }

        $candles = SignalContext::loadCandles($exchange, $symbol);
        if (count($candles['daily']) < SignalContext::MIN_DAILY) {
            return $this->fail('Not enough candle history for ' . $symbol . ' yet (' . count($candles['daily']) . ' daily candles, need ' . SignalContext::MIN_DAILY . ') — sync candles first');
        }

        // live price, falling back to the last close
        $quote = KiteService::getLtps([$exchange . ':' . $symbol]);
        $ltp   = $quote['ltps'][$exchange . ':' . $symbol] ?? KiteService::NO_LTP;
        $price = $ltp > 0 ? (float) $ltp : (float) end($candles['daily'])['close'];
        $maxLoss = AppSetting::maxLossPerTrade();

        $plan = $this->plan($exchange, $symbol);
        $context = SignalContext::render($exchange, $symbol, $candles, [
            'name' => $item->name ?? null, 'ltp' => $price, 'max_loss' => $maxLoss,
            'holding' => $holding ? ['qty' => $holding['quantity'], 'avg' => $holding['avg_price'], 'buckets' => implode(', ', $holding['buckets'])] : null,
            'plan' => ($plan && ($plan['buy_price'] !== null || $plan['stop_loss'] !== null)) ? ['buy' => $plan['buy_price'], 'stop_loss' => $plan['stop_loss']] : null,
        ]);

        try {
            $answer = app(SignalAnalyst::class)->analyse($context);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        // the model's opinion goes through the hard rules before anything is stored as a signal or a trade
        $risk = RiskRules::apply($answer['data'], $price, $maxLoss);

        $signal = TradeSignal::create([
            'exchange' => $exchange, 'symbol' => $symbol, 'action' => $risk['action'],
            'pattern' => mb_substr((string) ($answer['data']['pattern'] ?? ''), 0, 150),
            'confidence' => $risk['confidence'],
            'entry_price' => $risk['entry'], 'stop_loss' => $risk['stop'], 'target_price' => $risk['target'],
            'reward_risk' => $risk['reward_risk'], 'valid_days' => $risk['valid_days'], 'ltp' => round($price, 4),
            'summary' => (string) ($answer['data']['summary'] ?? ''),
            'analysis' => json_encode(['claude' => $answer['data'], 'prompt_version' => SignalAnalyst::PROMPT_VERSION]),
            'risk_note' => $risk['note'], 'model' => $answer['model'] ?? SignalAnalyst::modelName(),
            'input_tokens' => $answer['input_tokens'] ?? null, 'output_tokens' => $answer['output_tokens'] ?? null,
            'created_at' => now(),
        ]);

        $paper = PaperTradeService::open($signal, $risk);

        return Response::json([
            'success' => true, 'reused' => false,
            'signal' => $this->signalPayload($signal),
            'paper_trade' => $paper['trade'] ? $this->paperPayload($paper['trade']) : null,
            'paper_note' => $paper['note'],
            'message' => 'Analysis done',
        ], 200);
    }

    // ─── "Use as my plan": copy entry / stop loss onto the watchlist ─────────

    public function planSave(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        [$exchange, $symbol, $error] = $this->symbolFrom($request);
        if ($error) return $error;

        $num = function ($v) { $v = str_replace(',', '', trim((string) $v)); return $v === '' ? null : (is_numeric($v) ? (float) $v : false); };
        $buy = $num($request->buy_price);
        $sl  = $num($request->stop_loss);
        if ($buy === false || $sl === false || ($buy !== null && $buy <= 0) || ($sl !== null && $sl <= 0)) return $this->fail('Buy price and stop loss must be positive numbers');
        if ($buy !== null && $sl !== null && $sl >= $buy) return $this->fail('Stop loss must be below the buy price');

        $count = WatchlistItem::where('exchange', $exchange)->where('symbol', $symbol)->update(['buy_price' => $buy, 'stop_loss' => $sl, 'updated_at' => now()]);
        if ($count === 0) return $this->fail("{$exchange}:{$symbol} is not in any watchlist — add it to one to keep a plan");

        return Response::json(['success' => true, 'message' => 'Plan saved', 'plan' => $this->plan($exchange, $symbol)], 200);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: ?string, 1: ?string, 2: mixed} [exchange, symbol, error response] */
    private function symbolFrom(Request $request): array
    {
        $validator = Validator::make($request->all(), ['symbol' => 'required|string|max:50', 'exchange' => 'nullable|in:NSE,BSE']);
        if (!$validator->passes()) return [null, null, $this->fail($validator->errors()->first())];

        return [$request->exchange ?: 'NSE', strtoupper(trim($request->symbol)), null];
    }

    /** TRADE_SIGNALS_ENABLED=false: no analysis, no signals, no paper trades. */
    private function switchedOff()
    {
        return config('services.trade_signals.enabled') ? null : $this->fail('Trade signals are switched off (TRADE_SIGNALS_ENABLED=false in .env)');
    }

    private function fail(string $message)
    {
        return Response::json(['success' => false, 'message' => $message], 200);
    }

    /** What the user owns of this stock (tracking-only and empty placeholders are not "owned"). */
    private function holding(string $exchange, string $symbol): ?array
    {
        $rows = PortfolioHolding::where('exchange', $exchange)->where('zerodha_symbol', $symbol)
            ->where('is_tracking', 0)->where('quantity', '>', 0)->get();
        if ($rows->isEmpty()) return null;

        $qty = (int) $rows->sum('quantity');
        $names = PortfolioBucket::pluck('name', 'id');

        return [
            'quantity'  => $qty,
            'avg_price' => round($rows->sum(fn ($h) => $h->quantity * $h->avg_price) / $qty, 2),
            'buckets'   => $rows->map(fn ($h) => ($names[$h->bucket_id] ?? '—') . ' (' . $h->quantity . ')')->all(),
        ];
    }

    /** The user's own buy price / stop loss from the watchlist, if any. */
    private function plan(string $exchange, string $symbol): ?array
    {
        $items = WatchlistItem::where('exchange', $exchange)->where('symbol', $symbol)->get();
        if ($items->isEmpty()) return null;

        $item = $items->first(fn ($i) => $i->buy_price !== null || $i->stop_loss !== null) ?? $items->first();

        return [
            'buy_price' => $item->buy_price !== null ? (float) $item->buy_price : null,
            'stop_loss' => $item->stop_loss !== null ? (float) $item->stop_loss : null,
        ];
    }

    private function latestPaper(string $exchange, string $symbol): ?array
    {
        $t = PaperTrade::where('exchange', $exchange)->where('symbol', $symbol)->orderByDesc('id')->first();
        return $t ? $this->paperPayload($t) : null;
    }

    private function signalPayload(TradeSignal $s): array
    {
        $analysis = json_decode((string) $s->analysis, true)['claude'] ?? [];

        return [
            'id' => $s->id, 'action' => $s->action, 'pattern' => $s->pattern, 'confidence' => $s->confidence,
            'entry_price' => $s->entry_price !== null ? (float) $s->entry_price : null,
            'stop_loss' => $s->stop_loss !== null ? (float) $s->stop_loss : null,
            'target_price' => $s->target_price !== null ? (float) $s->target_price : null,
            'reward_risk' => $s->reward_risk !== null ? (float) $s->reward_risk : null,
            'valid_days' => $s->valid_days, 'ltp' => $s->ltp !== null ? (float) $s->ltp : null,
            'summary' => $s->summary, 'reasons' => $analysis['reasons'] ?? [], 'risks' => $analysis['risks'] ?? [],
            'invalidation' => $analysis['invalidation'] ?? null,
            'risk_note' => $s->risk_note, 'model' => $s->model,
            'created_at' => $s->created_at->toDateTimeString(), 'ago' => $s->created_at->diffForHumans(),
        ];
    }

    private function paperPayload(PaperTrade $t): array
    {
        return [
            'id' => $t->id, 'signal_id' => $t->signal_id, 'status' => $t->status, 'entry_type' => $t->entry_type, 'qty' => $t->qty,
            'entry_price' => (float) $t->entry_price, 'stop_loss' => (float) $t->stop_loss, 'target_price' => (float) $t->target_price,
            'valid_days' => $t->valid_days, 'signal_date' => $t->signal_date, 'filled_on' => $t->filled_on, 'fill_price' => $t->fill_price !== null ? (float) $t->fill_price : null,
            'closed_on' => $t->closed_on, 'exit_price' => $t->exit_price !== null ? (float) $t->exit_price : null,
            'mark_price' => $t->mark_price !== null ? (float) $t->mark_price : null,
            'pnl' => $t->pnl !== null ? (float) $t->pnl : null, 'r_multiple' => $t->r_multiple !== null ? (float) $t->r_multiple : null,
        ];
    }
}
