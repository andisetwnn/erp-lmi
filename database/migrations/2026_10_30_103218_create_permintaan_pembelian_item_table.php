<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang yang diminta dalam satu PR. Nama barang diketik bebas (belum ada
 * daftar barang baku di versi pertama).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permintaan_pembelian_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permintaan_pembelian_id')->constrained('permintaan_pembelian')->cascadeOnDelete();
            $table->string('nama_barang');
            $table->string('spesifikasi')->nullable();
            $table->decimal('jumlah', 12, 2);
            $table->string('satuan', 20);
            $table->decimal('harga_perkiraan', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_pembelian_item');
    }
};
