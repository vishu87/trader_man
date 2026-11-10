<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE TABLE `watchlists` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              `sort_order` INT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_watchlists_name` (`name`)
            ) ENGINE=InnoDB;
        ");

        DB::unprepared("
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
        ");

        // Daily + weekly candles with the indicators the charts need. Written by python/candle_storage.py
        DB::unprepared("
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
        ");
    }

    public function down(): void
    {
        DB::unprepared("DROP TABLE IF EXISTS `stock_candles`");
        DB::unprepared("DROP TABLE IF EXISTS `watchlist_items`");
        DB::unprepared("DROP TABLE IF EXISTS `watchlists`");
    }
};
