<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lepaskan bank_kode dari enum.
 *
 * Daftar bank KPR sekarang dikelola di master `bank_kpr`. Selama kolom ini
 * masih enum, menambah bank lewat master tetap tidak bisa dipakai: MySQL
 * menolak nilai di luar daftar enum, dan penolakannya baru muncul saat Admin
 * KPR menyimpan — bukan saat banknya dibuat.
 *
 * Kolomnya dilebarkan jadi teks; daftar yang sah sekarang ditegakkan lewat
 * validasi terhadap master, bukan lewat bentuk kolom.
 *
 * SQLite tidak mengenal enum dan menyimpannya sebagai teks sejak awal, jadi
 * perubahan ini khusus MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE spr_pemberkasan MODIFY COLUMN bank_kode VARCHAR(10) NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Nilai di luar daftar lama dikosongkan dulu, kalau tidak MySQL menolak
        // penyempitan kolomnya.
        $lama = ['CBN', 'BSY', 'BSN', 'NBU', 'BCA'];

        DB::table('spr_pemberkasan')->whereNotNull('bank_kode')
            ->whereNotIn('bank_kode', $lama)
            ->update(['bank_kode' => null]);

        $daftar = "'".implode("','", $lama)."'";

        DB::statement("ALTER TABLE spr_pemberkasan MODIFY COLUMN bank_kode ENUM($daftar) NULL");
    }
};
