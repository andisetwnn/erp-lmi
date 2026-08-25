<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah unique constraint di biaya_tambahan_realisasi.nomor_kuitansi.
 * Buku kuitansi biaya tambahan terpisah dari UM — tapi TETAP unik dalam bukunya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biaya_tambahan_realisasi', function (Blueprint $table) {
            $table->unique('nomor_kuitansi');
        });
    }

    public function down(): void
    {
        Schema::table('biaya_tambahan_realisasi', function (Blueprint $table) {
            $table->dropUnique(['nomor_kuitansi']);
        });
    }
};
