<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu kali unggah berkas Matrix = satu baris di sini.
 *
 * Matrix masih disusun manual di luar sistem dan diperbarui tiap minggu. Selama
 * itu berjalan, laporannya dibaca dari berkas yang diunggah, bukan dari data
 * transaksi — jadi tiap unggahan perlu jejaknya sendiri supaya jelas laporan
 * yang sedang dilihat berasal dari berkas kapan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matrix_import', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('nama_file');
            $table->string('proyek')->nullable();
            $table->string('minggu_ke', 60)->nullable();
            $table->string('periode', 60)->nullable();
            $table->unsignedInteger('jumlah_baris')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matrix_import');
    }
};
