<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order (PO) — diterbitkan Keuangan dari PR yang anggarannya disetujui.
 * Satu PR boleh dipecah ke beberapa supplier, jadi satu PR bisa punya banyak PO.
 *
 * Supplier dibayar dulu, baru barang dikirim (konfirmasi FAT). Status bayar dan
 * status barang dihitung dari tabel pembayaran & penerimaan; kolom status di sini
 * hanya siklus hidup dokumennya:
 *   terbit  — berjalan
 *   selesai — semua barang sudah diterima (otomatis)
 *   batal   — dibatalkan sebelum ada barang diterima
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesanan_pembelian', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 60)->unique();
            $table->date('tanggal');
            $table->foreignId('permintaan_pembelian_id')->constrained('permintaan_pembelian')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('supplier')->restrictOnDelete();
            $table->date('tanggal_kirim')->nullable();
            $table->string('syarat_bayar')->nullable();
            $table->boolean('ppn_terpisah')->default(false); // false = harga sudah termasuk PPN / non-PKP
            $table->decimal('ppn_persen', 5, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('ppn', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->enum('status', ['terbit', 'selesai', 'batal'])->default('terbit');
            $table->text('catatan')->nullable();
            $table->text('alasan_batal')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesanan_pembelian');
    }
};
