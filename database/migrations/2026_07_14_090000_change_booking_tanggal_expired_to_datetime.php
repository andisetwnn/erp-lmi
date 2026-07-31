<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change tanggal_expired dari DATE ke DATETIME supaya bisa store exact 24-hour deadline.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE booking MODIFY COLUMN tanggal_expired DATETIME NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE booking MODIFY COLUMN tanggal_expired DATE NULL');
        }
    }
};
