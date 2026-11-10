<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // your own star rating of a watchlist symbol, 1 (weak) to 5 (best); NULL = not rated
        DB::unprepared("ALTER TABLE `watchlist_items` ADD COLUMN `rating` TINYINT NULL DEFAULT NULL AFTER `follow`");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `watchlist_items` DROP COLUMN `rating`");
    }
};
