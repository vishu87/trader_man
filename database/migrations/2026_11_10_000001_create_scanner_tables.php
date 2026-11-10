<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // one row per uploaded scanner file
        DB::unprepared("
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
        ");

        // every time the scanner flagged a stock; signal_at = the START of the 5-minute candle it fired on
        DB::unprepared("
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
        ");

        // candles used by the backtester. timeframe '5min' | 'day'; candle_at = candle start (a day candle is 00:00:00)
        DB::unprepared("
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
        ");

        // Kite's instrument list (NSE equities): symbol → token, plus the name, used to spot ETFs
        DB::unprepared("
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
        ");
    }

    public function down(): void
    {
        foreach (['scanner_instruments', 'scanner_candles', 'scanner_signals', 'scanner_imports'] as $t) {
            DB::unprepared("DROP TABLE IF EXISTS `{$t}`");
        }
    }
};
