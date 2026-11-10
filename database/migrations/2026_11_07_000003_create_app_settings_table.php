<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE TABLE `app_settings` (
              `setting_key` VARCHAR(100) NOT NULL,
              `setting_value` VARCHAR(255) NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`setting_key`)
            ) ENGINE=InnoDB;
        ");

        // Risk taken on a single trade — drives the watchlist position sizing
        DB::unprepared("INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('max_loss_per_trade', '5000', NOW())");
    }

    public function down(): void
    {
        DB::unprepared("DROP TABLE IF EXISTS `app_settings`");
    }
};
