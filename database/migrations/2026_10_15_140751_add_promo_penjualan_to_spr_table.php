<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo yang didapat sebuah SPR, beserta potongannya.
 *
 * Nominal potongan disalin ke SPR, tidak dibaca ulang dari master: kalau
 * promonya diubah belakangan, SPR yang sudah mendapatkannya tidak ikut bergeser.
 *
 * `utj_nominal` dan `um_net` sengaja tidak disentuh — itu angka di dokumen yang
 * ditandatangani. Yang dibayar konsumen dihitung: angka itu dikurangi potongan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->foreignId('promo_penjualan_id')->nullable()->after('um_net')
                ->constrained('promo_penjualan')->nullOnDelete();
            $table->decimal('promo_potongan_utj', 15, 2)->default(0)->after('promo_penjualan_id');
            $table->decimal('promo_potongan_um', 15, 2)->default(0)->after('promo_potongan_utj');
            // Terisi untuk promo yang dilampirkan susulan atau dilepas.
            $table->string('promo_alasan', 255)->nullable()->after('promo_potongan_um');
            $table->foreignId('promo_oleh_user_id')->nullable()->after('promo_alasan')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('promo_at')->nullable()->after('promo_oleh_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_oleh_user_id');
            $table->dropConstrainedForeignId('promo_penjualan_id');
            $table->dropColumn(['promo_potongan_utj', 'promo_potongan_um', 'promo_alasan', 'promo_at']);
        });
    }
};
