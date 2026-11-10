<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A real trade: one stock, bought and sold in as many steps as you like. status active | archived.
        DB::unprepared("
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
        ");

        // The buys and sells you actually made
        DB::unprepared("
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
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `trade_actions`');
        DB::unprepared('DROP TABLE IF EXISTS `trades`');
    }
};
