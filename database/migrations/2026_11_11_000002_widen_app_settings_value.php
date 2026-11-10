<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // the live trading config is saved as JSON in one setting: room for it
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `app_settings` MODIFY `setting_value` TEXT NULL");
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE `app_settings` MODIFY `setting_value` VARCHAR(255) NULL");
    }
};
