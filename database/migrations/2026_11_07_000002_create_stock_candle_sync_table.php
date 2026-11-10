<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
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
        ");
    }

    public function down(): void
    {
        DB::unprepared("DROP TABLE IF EXISTS `stock_candle_sync`");
    }
};
