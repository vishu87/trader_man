<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // price at the moment the scanner fired (ChartInk's trigger price), when the feed gives it
        DB::unprepared("ALTER TABLE `scanner_signals` ADD `trigger_price` DECIMAL(12,2) NULL DEFAULT NULL AFTER `sector`");
        // where an import came from: 'file' (an upload) or 'feed' (read from the futures app's alert feed)
        DB::unprepared("ALTER TABLE `scanner_imports` ADD `source` VARCHAR(6) NOT NULL DEFAULT 'file' AFTER `file_name`");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `scanner_imports` DROP COLUMN `source`");
        DB::unprepared("ALTER TABLE `scanner_signals` DROP COLUMN `trigger_price`");
    }
};
