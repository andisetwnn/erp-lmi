<?php

namespace Database\Seeders;

use App\Models\Akunting\Jurnal;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Models\User;
use App\Services\JurnalService;
use Illuminate\Database\Seeder;

/**
 * Sample jurnal umum — skenario developer rumah PT LMI (Proyek Grha Aryana),
 * periode Jan – Aug 2026. Comprehensive untuk demo Buku Besar + Laba Rugi + Neraca.
 *
 * Cakupan (~40 jurnal):
 * A. Modal awal (Jan) — setoran owner, distribusi kas → bank, beli inventaris
 * B. Investasi proyek (Jan-Feb) — HPP tanah, perijinan, cut&fill, infrastruktur
 * C. Konstruksi rumah (Feb-May) — HPP rumah Type 30, material, bayar SPK
 * D. Operasional bulanan (Jan-Aug) — gaji, sewa, listrik, brosur, ATK, dll
 * E. Revenue penjualan (Mei-Aug) — terima UM/UTJ, cair KPR akad → pendapatan
 * F. Pendapatan lain — bunga giro, pembatalan penalti
 * G. Pemeliharaan kendaraan (Jan-Jul, 9 tx dari data legacy screenshot)
 *
 * Idempotent: skip kalau no_bukti sudah ada.
 */
class JurnalSeeder extends Seeder
{
    public function run(): void
    {
        $perusahaan = Perusahaan::firstWhere('kode_surat', 'LMI') ?? Perusahaan::first();
        if (! $perusahaan) {
            $this->command?->warn('Perusahaan LMI belum ada, JurnalSeeder di-skip.');

            return;
        }

        $adminId = User::firstWhere('email', 'admin@lmi.test')?->id
            ?? User::first()?->id;
        if (! $adminId) {
            $this->command?->warn('User admin belum ada, JurnalSeeder di-skip.');

            return;
        }

        // Ensure Bank BCA exists (dipakai di sample legacy pemeliharaan kendaraan)
        $bankHeader = Coa::firstWhere(['perusahaan_id' => $perusahaan->id, 'kode' => '1002']);
        Coa::firstOrCreate(
            ['perusahaan_id' => $perusahaan->id, 'kode' => '1002.006'],
            [
                'nama' => 'Bank BCA 8888xxx',
                'tipe' => 'aset',
                'saldo_normal' => 'debit',
                'parent_id' => $bankHeader?->id,
                'is_header' => false,
                'is_aktif' => true,
            ],
        );

        // Kode COA yang dipakai
        $kodes = [
            'kas_pusat' => '1001.001',
            'kas_proyek' => '1001.002',
            'kas_tehnik' => '1001.003',
            'bank_btn' => '1002.001',
            'bank_bca' => '1002.006',
            'bank_nobu' => '1002.002',
            'piutang_konsumen' => '1007.002',
            'piutang_btn' => '1007.001',
            'aktiva_kendaraan' => '1500.001',
            'aktiva_inventaris' => '1500.002',
            'hutang_spk_rumah' => '2001.001',
            'hutang_spk_infra' => '2001.002',
            'hutang_pph21' => '2011.001',
            'hutang_pph_final' => '2011.004',
            'modal_dcw' => '3001.003', // Nur Cahyo
            'modal_flt' => '3001.004', // Faisal
            'penjualan_type30' => '4001.003',
            'hpp_tanah' => '5001.001',
            'hpp_perijinan' => '5002.001',
            'hpp_cutfill' => '5005.001',
            'hpp_jalan' => '5006.001',
            'hpp_saluran' => '5006.002',
            'hpp_material' => '5006.017',
            'hpp_rumah30' => '5007.006',
            'brosur' => '6001.001',
            'spanduk' => '6001.002',
            'iklan' => '6001.004',
            'komisi' => '6001.005',
            'gaji' => '6002.001',
            'thr' => '6002.004',
            'atk' => '6002.006',
            'bbm' => '6002.007',
            'listrik' => '6002.009',
            'telp' => '6002.011',
            'keamanan' => '6002.014',
            'sewa_kantor' => '6002.016',
            'pemel_bangunan' => '6002.021',
            'pemel_kendaraan' => '6002.023',
            'adm_bank' => '6003.002',
            'giro' => '7001.001',
            'penalti_batal' => '7001.003',
        ];

        $coaMap = Coa::where('perusahaan_id', $perusahaan->id)
            ->whereIn('kode', array_values($kodes))
            ->pluck('id', 'kode');

        // Helper: build single entry
        $mkDetail = function (string $kodeAlias, float $debet = 0, float $kredit = 0) use ($kodes, $coaMap): array {
            $kode = $kodes[$kodeAlias] ?? null;
            if (! $kode || ! isset($coaMap[$kode])) {
                throw new \RuntimeException("COA alias '$kodeAlias' (kode=$kode) tidak ditemukan.");
            }

            return ['coa_id' => $coaMap[$kode], 'debet' => $debet, 'kredit' => $kredit];
        };

        // ═══════════════════════════════════════════════════════════════════
        // Definisi samples
        // ═══════════════════════════════════════════════════════════════════
        $samples = [];

        // ─── A. MODAL AWAL (Jan 2026) ──────────────────────────────────────
        $samples[] = ['no_bukti' => 'JU/01/26/001', 'tanggal' => '2026-01-02',
            'keterangan' => 'Setoran modal awal Nur Cahyo Wibowo (kas)',
            'lines' => [$mkDetail('kas_pusat', 300_000_000), $mkDetail('modal_dcw', 0, 300_000_000)]];

        $samples[] = ['no_bukti' => 'JU/01/26/002', 'tanggal' => '2026-01-03',
            'keterangan' => 'Setoran modal awal Faisal Lutfi (kas)',
            'lines' => [$mkDetail('kas_pusat', 200_000_000), $mkDetail('modal_flt', 0, 200_000_000)]];

        $samples[] = ['no_bukti' => 'JU/01/26/003', 'tanggal' => '2026-01-05',
            'keterangan' => 'Transfer kas pusat → Bank BTN untuk operasional proyek',
            'lines' => [$mkDetail('bank_btn', 300_000_000), $mkDetail('kas_pusat', 0, 300_000_000)]];

        $samples[] = ['no_bukti' => 'JU/01/26/004', 'tanggal' => '2026-01-05',
            'keterangan' => 'Transfer kas pusat → Bank BCA untuk penerimaan konsumen',
            'lines' => [$mkDetail('bank_bca', 150_000_000), $mkDetail('kas_pusat', 0, 150_000_000)]];

        $samples[] = ['no_bukti' => 'JU/01/26/005', 'tanggal' => '2026-01-08',
            'keterangan' => 'Pembelian inventaris kantor (meja, kursi, komputer, printer)',
            'lines' => [$mkDetail('aktiva_inventaris', 30_000_000), $mkDetail('bank_btn', 0, 30_000_000)]];

        $samples[] = ['no_bukti' => 'JU/01/26/006', 'tanggal' => '2026-01-10',
            'keterangan' => 'Pembelian motor operasional B 5516 TRQ (Honda Vario)',
            'lines' => [$mkDetail('aktiva_kendaraan', 25_000_000), $mkDetail('bank_btn', 0, 25_000_000)]];

        // ─── B. INVESTASI PROYEK (Jan-Feb 2026) ────────────────────────────
        $samples[] = ['no_bukti' => 'JU/01/26/010', 'tanggal' => '2026-01-15',
            'keterangan' => 'HPP Perolehan Tanah - lokasi Grha Aryana 8.500 m²',
            'lines' => [$mkDetail('hpp_tanah', 850_000_000), $mkDetail('bank_btn', 0, 850_000_000)]];

        $samples[] = ['no_bukti' => 'JU/01/26/011', 'tanggal' => '2026-01-25',
            'keterangan' => 'HPP Perijinan / Ijin Lokasi & Sertifikat Induk',
            'lines' => [$mkDetail('hpp_perijinan', 45_000_000), $mkDetail('kas_pusat', 0, 45_000_000)]];

        $samples[] = ['no_bukti' => 'JU/02/26/010', 'tanggal' => '2026-02-05',
            'keterangan' => 'HPP Cut & Fill lahan Grha Aryana (kontraktor CV Berkah)',
            'lines' => [$mkDetail('hpp_cutfill', 120_000_000), $mkDetail('hutang_spk_infra', 0, 120_000_000)]];

        $samples[] = ['no_bukti' => 'JU/02/26/011', 'tanggal' => '2026-02-20',
            'keterangan' => 'Bayar sebagian Hutang SPK Cut & Fill (CV Berkah)',
            'lines' => [$mkDetail('hutang_spk_infra', 60_000_000), $mkDetail('bank_btn', 0, 60_000_000)]];

        $samples[] = ['no_bukti' => 'JU/03/26/010', 'tanggal' => '2026-03-08',
            'keterangan' => 'HPP Jalan Konstruksi paving blok utama',
            'lines' => [$mkDetail('hpp_jalan', 85_000_000), $mkDetail('hutang_spk_infra', 0, 85_000_000)]];

        $samples[] = ['no_bukti' => 'JU/03/26/011', 'tanggal' => '2026-03-12',
            'keterangan' => 'HPP Saluran Drainase blok AD-AE',
            'lines' => [$mkDetail('hpp_saluran', 32_000_000), $mkDetail('hutang_spk_infra', 0, 32_000_000)]];

        // ─── C. KONSTRUKSI RUMAH TYPE 30 (Feb-Jun 2026) ────────────────────
        $samples[] = ['no_bukti' => 'JU/02/26/020', 'tanggal' => '2026-02-15',
            'keterangan' => 'Pembelian material konstruksi (semen, besi, batu bata)',
            'lines' => [$mkDetail('hpp_material', 55_000_000), $mkDetail('bank_btn', 0, 55_000_000)]];

        $samples[] = ['no_bukti' => 'JU/03/26/020', 'tanggal' => '2026-03-20',
            'keterangan' => 'HPP Rumah Type 30 - konstruksi batch 1 (5 unit blok AD)',
            'lines' => [$mkDetail('hpp_rumah30', 350_000_000), $mkDetail('hutang_spk_rumah', 0, 350_000_000)]];

        $samples[] = ['no_bukti' => 'JU/04/26/010', 'tanggal' => '2026-04-10',
            'keterangan' => 'Bayar Hutang SPK Rumah batch 1 (Termin 1 - 40%)',
            'lines' => [$mkDetail('hutang_spk_rumah', 140_000_000), $mkDetail('bank_btn', 0, 140_000_000)]];

        $samples[] = ['no_bukti' => 'JU/04/26/011', 'tanggal' => '2026-04-15',
            'keterangan' => 'HPP Rumah Type 30 - konstruksi batch 2 (5 unit blok AE)',
            'lines' => [$mkDetail('hpp_rumah30', 350_000_000), $mkDetail('hutang_spk_rumah', 0, 350_000_000)]];

        $samples[] = ['no_bukti' => 'JU/05/26/010', 'tanggal' => '2026-05-08',
            'keterangan' => 'Bayar Hutang SPK Rumah (termin 2 batch 1 - 40%)',
            'lines' => [$mkDetail('hutang_spk_rumah', 140_000_000), $mkDetail('bank_btn', 0, 140_000_000)]];

        // ─── D. OPERASIONAL BULANAN (Jan-Aug 2026) ─────────────────────────
        // Gaji bulanan (25jt gross, potong PPh 21 5% = 1.25jt, net 23.75jt)
        foreach (['01', '02', '03', '04', '05', '06', '07'] as $bln) {
            $samples[] = ['no_bukti' => "JU/$bln/26/G01", 'tanggal' => "2026-$bln-25",
                'keterangan' => 'Pembayaran gaji karyawan bulan '.date('F', mktime(0, 0, 0, (int) $bln, 1, 2026)),
                'lines' => [
                    $mkDetail('gaji', 25_000_000),
                    $mkDetail('hutang_pph21', 0, 1_250_000),
                    $mkDetail('bank_btn', 0, 23_750_000),
                ]];
            // Bayar PPh 21 ke kas negara bulan berikutnya
            $blnNext = str_pad((string) (((int) $bln) + 1), 2, '0', STR_PAD_LEFT);
            if ((int) $blnNext <= 8) {
                $samples[] = ['no_bukti' => "JU/$blnNext/26/P01", 'tanggal' => "2026-$blnNext-10",
                    'keterangan' => 'Setor PPh 21 gaji bulan sebelumnya ke kas negara',
                    'lines' => [$mkDetail('hutang_pph21', 1_250_000), $mkDetail('bank_btn', 0, 1_250_000)]];
            }
        }

        // Sewa kantor bulanan
        foreach (['01', '02', '03', '04', '05', '06', '07'] as $bln) {
            $samples[] = ['no_bukti' => "JU/$bln/26/S01", 'tanggal' => "2026-$bln-03",
                'keterangan' => 'Bayar sewa kantor bulanan (kontrak tahunan)',
                'lines' => [$mkDetail('sewa_kantor', 5_000_000), $mkDetail('bank_btn', 0, 5_000_000)]];
        }

        // Listrik bulanan
        foreach (['02', '03', '04', '05', '06', '07', '08'] as $bln) {
            $samples[] = ['no_bukti' => "JU/$bln/26/L01", 'tanggal' => "2026-$bln-15",
                'keterangan' => 'Bayar listrik PLN kantor & site proyek',
                'lines' => [$mkDetail('listrik', 1_800_000), $mkDetail('bank_btn', 0, 1_800_000)]];
        }

        // Promosi & marketing
        $samples[] = ['no_bukti' => 'JU/02/26/M01', 'tanggal' => '2026-02-05',
            'keterangan' => 'Cetak brosur & booklet pemasaran (500 lembar)',
            'lines' => [$mkDetail('brosur', 3_500_000), $mkDetail('kas_pusat', 0, 3_500_000)]];

        $samples[] = ['no_bukti' => 'JU/03/26/M01', 'tanggal' => '2026-03-10',
            'keterangan' => 'Pasang spanduk & umbul-umbul launching Grha Aryana',
            'lines' => [$mkDetail('spanduk', 4_500_000), $mkDetail('kas_pusat', 0, 4_500_000)]];

        $samples[] = ['no_bukti' => 'JU/04/26/M01', 'tanggal' => '2026-04-20',
            'keterangan' => 'Iklan digital Instagram & TikTok Ads',
            'lines' => [$mkDetail('iklan', 5_500_000), $mkDetail('bank_bca', 0, 5_500_000)]];

        // Operasional kecil
        $samples[] = ['no_bukti' => 'JU/03/26/O01', 'tanggal' => '2026-03-05',
            'keterangan' => 'Fotocopy & ATK dokumen SPR + arsip',
            'lines' => [$mkDetail('atk', 850_000), $mkDetail('kas_pusat', 0, 850_000)]];

        $samples[] = ['no_bukti' => 'JU/05/26/O01', 'tanggal' => '2026-05-14',
            'keterangan' => 'BBM & tol survei lokasi + antar-jemput konsumen',
            'lines' => [$mkDetail('bbm', 1_250_000), $mkDetail('kas_pusat', 0, 1_250_000)]];

        $samples[] = ['no_bukti' => 'JU/06/26/O01', 'tanggal' => '2026-06-04',
            'keterangan' => 'Pemeliharaan bangunan kantor - cat ulang & perbaikan atap',
            'lines' => [$mkDetail('pemel_bangunan', 4_200_000), $mkDetail('kas_pusat', 0, 4_200_000)]];

        // Bulanan
        foreach (['02', '04', '06', '08'] as $bln) {
            $samples[] = ['no_bukti' => "JU/$bln/26/K01", 'tanggal' => "2026-$bln-12",
                'keterangan' => 'Honor keamanan & kebersihan bulanan (2 orang)',
                'lines' => [$mkDetail('keamanan', 3_500_000), $mkDetail('kas_pusat', 0, 3_500_000)]];
        }

        foreach (['02', '05', '08'] as $bln) {
            $samples[] = ['no_bukti' => "JU/$bln/26/T01", 'tanggal' => "2026-$bln-18",
                'keterangan' => 'Isi voucher telp & internet office',
                'lines' => [$mkDetail('telp', 600_000), $mkDetail('kas_pusat', 0, 600_000)]];
        }

        // THR Idul Fitri (asumsi April)
        $samples[] = ['no_bukti' => 'JU/04/26/H01', 'tanggal' => '2026-04-01',
            'keterangan' => 'THR Idul Fitri karyawan (12 orang)',
            'lines' => [$mkDetail('thr', 30_000_000), $mkDetail('bank_btn', 0, 30_000_000)]];

        // ─── E. REVENUE PENJUALAN (Mei-Aug 2026) ───────────────────────────
        // Cair KPR dari Bank BTN untuk 3 unit yang sudah akad
        $samples[] = ['no_bukti' => 'JU/06/26/R01', 'tanggal' => '2026-06-15',
            'keterangan' => 'Pencairan KPR BTN - akad kredit 2 unit Type 30 (blok AD-18 & AE-05)',
            'lines' => [
                $mkDetail('bank_btn', 358_000_000),
                $mkDetail('penjualan_type30', 0, 358_000_000),
            ]];

        $samples[] = ['no_bukti' => 'JU/07/26/R01', 'tanggal' => '2026-07-20',
            'keterangan' => 'Pencairan KPR BTN - akad kredit 3 unit Type 30 (blok AE-02, AD-28, AF-05)',
            'lines' => [
                $mkDetail('bank_btn', 537_000_000),
                $mkDetail('penjualan_type30', 0, 537_000_000),
            ]];

        // Terima UM konsumen (10 orang × 5jt) via Bank BCA (masuk sbg pendapatan on cash basis)
        $samples[] = ['no_bukti' => 'JU/05/26/R01', 'tanggal' => '2026-05-20',
            'keterangan' => 'Terima UM konsumen 8 unit @Rp 5.000.000 (Mei)',
            'lines' => [
                $mkDetail('bank_bca', 40_000_000),
                $mkDetail('penjualan_type30', 0, 40_000_000),
            ]];

        $samples[] = ['no_bukti' => 'JU/07/26/R02', 'tanggal' => '2026-07-25',
            'keterangan' => 'Terima UM konsumen 6 unit @Rp 4.500.000 (Juli)',
            'lines' => [
                $mkDetail('bank_bca', 27_000_000),
                $mkDetail('penjualan_type30', 0, 27_000_000),
            ]];

        // ─── F. PENDAPATAN LAIN ────────────────────────────────────────────
        $samples[] = ['no_bukti' => 'JU/03/26/B01', 'tanggal' => '2026-03-31',
            'keterangan' => 'Bunga jasa giro Bank BTN Q1 2026',
            'lines' => [$mkDetail('bank_btn', 425_000), $mkDetail('giro', 0, 425_000)]];

        $samples[] = ['no_bukti' => 'JU/06/26/B01', 'tanggal' => '2026-06-30',
            'keterangan' => 'Bunga jasa giro Bank BTN Q2 2026',
            'lines' => [$mkDetail('bank_btn', 685_000), $mkDetail('giro', 0, 685_000)]];

        $samples[] = ['no_bukti' => 'JU/07/26/B01', 'tanggal' => '2026-07-15',
            'keterangan' => 'Pendapatan penalti pembatalan konsumen (4 unit @Rp 500.000)',
            'lines' => [$mkDetail('bank_bca', 2_000_000), $mkDetail('penalti_batal', 0, 2_000_000)]];

        // Adm bank
        $samples[] = ['no_bukti' => 'JU/07/26/A01', 'tanggal' => '2026-07-31',
            'keterangan' => 'Biaya administrasi Bank BTN + BCA bulan Juli',
            'lines' => [
                $mkDetail('adm_bank', 45_000),
                $mkDetail('bank_btn', 0, 25_000),
                $mkDetail('bank_bca', 0, 20_000),
            ]];

        // ─── G. PEMELIHARAAN KENDARAAN (data legacy screenshot) ────────────
        $pemel = [
            ['KASTEHNIK/12/25/08', '2026-01-01', 'Service Motor B5516TRQ', 200_000, 'kas_tehnik'],
            ['BTN9130/02/26/45', '2026-02-12', 'Biaya Perpanjangan Pajak Motor B 5516 TRQ (Pak Faldhi)', 350_000, 'bank_btn'],
            ['BTN9130/04/26/67', '2026-04-16', 'Biaya Pembelian Aksesoris Mobil Rush B 2634 WFI (Sarung Jok, Karpet, Lampu, Klakson)', 4_450_000, 'bank_btn'],
            ['KASPUSAT/05/26/07', '2026-05-13', 'Cuci Mobil dan Dompet STNK', 80_000, 'kas_pusat'],
            ['KASPUSAT/05/26/25', '2026-05-25', 'Biaya Isi Tambahan Nitrogen dan Tambal Ban Mobil Bu Uli', 50_000, 'kas_pusat'],
            ['KASPUSAT/06/26/03', '2026-06-04', 'Biaya tambah ban mobil operasional', 20_000, 'kas_pusat'],
            ['KASPUSAT/06/26/15', '2026-06-10', 'Tambal ban dan tambah gas nitrogen mobil operasional', 40_000, 'kas_pusat'],
            ['BCA8888/07/26/002', '2026-07-01', 'Biaya Service Mobil Operasional', 166_500, 'bank_bca'],
            ['BCA8888/07/26/007', '2026-07-02', 'Biaya Service Mobil Operasional', 1_500_000, 'bank_bca'],
        ];
        foreach ($pemel as [$noBukti, $tgl, $ket, $nominal, $sumber]) {
            $samples[] = ['no_bukti' => $noBukti, 'tanggal' => $tgl, 'keterangan' => $ket,
                'lines' => [$mkDetail('pemel_kendaraan', $nominal), $mkDetail($sumber, 0, $nominal)]];
        }

        // ═══════════════════════════════════════════════════════════════════
        // Post semua
        // ═══════════════════════════════════════════════════════════════════
        $svc = app(JurnalService::class);
        $created = 0;
        $skipped = 0;
        $totalDebet = 0;

        foreach ($samples as $s) {
            if (Jurnal::where('perusahaan_id', $perusahaan->id)
                ->where('no_bukti', $s['no_bukti'])
                ->exists()) {
                $skipped++;

                continue;
            }

            $jurnal = $svc->create([
                'perusahaan_id' => $perusahaan->id,
                'tanggal' => $s['tanggal'],
                'no_bukti' => $s['no_bukti'],
                'tipe' => 'umum',
                'keterangan' => $s['keterangan'],
                'created_by_user_id' => $adminId,
            ], $s['lines']);

            $svc->post($jurnal, $adminId);

            $totalDebet += collect($s['lines'])->sum('debet');
            $created++;
        }

        $this->command?->info("JurnalSeeder: $created created, $skipped skipped. Total volume: Rp ".number_format($totalDebet, 0, ',', '.'));
    }
}
