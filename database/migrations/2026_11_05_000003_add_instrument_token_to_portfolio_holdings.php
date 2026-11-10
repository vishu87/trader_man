<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `portfolio_holdings` ADD `instrument_token` BIGINT NULL DEFAULT NULL AFTER `exchange`");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `portfolio_holdings` DROP COLUMN `instrument_token`");
    }
};
