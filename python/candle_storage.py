"""
Store daily + weekly candles (with indicators) for every stock we care about.

Candles  : daily, weekly and monthly (weekly / monthly are built from the daily candles)
Symbols  : portfolio holdings (zerodha_symbol) + watchlist items + symbols in a live paper trade or an active trade, de-duplicated
Source   : Kite historical data
Storage  : `stock_candles` in the trader_man database (upsert, safe to re-run any time)
Token    : read from `app_settings` of the trader_man database (kept fresh by the PHP side: `kite:refresh-token`, hourly)

Usage (from the project root):
    python/venv/bin/python python/candle_storage.py                 # everything
    python/venv/bin/python python/candle_storage.py --symbol INFY   # one or more (comma separated)
    python/venv/bin/python python/candle_storage.py --list          # just show what would be synced
    python/venv/bin/python python/candle_storage.py --force         # refetch even symbols that are already up to date
    python/venv/bin/python python/candle_storage.py --full          # download the whole history again, not just what is new

A general run SKIPS symbols that were already synced successfully (see is_fresh); symbols that failed or were never
fetched are always retried. Naming symbols with --symbol is a deliberate refetch, so it never skips.

INCREMENTAL: for a symbol that already has daily candles stored, only the days AFTER the last stored date are fetched (plus a
few days of overlap, see OVERLAP_DAYS). Weekly / monthly candles and the moving averages are rebuilt from the stored daily
history joined with the new days, and only the rows that can have changed are written. The overlap doubles as a safety check:
if Kite's recent history no longer matches what is stored (a split / bonus adjusted the old prices), that symbol is downloaded
in full again. Naming symbols with --symbol, or --full, always downloads the whole history.

Credentials come from the project's .env (DB_*, KITE_API_KEY) — nothing is hard-coded here. Only the DB_DATABASE database is used.
"""

import argparse
import datetime
import os
import sys
import time

import mysql.connector
import pandas as pd
from kiteconnect import KiteConnect

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.realpath(__file__)), ".."))

# Kite allows at most 2000 days per request for the "day" interval
MAX_DAYS = 2000
# Kite historical API is limited to ~3 requests / second
REQUEST_GAP = 0.4
UPSERT_CHUNK = 500
# An incremental fetch starts this many calendar days before the last stored candle. That re-fetches the last candle (which may
# have been fetched while still forming) and gives something to compare against to notice price adjustments.
OVERLAP_DAYS = 10
# Stored closes may differ from Kite's by at most this fraction before we conclude history was adjusted and refetch it all
ADJUST_TOLERANCE = 0.01
# On an incremental run weekly / monthly rows are re-written from this many days before the fetch start (a month is dated by its
# first trading day, so the running week / month began up to ~31 days earlier)
REWRITE_PAD_DAYS = 35
# Indian cash market closes 15:30; a candle fetched before that is still forming
MARKET_CLOSE = datetime.time(15, 35)


def log(msg=""):
    print(msg, flush=True)


# ─── Config ──────────────────────────────────────────────────────────

def load_env(path):
    env = {}
    with open(path) as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            env[key.strip()] = value.strip().strip('"').strip("'")
    return env


def connect(env, database):
    return mysql.connector.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=int(env.get("DB_PORT", 3306)),
        user=env.get("DB_USERNAME", "root"),
        password=env.get("DB_PASSWORD", ""),
        database=database,
    )


def get_access_token(env):
    """Today's Kite access token from this app's own `app_settings` table (keys kite_access_token + kite_token_date).
    A token stored on an earlier day is not used. Returns None when there is none for today."""
    db = connect(env, env["DB_DATABASE"])
    cur = db.cursor()
    cur.execute("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('kite_access_token', 'kite_token_date')")
    rows = dict(cur.fetchall())
    db.close()
    if rows.get("kite_token_date") != datetime.date.today().strftime("%Y-%m-%d"):
        return None
    return rows.get("kite_access_token") or None


# ─── Which symbols ───────────────────────────────────────────────────

def get_symbols(db):
    """{(exchange, symbol): instrument_token or None}"""
    cur = db.cursor()
    cur.execute(
        """
        SELECT exchange, zerodha_symbol, instrument_token FROM portfolio_holdings
        UNION ALL
        SELECT exchange, symbol, instrument_token FROM watchlist_items
        UNION ALL
        SELECT exchange, symbol, NULL FROM paper_trades WHERE status IN ('pending', 'open')
        UNION ALL
        SELECT exchange, symbol, NULL FROM trades WHERE status = 'active'
        """
    )
    symbols = {}
    for exchange, symbol, token in cur.fetchall():
        key = (exchange, symbol)
        # the same symbol can appear in several places; keep a token if any of them has one
        if key not in symbols or (symbols[key] is None and token):
            symbols[key] = token
    return symbols


def resolve_missing_tokens(kite, db, symbols):
    """Fill in instrument tokens we have not seen yet (via a quote) and save them back."""
    missing = [key for key, token in symbols.items() if not token]
    if not missing:
        return

    cur = db.cursor()
    for i in range(0, len(missing), 200):
        chunk = missing[i:i + 200]
        try:
            quotes = kite.quote([f"{ex}:{sym}" for ex, sym in chunk])
        except Exception as e:
            # these symbols simply stay without a token and get skipped below; everything else carries on
            log(f"WARNING: could not look up {len(chunk)} instrument token(s): {e}")
            continue

        for ex, sym in chunk:
            try:
                # Kite leaves unknown symbols out of the response — they stay without a token
                quote = quotes.get(f"{ex}:{sym}")
                if quote and quote.get("instrument_token"):
                    token = int(quote["instrument_token"])
                    symbols[(ex, sym)] = token
                    cur.execute("UPDATE portfolio_holdings SET instrument_token = %s WHERE exchange = %s AND zerodha_symbol = %s", (token, ex, sym))
                    cur.execute("UPDATE watchlist_items SET instrument_token = %s WHERE exchange = %s AND symbol = %s", (token, ex, sym))
            except Exception as e:
                log(f"WARNING: could not save the instrument token of {ex}:{sym}: {e}")
        db.commit()


# ─── Candles + indicators ────────────────────────────────────────────

def to_frame(history):
    df = pd.DataFrame(history)
    # Kite returns tz-aware timestamps; keep the exchange's wall-clock date
    df["date"] = pd.to_datetime(df["date"].apply(lambda d: d.replace(tzinfo=None))).dt.normalize()
    return df[["date", "open", "high", "low", "close", "volume"]].sort_values("date").reset_index(drop=True)


def to_weekly(daily):
    """One candle per ISO week, dated by the week's first trading day. The running week is included
    (and simply re-written as it fills up on later runs)."""
    iso = daily["date"].dt.isocalendar()
    key = iso["year"].astype(int) * 100 + iso["week"].astype(int)
    return (
        daily.groupby(key, sort=True)
        .agg(date=("date", "first"), open=("open", "first"), high=("high", "max"),
             low=("low", "min"), close=("close", "last"), volume=("volume", "sum"))
        .reset_index(drop=True)
    )


def to_monthly(daily):
    """One candle per calendar month, dated by the month's first trading day. The running month is included
    (and re-written as it fills up on later runs)."""
    key = daily["date"].dt.year * 100 + daily["date"].dt.month
    return (
        daily.groupby(key, sort=True)
        .agg(date=("date", "first"), open=("open", "first"), high=("high", "max"),
             low=("low", "min"), close=("close", "last"), volume=("volume", "sum"))
        .reset_index(drop=True)
    )


def add_indicators(df):
    close = df["close"]
    for n in (10, 20, 40, 50):
        df[f"sma{n}"] = close.rolling(n).mean()

    # Bollinger bands: 20-period, 2 standard deviations (population std, same as the `ta` library)
    std20 = close.rolling(20).std(ddof=0)
    df["bb_high"] = df["sma20"] + 2 * std20
    df["bb_low"] = df["sma20"] - 2 * std20

    df["volume_ma21"] = df["volume"].rolling(21).mean()
    return df


def num(value, digits=4):
    """NaN -> None (NULL in MySQL) so 'not enough history yet' is never stored as 0."""
    return None if pd.isna(value) else round(float(value), digits)


def to_rows(df, exchange, symbol, timeframe):
    rows = []
    for r in df.itertuples(index=False):
        vol_ma = None if pd.isna(r.volume_ma21) else int(round(r.volume_ma21))
        rows.append((
            exchange, symbol, timeframe, r.date.strftime("%Y-%m-%d"),
            num(r.open), num(r.high), num(r.low), num(r.close), int(r.volume),
            num(r.sma10), num(r.sma20), num(r.sma40), num(r.sma50),
            num(r.bb_low), num(r.bb_high), vol_ma,
        ))
    return rows


UPSERT_SQL = """
    INSERT INTO stock_candles
        (exchange, symbol, timeframe, `date`, `open`, high, low, `close`, volume,
         sma10, sma20, sma40, sma50, bb_low, bb_high, volume_ma21)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
    ON DUPLICATE KEY UPDATE
        `open` = VALUES(`open`), high = VALUES(high), low = VALUES(low), `close` = VALUES(`close`),
        volume = VALUES(volume), sma10 = VALUES(sma10), sma20 = VALUES(sma20), sma40 = VALUES(sma40),
        sma50 = VALUES(sma50), bb_low = VALUES(bb_low), bb_high = VALUES(bb_high), volume_ma21 = VALUES(volume_ma21)
"""


def save(db, rows):
    cur = db.cursor()
    for i in range(0, len(rows), UPSERT_CHUNK):
        cur.executemany(UPSERT_SQL, rows[i:i + UPSERT_CHUNK])
    db.commit()


# ─── Monthly candles from what is already stored ─────────────────────

def symbols_missing_monthly(db, symbols):
    """Selected symbols that have daily candles stored but no monthly ones yet."""
    cur = db.cursor()
    cur.execute("SELECT DISTINCT exchange, symbol FROM stock_candles WHERE timeframe = 'monthly'")
    have = set(cur.fetchall())
    cur.execute("SELECT DISTINCT exchange, symbol FROM stock_candles WHERE timeframe = 'daily'")
    with_daily = set(cur.fetchall())
    return sorted(k for k in symbols if k in with_daily and k not in have)


def build_monthly_from_stored(db, keys):
    """Derive monthly candles from the DAILY candles already in the database — no Kite call, no rate limit.
    Returns (built, [(exchange:symbol, reason), ...]). One symbol failing never stops the others."""
    built, failed = 0, []
    cur = db.cursor()
    for exchange, symbol in keys:
        try:
            cur.execute(
                "SELECT `date`, `open`, high, low, `close`, volume FROM stock_candles "
                "WHERE exchange = %s AND symbol = %s AND timeframe = 'daily' ORDER BY `date`",
                (exchange, symbol),
            )
            rows = cur.fetchall()
            if not rows:
                continue
            daily = pd.DataFrame(rows, columns=["date", "open", "high", "low", "close", "volume"])
            daily["date"] = pd.to_datetime(daily["date"])
            daily = daily.astype({"open": float, "high": float, "low": float, "close": float, "volume": float})

            monthly = add_indicators(to_monthly(daily))
            save(db, to_rows(monthly, exchange, symbol, "monthly"))
            built += 1
        except Exception as e:
            log(f"WARNING: monthly candles for {exchange}:{symbol} failed — {type(e).__name__}: {e}")
            failed.append((f"{exchange}:{symbol}", f"{type(e).__name__}: {e}"))
            _recover_db(db)
    return built, failed


# ─── What has already been synced ────────────────────────────────────

def load_sync_state(db):
    """{(exchange, symbol): datetime of the last SUCCESSFUL sync}"""
    cur = db.cursor()
    cur.execute("SELECT exchange, symbol, synced_at FROM stock_candle_sync WHERE synced_at IS NOT NULL")
    return {(ex, sym): at for ex, sym, at in cur.fetchall()}


def is_fresh(synced_at, now):
    """True when a symbol does not need fetching again.

    Only a sync from TODAY counts (so the daily run still refreshes everything each day). One more rule: a candle fetched
    during market hours is still forming, so once the market has closed, a symbol last synced before the close is
    fetched once more to capture the final candle. Before the close, 'synced earlier today' is good enough.
    """
    if not synced_at or synced_at.date() != now.date():
        return False
    close = datetime.datetime.combine(now.date(), MARKET_CLOSE)
    return synced_at >= close or now < close


def mark_success(db, exchange, symbol, now):
    cur = db.cursor()
    cur.execute(
        """INSERT INTO stock_candle_sync (exchange, symbol, synced_at, last_attempt_at, last_error)
           VALUES (%s, %s, %s, %s, NULL)
           ON DUPLICATE KEY UPDATE synced_at = VALUES(synced_at), last_attempt_at = VALUES(last_attempt_at), last_error = NULL""",
        (exchange, symbol, now, now),
    )
    db.commit()


def mark_failure(db, exchange, symbol, now, reason):
    """Remember why it failed. synced_at is left alone, so an earlier good sync is not forgotten
    (it simply is not 'fresh' any more, and the symbol is retried next run)."""
    cur = db.cursor()
    cur.execute(
        """INSERT INTO stock_candle_sync (exchange, symbol, last_attempt_at, last_error)
           VALUES (%s, %s, %s, %s)
           ON DUPLICATE KEY UPDATE last_attempt_at = VALUES(last_attempt_at), last_error = VALUES(last_error)""",
        (exchange, symbol, now, str(reason)[:255]),
    )
    db.commit()


def track(fn, *args):
    """Bookkeeping must never break a sync."""
    try:
        fn(*args)
    except Exception as e:
        log(f"WARNING: could not record sync state: {e}")


# ─── One symbol / all symbols ────────────────────────────────────────

class SymbolSkipped(Exception):
    """An expected, harmless reason a symbol has no candles (unknown to Kite, nothing returned, ...)."""


def load_stored_daily(db, exchange, symbol):
    """The daily candles already in the database, oldest first (an empty frame when there are none)."""
    cur = db.cursor()
    cur.execute(
        "SELECT `date`, `open`, high, low, `close`, volume FROM stock_candles "
        "WHERE exchange = %s AND symbol = %s AND timeframe = 'daily' ORDER BY `date`",
        (exchange, symbol),
    )
    rows = cur.fetchall()
    if not rows:
        return pd.DataFrame(columns=["date", "open", "high", "low", "close", "volume"])
    df = pd.DataFrame(rows, columns=["date", "open", "high", "low", "close", "volume"])
    df["date"] = pd.to_datetime(df["date"])
    return df.astype({"open": float, "high": float, "low": float, "close": float, "volume": float})


def history_was_adjusted(stored, fresh, last_stored):
    """True when Kite's recent candles disagree with the ones we stored for the same days — i.e. old prices were adjusted (split,
    bonus ...). The last stored day is left out: it may have been saved while still forming."""
    both = stored[stored["date"] < last_stored].merge(fresh, on="date", suffixes=("_old", "_new"))
    if both.empty:
        return False
    diff = (both["close_old"] - both["close_new"]).abs() / both["close_old"].abs().clip(lower=1e-9)
    return bool((diff > ADJUST_TOLERANCE).any())


def build_and_save(db, exchange, symbol, daily_ohlcv, rewrite_from=None):
    """Indicators + weekly + monthly from a full daily series, then upsert. rewrite_from (a date) limits the rows written to
    those that can have changed; None writes everything. Returns (daily, weekly, monthly) frames."""
    daily = add_indicators(daily_ohlcv.copy())
    weekly = add_indicators(to_weekly(daily_ohlcv))
    monthly = add_indicators(to_monthly(daily_ohlcv))

    def tail(df):
        return df if rewrite_from is None else df[df["date"] >= rewrite_from]

    save(db, to_rows(tail(daily), exchange, symbol, "daily") + to_rows(tail(weekly), exchange, symbol, "weekly")
         + to_rows(tail(monthly), exchange, symbol, "monthly"))
    return daily, weekly, monthly


def sync_symbol(kite, db, exchange, symbol, instrument_token, from_date, to_date, full=False):
    """Fetch, compute and store one symbol. Returns a summary string; raises on any problem.

    Unless full, a symbol that already has daily candles only has the days after the last stored one fetched."""
    if not instrument_token:
        raise SymbolSkipped("Kite does not know this symbol (no instrument token)")

    stored = None if full else load_stored_daily(db, exchange, symbol)
    incremental = stored is not None and len(stored) > 0
    note = ""

    if incremental:
        last_stored = stored["date"].iloc[-1]
        start = max(from_date, (last_stored - datetime.timedelta(days=OVERLAP_DAYS)).to_pydatetime())
        history = kite.historical_data(instrument_token, start, to_date, "day")
        if not history:
            raise SymbolSkipped("no candles returned (delisted / expired / no trades?)")

        fresh = to_frame(history)
        if history_was_adjusted(stored, fresh, last_stored):
            incremental = False
            note = "  [prices were adjusted since the last sync — whole history refetched]"
        else:
            new_days = int((fresh["date"] > last_stored).sum())
            merged = pd.concat([stored[stored["date"] < fresh["date"].min()], fresh], ignore_index=True)
            merged = merged.drop_duplicates("date", keep="last").sort_values("date").reset_index(drop=True)
            rewrite_from = pd.Timestamp(start) - datetime.timedelta(days=REWRITE_PAD_DAYS)
            daily, weekly, monthly = build_and_save(db, exchange, symbol, merged, rewrite_from)
            return f"+{new_days} new day(s) since {last_stored.strftime('%Y-%m-%d')}  (to {daily['date'].iloc[-1].strftime('%Y-%m-%d')})"

    history = kite.historical_data(instrument_token, from_date, to_date, "day")
    if not history:
        raise SymbolSkipped("no candles returned (delisted / expired / no trades?)")

    daily, weekly, monthly = build_and_save(db, exchange, symbol, to_frame(history))
    return f"{len(daily)} daily, {len(weekly)} weekly, {len(monthly)} monthly  (to {daily['date'].iloc[-1].strftime('%Y-%m-%d')}){note}"


def sync_all(kite, db, symbols, from_date, to_date, gap=REQUEST_GAP, state=None, force=False, now=None, full=False):
    """Sync every symbol. One symbol failing for ANY reason never stops the others.

    state : {(exchange, symbol): last successful sync datetime}. When given (and not force), symbols that are
            still fresh are skipped without touching Kite; and every outcome is recorded in stock_candle_sync.
    full  : download the whole history even for symbols that already have candles stored (default: only what is new).
    Returns (ok_count, [(exchange:symbol, reason), ...] failed, [exchange:symbol, ...] skipped as up to date).
    """
    now = now or datetime.datetime.now()
    ok, failed, fresh = 0, [], []
    ordered = sorted(symbols.items(), key=lambda x: (x[0][1], x[0][0]))

    for n, ((exchange, symbol), instrument_token) in enumerate(ordered, start=1):
        label = f"[{n}/{len(ordered)}] {exchange}:{symbol}"

        if state is not None and not force and is_fresh(state.get((exchange, symbol)), now):
            log(f"{label}  up to date (synced {state[(exchange, symbol)].strftime('%H:%M')}) — skipped")
            fresh.append(f"{exchange}:{symbol}")
            continue

        try:
            summary = sync_symbol(kite, db, exchange, symbol, instrument_token, from_date, to_date, full=full)
            log(f"{label}  {summary}")
            ok += 1
            if state is not None:
                track(mark_success, db, exchange, symbol, datetime.datetime.now())
        except SymbolSkipped as e:
            log(f"{label}  SKIP — {e}")
            failed.append((f"{exchange}:{symbol}", str(e)))
            if state is not None:
                track(mark_failure, db, exchange, symbol, datetime.datetime.now(), e)
        except Exception as e:  # network / rate limit / bad data / database — log it and carry on
            log(f"{label}  FAILED — {type(e).__name__}: {e}")
            failed.append((f"{exchange}:{symbol}", f"{type(e).__name__}: {e}"))
            _recover_db(db)
            if state is not None:
                track(mark_failure, db, exchange, symbol, datetime.datetime.now(), f"{type(e).__name__}: {e}")

        time.sleep(gap)

    return ok, failed, fresh


def _recover_db(db):
    """After a failed save, drop the half-done transaction and make sure the connection still works."""
    try:
        db.rollback()
    except Exception:
        pass
    try:
        db.ping(reconnect=True, attempts=3, delay=1)
    except Exception:
        pass


# ─── Main ────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Sync daily + weekly candles from Kite")
    parser.add_argument("--symbol", help="only these symbols (comma separated, e.g. INFY,TCS)")
    parser.add_argument("--exchange", help="limit to NSE or BSE")
    parser.add_argument("--days", type=int, default=MAX_DAYS, help=f"history length in days (max {MAX_DAYS})")
    parser.add_argument("--list", action="store_true", help="only list the symbols that would be synced")
    parser.add_argument("--force", action="store_true", help="refetch even symbols that are already up to date")
    parser.add_argument("--full", action="store_true", help="download the whole history again instead of only the days after the last stored one")
    parser.add_argument("--incremental", action="store_true", help="with --symbol: refetch those symbols but only the days after the last stored one (quick refresh of today's candle), not the whole history")
    args = parser.parse_args()

    env = load_env(os.path.join(ROOT, ".env"))
    db = connect(env, env["DB_DATABASE"])

    symbols = get_symbols(db)
    if args.symbol:
        wanted = {s.strip().upper() for s in args.symbol.split(",") if s.strip()}
        symbols = {k: v for k, v in symbols.items() if k[1].upper() in wanted}
    if args.exchange:
        symbols = {k: v for k, v in symbols.items() if k[0] == args.exchange.upper()}

    # naming symbols is a deliberate refetch — it never skips
    force = args.force or bool(args.symbol)
    # ... and so is a full download: it is the way to repair a symbol, while a general run only adds what is new
    full = args.full or (bool(args.symbol) and not args.incremental)
    now = datetime.datetime.now()
    state = load_sync_state(db)

    log(f"{len(symbols)} symbol(s) selected")
    if args.list or not symbols:
        for (ex, sym), token in sorted(symbols.items(), key=lambda x: (x[0][1], x[0][0])):
            at = state.get((ex, sym))
            status = ("up to date" if is_fresh(at, now) else "due") + (f" (last sync {at.strftime('%Y-%m-%d %H:%M')})" if at else " (never synced)")
            log(f"  {ex}:{sym}  token={token}  {status}")
        return 0

    # Monthly candles come from the stored daily ones, so symbols that were synced before monthly existed (and are
    # therefore "up to date" and skipped below) still get them — without touching Kite.
    missing = symbols_missing_monthly(db, symbols)
    if missing:
        built, _ = build_monthly_from_stored(db, missing)
        log(f"Built monthly candles from stored daily data for {built} of {len(missing)} symbol(s)")

    pending = [k for k in symbols if force or not is_fresh(state.get(k), now)]
    if not pending:
        log("Everything is already up to date — nothing to fetch (use --force or --symbol to refetch)")
        return 0

    token = get_access_token(env)
    if not token:
        log("ERROR: no Kite access token for today in app_settings — use Refresh Token on the Dashboard (it is also fetched hourly) — nothing synced")
        return 2

    kite = KiteConnect(api_key=env["KITE_API_KEY"])
    kite.set_access_token(token)

    resolve_missing_tokens(kite, db, symbols)

    days = min(args.days, MAX_DAYS)
    to_date = datetime.datetime.now()
    from_date = to_date - datetime.timedelta(days=days)

    ok, failed, fresh = sync_all(kite, db, symbols, from_date, to_date, state=state, force=force, now=now, full=full)

    log("")
    log(f"Done: {ok} synced, {len(fresh)} already up to date (skipped), {len(failed)} skipped/failed")
    for name, reason in failed:
        log(f"  - {name}: {reason}")
    db.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
