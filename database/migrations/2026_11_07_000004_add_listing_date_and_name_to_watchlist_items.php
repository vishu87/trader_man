<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `watchlist_items` ADD `listing_date` DATE NULL DEFAULT NULL AFTER `stop_loss`, ADD `name` VARCHAR(150) NULL DEFAULT NULL AFTER `symbol`");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `watchlist_items` DROP COLUMN `listing_date`, DROP COLUMN `name`");
    }
};
