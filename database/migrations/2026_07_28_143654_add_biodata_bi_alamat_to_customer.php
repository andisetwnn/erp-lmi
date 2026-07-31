<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer', function (Blueprint $table) {
            // Biodata KTP (mengikuti struktur prospect_customer supaya sinkron)
            $table->string('tempat_lahir', 100)->nullable()->after('nik');
            $table->date('tanggal_lahir')->nullable()->after('tempat_lahir');
            $table->enum('jenis_kelamin', ['L', 'P'])->nullable()->after('tanggal_lahir');
            $table->string('agama', 20)->nullable()->after('jenis_kelamin');
            $table->string('status_perkawinan', 20)->nullable()->after('agama');

            // Kontak tambahan
            $table->string('hp_2', 30)->nullable()->after('hp');
            $table->string('sumber', 100)->nullable()->after('hp_2');

            // BI Checking
            $table->enum('bi_kol', ['1', '2', '3', '4', '5'])->nullable()->after('bidang_usaha');
            $table->decimal('bi_dbr', 5, 2)->nullable()->after('bi_kol');
            $table->text('bi_keterangan')->nullable()->after('bi_dbr');

            // Alamat terstruktur (kode wilayah + nama)
            $table->string('rt_rw', 20)->nullable()->after('alamat_ktp');
            $table->string('provinsi_code', 10)->nullable()->after('rt_rw');
            $table->string('provinsi_nama', 100)->nullable()->after('provinsi_code');
            $table->string('kota_code', 10)->nullable()->after('provinsi_nama');
            $table->string('kota_nama', 100)->nullable()->after('kota_code');
            $table->string('kecamatan_code', 10)->nullable()->after('kota_nama');
            $table->string('kecamatan_nama', 100)->nullable()->after('kecamatan_code');
            $table->string('kelurahan_code', 10)->nullable()->after('kecamatan_nama');
            $table->string('kelurahan_nama', 100)->nullable()->after('kelurahan_code');

            // Catatan bebas
            $table->text('catatan')->nullable()->after('kelurahan_nama');
        });
    }

    public function down(): void
    {
        Schema::table('customer', function (Blueprint $table) {
            $table->dropColumn([
                'tempat_lahir', 'tanggal_lahir', 'jenis_kelamin', 'agama', 'status_perkawinan',
                'hp_2', 'sumber',
                'bi_kol', 'bi_dbr', 'bi_keterangan',
                'rt_rw', 'provinsi_code', 'provinsi_nama', 'kota_code', 'kota_nama',
                'kecamatan_code', 'kecamatan_nama', 'kelurahan_code', 'kelurahan_nama',
                'catatan',
            ]);
        });
    }
};
