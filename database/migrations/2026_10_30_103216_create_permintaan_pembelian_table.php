<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan Pembelian (PR) — diajukan orang proyek atau kantor pusat.
 *
 * Alur (konfirmasi FAT 30 Okt 2026, matrix approval 3 Nov 2026): diajukan →
 * ditinjau FAT → [direktur, bila di atas perusahaan.batas_persetujuan_direktur]
 * → PO. Dikembalikan = kembali ke draft dengan alasan.
 *
 * status:
 *   draft     — masih disusun peminta (juga setelah dikembalikan)
 *   diajukan  — menunggu review Keuangan (FAT)
 *   ditinjau  — sudah disetujui FAT, nilainya di atas batas → menunggu direktur
 *   disetujui — PO boleh diterbitkan (disetujui_* kosong = cukup FAT)
 *   ditolak   — ditolak FAT atau direktur (final)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permintaan_pembelian', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 60)->unique();
            $table->date('tanggal');
            $table->foreignId('proyek_id')->nullable()->constrained('proyek')->nullOnDelete(); // null = kantor pusat
            $table->string('departemen', 30);
            $table->text('keperluan');
            $table->date('tanggal_dibutuhkan')->nullable();
            $table->enum('status', ['draft', 'diajukan', 'ditinjau', 'disetujui', 'ditolak'])->default('draft');
            $table->text('alasan')->nullable(); // alasan dikembalikan / ditolak terakhir
            $table->string('lampiran_path')->nullable();
            $table->string('lampiran_nama')->nullable();

            $table->timestamp('diajukan_at')->nullable();
            $table->foreignId('diajukan_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ditinjau_at')->nullable();
            $table->foreignId('ditinjau_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disetujui_at')->nullable();
            $table->foreignId('disetujui_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ditolak_at')->nullable();
            $table->foreignId('ditolak_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_pembelian');
    }
};
