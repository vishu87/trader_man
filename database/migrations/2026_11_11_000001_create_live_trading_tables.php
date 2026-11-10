<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // every webhook call exactly as it arrived (ChartInk posts the scan's alert here)
        DB::unprepared("
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
        ");

        // one row per stock in an alert; what the trading engine did with it
        DB::unprepared("
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
        ");

        // a stock we hold (or are trying to buy / sell): buys and averages add to it, sells close it
        DB::unprepared("
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
        ");

        DB::unprepared("
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
        ");
    }

    public function down(): void
    {
        foreach (['live_orders', 'live_positions', 'live_signals', 'raw_data_chartink'] as $t) DB::unprepared("DROP TABLE IF EXISTS `{$t}`");
    }
};
