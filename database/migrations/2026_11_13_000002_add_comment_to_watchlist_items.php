<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // your own free-text note about a watchlist symbol (shown under the symbol, edited in place); NULL = no comment
        DB::unprepared("ALTER TABLE `watchlist_items` ADD COLUMN `comment` TEXT NULL DEFAULT NULL AFTER `rating`");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `watchlist_items` DROP COLUMN `comment`");
    }
};
