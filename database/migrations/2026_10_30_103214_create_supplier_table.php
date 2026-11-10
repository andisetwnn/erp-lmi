<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master supplier barang untuk Modul Pembelian.
 *
 * Terpisah dari subcon: subcon adalah kontraktor jasa (punya SBU, SKA, tarif PPh),
 * supplier menjual barang. Rekening ditaruh langsung di sini seperti Notaris —
 * pembayaran pembelian cukup ke satu rekening per supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('kontak_nama')->nullable();
            $table->string('hp', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('alamat')->nullable();
            $table->string('npwp', 30)->nullable();
            $table->boolean('is_pkp')->default(false); // PKP → PPN bisa dicantumkan terpisah di PO
            $table->foreignId('bank_id')->nullable()->constrained('bank')->nullOnDelete();
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('rekening_atas_nama')->nullable();
            $table->boolean('is_aktif')->default(true);
            $table->text('keterangan')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_aktif', 'nama']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier');
    }
};
