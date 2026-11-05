<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE `registrations` ADD `wa_send` TINYINT(1) NOT NULL DEFAULT 0 AFTER `terms_accepted`, ADD `wa_attempts` TINYINT NOT NULL DEFAULT 0 AFTER `wa_send`, ADD INDEX (`wa_send`)");

        // registrations made before this change must not be messaged now
        DB::unprepared("UPDATE `registrations` SET `wa_send` = 1");
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE `registrations` DROP COLUMN `wa_send`, DROP COLUMN `wa_attempts`');
    }
};
