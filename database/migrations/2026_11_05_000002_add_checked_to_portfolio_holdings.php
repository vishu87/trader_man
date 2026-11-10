<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `portfolio_holdings` ADD `checked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `avg_price`");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `portfolio_holdings` DROP COLUMN `checked`");
    }
};
