<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE subjects CHANGE semester term ENUM('1st', '2nd', '3rd') NOT NULL");
        DB::statement("ALTER TABLE classrooms CHANGE semester term ENUM('1st', '2nd', '3rd') NOT NULL");
        DB::statement("ALTER TABLE final_grades CHANGE semester term ENUM('1st', '2nd', '3rd') NOT NULL");
        DB::statement("ALTER TABLE system_settings CHANGE semester term ENUM('1st', '2nd', '3rd') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE subjects CHANGE term semester ENUM('1st', '2nd') NOT NULL");
        DB::statement("ALTER TABLE classrooms CHANGE term semester ENUM('1st', '2nd') NOT NULL");
        DB::statement("ALTER TABLE final_grades CHANGE term semester ENUM('1st', '2nd') NOT NULL");
        DB::statement("ALTER TABLE system_settings CHANGE term semester ENUM('1st', '2nd') NOT NULL");
    }
};