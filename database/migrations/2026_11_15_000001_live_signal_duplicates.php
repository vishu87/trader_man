<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Duplicate signals (a stock that already has a signal today — same alert or another — repeated later) live in their own table, so the
        // live_signals table stays small and the duplicates can be deleted in one go. Nothing in the trading engine reads this table.
        DB::unprepared("
            CREATE TABLE IF NOT EXISTS `live_signal_duplicates` (
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
            ) ENGINE=InnoDB
        ");

        // the repeats already stored as ignored signals move over
        DB::unprepared("
            INSERT IGNORE INTO `live_signal_duplicates`
              (raw_id, alert_name, scan_name, exchange, symbol, trigger_price, sector, market_cap, alert_rsi, alert_close, triggered_at, received_at, repeat_of, first_alert, reason, created_at)
            SELECT s.raw_id, s.alert_name, s.scan_name, s.exchange, s.symbol, s.trigger_price, s.sector, s.market_cap, s.alert_rsi, s.alert_close, s.triggered_at, s.received_at,
                   s.repeat_of, f.alert_name, s.reason, s.created_at
              FROM `live_signals` s LEFT JOIN `live_signals` f ON f.id = s.repeat_of
             WHERE s.ignored = 1 AND s.reason LIKE 'repeat of%'
        ");
        DB::unprepared("DELETE FROM `live_signals` WHERE `ignored` = 1 AND `reason` LIKE 'repeat of%'");
    }

    public function down(): void
    {
        DB::unprepared("DROP TABLE IF EXISTS `live_signal_duplicates`");
    }
};
