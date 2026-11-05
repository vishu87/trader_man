<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE TABLE `registrations` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `full_name` VARCHAR(150) NOT NULL,
              `email` VARCHAR(190) NOT NULL,
              `mobile` VARCHAR(10) NOT NULL,
              `city_id` INT NOT NULL,
              `store_id` INT NOT NULL,
              `time_slot_id` INT NOT NULL,
              `slot_date` DATE NOT NULL,
              `terms_accepted` TINYINT(1) NOT NULL DEFAULT 0,
              `ip_address` VARCHAR(45) NULL DEFAULT NULL,
              `user_agent` VARCHAR(500) NULL DEFAULT NULL,
              `created_at` TIMESTAMP NULL DEFAULT NULL,
              `updated_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `registrations_mobile_unique` (`mobile`),
              INDEX (`city_id`),
              INDEX (`store_id`),
              INDEX (`time_slot_id`, `slot_date`)
            ) ENGINE=InnoDB;
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `registrations`');
    }
};
