<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan pecah sertifikat induk jadi sertifikat per unit.
 *
 * Satu baris di sini mewakili satu berkas pengajuan ke BPN, isinya bisa
 * belasan unit. Diajukan per kavling (pilih unit satu-satu) atau per blok
 * (seluruh unit blok tersebut yang belum punya sertifikat).
 *
 * Yang terjadi sesudahnya ada di luar sistem — BPN memprosesnya, dan hasilnya
 * dicatat belakangan di tabel `sertifikat`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sertifikat_pengajuan', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();

            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->string('nomor', 60)->unique();
            $table->date('tanggal_pengajuan');

            // Dicatat supaya laporan bisa membedakan pengajuan kolektif se-blok
            // dari pengajuan satuan — dua-duanya sah, tapi beda perlakuannya
            // waktu menagih ke BPN.
            $table->enum('metode', ['blok', 'kavling'])->default('kavling');
            $table->string('blok', 20)->nullable();

            $table->string('catatan', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['proyek_id', 'tanggal_pengajuan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sertifikat_pengajuan');
    }
};
