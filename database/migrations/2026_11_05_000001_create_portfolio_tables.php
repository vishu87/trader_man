<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE TABLE `portfolio_buckets` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              `description` VARCHAR(255) NULL DEFAULT NULL,
              `sort_order` INT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_portfolio_buckets_name` (`name`)
            ) ENGINE=InnoDB;
        ");

        DB::unprepared("
            CREATE TABLE `portfolio_holdings` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `bucket_id` INT NOT NULL,
              `symbol` VARCHAR(50) NOT NULL,
              `zerodha_symbol` VARCHAR(50) NOT NULL,
              `exchange` VARCHAR(10) NOT NULL DEFAULT 'NSE',
              `quantity` INT NOT NULL DEFAULT 0,
              `avg_price` DECIMAL(14,4) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_portfolio_holdings_bucket_symbol` (`bucket_id`, `symbol`),
              INDEX (`zerodha_symbol`)
            ) ENGINE=InnoDB;
        ");

        // Rebuilt on every CSV upload
        DB::unprepared("
            CREATE TABLE `portfolio_not_mapped` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `symbol` VARCHAR(50) NOT NULL,
              `quantity` INT NOT NULL,
              `avg_price` DECIMAL(14,4) NOT NULL DEFAULT 0,
              `uploaded_qty` INT NOT NULL DEFAULT 0,
              `bucket_qty` INT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              INDEX (`symbol`)
            ) ENGINE=InnoDB;
        ");

        // Rebuilt on every CSV upload; rows are removed manually once rectified
        DB::unprepared("
            CREATE TABLE `portfolio_removals` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `symbol` VARCHAR(50) NOT NULL,
              `quantity` INT NOT NULL,
              `uploaded_qty` INT NOT NULL DEFAULT 0,
              `bucket_qty` INT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              INDEX (`symbol`)
            ) ENGINE=InnoDB;
        ");
    }

    public function down(): void
    {
        DB::unprepared("DROP TABLE IF EXISTS `portfolio_removals`");
        DB::unprepared("DROP TABLE IF EXISTS `portfolio_not_mapped`");
        DB::unprepared("DROP TABLE IF EXISTS `portfolio_holdings`");
        DB::unprepared("DROP TABLE IF EXISTS `portfolio_buckets`");
    }
};
