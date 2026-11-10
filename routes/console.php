<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Daily + weekly candles for the portfolio and watchlists, after the market closes (needs the scheduler cron)
Schedule::command('candles:sync')->weekdays()->at('16:30');

// Today's Zerodha executions, shortly after the market closes (Kite only returns the current day, so a missed day can only be
// filled from an uploaded tradebook). Needs the scheduler cron and today's Kite token.
Schedule::command('zerodha:sync-trades')->weekdays()->at('15:45')->withoutOverlapping();

// Live trading: every minute while the market is open — act on waiting signals, update orders, stop / target / time exits.
// Needs the scheduler cron (* * * * * php artisan schedule:run). Exits keep running even when trading is switched off.
Schedule::command('live:tick')->weekdays()->everyMinute()->between('9:10', '15:40')->withoutOverlapping(10);

// IPO list from ipoalerts.in — once a week, page 1 only. --scheduled means it stays idle until you have done the
// one-time load from 2023 yourself (php artisan ipos:sync).
// Schedule::command('ipos:sync --scheduled')->weeklyOn(0, '10:00')->withoutOverlapping()->runInBackground();

// The Screener scraper (php artisan ipos:fetch) is kept as a manual fallback but is no longer scheduled: two sources
// writing the same names would keep overwriting each other, and Screener's robots.txt asks crawlers not to use ?page=.
// Schedule::command('ipos:fetch --scheduled')->dailyAt('18:30')->withoutOverlapping();
