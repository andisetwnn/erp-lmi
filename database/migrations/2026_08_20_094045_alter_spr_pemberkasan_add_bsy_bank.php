<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambah kode bank BSY (BTN Syariah) ke enum spr_pemberkasan.bank_kode.
 * Ditemukan di Excel sumber baru (DATA MASTER GRHA ARYANA — sheet SOP): 19 record "KPR BTN SYARIAH".
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ubahEnum(['CBN', 'BSN', 'NBU', 'BCA', 'BSY']);
    }

    public function down(): void
    {
        // Hati-hati: down akan gagal kalau ada row bank_kode='BSY'. Set null dulu manual.
        $this->ubahEnum(['CBN', 'BSN', 'NBU', 'BCA']);
    }

    /**
     * MODIFY COLUMN hanya dikenal MySQL/MariaDB. SQLite (dipakai test suite) tidak punya
     * tipe ENUM sama sekali — kolomnya sudah berupa varchar di sana, jadi tidak perlu diubah.
     *
     * @param  array<int, string>  $nilai
     */
    private function ubahEnum(array $nilai): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $daftar = implode(',', array_map(fn (string $v) => "'$v'", $nilai));
        DB::statement("ALTER TABLE spr_pemberkasan MODIFY COLUMN bank_kode ENUM($daftar) NULL");
    }
};
