<?php

namespace Database\Seeders;

use App\Models\Master\Bank;
use App\Models\Master\Notaris;
use App\Models\Master\Proyek;
use App\Models\Master\RencanaAkad;
use App\Models\Master\RencanaAkadBiayaSesi;
use App\Models\Master\Subcon;
use App\Models\User;
use App\Services\RencanaAkadService;
use Illuminate\Database\Seeder;

/**
 * Rencana Akad contoh — meniru lembar Aju Dana Grha Aryana type ARJUNA (30/60)
 * tanggal 27 Agustus 2026 di BTN KC Cibinong dengan Notaris Zuwanna Corna
 * Gumanti S.H., M.Kn. Berisi 11 unit.
 *
 * Angka biaya per unit (By Proses Akad 1.645.000, AJB Notaris 185.000, PPh 4(2)
 * 1.850.000) dan biaya sesi (Dana Koordinasi 225.000, Sewa Kursi 176.000, Snack
 * 176.000, Kebersihan 100.000) diambil apa adanya dari lembar itu, supaya kotak
 * "Biaya Proses" di layar bisa dicocokkan dengan kertasnya (jumlah 39.122.000).
 *
 * TIDAK dipanggil DatabaseSeeder — jalankan manual:
 *   php artisan db:seed --class=RencanaAkadContohSeeder
 */
class RencanaAkadContohSeeder extends Seeder
{
    /** @var array<int, array{sales:string, blk:string, no:string, tgl:string, lot:string, subcon:string, progres:int, hgb:string, um_masuk:float}> */
    private const UNIT_CONTOH = [
        ['sales' => 'ARIFIN',  'blk' => 'CB', 'no' => '15', 'tgl' => '2026-03-11', 'lot' => '3', 'subcon' => 'WIN', 'progres' => 81, 'hgb' => '000144369', 'um_masuk' => 15_000_000],
        ['sales' => 'ANA',     'blk' => 'CB', 'no' => '18', 'tgl' => '2026-03-25', 'lot' => '3', 'subcon' => 'WIN', 'progres' => 81, 'hgb' => '000144372', 'um_masuk' => 15_000_000],
        ['sales' => 'RIDHO',   'blk' => 'CB', 'no' => '19', 'tgl' => '2026-03-27', 'lot' => '3', 'subcon' => 'WIN', 'progres' => 81, 'hgb' => '000144373', 'um_masuk' => 15_000_000],
        ['sales' => 'HENDRA',  'blk' => 'CC', 'no' => '2',  'tgl' => '2026-03-23', 'lot' => '3', 'subcon' => 'EK',  'progres' => 82, 'hgb' => '000144375', 'um_masuk' => 15_000_000],
        ['sales' => 'ARIFIN',  'blk' => 'CC', 'no' => '3',  'tgl' => '2026-05-14', 'lot' => '3', 'subcon' => 'EK',  'progres' => 86, 'hgb' => '000144376', 'um_masuk' => 15_000_000],
        ['sales' => 'RAMDAN',  'blk' => 'CC', 'no' => '16', 'tgl' => '2026-04-23', 'lot' => '3', 'subcon' => 'EK',  'progres' => 86, 'hgb' => '000144382', 'um_masuk' => 15_000_000],
        // HENDRO — UM baru masuk 3.5jt, kekurangan 11.5jt jadi Target Masuk UM
        ['sales' => 'ANA',     'blk' => 'CB', 'no' => '17', 'tgl' => '2026-03-24', 'lot' => '3', 'subcon' => 'WIN', 'progres' => 81, 'hgb' => '000144371', 'um_masuk' => 3_500_000],
        ['sales' => 'ANA',     'blk' => 'CC', 'no' => '20', 'tgl' => '2026-04-11', 'lot' => '3', 'subcon' => 'EK',  'progres' => 86, 'hgb' => '000144386', 'um_masuk' => 15_000_000],
        ['sales' => 'ANA',     'blk' => 'CC', 'no' => '21', 'tgl' => '2026-04-12', 'lot' => '3', 'subcon' => 'EK',  'progres' => 86, 'hgb' => '000144387', 'um_masuk' => 15_000_000],
        ['sales' => 'HENDRA',  'blk' => 'CD', 'no' => '3',  'tgl' => '2026-04-13', 'lot' => '3', 'subcon' => 'EK',  'progres' => 86, 'hgb' => '000144391', 'um_masuk' => 15_000_000],
        // RANI — harga jual naik jadi 207jt (kelebihan tanah), UM 24jt
        ['sales' => 'ANA',     'blk' => 'CC', 'no' => '7',  'tgl' => '2026-07-20', 'lot' => '3', 'subcon' => 'EK',  'progres' => 82, 'hgb' => '000144380', 'um_masuk' => 24_000_000],
    ];

    public function run(): void
    {
        $proyek = Proyek::where('kode_surat', 'GA')->first() ?? Proyek::first();

        if (! $proyek) {
            $this->command?->warn('Master proyek kosong — tidak ada yang bisa dijadwalkan.');

            return;
        }

        $svc = app(RencanaAkadService::class);
        $penyusun = User::whereHas('roles', fn ($q) => $q->where('name', 'admin-kpr'))->first()
            ?? User::first();

        $notaris = $this->notaris();
        $bank = $this->bank();

        $kandidat = $svc->unitSiap($proyek->id)->take(count(self::UNIT_CONTOH));

        if ($kandidat->count() < count(self::UNIT_CONTOH)) {
            $this->command?->warn('Unit siap kurang dari '.count(self::UNIT_CONTOH).' — cuma dapat '.$kandidat->count().'. Rencana tetap dibuat dengan sisa unit.');
        }

        // Tanggal akad: Kamis, 27 Agustus 2026 jam 10:00 WIB (persis lembar aslinya)
        $tanggal = '2026-08-27';

        $rencana = RencanaAkad::create([
            'proyek_id' => $proyek->id,
            'nomor' => $svc->nomorBerikutnya($proyek, $tanggal),
            'tanggal_rencana' => $tanggal,
            'bank_id' => $bank?->id,
            'notaris_id' => $notaris->id,
            'created_by_user_id' => $penyusun?->id,
            'catatan' => 'Contoh dari lembar Aju Dana 27 Agustus 2026 (Grha Aryana type ARJUNA di BTN KC Cibinong).',
        ]);

        // Biaya sesi (bukan per unit) — nama & nominalnya bebas.
        // Nominal ini persis dari lembar Aju Dana asli.
        foreach ([
            ['nama' => 'Dana Koordinasi', 'nominal' => 225_000],
            ['nama' => 'Sewa Kursi', 'nominal' => 176_000],
            ['nama' => 'Snack', 'nominal' => 176_000],
            ['nama' => 'Kebersihan & Keamanan', 'nominal' => 100_000],
        ] as $urutan => $bs) {
            RencanaAkadBiayaSesi::create([
                'rencana_akad_id' => $rencana->id,
                'nama' => $bs['nama'],
                'nominal' => $bs['nominal'],
                'urutan' => $urutan,
            ]);
        }

        foreach ($kandidat as $i => $spr) {
            $detail = self::UNIT_CONTOH[$i] ?? self::UNIT_CONTOH[0];

            $unit = $svc->tambahUnit($rencana, $spr);

            // Angka Aju Dana per unit — sama untuk semua unit karena tipenya sama.
            $unit->update([
                'jenis_akad' => 'ppjb',
                'hgb' => $detail['hgb'],
                // Nilai akta AJB — dasar hitung pajak, tampil di kolom "AJB NOTARIS"
                // di lembar Aju Dana (11 x 185jt = 2.035.000.000).
                'nilai_ajb' => 185_000_000,
                // Kolom "By Proses Akad" di kertas Aju Dana = biaya notaris di sistem.
                'bayar_bi_notaris' => 1_645_000,
                // "PPh 4(2)" — sudah ada kolom khususnya.
                'bayar_ps4a2' => 1_850_000,
                // "BPHTB" — kertas asli kosong, biaya masuk saat akad AJB nanti.
                'bayar_bphtb' => 0,
            ]);

            // Progres bangunan & subcon supaya kolom "%RMH" dan "SUBCONT" di layar terisi.
            $spr->rumah?->update([
                'subcon_id' => Subcon::firstOrCreate(['nama' => $detail['subcon']])->id,
                'progres_fisik' => $detail['progres'],
                'lot' => $detail['lot'],
            ]);
        }

        $biaya = $svc->ringkasanBiaya($rencana->fresh());
        $unitAktif = $biaya['unit'];

        $this->command?->info("Rencana Akad {$rencana->nomor} dibuat — {$unitAktif} unit, biaya proses Rp ".number_format($biaya['jumlah'], 0, ',', '.'));
        $this->command?->line('  Target lembar Aju Dana asli: 11 unit, biaya proses Rp 39.122.000');
    }

    /**
     * Notaris di lembar aslinya. Kalau belum ada di master, dibuat dengan NIK
     * sementara — WAJIB dibetulkan sebelum dipakai sungguhan.
     */
    private function notaris(): Notaris
    {
        return Notaris::firstOrCreate(
            ['nama' => 'Zuwanna Corna Gumanti, S.H., M.Kn.'],
            ['nik' => '0000000000000000', 'nomor_rekening' => '0000000000'],
        );
    }

    private function bank(): ?Bank
    {
        return Bank::where('nama', 'like', '%BTN%')->first()
            ?? Bank::where('nama', 'like', '%Syariah%')->first()
            ?? Bank::first();
    }
}
