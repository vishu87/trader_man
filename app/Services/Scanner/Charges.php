<?php

namespace App\Services\Scanner;

/**
 * Zerodha's equity charges, estimated. Rates are constants here so they are easy to correct.
 *   intraday (MIS): brokerage min(0.03%, ₹20) per order, STT 0.025% on the sell, stamp 0.003% on the buy
 *   delivery (CNC): no brokerage, STT 0.1% both sides, stamp 0.015% on the buy, DP charge ₹13.5 + GST on the sell
 *   both: exchange fee 0.00297% (NSE), SEBI ₹10 per crore, GST 18% on brokerage + exchange fee + SEBI
 */
class Charges
{
    const BROKERAGE_RATE = 0.0003;
    const BROKERAGE_CAP  = 20.0;
    const STT_INTRADAY_SELL = 0.00025;
    const STT_DELIVERY      = 0.001;
    const EXCHANGE_FEE  = 0.0000297;
    const SEBI_FEE      = 0.000001;
    const STAMP_INTRADAY = 0.00003;
    const STAMP_DELIVERY = 0.00015;
    const GST           = 0.18;
    const DP_CHARGE     = 13.5;   // + GST, once per scrip on the day it is sold

    /**
     * @param float[] $buyOrders  the value of each buy order (each order pays its own brokerage)
     * @param float   $sellValue  the value of the sell (one order)
     */
    public static function equity(array $buyOrders, float $sellValue, bool $intraday): float
    {
        $buy      = array_sum($buyOrders);
        $turnover = $buy + $sellValue;

        $brokerage = 0.0;
        if ($intraday) {
            foreach (array_merge($buyOrders, [$sellValue]) as $v) $brokerage += min(self::BROKERAGE_CAP, self::BROKERAGE_RATE * $v);
        }

        $stt   = $intraday ? self::STT_INTRADAY_SELL * $sellValue : self::STT_DELIVERY * $turnover;
        $fee   = self::EXCHANGE_FEE * $turnover;
        $sebi  = self::SEBI_FEE * $turnover;
        $stamp = ($intraday ? self::STAMP_INTRADAY : self::STAMP_DELIVERY) * $buy;
        $gst   = self::GST * ($brokerage + $fee + $sebi);
        $dp    = $intraday ? 0.0 : self::DP_CHARGE * (1 + self::GST);

        return round($brokerage + $stt + $fee + $sebi + $stamp + $gst + $dp, 2);
    }
}
