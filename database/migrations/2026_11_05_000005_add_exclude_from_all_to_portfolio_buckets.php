<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `portfolio_buckets` ADD `exclude_from_all` TINYINT(1) NOT NULL DEFAULT 0 AFTER `description`");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `portfolio_buckets` DROP COLUMN `exclude_from_all`");
    }
};
