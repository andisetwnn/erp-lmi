<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipe_rumah', function (Blueprint $table) {
            // Drop FK dulu sebelum drop kolom
            $table->dropForeign(['bank_kpr_default_id']);
        });

        Schema::table('tipe_rumah', function (Blueprint $table) {
            $table->dropColumn([
                'nilai_um',                // UM sekarang auto-compute = all_in - plafon_kpr
                'tenor_bulan',             // SPR gak pakai tenor bulan
                'bank_kpr_default_id',     // Bank pindah ke modul Admin KPR
                'harga_per_m2_kelebihan',  // Kelebihan Tanah dihapus dari flow SPR
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tipe_rumah', function (Blueprint $table) {
            $table->decimal('nilai_um', 15, 2)->default(0)->after('utj');
            $table->unsignedSmallInteger('tenor_bulan')->default(0)->after('sbum');
            $table->foreignId('bank_kpr_default_id')->nullable()->after('tenor_bulan')->constrained('bank')->nullOnDelete();
            $table->decimal('harga_per_m2_kelebihan', 15, 2)->default(0)->after('luas_bangunan');
        });
    }
};
