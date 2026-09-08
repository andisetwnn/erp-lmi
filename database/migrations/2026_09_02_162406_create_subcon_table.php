<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master subkontraktor.
 *
 * Yang dicatat adalah ORANGNYA; badan usaha menyusul kalau punya. Sebagian subcon
 * memang perorangan tanpa CV/PT — itu yang membedakan daftar Perorangan dan Badan Usaha.
 *
 * Dokumen (NPWP, SIUP, SBU, SIUJK, PKP, SKA) dan rekening bank ditaruh di tabel anak,
 * bukan puluhan kolom yang sebagian besar kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcon', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('badan_usaha')->nullable();

            $table->string('nik', 20)->nullable();
            $table->string('foto_ktp_path')->nullable();
            $table->text('alamat')->nullable();
            $table->string('telepon', 30)->nullable();

            $table->enum('jenis_jasa', [
                'pelaksana',          // Pelaksana konstruksi
                'perencana_pengawas', // Perencanaan dan pengawasan konstruksi
                'jasa',               // Jasa lain
                'notaris',
            ])->nullable();

            $table->enum('kualifikasi', ['kecil', 'menengah', 'besar', 'pribadi'])->nullable();

            /**
             * Tarif PPh final. Disarankan sistem dari jenis jasa + kualifikasi +
             * ada-tidaknya sertifikat, tapi boleh ditimpa — alasannya wajib diisi
             * supaya penyimpangan tarif pajak tidak terjadi diam-diam.
             */
            $table->decimal('pph_persen', 5, 2)->nullable();
            $table->text('pph_catatan')->nullable();

            /** Hanya diisi kalau subcon berstatus PKP. */
            $table->decimal('ppn_persen', 5, 2)->default(0);

            $table->boolean('is_aktif')->default(true);
            $table->text('catatan')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('nama');
            $table->index('is_aktif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcon');
    }
};
