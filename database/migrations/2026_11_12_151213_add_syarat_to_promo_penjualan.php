<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo bersyarat, misalnya KAPUR yang hanya untuk pengajar. Kalau promo punya syarat,
 * sales ditanya di form SPR apakah konsumennya memenuhi; jawabannya disimpan di SPR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_penjualan', function (Blueprint $table) {
            $table->string('syarat', 255)->nullable()->after('keterangan');
        });

        Schema::table('spr', function (Blueprint $table) {
            // Null = tidak ditanya (promonya tanpa syarat atau tidak ada promo).
            $table->boolean('promo_syarat_terpenuhi')->nullable()->after('promo_potongan_um');
        });
    }

    public function down(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->dropColumn('promo_syarat_terpenuhi');
        });

        Schema::table('promo_penjualan', function (Blueprint $table) {
            $table->dropColumn('syarat');
        });
    }
};
