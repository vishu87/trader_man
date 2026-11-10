<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `portfolio_holdings` ADD `follow` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_tracking`");
        DB::unprepared("ALTER TABLE `watchlist_items` ADD `follow` TINYINT(1) NOT NULL DEFAULT 0 AFTER `stop_loss`");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `portfolio_holdings` DROP COLUMN `follow`");
        DB::unprepared("ALTER TABLE `watchlist_items` DROP COLUMN `follow`");
    }
};
