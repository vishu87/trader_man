<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One row per import run: an uploaded tradebook file, or a sync from the Kite API
        DB::unprepared("
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
        ");

        // Every execution (fill) Zerodha reported. The same trade can arrive from a file and from the API: the key is
        // (exchange, trade_date, trade_id), so it is stored once.
        DB::unprepared("
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
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `zerodha_trades`');
        DB::unprepared('DROP TABLE IF EXISTS `zerodha_imports`');
    }
};
