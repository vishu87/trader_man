<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE TABLE `cities` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(150) NOT NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB;
        ");

        DB::unprepared("
            CREATE TABLE `stores` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `store_name` VARCHAR(150) NOT NULL,
              `city_id` INT NOT NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              INDEX (`city_id`)
            ) ENGINE=InnoDB;
        ");

        DB::unprepared("
            CREATE TABLE `time_slots` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `slot_name` VARCHAR(150) NOT NULL,
              `start_time` TIME NULL DEFAULT NULL,
              `end_time` TIME NULL DEFAULT NULL,
              `max_capacity` INT NOT NULL DEFAULT 0,
              `store_id` INT NOT NULL,
              `created_at` TIMESTAMP NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              INDEX (`store_id`)
            ) ENGINE=InnoDB;
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `time_slots`');
        DB::unprepared('DROP TABLE IF EXISTS `stores`');
        DB::unprepared('DROP TABLE IF EXISTS `cities`');
    }
};
