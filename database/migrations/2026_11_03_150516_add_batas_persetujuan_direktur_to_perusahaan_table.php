<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matrix approval pembelian (arahan 3 Nov 2026): PR sampai nilai ini cukup
 * disetujui FAT; di atasnya wajib disetujui direktur juga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perusahaan', function (Blueprint $table) {
            $table->decimal('batas_persetujuan_direktur', 15, 2)->default(50000000)->after('no_telepon');
        });
    }

    public function down(): void
    {
        Schema::table('perusahaan', function (Blueprint $table) {
            $table->dropColumn('batas_persetujuan_direktur');
        });
    }
};
