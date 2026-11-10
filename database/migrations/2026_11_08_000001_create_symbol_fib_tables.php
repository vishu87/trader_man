<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One Fibonacci swing per row: the low and the high it was drawn from. A symbol can have several.
        DB::unprepared("
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
        ");

        // The computed levels of each swing, one row per ratio, so other features can query them by price or ratio
        DB::unprepared("
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
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `symbol_fib_levels`');
        DB::unprepared('DROP TABLE IF EXISTS `symbol_fibs`');
    }
};
