<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `paper_trades` ADD `entry_type` VARCHAR(5) NOT NULL DEFAULT 'limit' AFTER `symbol`");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `paper_trades` DROP COLUMN `entry_type`");
    }
};
