<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->string('nama_lengkap');
            $table->string('nik', 32);
            $table->string('npwp', 32)->nullable();
            $table->string('hp', 30);
            $table->foreignId('tempat_kerja_id')->nullable()->constrained('tempat_kerja')->nullOnDelete();
            $table->string('alamat_ktp', 500);
            $table->string('foto_ktp')->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('bank')->nullOnDelete();
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('rekening_atas_nama')->nullable();
            $table->string('nama_pasangan')->nullable();
            $table->string('nik_pasangan', 32)->nullable();
            $table->string('nama_perusahaan_bu')->nullable();
            $table->enum('bentuk_badan_usaha', ['pt', 'cv', 'yayasan', 'perorangan', 'lainnya'])->nullable();
            $table->string('alamat_perusahaan', 500)->nullable();
            $table->string('no_telp_kantor', 30)->nullable();
            $table->string('jenis_pekerjaan')->nullable();
            $table->string('bidang_usaha')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer');
    }
};
