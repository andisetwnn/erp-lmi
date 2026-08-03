<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom tanggal akad kredit di notaris. Diisi manual oleh Keuangan / Admin KPR
 * setelah proses akad selesai, atau di-import dari data legacy Excel.
 * Kalau status SPR='akad', tgl_akad wajib terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->date('tgl_akad')->nullable()->after('nilai_kpr');
        });
    }

    public function down(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->dropColumn('tgl_akad');
        });
    }
};
