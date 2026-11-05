<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Email is no longer collected on the public form, so it can't stay required
        DB::unprepared("ALTER TABLE `registrations` MODIFY `email` VARCHAR(190) NULL DEFAULT NULL");
        DB::unprepared("ALTER TABLE `registrations` ADD `wears_glasses` TINYINT(1) NULL DEFAULT NULL AFTER `mobile`");
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE `registrations` DROP COLUMN `wears_glasses`');
    }
};
