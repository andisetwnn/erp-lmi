<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen legalitas subcon — NPWP, SIUP, SBU, SIUJK, PKP, SKA.
 *
 * Keenamnya berpola sama: nomor, masa berlaku, berkas. Jadi satu tabel dengan kolom
 * jenis, bukan delapan belas kolom di tabel subcon yang mayoritas kosong.
 *
 * Masa berlaku dipantau: di sistem lama, SIUP yang lewat tanggal ditandai merah di
 * daftar. NPWP tidak punya masa berlaku, jadi kolomnya boleh kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcon_dokumen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcon_id')->constrained('subcon')->cascadeOnDelete();

            $table->enum('jenis', ['npwp', 'siup', 'sbu', 'siujk', 'pkp', 'ska']);
            $table->string('nomor');
            $table->date('berlaku_sampai')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_original_name')->nullable();
            $table->text('catatan')->nullable();

            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu subcon punya paling banyak satu dokumen tiap jenis.
            $table->unique(['subcon_id', 'jenis']);
            $table->index('berlaku_sampai');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcon_dokumen');
    }
};
