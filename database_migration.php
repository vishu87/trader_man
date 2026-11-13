<?php


-- Portfolio: buckets (named lists of stocks)
CREATE TABLE `portfolio_buckets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portfolio_buckets_name` (`name`)
) ENGINE=InnoDB;

-- Portfolio: stocks held inside a bucket. `symbol` is our own name; `zerodha_symbol` is the
-- tradingsymbol used for Kite LTP and for matching the uploaded Zerodha holdings CSV.
CREATE TABLE `portfolio_holdings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `bucket_id` INT NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `zerodha_symbol` VARCHAR(50) NOT NULL,
  `exchange` VARCHAR(10) NOT NULL DEFAULT 'NSE',
  `quantity` INT NOT NULL DEFAULT 0,
  `avg_price` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portfolio_holdings_bucket_symbol` (`bucket_id`, `symbol`),
  INDEX (`zerodha_symbol`)
) ENGINE=InnoDB;

-- Portfolio: CSV quantity in excess of what the buckets hold (wiped + rebuilt on every upload)
CREATE TABLE `portfolio_not_mapped` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `symbol` VARCHAR(50) NOT NULL,
  `quantity` INT NOT NULL,
  `avg_price` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `uploaded_qty` INT NOT NULL DEFAULT 0,
  `bucket_qty` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX (`symbol`)
) ENGINE=InnoDB;

-- Portfolio: bucket quantity in excess of the CSV (wiped + rebuilt on every upload; rows are deleted manually once rectified)
CREATE TABLE `portfolio_removals` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `symbol` VARCHAR(50) NOT NULL,
  `quantity` INT NOT NULL,
  `uploaded_qty` INT NOT NULL DEFAULT 0,
  `bucket_qty` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX (`symbol`)
) ENGINE=InnoDB;

-- Portfolio: manual "I have reviewed this stock" flag
ALTER TABLE `portfolio_holdings` ADD `checked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `avg_price`;

-- Portfolio: Kite instrument token (captured while fetching LTP; used for the Kite chart link)
ALTER TABLE `portfolio_holdings` ADD `instrument_token` BIGINT NULL DEFAULT NULL AFTER `exchange`;

-- Portfolio: tracking-only stocks (listed in a bucket, but excluded from portfolio totals and from CSV reconciliation)
ALTER TABLE `portfolio_holdings` ADD `is_tracking` TINYINT(1) NOT NULL DEFAULT 0 AFTER `checked`;

-- Portfolio: bucket whose stocks are left out of the "All" tab (list, totals, counts, export)
ALTER TABLE `portfolio_buckets` ADD `exclude_from_all` TINYINT(1) NOT NULL DEFAULT 0 AFTER `description`;

-- Watchlist: named lists of symbols being tracked (not owned), each with optional buy price / stop loss
CREATE TABLE `watchlists` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_watchlists_name` (`name`)
) ENGINE=InnoDB;

CREATE TABLE `watchlist_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `watchlist_id` INT NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `exchange` VARCHAR(10) NOT NULL DEFAULT 'NSE',
  `instrument_token` BIGINT NULL DEFAULT NULL,
  `buy_price` DECIMAL(14,4) NULL DEFAULT NULL,
  `stop_loss` DECIMAL(14,4) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_watchlist_items_list_symbol` (`watchlist_id`, `exchange`, `symbol`),
  INDEX (`symbol`)
) ENGINE=InnoDB;

-- Historical candles (daily + weekly) with indicators; written by python/candle_storage.py.
-- `symbol` is the Zerodha tradingsymbol. Indicators are NULL until enough history exists.
CREATE TABLE `stock_candles` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `timeframe` VARCHAR(10) NOT NULL,
  `date` DATE NOT NULL,
  `open` DECIMAL(14,4) NOT NULL,
  `high` DECIMAL(14,4) NOT NULL,
  `low` DECIMAL(14,4) NOT NULL,
  `close` DECIMAL(14,4) NOT NULL,
  `volume` BIGINT NOT NULL DEFAULT 0,
  `sma10` DECIMAL(14,4) NULL DEFAULT NULL,
  `sma20` DECIMAL(14,4) NULL DEFAULT NULL,
  `sma40` DECIMAL(14,4) NULL DEFAULT NULL,
  `sma50` DECIMAL(14,4) NULL DEFAULT NULL,
  `bb_low` DECIMAL(14,4) NULL DEFAULT NULL,
  `bb_high` DECIMAL(14,4) NULL DEFAULT NULL,
  `volume_ma21` BIGINT NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_candles` (`exchange`, `symbol`, `timeframe`, `date`)
) ENGINE=InnoDB;

-- "Follow" flag: stocks (portfolio or watchlist) whose charts we actually want to look at
ALTER TABLE `portfolio_holdings` ADD `follow` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_tracking`;
ALTER TABLE `watchlist_items` ADD `follow` TINYINT(1) NOT NULL DEFAULT 0 AFTER `stop_loss`;

-- Your own star rating of a watchlist symbol (1 = weak … 5 = best); NULL = not rated. Set from the Watchlist list or the chart popup.
ALTER TABLE `watchlist_items` ADD `rating` TINYINT NULL DEFAULT NULL AFTER `follow`;

-- Your own free-text comment about a watchlist symbol (up to 5000 characters), shown under the symbol and edited in place. Kept on every watchlist row of the symbol, like the rating.
ALTER TABLE `watchlist_items` ADD `comment` TEXT NULL DEFAULT NULL AFTER `rating`;

-- Candle sync bookkeeping: when each symbol was last synced SUCCESSFULLY (a general run skips symbols that are
-- already fresh; a symbol-specific run always refetches) and why the last attempt failed, if it did.
CREATE TABLE `stock_candle_sync` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `synced_at` DATETIME NULL DEFAULT NULL,
  `last_attempt_at` DATETIME NULL DEFAULT NULL,
  `last_error` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_candle_sync` (`exchange`, `symbol`)
) ENGINE=InnoDB;

-- This app's own settings (key/value). NOT the futures `settings` table, which only supplies the Kite token.
CREATE TABLE `app_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` VARCHAR(255) NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB;

-- max loss (in rupees) accepted on one trade; position size = max loss / (buy price - stop loss)
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('max_loss_per_trade', '5000', NOW());

-- Watchlist items filled by the IPO job: company name + listing date (NULL for everything added by hand)
ALTER TABLE `watchlist_items`
  ADD `listing_date` DATE NULL DEFAULT NULL AFTER `stop_loss`,
  ADD `name` VARCHAR(150) NULL DEFAULT NULL AFTER `symbol`;

-- When each symbol's chart was last looked at in the chart popup (one row per exchange:symbol, shared by Portfolio and Watchlist).
-- Drives the "Last viewed" column and the "not viewed today" filter.
CREATE TABLE `chart_views` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `last_viewed_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chart_views` (`exchange`, `symbol`)
) ENGINE=InnoDB;

-- Claude's analysis of one stock (action buy/sell/hold/avoid, pattern, entry / stop loss / target, reasons).
-- `analysis` holds the full JSON answer (reasons, risks, invalidation); `risk_note` says why the code-level risk rules
-- changed or rejected the call, if they did.
CREATE TABLE `trade_signals` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `action` VARCHAR(10) NOT NULL,
  `pattern` VARCHAR(150) NULL DEFAULT NULL,
  `confidence` TINYINT NULL DEFAULT NULL,
  `entry_price` DECIMAL(14,4) NULL DEFAULT NULL,
  `stop_loss` DECIMAL(14,4) NULL DEFAULT NULL,
  `target_price` DECIMAL(14,4) NULL DEFAULT NULL,
  `reward_risk` DECIMAL(6,2) NULL DEFAULT NULL,
  `valid_days` TINYINT NULL DEFAULT NULL,
  `ltp` DECIMAL(14,4) NULL DEFAULT NULL,
  `summary` TEXT NULL,
  `analysis` TEXT NULL,
  `risk_note` VARCHAR(500) NULL DEFAULT NULL,
  `model` VARCHAR(60) NOT NULL,
  `input_tokens` INT NULL DEFAULT NULL,
  `output_tokens` INT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  INDEX (`exchange`, `symbol`, `created_at`),
  INDEX (`created_at`)
) ENGINE=InnoDB;

-- Simulated long trade for every valid BUY signal (paper trading). Settled against stored daily candles.
-- status: pending (limit not yet touched) | open | stopped | target_hit | expired (never filled within valid_days)
CREATE TABLE `paper_trades` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `signal_id` INT NOT NULL,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `qty` INT NOT NULL,
  `entry_price` DECIMAL(14,4) NOT NULL,
  `stop_loss` DECIMAL(14,4) NOT NULL,
  `target_price` DECIMAL(14,4) NOT NULL,
  `valid_days` TINYINT NOT NULL DEFAULT 5,
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
  `signal_date` DATE NOT NULL,
  `filled_on` DATE NULL DEFAULT NULL,
  `fill_price` DECIMAL(14,4) NULL DEFAULT NULL,
  `closed_on` DATE NULL DEFAULT NULL,
  `exit_price` DECIMAL(14,4) NULL DEFAULT NULL,
  `mark_price` DECIMAL(14,4) NULL DEFAULT NULL,
  `pnl` DECIMAL(14,2) NULL DEFAULT NULL,
  `r_multiple` DECIMAL(6,2) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX (`signal_id`),
  INDEX (`exchange`, `symbol`),
  INDEX (`status`)
) ENGINE=InnoDB;

-- paper_trades.entry_type: 'limit' = pullback entry (fills when price falls to it), 'stop' = breakout entry (fills when price rises to it)
ALTER TABLE `paper_trades` ADD `entry_type` VARCHAR(5) NOT NULL DEFAULT 'limit' AFTER `symbol`;

-- ─── Fibonacci levels (per symbol) ───────────────────────────────────────────────────────────
-- A swing: its low and high. direction 'up' = the low came first (price rose, levels measured DOWN from the high);
-- 'down' = the high came first (levels measured UP from the low). source 'dates' = low / high looked up from the daily
-- candles on low_date / high_date, 'values' = typed in by hand. is_shown = draw it on the charts.
CREATE TABLE `symbol_fibs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `label` VARCHAR(60) NULL DEFAULT NULL,
  `direction` VARCHAR(4) NOT NULL DEFAULT 'up',
  `low_price` DECIMAL(14,4) NOT NULL,
  `high_price` DECIMAL(14,4) NOT NULL,
  `low_date` DATE NULL DEFAULT NULL,
  `high_date` DATE NULL DEFAULT NULL,
  `source` VARCHAR(6) NOT NULL DEFAULT 'dates',
  `is_shown` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX (`exchange`, `symbol`)
) ENGINE=InnoDB;

-- The computed levels of a swing, one row per ratio. kind 'retracement' (0 … 1) or 'extension' (1.272, 1.618 …).
-- exchange / symbol are repeated here so a later feature can ask "which symbols are near their 0.618 level" without a join.
CREATE TABLE `symbol_fib_levels` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `fib_id` INT NOT NULL,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `kind` VARCHAR(12) NOT NULL,
  `ratio` DECIMAL(7,4) NOT NULL,
  `price` DECIMAL(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  INDEX (`fib_id`),
  INDEX (`exchange`, `symbol`, `ratio`),
  INDEX (`price`)
) ENGINE=InnoDB;

-- ─── Real trades (buys and sells you actually made) ──────────────────────────────────────────
-- A trade is one stock; status 'active' | 'archived'. Only one ACTIVE trade per symbol (enforced in the app).
CREATE TABLE `trades` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `status` VARCHAR(8) NOT NULL DEFAULT 'active',
  `notes` VARCHAR(500) NULL DEFAULT NULL,
  `archived_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX (`status`),
  INDEX (`exchange`, `symbol`)
) ENGINE=InnoDB;

-- The buy / sell steps of a trade. side 'buy' | 'sell'. charges = brokerage + taxes of that step (optional, 0 if unknown).
-- Quantity held, average cost and profit / loss are always worked out from these rows (average-cost method), never stored.
CREATE TABLE `trade_actions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `trade_id` INT NOT NULL,
  `side` VARCHAR(4) NOT NULL,
  `qty` INT NOT NULL,
  `price` DECIMAL(14,4) NOT NULL,
  `charges` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `action_date` DATE NOT NULL,
  `notes` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX (`trade_id`, `action_date`)
) ENGINE=InnoDB;

-- ─── Zerodha trades (tradebook uploads + Kite API sync) ──────────────────────────────────────
-- One row per import run: source 'file' (an uploaded tradebook) or 'api' (php artisan zerodha:sync-trades / the Sync button).
-- added = new executions saved, duplicates = already stored, conflicts = same trade id but different data (left as stored),
-- skipped = rows that could not be read.
CREATE TABLE `zerodha_imports` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(5) NOT NULL,
  `file_name` VARCHAR(190) NULL DEFAULT NULL,
  `rows_read` INT NOT NULL DEFAULT 0,
  `added` INT NOT NULL DEFAULT 0,
  `duplicates` INT NOT NULL DEFAULT 0,
  `conflicts` INT NOT NULL DEFAULT 0,
  `skipped` INT NOT NULL DEFAULT 0,
  `first_date` DATE NULL DEFAULT NULL,
  `last_date` DATE NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  INDEX (`created_at`)
) ENGINE=InnoDB;

-- Every execution (fill) Zerodha reported. trade_type 'buy' | 'sell'. price is rounded to paise (the tradebook file carries
-- float noise such as 204.399994). order_id = the EXCHANGE order id, the same value in the file and in the API's exchange_order_id,
-- so an order's partial fills group together. The same trade arriving from a file and from the API is stored once.
CREATE TABLE `zerodha_trades` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL,
  `segment` VARCHAR(10) NULL DEFAULT NULL,
  `series` VARCHAR(10) NULL DEFAULT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `isin` VARCHAR(20) NULL DEFAULT NULL,
  `trade_type` VARCHAR(4) NOT NULL,
  `quantity` INT NOT NULL,
  `price` DECIMAL(14,2) NOT NULL,
  `trade_id` VARCHAR(40) NOT NULL,
  `order_id` VARCHAR(40) NULL DEFAULT NULL,
  `trade_date` DATE NOT NULL,
  `executed_at` DATETIME NULL DEFAULT NULL,
  `product` VARCHAR(10) NULL DEFAULT NULL,
  `source` VARCHAR(5) NOT NULL,
  `import_id` INT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zerodha_trade` (`exchange`, `trade_date`, `trade_id`),
  INDEX (`symbol`, `trade_date`),
  INDEX (`trade_date`),
  INDEX (`order_id`),
  INDEX (`import_id`)
) ENGINE=InnoDB;

-- ─── Scanner signals + backtest ──────────────────────────────────────────────────────────────
-- Signals from an external scanner (CSV: Date, Symbol, Marketcapname, Sector). signal_at is the START time of the 5-minute
-- candle the scanner fired on (the signal is known at that candle's close).
CREATE TABLE `scanner_imports` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `file_name` VARCHAR(190) NULL DEFAULT NULL,
  `rows_read` INT NOT NULL DEFAULT 0,
  `added` INT NOT NULL DEFAULT 0,
  `duplicates` INT NOT NULL DEFAULT 0,
  `skipped` INT NOT NULL DEFAULT 0,
  `first_date` DATE NULL DEFAULT NULL,
  `last_date` DATE NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE `scanner_signals` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `import_id` INT NULL DEFAULT NULL,
  `exchange` VARCHAR(10) NOT NULL DEFAULT 'NSE',
  `symbol` VARCHAR(50) NOT NULL,
  `signal_at` DATETIME NOT NULL,
  `trade_date` DATE NOT NULL,
  `market_cap` VARCHAR(12) NULL DEFAULT NULL,
  `sector` VARCHAR(60) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scanner_signal` (`exchange`, `symbol`, `signal_at`),
  INDEX (`trade_date`),
  INDEX (`import_id`)
) ENGINE=InnoDB;

-- Candles fetched from Kite for the backtester only (kept apart from stock_candles, which carries chart indicators).
-- timeframe '5min' | 'day'; candle_at = candle start (a day candle is 00:00:00).
CREATE TABLE `scanner_candles` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `timeframe` VARCHAR(5) NOT NULL,
  `candle_at` DATETIME NOT NULL,
  `open` DECIMAL(12,2) NOT NULL,
  `high` DECIMAL(12,2) NOT NULL,
  `low` DECIMAL(12,2) NOT NULL,
  `close` DECIMAL(12,2) NOT NULL,
  `volume` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scanner_candle` (`exchange`, `symbol`, `timeframe`, `candle_at`)
) ENGINE=InnoDB;

-- Kite instrument list (NSE equities): symbol → token, and the name (used to spot ETFs)
CREATE TABLE `scanner_instruments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `instrument_token` BIGINT NOT NULL,
  `name` VARCHAR(120) NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scanner_instrument` (`exchange`, `symbol`)
) ENGINE=InnoDB;

-- Scanner signals: the price at the moment the scanner fired (ChartInk "trigger price"), and where an import came from
-- ('file' = uploaded CSV, 'feed' = read from the futures app's raw_data alert feed).
ALTER TABLE `scanner_signals` ADD `trigger_price` DECIMAL(12,2) NULL DEFAULT NULL AFTER `sector`;
ALTER TABLE `scanner_imports` ADD `source` VARCHAR(6) NOT NULL DEFAULT 'file' AFTER `file_name`;

-- Scanner signals are historical data for backtesting only: no import bookkeeping any more.
ALTER TABLE `scanner_signals` DROP INDEX `import_id`;
ALTER TABLE `scanner_signals` DROP COLUMN `import_id`;
DROP TABLE IF EXISTS `scanner_imports`;

-- ─── Live trading (separate from the scanner backtest) ───────────────────────────────────────
-- Every ChartInk webhook call, as received.
CREATE TABLE `raw_data_chartink` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `alert_name` VARCHAR(100) NULL DEFAULT NULL,
  `raw_data` TEXT NOT NULL,
  `ip` VARCHAR(45) NULL DEFAULT NULL,
  `received_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  INDEX (`received_at`),
  INDEX (`alert_name`)
) ENGINE=InnoDB;

-- One row per stock in an alert. status: new → working → done | skipped | failed. action: entry | average (what was done). reason = why skipped / failed.
CREATE TABLE `live_signals` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `raw_id` INT NULL DEFAULT NULL,
  `alert_name` VARCHAR(100) NOT NULL,
  `exchange` VARCHAR(10) NOT NULL DEFAULT 'NSE',
  `symbol` VARCHAR(50) NOT NULL,
  `trigger_price` DECIMAL(12,2) NULL DEFAULT NULL,
  `triggered_at` DATETIME NOT NULL,
  `received_at` DATETIME NOT NULL,
  `status` VARCHAR(8) NOT NULL DEFAULT 'new',
  `action` VARCHAR(8) NULL DEFAULT NULL,
  `reason` VARCHAR(190) NULL DEFAULT NULL,
  `position_id` INT NULL DEFAULT NULL,
  `processed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_live_signal` (`alert_name`, `symbol`, `triggered_at`),
  INDEX (`status`),
  INDEX (`received_at`)
) ENGINE=InnoDB;

-- A stock we hold or are trying to buy / sell. mode: dry_run | live. status: pending (entry order not filled yet) | open | closed | cancelled (entry never filled) | failed.
-- sold_qty / exit_value accumulate sell fills; exit_price = exit_value / sold_qty and pnl are set when it closes. exit_reason: stop | target | time_exit.
CREATE TABLE `live_positions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `exchange` VARCHAR(10) NOT NULL DEFAULT 'NSE',
  `symbol` VARCHAR(50) NOT NULL,
  `mode` VARCHAR(8) NOT NULL,
  `status` VARCHAR(9) NOT NULL DEFAULT 'pending',
  `signal_id` INT NULL DEFAULT NULL,
  `qty` INT NOT NULL DEFAULT 0,
  `avg_price` DECIMAL(12,4) NOT NULL DEFAULT 0,
  `invested` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `adds` INT NOT NULL DEFAULT 0,
  `sold_qty` INT NOT NULL DEFAULT 0,
  `exit_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `exit_price` DECIMAL(12,4) NULL DEFAULT NULL,
  `exit_reason` VARCHAR(10) NULL DEFAULT NULL,
  `pnl` DECIMAL(14,2) NULL DEFAULT NULL,
  `hold_until` DATE NULL DEFAULT NULL,
  `last_price` DECIMAL(12,2) NULL DEFAULT NULL,
  `last_checked_at` DATETIME NULL DEFAULT NULL,
  `opened_at` DATETIME NULL DEFAULT NULL,
  `closed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX (`status`),
  INDEX (`symbol`, `status`)
) ENGINE=InnoDB;

-- Every order sent (or simulated in dry-run). side: buy | sell. kind: entry | average | stop | target | time_exit. status: open | complete | rejected | cancelled.
CREATE TABLE `live_orders` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `position_id` INT NOT NULL,
  `signal_id` INT NULL DEFAULT NULL,
  `side` VARCHAR(4) NOT NULL,
  `kind` VARCHAR(9) NOT NULL,
  `symbol` VARCHAR(50) NOT NULL,
  `qty` INT NOT NULL,
  `order_type` VARCHAR(6) NOT NULL,
  `price` DECIMAL(12,2) NULL DEFAULT NULL,
  `status` VARCHAR(9) NOT NULL DEFAULT 'open',
  `filled_qty` INT NOT NULL DEFAULT 0,
  `filled_price` DECIMAL(12,4) NULL DEFAULT NULL,
  `broker_order_id` VARCHAR(40) NULL DEFAULT NULL,
  `mode` VARCHAR(8) NOT NULL,
  `message` VARCHAR(255) NULL DEFAULT NULL,
  `placed_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX (`position_id`),
  INDEX (`status`),
  INDEX (`placed_at`)
) ENGINE=InnoDB;

-- app_settings.setting_value now holds JSON (the live trading config): room for it.
ALTER TABLE `app_settings` MODIFY `setting_value` TEXT NULL;

-- ─── Live signals: classification (Can enter / Watch / Skip), ignored duplicates, manual exit ───────────────────────────
-- live_signals.status is now: new → (working) → watching (verdict Watch: re-checked on every closed 5-min candle) | enter (verdict Can enter, bought not yet: waiting for a free slot / limits)
--   → done (bought) | skipped | ignored (the stock is already held, or an earlier signal for it is still waiting) | expired (no entry within signal_valid_minutes) | failed.
-- verdict = the latest classification: enter | watch | skip | nodata. checks = how many times it was looked at (rows in live_signal_checks).
ALTER TABLE `live_signals`
  ADD COLUMN `scan_name` VARCHAR(100) NULL DEFAULT NULL AFTER `alert_name`,
  ADD COLUMN `sector` VARCHAR(80) NULL DEFAULT NULL AFTER `trigger_price`,
  ADD COLUMN `market_cap` VARCHAR(30) NULL DEFAULT NULL AFTER `sector`,
  ADD COLUMN `alert_rsi` DECIMAL(6,2) NULL DEFAULT NULL AFTER `market_cap`,
  ADD COLUMN `alert_close` DECIMAL(12,2) NULL DEFAULT NULL AFTER `alert_rsi`,
  ADD COLUMN `verdict` VARCHAR(8) NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN `score` TINYINT NULL DEFAULT NULL AFTER `verdict`,
  ADD COLUMN `checked_candle` DATETIME NULL DEFAULT NULL AFTER `reason`,
  ADD COLUMN `checked_at` DATETIME NULL DEFAULT NULL AFTER `checked_candle`,
  ADD COLUMN `checks` SMALLINT NOT NULL DEFAULT 0 AFTER `checked_at`;

-- A position closed by hand (the Exit button): the once-a-minute check keeps trying until it is sold. exit_reason / order kind can now also be 'manual'.
ALTER TABLE `live_positions` ADD COLUMN `exit_requested_at` DATETIME NULL DEFAULT NULL AFTER `hold_until`;

-- The status of a signal over time: one row each time it was classified. kind: candle (a new closed candle) | recheck (re-validated just before an entry).
CREATE TABLE `live_signal_checks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `signal_id` INT NOT NULL,
  `checked_at` DATETIME NOT NULL,
  `candle_at` DATETIME NULL DEFAULT NULL,
  `kind` VARCHAR(8) NOT NULL,
  `verdict` VARCHAR(8) NOT NULL,
  `score` TINYINT NULL DEFAULT NULL,
  `price` DECIMAL(12,2) NULL DEFAULT NULL,
  `ltp` DECIMAL(12,2) NULL DEFAULT NULL,
  `fails` VARCHAR(120) NULL DEFAULT NULL,
  `note` VARCHAR(250) NULL DEFAULT NULL,
  `details` TEXT NULL,
  PRIMARY KEY (`id`),
  INDEX (`signal_id`, `checked_at`)
) ENGINE=InnoDB;

-- ─── The Kite access token now lives in this database (no more futures database) ─────────────────────────────────────────
-- No schema change: two rows in the existing `app_settings` table, written by KiteService::refreshAccessToken() (hourly `kite:refresh-token`,
-- the Dashboard's Refresh Token button, and the Sync from Kite buttons) and read by PHP and by python/candle_storage.py:
--   setting_key = 'kite_access_token'  → the token
--   setting_key = 'kite_token_date'    → the day it is valid for (Y-m-d); a token of an earlier day is never used
-- The old `mysql_futures` connection (DB_*_FUTURES in .env) is removed.

-- Live signals: ignored is a SEPARATE field (not only a status). A repeat of a signal we already have — same alert, same stock, same day, the
-- earlier one still waiting / bought / judged Skip (or an alert that is not enabled: record-only) — and a signal for a stock we already hold are stored
-- with ignored = 1 (status stays 'ignored', repeat_of = the signal it repeats). The Signals tab's All / Waiting / … lists leave them out; the Ignored tab shows them.
ALTER TABLE `live_signals`
  ADD COLUMN `ignored` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
  ADD COLUMN `repeat_of` INT NULL DEFAULT NULL AFTER `ignored`,
  ADD INDEX `idx_live_signals_ignored` (`ignored`, `id`);
UPDATE `live_signals` SET `ignored` = 1 WHERE `status` = 'ignored';

-- Live signals: DUPLICATES have their own table. A signal for a stock that already has a signal today (same alert, or another enabled alert) that is
-- still waiting, was bought, or was judged Skip is stored here, not in live_signals; the trading engine never reads it. The Live Signals page lists them
-- (Duplicates tab) and has a button that deletes them. repeat_of = the live_signals row it repeats, first_alert = that row's alert.
-- Rows that were already stored as ignored repeats move over (the engine's own "ignored" signals — stock held / already waiting — stay in live_signals).
CREATE TABLE `live_signal_duplicates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `raw_id` INT NULL DEFAULT NULL,
  `alert_name` VARCHAR(100) NOT NULL,
  `scan_name` VARCHAR(100) NULL DEFAULT NULL,
  `exchange` VARCHAR(10) NOT NULL DEFAULT 'NSE',
  `symbol` VARCHAR(50) NOT NULL,
  `trigger_price` DECIMAL(12,2) NULL DEFAULT NULL,
  `sector` VARCHAR(80) NULL DEFAULT NULL,
  `market_cap` VARCHAR(30) NULL DEFAULT NULL,
  `alert_rsi` DECIMAL(8,2) NULL DEFAULT NULL,
  `alert_close` DECIMAL(12,2) NULL DEFAULT NULL,
  `triggered_at` DATETIME NOT NULL,
  `received_at` DATETIME NOT NULL,
  `repeat_of` INT NULL DEFAULT NULL,
  `first_alert` VARCHAR(100) NULL DEFAULT NULL,
  `reason` VARCHAR(190) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_live_signal_duplicate` (`alert_name`, `symbol`, `triggered_at`),
  INDEX (`triggered_at`),
  INDEX (`repeat_of`)
) ENGINE=InnoDB;
INSERT IGNORE INTO `live_signal_duplicates` (raw_id, alert_name, scan_name, exchange, symbol, trigger_price, sector, market_cap, alert_rsi, alert_close, triggered_at, received_at, repeat_of, first_alert, reason, created_at)
  SELECT s.raw_id, s.alert_name, s.scan_name, s.exchange, s.symbol, s.trigger_price, s.sector, s.market_cap, s.alert_rsi, s.alert_close, s.triggered_at, s.received_at, s.repeat_of, f.alert_name, s.reason, s.created_at
    FROM `live_signals` s LEFT JOIN `live_signals` f ON f.id = s.repeat_of WHERE s.ignored = 1 AND s.reason LIKE 'repeat of%';
DELETE FROM `live_signals` WHERE `ignored` = 1 AND `reason` LIKE 'repeat of%';
