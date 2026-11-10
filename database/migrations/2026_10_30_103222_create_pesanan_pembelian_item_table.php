<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang dalam satu PO, dengan harga final dari supplier.
 *
 * jumlah_diterima dijaga oleh PembelianService setiap barang datang (dalam
 * transaksi yang sama), supaya sisa barang per PO bisa dibaca tanpa menjumlah
 * ulang riwayat penerimaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesanan_pembelian_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesanan_pembelian_id')->constrained('pesanan_pembelian')->cascadeOnDelete();
            $table->foreignId('permintaan_pembelian_item_id')->nullable()->constrained('permintaan_pembelian_item')->nullOnDelete();
            $table->string('nama_barang');
            $table->string('spesifikasi')->nullable();
            $table->decimal('jumlah', 12, 2);
            $table->string('satuan', 20);
            $table->decimal('harga', 15, 2)->default(0);
            $table->decimal('jumlah_diterima', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesanan_pembelian_item');
    }
};
