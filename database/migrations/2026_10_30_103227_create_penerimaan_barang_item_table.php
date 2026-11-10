<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jumlah yang diterima per barang PO dalam satu kedatangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penerimaan_barang_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('penerimaan_barang_id')->constrained('penerimaan_barang')->cascadeOnDelete();
            $table->foreignId('pesanan_pembelian_item_id')->constrained('pesanan_pembelian_item')->cascadeOnDelete();
            $table->decimal('jumlah', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penerimaan_barang_item');
    }
};
