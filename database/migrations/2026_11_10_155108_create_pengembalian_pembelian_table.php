<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uang yang dikembalikan supplier per PO — kelebihan bayar setelah PO ditutup
 * karena barangnya tidak terkirim semua. Boleh lebih dari sekali (dicicil),
 * tapi totalnya tidak melebihi kelebihan bayar. Belum membuat jurnal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengembalian_pembelian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesanan_pembelian_id')->constrained('pesanan_pembelian')->cascadeOnDelete();
            $table->date('tanggal');
            $table->decimal('jumlah', 15, 2);
            $table->foreignId('rekening_coa_id')->nullable()->constrained('coa')->nullOnDelete(); // kas/bank penerima
            $table->string('bukti_path')->nullable();
            $table->string('bukti_nama')->nullable();
            $table->text('keterangan')->nullable();
            $table->foreignId('input_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengembalian_pembelian');
    }
};
