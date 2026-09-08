<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening bank subcon. Bisa lebih dari satu — di sistem lama tombolnya "Lihat"
 * atau "+ Tambah", bukan satu isian tetap.
 *
 * Salah satunya ditandai utama supaya pembayaran punya tujuan bawaan yang jelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcon_rekening', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcon_id')->constrained('subcon')->cascadeOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained('bank')->nullOnDelete();

            /** Dipakai kalau banknya tidak ada di master bank. */
            $table->string('bank_nama')->nullable();

            $table->string('nomor_rekening', 50);
            $table->string('atas_nama');
            $table->boolean('is_utama')->default(false);

            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('subcon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcon_rekening');
    }
};
