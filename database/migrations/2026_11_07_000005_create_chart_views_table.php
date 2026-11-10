<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE TABLE `chart_views` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `exchange` VARCHAR(10) NOT NULL,
              `symbol` VARCHAR(50) NOT NULL,
              `last_viewed_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_chart_views` (`exchange`, `symbol`)
            ) ENGINE=InnoDB;
        ");
    }

    public function down(): void
    {
        DB::unprepared("DROP TABLE IF EXISTS `chart_views`");
    }
};
