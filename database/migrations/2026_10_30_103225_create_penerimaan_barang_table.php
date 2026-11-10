<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu kedatangan barang dari supplier untuk satu PO. Barang boleh datang
 * bertahap (pesan 5, datang 1 lalu 2 lalu 2) — tiap kedatangan satu baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penerimaan_barang', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesanan_pembelian_id')->constrained('pesanan_pembelian')->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('nomor_surat_jalan', 60)->nullable();
            $table->string('foto_path')->nullable();
            $table->string('foto_nama')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('input_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penerimaan_barang');
    }
};
