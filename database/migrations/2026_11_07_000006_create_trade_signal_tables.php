<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Claude analysis of one stock
        DB::unprepared("
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
        ");

        // Simulated long trade for every valid BUY signal — no real order is ever placed from this table
        DB::unprepared("
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
        ");
    }

    public function down(): void
    {
        DB::unprepared("DROP TABLE IF EXISTS `paper_trades`");
        DB::unprepared("DROP TABLE IF EXISTS `trade_signals`");
    }
};
