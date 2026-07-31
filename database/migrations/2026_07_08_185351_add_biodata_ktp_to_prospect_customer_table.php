<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospect_customer', function (Blueprint $table) {
            $table->string('tempat_lahir', 100)->nullable()->after('nik');
            $table->date('tanggal_lahir')->nullable()->after('tempat_lahir');
            // 'L' = Laki-laki, 'P' = Perempuan
            $table->enum('jenis_kelamin', ['L', 'P'])->nullable()->after('tanggal_lahir');
            $table->string('agama', 20)->nullable()->after('jenis_kelamin');
            $table->string('status_perkawinan', 20)->nullable()->after('agama');
            // Pekerjaan dari KTP — kadang beda sama nama perusahaan/pekerjaan aktual
            $table->string('pekerjaan_ktp', 100)->nullable()->after('status_perkawinan');

            // RT/RW dari KTP — belum ada di tabel
            $table->string('rt_rw', 10)->nullable()->after('kelurahan_nama');
        });
    }

    public function down(): void
    {
        Schema::table('prospect_customer', function (Blueprint $table) {
            $table->dropColumn([
                'tempat_lahir',
                'tanggal_lahir',
                'jenis_kelamin',
                'agama',
                'status_perkawinan',
                'pekerjaan_ktp',
                'rt_rw',
            ]);
        });
    }
};
