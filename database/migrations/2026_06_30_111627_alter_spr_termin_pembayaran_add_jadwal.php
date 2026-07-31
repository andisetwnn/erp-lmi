<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spr_termin_pembayaran', function (Blueprint $table) {
            $table->date('tanggal_jadwal')->nullable()->after('urutan');
            $table->decimal('jumlah_jadwal', 15, 2)->default(0)->after('tanggal_jadwal');
        });

        // Data historic (imported) sudah berisi realisasi langsung — copy ke jadwal
        // agar tampilan tidak kosong saat detail dibuka.
        DB::statement('UPDATE spr_termin_pembayaran SET tanggal_jadwal = tanggal_realisasi, jumlah_jadwal = jumlah');
    }

    public function down(): void
    {
        Schema::table('spr_termin_pembayaran', function (Blueprint $table) {
            $table->dropColumn(['tanggal_jadwal', 'jumlah_jadwal']);
        });
    }
};
