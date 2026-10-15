<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyerahan fisik sertifikat ke bank, beserta BAST-nya.
 *
 * Satu penyerahan memuat banyak sertifikat tapi hanya satu dokumen BAST —
 * itu sebabnya ia tabel tersendiri, bukan kolom di tiap sertifikat. Kalau
 * disimpan per sertifikat, berkas yang sama tersalin puluhan kali dan
 * mengoreksi satu tanggal berarti menyunting puluhan baris.
 *
 * Dibuat lebih dulu dari tabel `sertifikat` karena dirujuk olehnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sertifikat_serah_terima', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();

            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->date('tanggal');

            // BAST disimpan di disk privat, sama seperti unggahan pemberkasan.
            $table->string('file_path')->nullable();
            $table->string('file_original_name')->nullable();

            $table->string('catatan', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['proyek_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sertifikat_serah_terima');
    }
};
