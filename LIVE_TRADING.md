# Live Trading — how live data is handled

This describes everything the live module does, from the moment ChartInk fires an alert to the moment the shares are sold.
It is separate from the Scanner backtest: **nothing in `app/Services/Live/` reads any backtest code**, and scanner signals are
never used for live trading.

---

## 1. The short version

```
ChartInk alert ──► POST /api/chart-ink-alerts
                      │  stores the raw call            → raw_data_chartink
                      │  one row per stock (+ sector, market cap, RSI, close) → live_signals   (status "new")
                      └─ starts  php artisan live:process-signals  in the background
                                   │
                                   ▼
        SignalProcessor:  ignore? ─► wait for the 5-min candle to CLOSE ─► classify (SignalClassifier, Zerodha candles)
                                   │        Can enter ─► re-validate ─► buy the 1st lot   → live_positions + live_orders
                                   │        Watch     ─► re-checked on every closed candle until Can enter / expired
                                   │        Skip      ─► final
        every minute (cron) ──►  live:tick   (LiveTick)
                                   ├─ keeps working the waiting signals (Watch, and Can enter that had no room)
                                   ├─ asks the broker how every open order stands → fills update the position
                                   ├─ cancels buys / sells that did not fill in time
                                   ├─ exits:  manual (Exit button) · target · (stop · time exit, off by default) → sell order
                                   └─ extra buys:  5-min 20 SMA, then every x % lower
```

* **Classification** — every signal is rated **Can enter / Watch / Skip** on its *closed* 5-minute candle with the same twelve checks as the Scanner's *Signal quality* tab (section 4).
* **1st buy** — only for a *Can enter* signal, validated once more just before the order. We trade on closed candles only.
* **2nd buy** — when the price falls to the **5-minute 20 SMA**.
* **3rd and 4th buy** — each time the price is **x % under the last buy** (default 3 %).
* **Every extra buy** — only while the price is **below the day's VWAP**.
* **Sell** — for now by **profit target** (default 10 % over the average) or **by hand** with the *Exit* button on the Positions tab. A stop loss and a time exit exist but are **off** (empty) by default.

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
POST  https://<your-site>/api/chart-ink-alerts                 ← the URL ChartInk is given
POST  https://<your-site>/api/chart-ink-alerts?key=<secret>    ← same, with the secret (needed in LIVE mode)
POST  https://<your-site>/api/chartink/<secret>                ← the older form, still works
```

(`GET` and any other method also work.) Both forms are shown on the Live Config page. The secret is the
`CHARTINK_WEBHOOK_SECRET` line in `.env`.

| Situation | Answer |
|---|---|
| `/api/chart-ink-alerts`, no key, mode **Dry run** | `200 OK` (open URL: it can only ever cause simulated orders) |
| `/api/chart-ink-alerts`, no key, mode **Live** | `403` — real orders are never placed because of a call anyone could make. Use the `?key=` URL in ChartInk |
| Wrong key / wrong secret | `404 Not found` — nothing is stored |
| Key given but `CHARTINK_WEBHOOK_SECRET` not set | `503` |
| Correct key | `200 OK` (the alert is stored first, processing happens afterwards) |

**Sample call** (form-encoded or JSON):

```json
{
  "stocks": "INFY,TCS,HDFCBANK",
  "trigger_prices": "2500.00,600.00,3400.00",
  "triggered_at": "2:47 pm",
  "scan_name": "Copy - Copy - Rish5-1",
  "scan_url": "copy-copy-rish5-1-694",
  "alert_name": "RISH5-1",
  "webhook_url": "https://trade.avyaytech.in/api/chart-ink-alerts",
  "columns": [
    {"symbol": "INFY", "sector": "IT", "marketcapname": "Large Cap", "rsi": 61.2, "close": 2501.5},
    {"symbol": "TCS", "sector": "IT", "marketcapname": "Large Cap", "rsi": 55, "close": 600.1},
    {"symbol": "HDFCBANK", "sector": "Banks", "marketcapname": "Large Cap", "rsi": 48.9, "close": 3400}
  ]
}
```

| Field | Example | Use |
|---|---|---|
| `stocks` | `INFY,TCS` | comma-separated symbols — one signal each |
| `trigger_prices` | `2500.00,600.00` | price of each stock at the alert (same order) |
| `triggered_at` | `2:47 pm` | time it fired; today's date is assumed. Missing or in the future → "now" |
| `alert_name` | `RISH5-1` | which alert it is (must be enabled in Live Config) |
| `scan_name` | | stored on each signal |
| `columns[]` | `sector`, `marketcapname`, `rsi`, `close` | one object per stock. Matched to the stock by the `symbol` inside it, otherwise by position — never a column that names a different stock. Stored on the signal and shown in the list. A value that is not a number is stored as empty. |
| `scan_url`, `webhook_url` | | kept in the raw record only |

**What is stored**

1. `raw_data_chartink` — the call exactly as received (JSON), the sender's IP and the time received. Always written, even for
   alerts that will be ignored.
2. `live_signals` — one row per stock. A symbol must look like `A–Z 0–9 & - . _` (≤ 50 characters, no spaces — so placeholder text
   such as `SYMBOL 1, SYMBOL 2` is ignored) and the call must have an `alert_name`, otherwise that stock is ignored.
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

```
new ──► ignored                       the stock is already held / an entry order is placed / an earlier signal for it is still waiting
 │
 └─(its 5-min candle closed)─► classify ─┬─► Skip ──────────────► skipped
                                         ├─► Watch ─────────────► watching ──(each new closed candle: classify again)──► …
                                         └─► Can enter ─ no room ► enter (waiting for room) ─ re-validate ─► bought
                                                         room ───► re-validate ───────────────────────────► bought (done)
 watching / enter that run out of time ► expired        trading off ► skipped (but the verdict is kept and shown)
```

**Closed candles only.** A signal is not judged while the 5-minute candle it fired in is still forming (an alert at 10:43 belongs
to the 10:40 candle and is judged from 10:45:03). If the broker has not published that candle within 3 minutes of its close, the
signal is skipped (`the … candle is not available from the broker`). The webhook's background process (`live:process-signals`) waits
for the close and acts within seconds; after that the once-a-minute tick keeps the waiting signals going (at most about a minute
late). Only one such process runs at a time (a lock file in `storage/framework/live_process.lock`).

**Ignored** (decided at once, before the candle closes): a signal for a stock that already has an open or pending position
(`already in the trade — extra buys follow the price`, or `an entry order for this stock is already placed`), or while an *earlier*
signal for the same stock is still waiting (`signal #12 for this stock is already waiting for its entry`). An ignored signal is never
judged or bought. A signal whose earlier sibling was *skipped* is not ignored — it is judged on its own.

**Stock not found.** The stock is looked up on **NSE first, then BSE** (the instrument lists are fetched once a day). A BSE-only
stock is judged and traded on BSE (prices, candles, orders and the position all use BSE; the list shows an `NSE` / `BSE` tag).
Listed on neither → `skipped: not found on NSE or BSE`. If the lists cannot be read at all, NSE is assumed — a lookup failure never
blocks a trade.

**The verdict** — `SignalClassifier` (the Scanner's `SignalQuality` checks, on Zerodha's candles, using only closed candles):

| Group | Check | Fails → |
|---|---|---|
| timing (5-min candle) | closed green · not up too much today · not too far off the day's low · above VWAP but not stretched · 5-min RSI not overbought · RSI has strength · volume picking up · inside the entry window | **Watch** |
| context (daily / monthly) | **daily uptrend: close above the 20 SMA *and* at least 3 of the last 5 daily candles green** · not up too much in 5 days · daily RSI not overbought · not up too much last month | **Skip** |
| all pass | | **Can enter** |
| drift guard | the live price is already more than 1 % above the close of the candle judged | **Watch** (`price ₹x is already y % above the 10:00 close`) |

*Why the green-candle rule:* a close above the daily 20 SMA can come from one old spike; if the stock has been sliding in red daily
candles since, it is not an uptrend. The two numbers (3 of 5) are in Live Config → *Signal check*; 0 switches the rule off.

**Watch** is re-judged on **every new closed 5-minute candle** (once per candle, never twice on the same one). The first candle
on which everything passes makes it *Can enter*.

**Can enter** goes through the buying checks below, and **is validated again right before the order** (fresh candles and live
price — a *re-check* row in the history). If something changed (for example the price ran 3 % while it waited) it goes back to
*Watch* and nothing is bought.

**No room** — when the open positions or money limits are full, a *Can enter* signal **waits** (status `enter`, reason `limit: already
5 open positions — waiting for room`). When room appears (a position is sold, by hand or by target) it is validated again and bought.
It is checked again only when something can have changed (a new candle, or room appearing), not on every cycle.

**Expiry** — a *Watch* / *Can enter* signal that is still waiting `signal_valid_minutes` (default 60) after its candle closed, or when the buying
window ends (default 15:15), becomes `expired` (`not bought: …` with the last reason).

**Trading Off** — signals are still judged (so you can watch the verdicts), but nothing is queued or bought: the signal ends as
`skipped` — `trading is switched off — it was rated: enter` (the verdict stays on the row).

**Status over time.** Every look at a signal is saved (`live_signal_checks`): when, which candle, the verdict, the candle close
and live price, RSI, distance from VWAP, day move, and what failed. Open a row with the arrow on the Signals tab to see how the status
changed over time.

**Buying checks** (when a signal is *Can enter*; the first one that fails ends it with a visible reason):

| # | Check | Result shown |
|---|---|---|
| 1 | Alert name is in the enabled list | `alert "X" is not enabled` |
| 2 | Weekday, and time inside the buying window (default 09:15–15:15) — for a new signal | `outside the buying window (…)` |
| 3 | Not already held | `ignored` |
| 4 | Listed on NSE or BSE | `not found on NSE or BSE` |
| 5 | Classified Can enter (and re-validated) | `Watch` / `Skip` as above |
| 6 | Broker has a live price | `no live price from the broker` |
| 7 | Not an ETF / fund (name contains ETF, BEES or FUND; symbol ends BEES/ETF) | `ETF / fund` |
| 8 | Price ≥ minimum price (default ₹20) | `price ₹x is under the minimum` |
| 9 | Liquidity: average daily turnover over the last 20 sessions ≥ ₹1 crore (needs ≥ 5 sessions of history) | `illiquid: average ₹x cr a day` |
| 10 | Limits: max open positions, max money held, max new money per day | **waits** (`… — waiting for room`) |
| 11 | One lot (₹ first buy ÷ price) is at least 1 share | `price ₹x is above the ₹ first buy` |

If everything passes: a position is created (`pending`), a **buy order** is sent, and the signal becomes `done` (action
`entry`). A broker refusal makes the signal `failed` with the broker's message.

A repeated alert for a stock already held **never buys anything** — extra buys are driven by price, not alerts (section 6).

Signal statuses: `new` → `working` (being looked at) → `watching` / `enter` (waiting) → `done` / `skipped` / `ignored` / `expired` / `failed`.
A `working` signal that has been stuck for 5 minutes (a crash) is marked `failed — interrupted`, never retried (a real order might have gone out).

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

**Right now exits are by profit target or by hand.** The stop loss and the time exit are still in the engine but **off** by default
(*Stop loss %* and *Hold for (days)* empty in Live Config).

For each open position, using the live price:

| Exit | When | Order |
|---|---|---|
| **Manual** | the **Exit** button was pressed on the Positions tab | sell all (kind `manual`) |
| **Target** | price ≥ average × (1 + target %) (default 10 %) | sell all |
| Stop | price ≤ average × (1 − stop %) — only if a stop % is set | sell all |
| Time exit | today ≥ `hold_until` **and** time ≥ exit time — only if *Hold for* is set | sell all |

Order of checking is manual → stop → target → time. Rules:
* Exits run **even when Trading is Off** — switching off never leaves a position unwatched.
* If a sell is already working, no second one is placed.
* If the broker refuses the sell 3 times in 15 minutes, retries stop and the position shows an attention warning
  ("sell it by hand").
* Target (and stop) are placed as ordinary sell orders when the price is seen (checked once a minute) — **not** as GTT orders at
  Zerodha, so a fast move inside a minute can fill worse than the target price.

**The Exit button** (Positions tab; asks for confirmation, and says whether the order is real or a dry run):
1. the position is marked *exit requested*;
2. buy orders still waiting at the broker are cancelled (a position that was only a *waiting buy* is simply cancelled — "Cancel buy" —
   nothing is sold);
3. everything held is sold, as a limit `exit_buffer_pct` below the live price (or market, per *Order type*);
4. if the sell is refused, or the broker cannot give a price right now, the position **stays marked** and the once-a-minute check keeps
   trying (an unfilled sell is cancelled after `exit_timeout_minutes` and re-sent). A position being exited gets **no extra buys**.
A position opened in Dry run is never sold through the live broker, and the reverse: the button and the minute check refuse it and
say why (so switching the mode can never send a real sell for a simulated position).

Hold days: empty = no time exit; `0` = sell the same day; `1` = next trading day, etc. Only Mon–Fri are counted (**no holiday
calendar** — on a holiday the broker simply has no prices).

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
| A waiting signal expires after (`signal_valid_minutes`) | 60 | 5–240, minutes after its candle closed |
| First buy (`first_amount`) | ₹10,000 | ₹500 – ₹10,00,000 |
| Average with (`add_amount`) | ₹10,000 | per extra buy, 0 = never average |
| Max extra buys (`max_adds`) | 3 | 0–20 |
| Then every … % lower (`add_drop_pct`) | 3 | 0.1–50, % under the last buy for the 3rd+ buys |
| Buy from / until (`entry_from`, `entry_until`) | 09:15 / 15:15 | buying window (from ≥ 09:15, until ≤ 15:25) |
| Hold for (days) (`hold_days`) | empty (no time exit) | empty, or 0–10 trading days |
| Sell from (`exit_time`) | 15:15 | on the exit day (09:30–15:25) |
| Target % (`target_pct`) | 10 | empty = none; 0.1–100 |
| Stop loss % (`stop_pct`) | empty (no stop) | empty = none; 0.1–50 |
| Minimum price (`min_price`) | ₹20 | |
| Minimum turnover (`min_turnover`) | ₹1 crore | average ₹ traded per day |
| Over … sessions (`liquidity_days`) | 20 | 5–100 |
| Exclude ETFs (`exclude_etf`) | Yes | |
| Order type (`order_type`) | Limit | `limit` / `market` |
| Buy limit above price (`limit_buffer_pct`) | 0.5 % | 0–5 |
| Sell limit below price (`exit_buffer_pct`) | 1 % | 0–10 |
| Cancel an unfilled buy after (`entry_timeout_minutes`) | 10 | 1–60 |
| Cancel an unfilled sell after (`exit_timeout_minutes`) | 3 | 1–60 |
| Max open positions | 10 | a *Can enter* signal with no room waits for one |
| Signal check (`quality`) | see below | the thresholds that make a signal Can enter / Watch / Skip |
| Max money held in total | ₹2,00,000 | counts filled money plus buys still waiting |
| Max new money per day | ₹1,00,000 | all buys placed today |

**Signal check thresholds** (`quality`, Live Config → *Signal check*; the entry window is the *Buy from / until* above):

| Setting | Default | Fails → |
|---|---|---|
| Day move at most (`max_day_chg`) | 8 % | Watch |
| Off the day's low at most (`max_from_low`) | 6 % | Watch |
| Above VWAP at most (`max_vwap_dist`) — must also be above VWAP | 3 % | Watch |
| 5-min RSI at least / at most (`min_rsi`, `max_rsi`) | 45 / 75 | Watch |
| Volume at least × the average of the 20 candles before (`min_vol_ratio`) | 1.0 | Watch |
| Daily close above 20 SMA (`daily_uptrend`) | Yes | Skip |
| Green daily candles at least … of the last … days (`d_green_min`, `d_green_days`) | 3 of 5 | Skip |
| 5-day rise at most (`max_chg_5d`) | 15 % | Skip |
| Daily RSI at most (`max_daily_rsi`) | 80 | Skip |
| Last month rise at most (`max_chg_1m`) | 60 % | Skip |

Cross-checks on save: first buy ≤ both money limits; "sell the same day" (hold 0) needs the buying window to end before the exit
time; averaging with ₹ > 0 needs max extra buys ≥ 1; green days needed cannot exceed the days looked at.

---

## 12. Database tables

(DDL is in `database_migration.php`; migrations `2026_11_11_000001/2` and `2026_11_12_000001`.)

| Table | One row is | Key columns |
|---|---|---|
| `raw_data_chartink` | one webhook call | `alert_name`, `raw_data` (JSON as received), `ip`, `received_at` |
| `live_signals` | one stock in one alert | `alert_name`, `scan_name`, `exchange` (NSE/BSE), `symbol`, `trigger_price`, `sector`, `market_cap`, `alert_rsi`, `alert_close`, `triggered_at`, `received_at`, `status` (new/working/watching/enter/done/skipped/ignored/expired/failed), `verdict` (enter/watch/skip/nodata: the latest), `score`, `checked_candle`, `checked_at`, `checks`, `action` (entry/average), `reason`, `position_id`. Unique: alert + symbol + triggered_at |
| `live_signal_checks` | one look at a signal | `signal_id`, `checked_at`, `candle_at`, `kind` (candle / recheck), `verdict`, `score`, `price` (candle close), `ltp`, `fails` (codes), `note`, `details` (JSON: RSI, VWAP distance, day move …) |
| `live_positions` | one trade in one stock | `mode`, `status` (pending/open/closed/cancelled/failed), `qty`, `avg_price`, `invested`, `adds`, `sold_qty`, `exit_value`, `exit_price`, `exit_reason` (manual/stop/target/time_exit), `exit_requested_at` (the Exit button), `pnl`, `hold_until`, `last_price`, `opened_at`, `closed_at` |
| `live_orders` | one order | `position_id`, `signal_id`, `side`, `kind` (entry/average/manual/stop/target/time_exit), `qty`, `order_type`, `price`, `status` (sending/open/complete/rejected/cancelled/unknown), `filled_qty`, `filled_price`, `broker_order_id`, `mode`, `message` |
| `app_settings` | key/value | `live_config` (the settings above), `live_last_tick` |

---

## 13. API used by the pages

All `POST`, authenticated with the `apiToken` header like the rest of the app:
`/api/live/overview`, `/signals`, `/signal-checks` (one signal's status over time), `/positions`, `/positions/exit` (the Exit button), `/orders`, `/chart`,
`/tick` (runs one tick now), `/config`, `/config/save`.

---

## 14. Code map

| File | Role |
|---|---|
| `app/Http/Controllers/LiveWebhookController.php` | the public webhook |
| `app/Services/Live/ChartinkWebhook.php` | store raw call, create signals |
| `app/Services/Live/SignalProcessor.php` | the signal states (ignore / classify / watch / queue / expire), the first buy, the extra-buy rule, sending orders |
| `app/Services/Live/SignalClassifier.php` | rates a signal Can enter / Watch / Skip on Zerodha's candles; writes `live_signal_checks` |
| `app/Services/Scanner/SignalQuality.php` | the twelve checks themselves (shared with the Scanner's Signal quality tab) |
| `app/Services/Live/OrderSync.php` | broker order status → fills → positions |
| `app/Services/Live/LiveTick.php` | the once-a-minute job, exits, the manual exit |
| `app/Services/Live/LiveConfig.php` | settings, defaults, strict validation |
| `app/Services/Live/Brokers/Broker.php` | what the engine needs from a broker (interface) |
| `app/Services/Live/Brokers/KiteBroker.php` | real Zerodha orders + market data; NSE-then-BSE lookup |
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

Also tested with the fake broker and in a browser: the sample payload and its `columns`, the three webhook URL forms, every signal
state (Can enter / Watch → Can enter on a later candle / Skip / ignored / expired / waiting for room, then re-validated), the Exit
button (sold, refused then retried, unfilled then re-sent, a waiting buy cancelled, wrong mode refused), NSE-then-BSE lookup and a BSE order,
and the green-candle daily trend.

**Not tested:** real order placement on Zerodha (no real order has ever been sent), the real ChartInk payload (the sample you
gave has placeholder values, e.g. numbers in `columns`), the NSE/BSE instrument lists from the real Kite API (a fake client was
used), the classification on a live market day with real candles.

**Known limits:** no holiday calendar; stop/target checked once a minute and sold with ordinary orders (no GTT); no handling of
corporate actions on held shares; a position closed by hand at Zerodha is not noticed (the engine would try to sell shares that
are no longer there and the broker would refuse — watch the "sell by hand" warning).
