<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE spr MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled', 'akad') NOT NULL DEFAULT 'submitted'");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("UPDATE spr SET status = 'approved' WHERE status = 'akad'");
            DB::statement("ALTER TABLE spr MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'submitted'");
        }
    }
};
