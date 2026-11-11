<?php

namespace App\Services\StockScanner;

/**
 * Monthly chart: the first close above the upper Bollinger band after a long quiet spell, on the highest RSI and heavy volume.
 * (The user's hand-marked RBA chart.) The rules and the scoring are in BollingerBreakout; these are the monthly windows:
 * the reference month is the current one or up to 5 back, the 8 months before it were all under the band, the RSI average cross
 * happened within 6 months, and 30 months of candles are needed (RSI(9) + its 21-month average).
 */
class MonthlyBollingerBreakout extends BollingerBreakout
{
    const KEY  = 'monthly_bollinger_breakout';
    const NAME = 'Monthly Bollinger breakout';

    const TIMEFRAME = 'monthly';
    const CHART = 'Monthly';
    const UNIT = 'month';
    const UNITS = 'months';
    const DATE_FMT = 'M Y';

    const MAX_AGE = 5;
    const QUIET_BARS = 8;
    const CROSS_WINDOW = 6;
    const MIN_BARS = 30;
}
