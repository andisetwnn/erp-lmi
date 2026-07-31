<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyek', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('logo')->nullable();
            $table->string('siteplan')->nullable();
            $table->string('nama_proyek');
            $table->string('nama_perumahan');
            $table->string('desa');
            $table->string('kelurahan');
            $table->string('kecamatan');
            $table->string('kota_kabupaten');
            $table->string('kode_surat', 20)->unique();
            $table->string('kode_akuntansi', 30);
            $table->string('kode_virtual_account', 30);
            $table->text('keterangan')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyek');
    }
};
