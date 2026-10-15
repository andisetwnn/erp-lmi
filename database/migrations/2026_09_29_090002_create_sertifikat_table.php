<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sertifikat per unit rumah.
 *
 * Barisnya lahir saat pengajuan dibuat — nomor, NIB, dan HGB induk masih
 * kosong — lalu dilengkapi setelah sertifikatnya terbit. Karena itu tidak
 * perlu tabel baris pengajuan tersendiri.
 *
 * Statusnya tidak disimpan sebagai kolom. Ia terbaca dari data yang sudah
 * terisi: ada baris berarti diajukan, ada nomor berarti terbit, ada serah
 * terima berarti sudah di bank. Dengan begitu status dan data tidak mungkin
 * bertentangan.
 *
 * Satu unit satu sertifikat — dijaga kunci unik, bukan diimbau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sertifikat', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();

            $table->foreignId('rumah_id')->unique()->constrained('rumah')->cascadeOnDelete();
            $table->foreignId('sertifikat_pengajuan_id')->nullable()
                ->constrained('sertifikat_pengajuan')->nullOnDelete();

            // Luas yang diajukan dan luas yang akhirnya terbit sengaja dipisah.
            // Hasil ukur BPN kerap berbeda dari yang diajukan, dan yang dipakai
            // di dokumen adalah yang terbit.
            $table->decimal('luas_diajukan', 8, 2)->nullable();
            $table->decimal('luas_sertifikat', 8, 2)->nullable();

            // Sertifikat sudah elektronik, jadi yang dicatat cukup tiga ini.
            // Surat Ukur sengaja tidak dipakai lagi.
            $table->string('nomor_sertifikat', 60)->nullable();
            $table->string('nib', 40)->nullable();
            $table->string('hgb_induk', 60)->nullable();
            $table->date('tanggal_terbit')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_original_name')->nullable();

            $table->foreignId('sertifikat_serah_terima_id')->nullable()
                ->constrained('sertifikat_serah_terima')->nullOnDelete();

            $table->string('catatan', 500)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('nomor_sertifikat');
            $table->index('sertifikat_serah_terima_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sertifikat');
    }
};
