<?php

namespace App\Services\Live;

use Illuminate\Support\Carbon;

/** "Now" for the live engine — one place, so tests can set the time. */
class Clock
{
    private static ?Carbon $fixed = null;

    public static function now(): Carbon
    {
        return self::$fixed ? self::$fixed->copy() : Carbon::now();
    }

    /** Pin the time (tests); null releases it. */
    public static function set(?string $at): void
    {
        self::$fixed = $at ? Carbon::parse($at) : null;
    }

    /** Monday–Friday, 09:15–15:30. (There is no holiday calendar: on a holiday the broker simply has no prices.) */
    public static function marketOpen(?Carbon $t = null): bool
    {
        $t = $t ?? self::now();
        return $t->isWeekday() && $t->format('H:i') >= '09:15' && $t->format('H:i') <= '15:30';
    }

    /** The date n weekdays after $from (Y-m-d). */
    public static function addBusinessDays(string $from, int $n): string
    {
        $d = Carbon::parse($from);
        while ($n > 0) { $d->addDay(); if ($d->isWeekday()) $n--; }
        return $d->toDateString();
    }
}
