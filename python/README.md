# Candle storage

`candle_storage.py` pulls daily candles from Kite, builds weekly candles, adds the indicators
(SMA 10/20/40/50, Bollinger 20/2, volume SMA 21) and upserts them into `stock_candles`.
Symbols = portfolio holdings + watchlist items + symbols in a live paper trade. Safe to re-run any time.

**Incremental:** a symbol that already has candles only has the days after its last stored candle fetched (plus 10 days of
overlap); weekly / monthly candles and indicators are rebuilt from the stored history joined with the new days. If the overlap
shows Kite's recent prices no longer match what is stored (a split or bonus adjusted them), that symbol is downloaded in full.
`--symbol` and `--full` always download the whole history. `--symbol X --incremental` refetches that symbol but only the days after its last stored one (a few seconds, includes today's forming candle) — this is what the chart popup's Sync button runs.

## One-time setup (on every machine that runs it)

    python3 -m venv python/venv
    python/venv/bin/pip install -r python/requirements.txt

Credentials are read from the project `.env` (`DB_*`, `KITE_API_KEY`). Only the `DB_DATABASE` database is used.
The Kite access token comes from the `app_settings` table of that same database (keys `kite_access_token` and `kite_token_date`) and
must be today's. The PHP side keeps it fresh: `php artisan kite:refresh-token` runs hourly from the scheduler, and the Dashboard's
Refresh Token button and the Sync from Kite buttons fetch it too.
Set `PYTHON_BIN` in `.env` only if the interpreter is somewhere else.

## Running it

    php artisan candles:sync                  # everything (what the scheduler runs, weekdays 16:30)
    php artisan candles:sync --symbol=INFY    # one or more symbols, comma separated (whole history again)
    php artisan candles:sync --full           # whole history again for everything
    python/venv/bin/python python/candle_storage.py --list     # show what would be synced

The scheduled run needs the Laravel scheduler cron: `* * * * * php /path/to/artisan schedule:run`.
The "Sync candles" button on the Charts page starts the same script in the background.

The "Clear unused candles" button on the Charts page deletes stored candles of symbols that are in no portfolio bucket, watchlist or paper trade (it shows what it will remove first).
