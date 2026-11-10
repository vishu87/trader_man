<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ignored signals (repeats of a signal we already have, or of a stock we hold) are a separate field, so the main list stays small:
        // ChartInk fires the same stock again every 5 minutes while the scan still matches it.
        DB::unprepared("
            ALTER TABLE `live_signals`
              ADD COLUMN `ignored` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
              ADD COLUMN `repeat_of` INT NULL DEFAULT NULL AFTER `ignored`,
              ADD INDEX `idx_live_signals_ignored` (`ignored`, `id`)
        ");

        // signals already marked ignored by the engine
        DB::unprepared("UPDATE `live_signals` SET `ignored` = 1 WHERE `status` = 'ignored'");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `live_signals` DROP INDEX `idx_live_signals_ignored`, DROP COLUMN `repeat_of`, DROP COLUMN `ignored`");
    }
};
