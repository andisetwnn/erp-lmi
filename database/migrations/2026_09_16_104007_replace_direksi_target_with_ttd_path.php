<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perubahan alur Persetujuan Direksi:
 *
 * - Sebelumnya: link dikirim ke user Direksi spesifik, TTD-nya diambil dari
 *   file yang sudah di-upload di profil user.
 * - Sekarang: nama "Haryanto / Julianto Boentaran" hardcoded di lembar Aju
 *   Dana (mereka bergantian pakai sesuai siapa yang hadir akad), dan link
 *   universal — siapa pun dari mereka yang menerima link akan menggambar TTD
 *   di layar. Tidak perlu buat akun user untuk mereka.
 *
 * Yang berubah:
 * - Drop kolom `direksi_target_user_id` (tidak dipakai lagi).
 * - Tambah kolom `direksi_ttd_path` untuk menyimpan path file PNG TTD yang
 *   digambar di halaman preview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rencana_akad', function (Blueprint $table) {
            if (Schema::hasColumn('rencana_akad', 'direksi_target_user_id')) {
                $table->dropConstrainedForeignId('direksi_target_user_id');
            }
            if (! Schema::hasColumn('rencana_akad', 'direksi_ttd_path')) {
                $table->string('direksi_ttd_path', 255)
                    ->nullable()
                    ->after('direksi_link_generated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rencana_akad', function (Blueprint $table) {
            if (Schema::hasColumn('rencana_akad', 'direksi_ttd_path')) {
                $table->dropColumn('direksi_ttd_path');
            }
            if (! Schema::hasColumn('rencana_akad', 'direksi_target_user_id')) {
                $table->foreignId('direksi_target_user_id')
                    ->nullable()
                    ->after('direksi_link_generated_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }
};
