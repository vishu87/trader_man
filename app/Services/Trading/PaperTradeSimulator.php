<?php

namespace App\Services\Trading;

/**
 * Plays one simulated long trade forward over daily candles. Pure function: same trade + same candles = same answer, so
 * it can safely be re-run from scratch every time (no half-updated state to go wrong).
 *
 * Deliberately CONSERVATIVE, so paper results do not flatter the signals:
 *   - a pullback entry ('limit') fills when the low touches it, at the entry or at the open if the stock gapped below it;
 *     a breakout entry ('stop') fills when the high reaches it, at the entry or at the open if it gapped above it;
 *   - a candle can't tell us the order of its high and low, so whenever the stop loss AND the target are both inside
 *     one candle, the stop loss is assumed to have been hit first;
 *   - a gap through the stop loss exits at the open (a worse price than the stop), never at the stop;
 *   - an entry that is not filled within valid_days sessions expires and is not counted as a win or a loss.
 */
class PaperTradeSimulator
{
    /**
     * @param array $trade   ['entry_type' => 'limit'|'stop', 'entry_price', 'stop_loss', 'target_price', 'valid_days', 'qty']
     * @param array $candles daily candles AFTER the signal date, oldest -> newest: ['date', 'open', 'high', 'low', 'close']
     * @return array{status: string, filled_on: ?string, fill_price: ?float, closed_on: ?string, exit_price: ?float,
     *               mark_price: ?float, pnl: ?float, r_multiple: ?float}
     */
    public static function simulate(array $trade, array $candles): array
    {
        $entry = (float) $trade['entry_price'];
        $stop  = (float) $trade['stop_loss'];
        $target = (float) $trade['target_price'];
        $qty   = (int) $trade['qty'];
        $validDays = (int) $trade['valid_days'];
        $type  = $trade['entry_type'] ?? 'limit';

        $s = ['status' => 'pending', 'filled_on' => null, 'fill_price' => null, 'closed_on' => null, 'exit_price' => null,
              'mark_price' => null, 'pnl' => null, 'r_multiple' => null];

        $session = 0;
        $last = null;
        foreach ($candles as $c) {
            $session++;
            $last = $c;

            if ($s['status'] === 'pending') {
                if ($session > $validDays) {
                    $s['status'] = 'expired';
                    $s['closed_on'] = $c['date'];
                    break;
                }

                if ($type === 'limit') {
                    if ($c['low'] > $entry) continue;                      // never came down to the entry
                    $fill = min($entry, $c['open']);
                } else {
                    if ($c['high'] < $entry) continue;                     // never rose to the breakout
                    $fill = max($entry, $c['open']);
                }

                $s['status'] = 'open';
                $s['filled_on'] = $c['date'];
                $s['fill_price'] = $fill;

                // the rest of the fill candle: the stop is checked before the target (conservative)
                if ($c['low'] <= $stop) {
                    self::close($s, 'stopped', $c['date'], min($stop, $fill));
                } elseif ($c['high'] >= $target) {
                    self::close($s, 'target_hit', $c['date'], $target);
                }
            } else { // open
                if ($c['open'] <= $stop)        self::close($s, 'stopped', $c['date'], $c['open']);        // gapped through the stop
                elseif ($c['low'] <= $stop)     self::close($s, 'stopped', $c['date'], $stop);
                elseif ($c['open'] >= $target)  self::close($s, 'target_hit', $c['date'], $c['open']);     // gapped over the target
                elseif ($c['high'] >= $target)  self::close($s, 'target_hit', $c['date'], $target);
            }

            if (in_array($s['status'], ['stopped', 'target_hit'], true)) break;
        }

        // the entry window ended exactly with the last candle we have
        if ($s['status'] === 'pending' && $session >= $validDays && $last) {
            $s['status'] = 'expired';
            $s['closed_on'] = $last['date'];
        }

        if ($last) {
            $s['mark_price'] = $last['close'];
        }

        // money and R multiple (R = what was risked per share at entry)
        $risk = $entry - $stop;
        if ($s['fill_price'] !== null) {
            $price = $s['exit_price'] ?? $s['mark_price'];            // realised if closed, else unrealised at the last close
            $s['pnl'] = round(($price - $s['fill_price']) * $qty, 2);
            if ($s['exit_price'] !== null && $risk > 0) {
                $s['r_multiple'] = round(($s['exit_price'] - $s['fill_price']) / $risk, 2);
            }
        }

        return $s;
    }

    private static function close(array &$s, string $status, string $date, float $price): void
    {
        $s['status'] = $status;
        $s['closed_on'] = $date;
        $s['exit_price'] = round($price, 4);
    }
}
