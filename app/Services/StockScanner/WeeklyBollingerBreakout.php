<?php

namespace App\Services\StockScanner;

/**
 * Weekly chart: the same pattern as the monthly scanner (first close above the upper Bollinger band after a quiet spell, on the highest
 * RSI(9) and heavy volume), read on weekly candles. The rules and the scoring are in BollingerBreakout; these are the weekly windows,
 * the same numbers as the monthly ones counted in weeks: the reference week is the current one or up to 5 back, the 8 weeks before it
 * were all under the band, the RSI average cross happened within 6 weeks, and 30 weeks of candles are needed.
 */
class WeeklyBollingerBreakout extends BollingerBreakout
{
    const KEY  = 'weekly_bollinger_breakout';
    const NAME = 'Weekly Bollinger breakout';

    const TIMEFRAME = 'weekly';
    const CHART = 'Weekly';
    const UNIT = 'week';
    const UNITS = 'weeks';
    const DATE_FMT = 'd M Y';   // a weekly candle is dated by its first day: "week of 03 Nov 2026"

    const MAX_AGE = 5;
    const QUIET_BARS = 8;
    const CROSS_WINDOW = 6;
    const MIN_BARS = 30;
}
