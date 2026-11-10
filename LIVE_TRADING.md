# Live Trading — how live data is handled

This describes everything the live module does, from the moment ChartInk fires an alert to the moment the shares are sold.
It is separate from the Scanner backtest: **nothing in `app/Services/Live/` reads any backtest code**, and scanner signals are
never used for live trading.

---

## 1. The short version

```
ChartInk alert ──► POST /api/chartink/<secret>
                      │  stores the raw call            → raw_data_chartink
                      │  one row per stock              → live_signals   (status "new")
                      └─ starts  php artisan live:process-signals  in the background
                                   │
                                   ▼
                  SignalProcessor: checks → buys the 1st lot            → live_positions + live_orders
                                   │
        every minute (cron) ──►  live:tick   (LiveTick)
                                   ├─ processes any signal still waiting (safety net)
                                   ├─ asks the broker how every open order stands → fills update the position
                                   ├─ cancels buys / sells that did not fill in time
                                   ├─ exits:  stop · target · time exit  → sell order
                                   └─ extra buys:  5-min 20 SMA, then every x % lower
```

* **1st buy** — after the alert's 5-minute candle has **closed**, and only if it closed **green** (close > open). We trade on closed candles only.
* **2nd buy** — when the price falls to the **5-minute 20 SMA**.
* **3rd and 4th buy** — each time the price is **x % under the last buy** (default 3 %).
* **Every extra buy** — only while the price is **below the day's VWAP**.
* **Sell** — stop loss, target, or the time exit (default: next trading day from 15:15).

Defaults are safe: **Trading = Off** and **Mode = Dry run** (real prices, simulated orders). Nothing real is ever sent until
you change both.

---

## 2. Pages

| Page | URL | What it is for |
|---|---|---|
| **Live Signals** | `/live` | Watch what is happening: cards (mode, open positions, unrealised and realised P&L, alerts today, last check), tabs **Signals / Positions / Orders**, refreshes every 10 s, "Check now" button (runs one tick; only works while the market is open). |
| **Live Config** | `/live-config` | The only place live settings are saved. Shows the webhook URL. Refuses invalid values. Asks for confirmation before live mode. |

Every stock name on Live Signals (Signals, Positions and Orders tabs) has a small chart icon. It opens the **day chart** in a
side drawer: that day's 5-minute candles (from Zerodha; the stored backtest candles if Zerodha cannot answer), the 20 SMA
line the 2nd buy waits for, the day's **VWAP** line (typical price × volume since 09:15 — the level extra buys must be under), an **RSI (14)** panel (Wilder's method on 5-minute closes, carried across sessions, with 30/50/70 lines; the alert, buy and sell marks are repeated on it), volume, the previous close, and what live trading did that day — ◆ alert, ▲ buy, ▼ sell — plus
the position's average, stop and target lines. Below the chart is a list of the day's alerts and fills, each with the **RSI at that candle** (so the RSI at the signal is read straight off). The arrows and the
date box in the drawer step to other days (e.g. from the buy day to the sell day). Closed positions open on their buy day.
(API: `POST /api/live/chart` with `symbol` and `date`.) The same drawer is used on the **Scanner** page for backtest trades —
see below; its candles come from `app/Services/DayCandles.php`, which is display-only and shared by both pages.

On the **Scanner** page (Daily P&L tab → open a day) each trade row has the same chart icon. It opens the stock's day on the
stored backtest candles (the exact candles the backtest used; Zerodha only if they are missing) with that trade drawn on it: the
scanner signal, each buy, the sell, and the average / stop / target lines from the rules on the page. Use the arrows to see the
exit day of a trade held overnight. (API: `POST /api/scanner/chart`.)

**The Scanner's "Manual" strategy** (the default there; the other choice is "Rules", the SMA / VWAP / hold-days rules the live engine runs) replays
what was done by hand, **one row per signal** (a trade is entered on a signal and can run for days; a stock with an open trade is
never entered again until it is sold):
1. first buy on the signal (closed green candle);
2. extra buys at −5 %, −10 %, −15 % of the **first** buy price (₹ per buy and the % step are editable);
3. a trade in profit at the end of the day is sold at that close; a same-day trade that reaches +5 % gets a trailing stop (5 % under its high);
4. a trade not in profit goes into a second day, where it is sold **the moment the price is back to its average price (cost to cost)** ("Day 2: out at +%", default 0), or at the stop 10 % under the average; a trade that was averaged on day 1 but is in profit at that close is simply sold at the close;
5. a trade still open after "Max hold (days)" (default 5 trading days — candles are only downloaded ~10 days past a signal) is sold at that day's close.
Within one candle an exit is checked before an extra buy. This only exists in the backtest so far; live trading still runs the SMA / VWAP rules above.

**The Scanner's "Signal quality" tab** (no profit and loss, no exit — only how good each signal is as an ENTRY): for every signal it measures,
from candles that had closed at that moment, the 5-minute picture (RSI 14, distance from VWAP, move on the previous close, distance off the
day's low, volume against the last 20 candles), the daily picture of the day before (RSI, close vs the 20 and 50 SMA, last 5 days, ATR, distance
from the 52-week high) and the monthly one (last month, last 3 months, month so far, the latest close against the monthly Bollinger band).
Twelve checks, thresholds editable on the page: a failed **timing** check (the 5-minute candle) makes it **Watch** (right stock, wrong moment —
the next candle of that day where every timing check passes is shown as the entry waited for), a failed **context** check (daily / monthly)
makes it **Skip**, nothing failed is **Enter now**. Open a row to see every check with its value; the chart icon shows the day with the signal
and the entry waited for. Needs ~3 years of daily candles and 6 extra days of 5-minute candles per stock ("Fetch candles for this period"
downloads them; checks without enough data show as "not enough data", never as failed). Code: `app/Services/Scanner/SignalQuality.php`.
This is the first step toward a learned entry filter; it does not trade.

**Using it in the backtest:** the rules panel has a **Signal filter** (default off): *only "Enter now" signals*, or *"Enter now" + wait for the entry*
(a "Watch" signal that later reached a good entry the same day is traded at that later candle; its trade row shows the original signal time and how
long it waited; "Skip", never-good and no-data signals are dropped). The thresholds are the ones on the Signal quality tab. Daily P&L, Compare
and "What happens next" all use the filtered signals; the look-ahead check still looks at all of them.

The Scanner's rules panel has the same two rules as tick-boxes, **on by default**: *Green candle only* (the signal candle must have
closed with close > open) and *Average only below VWAP*. Untick them to see what each rule is worth in the backtest. Live trading
always applies both.

Sidebar group: **Live Trading** → Live Signals, Live Config.

---

## 3. The webhook (how data comes in)

**URL**

```
POST  https://<your-site>/api/chartink/<CHARTINK_WEBHOOK_SECRET>
```

(`GET` and any other method also work.) The full URL, with the secret, is shown on the Live Config page. The secret is the
`CHARTINK_WEBHOOK_SECRET` line in `.env`.

| Situation | Answer |
|---|---|
| Wrong secret | `404 Not found` — nothing is stored |
| `CHARTINK_WEBHOOK_SECRET` not set | `503` |
| Correct secret | `200 OK` (the alert is stored first, processing happens afterwards) |

**Fields read from the call** (form-encoded or JSON — same as the older futures app):

| Field | Example | Use |
|---|---|---|
| `stocks` | `RELIANCE,SBIN` | comma-separated symbols — one signal each |
| `trigger_prices` | `1500.5,800` | price of each stock at the alert (same order) |
| `triggered_at` | `9:18 am` | time it fired; today's date is assumed. Missing or in the future → "now" |
| `alert_name` | `RISH5-1` | which alert it is (must be enabled in Live Config) |
| `scan_name` | | kept in the raw record only |

**What is stored**

1. `raw_data_chartink` — the call exactly as received (JSON), the sender's IP and the time received. Always written, even for
   alerts that will be ignored.
2. `live_signals` — one row per stock. A symbol must look like `A–Z 0–9 & - . _` (≤ 50 characters) and the call must have an
   `alert_name`, otherwise that stock is ignored.
   * **Idempotent:** a unique key on *alert name + symbol + fired-at time* means a repeated delivery of the same alert creates
     no second signal.
   * Stored as **skipped** straight away (with the reason) when the alert name is not in the enabled list, or when it is older
     than `max_alert_age_minutes` (default 5) on arrival.
   * Otherwise stored as **new**.
3. If at least one new signal was stored, `php artisan live:process-signals` is started in the background (`nohup`, output in
   `storage/logs/live_process.log`) so the webhook answers ChartInk immediately. The webhook itself never talks to the broker.

The PHP binary for the background process is `PHP_BIN` from `.env` if set; otherwise the running PHP, otherwise the newest PHP
found under `/Applications/MAMP/bin/php`.

---

## 4. What happens to a signal

**Closed candles only.** An alert is not acted on while the 5-minute candle it fired in is still forming (an alert at 10:43 belongs
to the 10:40 candle and is acted on from 10:45:03). It waits as `new`; with Trading Off it is skipped at once. If the broker has
not published that candle within 3 minutes of its close, the signal is skipped (`the alert candle … is not available`).
The webhook's background process (`live:process-signals`) waits for the close and acts within seconds; if it has just
finished when an alert arrives, the once-a-minute tick picks it up (at most about a minute late). Only one such process runs at a time
(a lock file in `storage/framework/live_process.lock`).

`SignalProcessor` takes each ready `new` signal, claims it (`new → working`, so two processes can never handle the same one), and
decides. The first rule that fails skips the signal with a visible reason.

| # | Check | Skip reason shown |
|---|---|---|
| 1 | Trading is On | `trading is switched off` |
| 2 | Alert name is in the enabled list | `alert "X" is not enabled` |
| 3 | Weekday, and time inside the buying window (default 09:15–15:15) | `outside the buying window (…)` |
| 4 | Already holding this stock? | `already in the trade (extra buys follow the price, not new alerts)` |
| 5 | The alert's candle has closed **green** (close > open), read from the broker's 5-minute candles | `the 10:40 candle did not close green (open ₹x, close ₹y)` |
| 5b | Broker has a live price | `no live price from the broker` |
| 6 | Not an ETF / fund (name contains ETF, BEES or FUND; symbol ends BEES/ETF) | `ETF / fund` |
| 7 | Price ≥ minimum price (default ₹20) | `price ₹x is under the minimum` |
| 8 | Liquidity: average daily turnover over the last 20 sessions ≥ ₹1 crore (needs ≥ 5 sessions of history) | `illiquid: average ₹x cr a day` |
| 9 | Limits: max open positions, max money held, max new money per day | `limit: …` |
| 10 | One lot (₹ first buy ÷ price) is at least 1 share | `price ₹x is above the ₹ first buy` |

If everything passes: a position is created (`pending`), a **buy order** is sent, and the signal becomes `done` (action
`entry`). A broker refusal makes the signal `failed` with the broker's message.

A repeated alert for a stock already held **never buys anything** — extra buys are driven by price, not alerts (section 6).

Signal statuses: `new` → `working` → `done` / `skipped` / `failed`.

---

## 5. Orders

* **Order type:** `limit` (default) or `market`. A buy limit is `buffer %` **above** the live price (default 0.5 %) so it fills;
  a sell limit is `buffer %` **below** (default 1 %). Prices are rounded to the NSE 0.05 tick (up for buys, down for sells).
* **Quantity:** `floor(amount ÷ price)`.
* **Kite order:** `regular`, exchange `NSE`, product **CNC** (delivery), validity `DAY`.
* **Written before sent:** the order row is saved with status `sending` *before* the broker call, so a crash can never leave a
  real order we do not know about.
* **Order status:** `sending` → `open` → `complete` / `rejected` / `cancelled` (or `unknown`, see crashes).
* **Fills:** every tick asks the broker for each open order. A new fill updates the position:
  * buy fill → shares added, average price recomputed, invested money updated. The first fill turns the position `open` and
    sets `hold_until` = N trading days later (weekends skipped).
  * sell fill → shares removed; at zero the position is `closed` with exit price, reason and P&L (`value sold − average × qty`).
  * Partial fills are handled (the price of just the new part is derived from the broker's running average).
* **Timeouts:** an unfilled buy is cancelled after `entry_timeout_minutes` (10); an unfilled sell after
  `exit_timeout_minutes` (3) and then re-tried on the next tick. A first buy that never filled → position `cancelled`/`failed`.

---

## 6. Averaging (extra buys) — the rule

No dropdown, one rule. Checked **every minute by the cron**, not when an alert arrives.

| Buy | When |
|---|---|
| 1st | the alert arrives |
| 2nd | the live price falls to the **5-minute 20 SMA** — the average of the closes of the last 20 *completed* 5-minute candles (across sessions) — **and** that SMA is below the last buy |
| 3rd, 4th … | the live price is **`add_drop_pct` %** (default 3 %) **under the last buy price** |

Settings: **Average with (₹)** per extra buy (default ₹10,000), **Max extra buys** (default 3 → 4 buys in all), **Then every … % lower**
(default 3).

Details:
* The "last buy" is the fill price of the most recent buy order of that position.
* **Only below VWAP:** an extra buy is only made while the live price is under the day's VWAP (typical price × volume of today's
  completed 5-minute candles). Above VWAP, or before any candle has completed today, nothing is bought; the rule is re-checked every minute.
* Extra buys use the same order type/buffer and the same limits as a first buy, and only happen inside the buying window,
  on weekdays, while **Trading is On** (switching off stops extra buys too; exits keep running).
* Only one buy at a time per stock: nothing is added while an order for that position is still working.
* No SMA buy if the price is not under the last buy, if the SMA is not under the last buy, or if fewer than 20 candles exist.
* The count of extra buys goes up when an extra-buy order ends with at least one share filled; an unfilled one is cancelled by
  the timeout and the rule can fire again.
* Stop and target are measured on the **new average price** after every extra buy.
* Maximum money in one stock = first buy + average amount × max extra buys (shown on the Live Config page).

---

## 7. Exits (every minute)

For each open position, using the live price:

| Exit | When | Order |
|---|---|---|
| **Stop** | price ≤ average × (1 − stop %) (default 10 %) | sell all |
| **Target** | price ≥ average × (1 + target %) (default 10 %) | sell all |
| **Time exit** | today ≥ `hold_until` **and** time ≥ exit time (default 15:15) | sell all |

Order of checking is stop → target → time. Rules:
* Exits run **even when Trading is Off** — switching off never leaves a position unwatched.
* If a sell is already working, no second one is placed.
* If the broker refuses the sell 3 times in 15 minutes, retries stop and the position shows an attention warning
  ("sell it by hand").
* Stop and target are placed as ordinary sell orders when the price is seen (checked once a minute) — **not** as GTT orders at
  Zerodha, so a fast move inside a minute can fill worse than the stop/target price.

Hold days: `0` = sell the same day; `1` = next trading day, etc. Only Mon–Fri are counted (**no holiday calendar** — on a holiday
the broker simply has no prices).

---

## 8. The cron job

`routes/console.php`:

```php
Schedule::command('live:tick')->weekdays()->everyMinute()->between('9:10', '15:40')->withoutOverlapping(10);
```

The server must run the Laravel scheduler every minute:

```
* * * * * cd /Applications/MAMP/htdocs/trader_man && php artisan schedule:run >> /dev/null 2>&1
```

`live:tick` does nothing outside Mon–Fri 09:15–15:30 (prints "Market closed"). Each run:

1. stores the time as the "last check" (shown on `/live`);
2. flags **interrupted work** (see section 10);
3. processes any signal still `new` (safety net if the background process did not run);
4. syncs open orders with the broker;
5. cancels timed-out orders;
6. places exits;
7. syncs again (instant/simulated fills);
8. checks extra buys;
9. syncs once more.

Run it by hand: `php artisan live:tick` · process signals only: `php artisan live:process-signals`.

---

## 9. Dry run vs live

| | Dry run (default) | Live |
|---|---|---|
| Prices | real, from Zerodha (LTP, 5-min and daily history) | real |
| Orders | **simulated** — nothing is sent; a limit buy "fills" when the live price is at/under the limit, a sell when at/over; a market order fills at once at the live price | **real orders** on Zerodha (CNC) |
| Needs | Kite access token for market data | Kite access token + funds/holdings |

To go live (do these deliberately, in this order):
1. Make sure the scheduler cron runs.
2. Put the webhook URL on the ChartInk alert.
3. Run **Dry run with Trading On** for some days and read `/live`.
4. Live Config → **Mode = LIVE**, **Save** → confirm the warning (saving live mode requires this confirmation; the server also
   refuses without it).
5. **Trading = On**, save.

To stop: **Trading = Off** (no new buys or extra buys; exits continue) — or switch the Mode back to Dry run.

The Kite token comes from the same place as the rest of the app (`KiteService::client()`, today's token).

---

## 10. When things go wrong

| Problem | What the system does |
|---|---|
| Broker refuses an order | order `rejected` with the broker's message; first buy → position `failed` + signal `failed`; sells are retried (max 3 per 15 min, then "sell by hand") |
| Process dies while handling a signal | signal stays `working`; after 5 min a tick marks it `failed — interrupted, check the broker`. **Never retried automatically** (a real order may or may not have gone out) |
| Process dies while sending an order | order stays `sending`; after 2 min it is marked `unknown — check the broker` |
| Broker does not answer a status call | skipped, looked at again next minute |
| No live price | signal skipped / position left alone this minute |
| Same alert delivered twice | ignored (unique key) |
| Late alert (> 5 min old on arrival) | stored, skipped as stale |
| Two processes at once | signal claim is atomic; order sync locks the rows in a transaction |
| Tick overlaps the previous one | `withoutOverlapping` |

---

## 11. Settings reference (Live Config)

Saved in `app_settings` under the key `live_config` (JSON). Saving is **strict** (a bad value is refused with a message under
the field); reading is tolerant (anything missing falls back to the default).

| Setting | Default | Meaning |
|---|---|---|
| Trading (`enabled`) | Off | Off = no new buys / extra buys; exits still run |
| Mode (`mode`) | Dry run | `dry_run` or `live` |
| Alert names (`alert_names`) | `RISH5-1` | only these alerts trade |
| Ignore alerts older than (`max_alert_age_minutes`) | 5 | 1–30 |
| First buy (`first_amount`) | ₹10,000 | ₹500 – ₹10,00,000 |
| Average with (`add_amount`) | ₹10,000 | per extra buy, 0 = never average |
| Max extra buys (`max_adds`) | 3 | 0–20 |
| Then every … % lower (`add_drop_pct`) | 3 | 0.1–50, % under the last buy for the 3rd+ buys |
| Buy from / until (`entry_from`, `entry_until`) | 09:15 / 15:15 | buying window (from ≥ 09:15, until ≤ 15:25) |
| Hold for (days) (`hold_days`) | 1 | 0–10 trading days |
| Sell from (`exit_time`) | 15:15 | on the exit day (09:30–15:25) |
| Target % (`target_pct`) | 10 | empty = none; 0.1–100 |
| Stop loss % (`stop_pct`) | 10 | empty = none; 0.1–50 |
| Minimum price (`min_price`) | ₹20 | |
| Minimum turnover (`min_turnover`) | ₹1 crore | average ₹ traded per day |
| Over … sessions (`liquidity_days`) | 20 | 5–100 |
| Exclude ETFs (`exclude_etf`) | Yes | |
| Order type (`order_type`) | Limit | `limit` / `market` |
| Buy limit above price (`limit_buffer_pct`) | 0.5 % | 0–5 |
| Sell limit below price (`exit_buffer_pct`) | 1 % | 0–10 |
| Cancel an unfilled buy after (`entry_timeout_minutes`) | 10 | 1–60 |
| Cancel an unfilled sell after (`exit_timeout_minutes`) | 3 | 1–60 |
| Max open positions | 10 | |
| Max money held in total | ₹2,00,000 | counts filled money plus buys still waiting |
| Max new money per day | ₹1,00,000 | all buys placed today |

Cross-checks on save: first buy ≤ both money limits; "sell the same day" (hold 0) needs the buying window to end before the exit
time; averaging with ₹ > 0 needs max extra buys ≥ 1.

---

## 12. Database tables

(DDL is in `database_migration.php`; migrations `2026_11_11_000001/2`.)

| Table | One row is | Key columns |
|---|---|---|
| `raw_data_chartink` | one webhook call | `alert_name`, `raw_data` (JSON as received), `ip`, `received_at` |
| `live_signals` | one stock in one alert | `alert_name`, `symbol`, `trigger_price`, `triggered_at`, `received_at`, `status` (new/working/done/skipped/failed), `action` (entry/average), `reason`, `position_id`. Unique: alert + symbol + triggered_at |
| `live_positions` | one trade in one stock | `mode`, `status` (pending/open/closed/cancelled/failed), `qty`, `avg_price`, `invested`, `adds`, `sold_qty`, `exit_value`, `exit_price`, `exit_reason` (stop/target/time_exit), `pnl`, `hold_until`, `last_price`, `opened_at`, `closed_at` |
| `live_orders` | one order | `position_id`, `signal_id`, `side`, `kind` (entry/average/stop/target/time_exit), `qty`, `order_type`, `price`, `status` (sending/open/complete/rejected/cancelled/unknown), `filled_qty`, `filled_price`, `broker_order_id`, `mode`, `message` |
| `app_settings` | key/value | `live_config` (the settings above), `live_last_tick` |

---

## 13. API used by the pages

All `POST`, authenticated with the `apiToken` header like the rest of the app:
`/api/live/overview`, `/signals`, `/positions`, `/orders`, `/tick` (runs one tick now), `/config`, `/config/save`.

---

## 14. Code map

| File | Role |
|---|---|
| `app/Http/Controllers/LiveWebhookController.php` | the public webhook |
| `app/Services/Live/ChartinkWebhook.php` | store raw call, create signals |
| `app/Services/Live/SignalProcessor.php` | checks, first buy, extra-buy rule, sending orders |
| `app/Services/Live/OrderSync.php` | broker order status → fills → positions |
| `app/Services/Live/LiveTick.php` | the once-a-minute job |
| `app/Services/Live/LiveConfig.php` | settings, defaults, strict validation |
| `app/Services/Live/Brokers/Broker.php` | what the engine needs from a broker (interface) |
| `app/Services/Live/Brokers/KiteBroker.php` | real Zerodha orders + market data |
| `app/Services/Live/Brokers/DryRunBroker.php` | same market data, simulated orders |
| `app/Services/Live/BrokerFactory.php` | picks the broker from the saved mode (only `live` returns the real one) |
| `app/Services/Live/Runner.php` | starts the background process |
| `app/Services/Live/Clock.php` | "now", market hours, trading days |
| `app/Http/Controllers/LiveController.php` | the two pages and their API |
| `app/Console/Commands/LiveTickCommand.php`, `LiveProcessSignals.php` | `live:tick`, `live:process-signals` |
| `resources/js/Pages/LiveSignals/Index.vue`, `LiveConfig/Index.vue` | the pages |

---

## 15. What has and has not been verified

**Tested:** the whole engine with a fake broker (webhook, every skip rule, limits, entries, partial fills, timeouts, the
averaging rule above, stop/target/time exits including weekends, refused sells, crash recovery); the exact parameters sent to Kite
(with a fake Kite client); that dry run can never call `placeOrder`; the pages and the webhook in a real browser/HTTP.

**Not tested:** real order placement on Zerodha (no real order has ever been sent), the real ChartInk payload (assumed identical
to the older futures app), behaviour on a live market day.

**Known limits:** no holiday calendar; stop/target checked once a minute and sold with ordinary orders (no GTT); no handling of
corporate actions on held shares; a position closed by hand at Zerodha is not noticed (the engine would try to sell shares that
are no longer there and the broker would refuse — watch the "sell by hand" warning).
