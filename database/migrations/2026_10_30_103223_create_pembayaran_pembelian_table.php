<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran Keuangan ke supplier per PO — dicatat sebelum barang dikirim.
 * Boleh lebih dari sekali (uang muka / bertahap). Belum membuat jurnal:
 * Keuangan menjurnal manual saat uang keluar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembayaran_pembelian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesanan_pembelian_id')->constrained('pesanan_pembelian')->cascadeOnDelete();
            $table->date('tanggal');
            $table->decimal('jumlah', 15, 2);
            $table->foreignId('rekening_coa_id')->nullable()->constrained('coa')->nullOnDelete(); // kas/bank sumber dana
            $table->string('bukti_path')->nullable();
            $table->string('bukti_nama')->nullable();
            $table->text('keterangan')->nullable();
            $table->foreignId('input_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran_pembelian');
    }
};
