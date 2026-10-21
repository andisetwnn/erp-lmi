<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo Penjualan — dikelola Admin Sales, seluruhnya ditanggung developer.
 *
 * Angka di SPR tidak diubah oleh promo: UTJ dan UM tetap nominal normal, yang
 * ditampilkan adalah potongannya dan berapa yang benar-benar dibayar konsumen.
 *
 * Dua jenis:
 * - `um`          — potongan UM nominal tetap.
 * - `booking_fee` — booking fee ditentukan usia konsumen lewat tabel tingkat.
 *
 * Satu SPR paling banyak satu promo, dan masa berlaku promo dalam satu proyek
 * tidak boleh beririsan — jadi untuk satu tanggal UTJ selalu ada paling banyak
 * satu promo yang berlaku.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_penjualan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->string('nama', 150);
            // um = potongan UM; booking_fee_tetap = satu nominal booking fee untuk
            // semua konsumen; booking_fee = booking fee menurut tabel usia.
            $table->enum('jenis', ['um', 'booking_fee_tetap', 'booking_fee']);
            // Masa berlaku dicocokkan dengan tanggal UTJ.
            $table->date('berlaku_mulai');
            $table->date('berlaku_sampai');
            // Untuk jenis `um`.
            $table->decimal('potongan_um', 15, 2)->nullable();
            // Untuk jenis `booking_fee_tetap`: yang dibayar konsumen sebagai UTJ.
            $table->decimal('booking_fee_tetap', 15, 2)->nullable();
            // Untuk jenis `booking_fee`: tingkatnya dibaca dari usia atau tahun lahir.
            $table->enum('dasar_usia', ['usia', 'tahun_lahir'])->nullable();
            $table->boolean('semua_unit')->default(true);
            $table->string('keterangan', 255)->nullable();
            $table->boolean('is_aktif')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['proyek_id', 'berlaku_mulai', 'berlaku_sampai']);
        });

        Schema::create('promo_penjualan_rumah', function (Blueprint $table) {
            $table->foreignId('promo_penjualan_id')->constrained('promo_penjualan')->cascadeOnDelete();
            $table->foreignId('rumah_id')->constrained('rumah')->cascadeOnDelete();
            $table->primary(['promo_penjualan_id', 'rumah_id']);
        });

        // Tingkat booking fee, mis. usia 23–23 → 230.000, atau lahir 1997–2005 → 300.000.
        Schema::create('promo_penjualan_tingkat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_penjualan_id')->constrained('promo_penjualan')->cascadeOnDelete();
            $table->unsignedSmallInteger('dari');
            $table->unsignedSmallInteger('sampai');
            $table->decimal('booking_fee', 15, 2);
            $table->string('label', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_penjualan_tingkat');
        Schema::dropIfExists('promo_penjualan_rumah');
        Schema::dropIfExists('promo_penjualan');
    }
};
