<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The scanner signals are historical data for backtesting only: no import bookkeeping.
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `scanner_signals` DROP INDEX `import_id`");
        DB::unprepared("ALTER TABLE `scanner_signals` DROP COLUMN `import_id`");
        DB::unprepared("DROP TABLE IF EXISTS `scanner_imports`");
    }

    public function down(): void
    {
        DB::unprepared("CREATE TABLE `scanner_imports` (
              `id` INT NOT NULL AUTO_INCREMENT, `file_name` VARCHAR(190) NULL DEFAULT NULL, `source` VARCHAR(6) NOT NULL DEFAULT 'file',
              `rows_read` INT NOT NULL DEFAULT 0, `added` INT NOT NULL DEFAULT 0, `duplicates` INT NOT NULL DEFAULT 0, `skipped` INT NOT NULL DEFAULT 0,
              `first_date` DATE NULL DEFAULT NULL, `last_date` DATE NULL DEFAULT NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB");
        DB::unprepared("ALTER TABLE `scanner_signals` ADD `import_id` INT NULL DEFAULT NULL AFTER `id`, ADD INDEX (`import_id`)");
    }
};
