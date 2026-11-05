<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `stores` ADD `address` VARCHAR(500) NULL DEFAULT NULL AFTER `city_id`");
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE `stores` DROP COLUMN `address`');
    }
};
