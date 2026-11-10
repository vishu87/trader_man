<?php

namespace App\Services\Trading;

/**
 * Hard rules, in plain code, between Claude's opinion and everything that follows. Claude can suggest; it cannot override
 * these. A BUY that breaks any rule is downgraded to HOLD with the reason written down.
 */
class RiskRules
{
    const MIN_REWARD_RISK        = 1.5;
    const MAX_ENTRY_FROM_LTP_PCT = 10;   // an entry further than this from the live price is not actionable
    const MAX_STOP_DISTANCE_PCT  = 25;   // a stop wider than this is not a stop
    const DEFAULT_VALID_DAYS     = 5;
    const MAX_VALID_DAYS         = 10;

    /**
     * @param array $llm      Claude's decoded answer
     * @param float $ltp      live price (or last close)
     * @param float $maxLoss  ₹ the user accepts losing on one trade
     * @return array{action: string, confidence: int, entry: ?float, stop: ?float, target: ?float, reward_risk: ?float,
     *               valid_days: int, entry_type: ?string, qty: ?int, capital: ?float, loss_at_sl: ?float, note: ?string}
     */
    public static function apply(array $llm, float $ltp, float $maxLoss): array
    {
        $out = [
            'action' => 'hold', 'confidence' => max(0, min(100, (int) ($llm['confidence'] ?? 0))),
            'entry' => null, 'stop' => null, 'target' => null, 'reward_risk' => null,
            'valid_days' => max(1, min(self::MAX_VALID_DAYS, (int) ($llm['valid_days'] ?? self::DEFAULT_VALID_DAYS) ?: self::DEFAULT_VALID_DAYS)),
            'entry_type' => null, 'qty' => null, 'capital' => null, 'loss_at_sl' => null, 'note' => null,
        ];

        $action = strtolower(trim((string) ($llm['action'] ?? '')));
        if (!in_array($action, ['buy', 'sell', 'hold', 'avoid'], true)) {
            $out['note'] = 'The AI returned an unknown action; treated as HOLD';
            return $out;
        }

        $out['action'] = $action;
        if ($action !== 'buy') {
            return $out;   // levels only mean something for a buy
        }

        $reject = function (string $why) use ($out) {
            $out['action'] = 'hold';
            $out['note']   = 'The AI suggested BUY, but the risk rules rejected it: ' . $why;
            return $out;
        };

        $entry  = isset($llm['entry_price'])  && is_numeric($llm['entry_price'])  ? (float) $llm['entry_price']  : 0.0;
        $stop   = isset($llm['stop_loss'])    && is_numeric($llm['stop_loss'])    ? (float) $llm['stop_loss']    : 0.0;
        $target = isset($llm['target_price']) && is_numeric($llm['target_price']) ? (float) $llm['target_price'] : 0.0;

        if ($entry <= 0 || $stop <= 0 || $target <= 0) return $reject('entry, stop loss and target must all be given');
        if (!($stop < $entry && $entry < $target))      return $reject('needs stop loss < entry < target');
        if ($ltp <= 0)                                  return $reject('no live price to check the entry against');

        $fromLtp = abs($entry - $ltp) / $ltp * 100;
        if ($fromLtp > self::MAX_ENTRY_FROM_LTP_PCT) {
            return $reject(sprintf('entry %.2f is %.1f%% away from the live price %.2f (limit %d%%)', $entry, $fromLtp, $ltp, self::MAX_ENTRY_FROM_LTP_PCT));
        }

        $stopDistance = ($entry - $stop) / $entry * 100;
        if ($stopDistance > self::MAX_STOP_DISTANCE_PCT) {
            return $reject(sprintf('stop loss is %.1f%% below entry (limit %d%%)', $stopDistance, self::MAX_STOP_DISTANCE_PCT));
        }

        $rr = ($target - $entry) / ($entry - $stop);
        if ($rr < self::MIN_REWARD_RISK) {
            return $reject(sprintf('reward:risk is only %.2f (minimum %.1f)', $rr, self::MIN_REWARD_RISK));
        }

        // position size from the max-loss setting: quantity = floor(max loss / risk per share)
        $risk = $entry - $stop;
        $qty  = $maxLoss > 0 ? (int) floor($maxLoss / $risk + 1e-9) : 0;
        if ($qty < 1) {
            return $reject(sprintf('risk per share %.2f is more than the whole max loss of ₹%s', $risk, number_format($maxLoss, 0)));
        }

        return array_merge($out, [
            'entry' => round($entry, 2), 'stop' => round($stop, 2), 'target' => round($target, 2),
            'reward_risk' => round($rr, 2),
            // a buy level above the market is a breakout (buy-stop); at or below it is a pullback (buy-limit)
            'entry_type' => $entry > $ltp ? 'stop' : 'limit',
            'qty' => $qty, 'capital' => round($qty * $entry, 2), 'loss_at_sl' => round($qty * $risk, 2),
        ]);
    }
}
