<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // what ChartInk sends about each stock, and where the signal stands (verdict = latest classification, checks = how many times it was looked at)
        DB::unprepared("
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
              ADD COLUMN `checks` SMALLINT NOT NULL DEFAULT 0 AFTER `checked_at`
        ");

        // a position someone asked to close by hand: the once-a-minute check keeps trying until it is sold
        DB::unprepared("ALTER TABLE `live_positions` ADD COLUMN `exit_requested_at` DATETIME NULL DEFAULT NULL AFTER `hold_until`");

        // every time a signal was looked at: its status over time
        DB::unprepared("
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
        ");
    }

    public function down(): void
    {
        DB::unprepared("DROP TABLE IF EXISTS `live_signal_checks`");
        DB::unprepared("ALTER TABLE `live_positions` DROP COLUMN `exit_requested_at`");
        DB::unprepared("ALTER TABLE `live_signals` DROP COLUMN `scan_name`, DROP COLUMN `sector`, DROP COLUMN `market_cap`, DROP COLUMN `alert_rsi`, DROP COLUMN `alert_close`,
            DROP COLUMN `verdict`, DROP COLUMN `score`, DROP COLUMN `checked_candle`, DROP COLUMN `checked_at`, DROP COLUMN `checks`");
    }
};
