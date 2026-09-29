<?php

namespace Database\Seeders;

use App\Models\Master\BankKpr;
use Illuminate\Database\Seeder;

class BankKprSeeder extends Seeder
{
    /**
     * Lima bank yang selama ini dipakai Admin KPR, dipindah dari konstanta di
     * dalam kode ke master yang bisa dikelola sendiri.
     *
     * Tarif BSY dan BCA sengaja dibiarkan kosong — belum ada angkanya dari
     * pihak yang tahu. Kosong berarti "belum diketahui"; diisi nol akan
     * terbaca sebagai "gratis" di lembar Aju Dana, dan itu keliru.
     */
    public function run(): void
    {
        // lpa_wajib: LPA selama ini hanya diurus untuk BTN, konvensional maupun
        // syariah. Dulu daftar itu ditulis berulang di dalam kode.
        $daftar = [
            ['kode' => 'CBN', 'nama' => 'BTN KC Cibinong', 'biaya_proses_akad' => 1_645_000, 'lpa_wajib' => true],
            ['kode' => 'BSY', 'nama' => 'BTN Syariah', 'biaya_proses_akad' => null, 'lpa_wajib' => true],
            ['kode' => 'BSN', 'nama' => 'BSN KCP Warung Jambu', 'biaya_proses_akad' => 1_862_500, 'lpa_wajib' => false],
            ['kode' => 'NBU', 'nama' => 'Bank Nobu', 'biaya_proses_akad' => 2_395_000, 'lpa_wajib' => false],
            ['kode' => 'BCA', 'nama' => 'Bank BCA', 'biaya_proses_akad' => null, 'lpa_wajib' => false],
        ];

        foreach ($daftar as $bank) {
            // Tarif yang sudah diubah orang tidak ditimpa ulang — seeder ini
            // hanya melengkapi, bukan mengembalikan ke angka awal.
            BankKpr::firstOrCreate(['kode' => $bank['kode']], $bank);
        }
    }
}
