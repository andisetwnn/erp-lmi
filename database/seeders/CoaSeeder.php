<?php

namespace Database\Seeders;

use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use Illuminate\Database\Seeder;

class CoaSeeder extends Seeder
{
    public function run(): void
    {
        // COA di-seed untuk LMI saja. PT lain bisa pakai fitur "Copy COA" via UI.
        $lmi = Perusahaan::firstOrCreate(
            ['kode_surat' => 'LMI'],
            ['nama' => 'PT Langit Membangun Indonesia'],
        );

        // saldo_normal default per tipe.
        $defaultSaldoNormal = [
            'aset' => 'debit',
            'kewajiban' => 'kredit',
            'modal' => 'kredit',
            'pendapatan' => 'kredit',
            'beban' => 'debit',
        ];

        // ========== STEP 1: GROUP HEADERS (4-digit kode) ==========
        // Header = parent untuk leaf accounts, is_header=true (tidak untuk transaksi).
        $headers = [
            // ASET
            ['kode' => '1001', 'nama' => 'KAS',                          'tipe' => 'aset'],
            ['kode' => '1002', 'nama' => 'BANK',                         'tipe' => 'aset'],
            ['kode' => '1003', 'nama' => 'AYAT SILANG',                  'tipe' => 'aset'],
            ['kode' => '1004', 'nama' => 'UANG MUKA',                    'tipe' => 'aset'],
            ['kode' => '1007', 'nama' => 'PIUTANG',                      'tipe' => 'aset'],
            ['kode' => '1008', 'nama' => 'BIAYA PEROLEHAN TANAH',        'tipe' => 'aset'],
            ['kode' => '1012', 'nama' => 'BIAYA DALAM PROSES',           'tipe' => 'aset'],
            ['kode' => '1013', 'nama' => 'KONSTRUKSI DALAM PROSES',      'tipe' => 'aset'],
            ['kode' => '1015', 'nama' => 'JARINGAN & PENERANGAN',        'tipe' => 'aset'],
            ['kode' => '1016', 'nama' => 'PERSEDIAAN',                   'tipe' => 'aset'],
            ['kode' => '1500', 'nama' => 'AKTIVA TETAP',                 'tipe' => 'aset'],
            ['kode' => '1501', 'nama' => 'AKUMULASI PENYUSUTAN',         'tipe' => 'aset', 'saldo_normal' => 'kredit'],

            // KEWAJIBAN
            ['kode' => '2001', 'nama' => 'HUTANG SPK',                   'tipe' => 'kewajiban'],
            ['kode' => '2002', 'nama' => 'HUTANG SPK JARINGAN LISTRIK',  'tipe' => 'kewajiban'],
            ['kode' => '2006', 'nama' => 'HUTANG OPERASIONAL',           'tipe' => 'kewajiban'],
            ['kode' => '2009', 'nama' => 'HUTANG LEASING',               'tipe' => 'kewajiban'],
            ['kode' => '2011', 'nama' => 'HUTANG PAJAK',                 'tipe' => 'kewajiban'],
            ['kode' => '2012', 'nama' => 'UANG TITIPAN',                 'tipe' => 'kewajiban'],
            ['kode' => '2016', 'nama' => 'HUTANG OPERASIONAL LAIN',      'tipe' => 'kewajiban'],
            ['kode' => '2080', 'nama' => 'PINJAMAN BANK',                'tipe' => 'kewajiban'],

            // MODAL
            ['kode' => '3001', 'nama' => 'MODAL SAHAM',                  'tipe' => 'modal'],
            ['kode' => '3002', 'nama' => 'LABA DITAHAN',                 'tipe' => 'modal'],
            ['kode' => '3003', 'nama' => 'LABA TAHUN BERJALAN',          'tipe' => 'modal'],
            ['kode' => '3004', 'nama' => 'DEVIDEN',                      'tipe' => 'modal', 'saldo_normal' => 'debit'],

            // PENDAPATAN
            ['kode' => '4001', 'nama' => 'PENJUALAN RUMAH',              'tipe' => 'pendapatan'],

            // HPP (beban)
            ['kode' => '5001', 'nama' => 'HPP TANAH',                    'tipe' => 'beban'],
            ['kode' => '5002', 'nama' => 'HPP PERIJINAN',                'tipe' => 'beban'],
            ['kode' => '5005', 'nama' => 'HPP CUT & FILL',               'tipe' => 'beban'],
            ['kode' => '5006', 'nama' => 'HPP INFRASTRUKTUR',            'tipe' => 'beban'],
            ['kode' => '5007', 'nama' => 'HPP RUMAH',                    'tipe' => 'beban'],
            ['kode' => '5008', 'nama' => 'HPP UTILITAS',                 'tipe' => 'beban'],

            // BEBAN
            ['kode' => '6001', 'nama' => 'BIAYA PROMOSI',                'tipe' => 'beban'],
            ['kode' => '6002', 'nama' => 'BIAYA OPERASIONAL',            'tipe' => 'beban'],
            ['kode' => '6003', 'nama' => 'BIAYA BANK',                   'tipe' => 'beban'],
            ['kode' => '6004', 'nama' => 'BIAYA PENYUSUTAN',             'tipe' => 'beban'],
            ['kode' => '6005', 'nama' => 'BIAYA TENAGA AHLI',            'tipe' => 'beban'],
            ['kode' => '6006', 'nama' => 'BIAYA PROSES KPR',             'tipe' => 'beban'],
            ['kode' => '6020', 'nama' => 'BIAYA PAJAK PPh',              'tipe' => 'beban'],

            // PENDAPATAN LAIN
            ['kode' => '7001', 'nama' => 'PENDAPATAN LAIN-LAIN',         'tipe' => 'pendapatan'],

            // PAJAK FINAL
            ['kode' => '8000', 'nama' => 'PAJAK PPh FINAL',              'tipe' => 'beban'],
        ];

        // Map kode header → id (untuk parent_id leaf)
        $headerIdMap = [];
        foreach ($headers as $h) {
            $row = Coa::firstOrCreate(
                ['perusahaan_id' => $lmi->id, 'kode' => $h['kode']],
                [
                    'nama' => $h['nama'],
                    'tipe' => $h['tipe'],
                    'saldo_normal' => $h['saldo_normal'] ?? $defaultSaldoNormal[$h['tipe']],
                    'parent_id' => null,
                    'is_header' => true,
                    'is_aktif' => true,
                ],
            );
            $headerIdMap[$h['kode']] = $row->id;
        }

        // ========== STEP 2: LEAF ACCOUNTS ==========
        $accounts = [
            // ASET
            ['kode' => '1001.001', 'nama' => 'Kas Pusat',                                     'tipe' => 'aset'],
            ['kode' => '1001.002', 'nama' => 'Kas Proyek',                                    'tipe' => 'aset'],
            ['kode' => '1001.003', 'nama' => 'Kas Proyek Tehnik',                             'tipe' => 'aset'],
            ['kode' => '1001.004', 'nama' => 'Patty Cash',                                    'tipe' => 'aset'],
            ['kode' => '1002.001', 'nama' => 'Bank BTN 00044.01.30.0009130',                  'tipe' => 'aset'],
            ['kode' => '1002.002', 'nama' => 'Bank Nobu 10130553266',                         'tipe' => 'aset'],
            ['kode' => '1002.003', 'nama' => 'Deposit',                                       'tipe' => 'aset'],
            ['kode' => '1002.004', 'nama' => 'Bank BNI',                                      'tipe' => 'aset'],
            ['kode' => '1002.005', 'nama' => 'BTN Syariah',                                   'tipe' => 'aset'],
            ['kode' => '1002.008', 'nama' => 'BANK DKI',                                      'tipe' => 'aset'],
            ['kode' => '1003.001', 'nama' => 'Ayat Silang',                                   'tipe' => 'aset'],
            ['kode' => '1004.001', 'nama' => 'Uang Muka Sewa Kantor',                         'tipe' => 'aset'],
            ['kode' => '1004.002', 'nama' => 'Uang Muka PPN',                                 'tipe' => 'aset'],
            ['kode' => '1004.003', 'nama' => 'Uang Muka PPH Pasal 4(2)',                      'tipe' => 'aset'],
            ['kode' => '1007.001', 'nama' => 'Piutang BTN',                                   'tipe' => 'aset'],
            ['kode' => '1007.002', 'nama' => 'Piutang Konsumen',                              'tipe' => 'aset'],
            ['kode' => '1007.003', 'nama' => 'Piutang Personal',                              'tipe' => 'aset'],
            ['kode' => '1007.004', 'nama' => 'Piutang Sementara',                             'tipe' => 'aset'],
            ['kode' => '1007.005', 'nama' => 'Piutang Karyawan',                              'tipe' => 'aset'],
            ['kode' => '1007.006', 'nama' => 'Piutang PT. DPM',                               'tipe' => 'aset'],
            ['kode' => '1007.007', 'nama' => 'Piutang PT. DK',                                'tipe' => 'aset'],
            ['kode' => '1007.008', 'nama' => 'Piutang UM PPH',                                'tipe' => 'aset'],
            ['kode' => '1007.009', 'nama' => 'Piutang UM Intransit',                          'tipe' => 'aset'],
            ['kode' => '1007.011', 'nama' => 'Piutang BUM',                                   'tipe' => 'aset'],
            ['kode' => '1007.012', 'nama' => 'Piutang Lain-Lain',                             'tipe' => 'aset'],
            ['kode' => '1008.001', 'nama' => 'By Perolehan Tanah',                            'tipe' => 'aset'],
            ['kode' => '1008.003', 'nama' => 'By Perolehan Tanah Extention',                  'tipe' => 'aset'],
            ['kode' => '1012.001', 'nama' => 'By Cut&Fill Dlm Proses',                        'tipe' => 'aset'],
            ['kode' => '1013.001', 'nama' => 'Jalan Dalam Konstruksi',                        'tipe' => 'aset'],
            ['kode' => '1013.002', 'nama' => 'Saluran Dalam Konstruksi',                      'tipe' => 'aset'],
            ['kode' => '1013.003', 'nama' => 'Turap',                                         'tipe' => 'aset'],
            ['kode' => '1013.004', 'nama' => 'Taman & Lingkungan',                            'tipe' => 'aset'],
            ['kode' => '1013.005', 'nama' => 'Persediaan Sarana Lain',                        'tipe' => 'aset'],
            ['kode' => '1015.001', 'nama' => 'Jaringan Tegangan Menengah & Rendah',           'tipe' => 'aset'],
            ['kode' => '1015.002', 'nama' => 'Penerangan Jalan Umum (PJU) Dlm Konstruksi',    'tipe' => 'aset'],
            ['kode' => '1016.001', 'nama' => 'Persediaan Air Bersih',                         'tipe' => 'aset'],
            ['kode' => '1016.002', 'nama' => 'Persediaan Material',                           'tipe' => 'aset'],
            ['kode' => '1016.003', 'nama' => 'Persediaan Rumah Type 30',                      'tipe' => 'aset'],
            ['kode' => '1016.004', 'nama' => 'Persediaan Rumah Type 36',                      'tipe' => 'aset'],
            ['kode' => '1500.001', 'nama' => 'Aktiv Tetap Kendaraan Bermotor',                'tipe' => 'aset'],
            ['kode' => '1500.002', 'nama' => 'Aktiva Tetap Inventaris Kantor',                'tipe' => 'aset'],
            ['kode' => '1501.001', 'nama' => 'Akumulasi Penyusutan Kendaraan Bermotor',       'tipe' => 'aset', 'saldo_normal' => 'kredit'],
            ['kode' => '1501.002', 'nama' => 'Akumulasi Penyusutan Inventaris Kantor',        'tipe' => 'aset', 'saldo_normal' => 'kredit'],

            // KEWAJIBAN
            ['kode' => '2001.001', 'nama' => 'Hutang SPK Rumah',                              'tipe' => 'kewajiban'],
            ['kode' => '2001.002', 'nama' => 'Hutang SPK Infrastruksur',                      'tipe' => 'kewajiban'],
            ['kode' => '2002.001', 'nama' => 'Hutang SPK Jaringan Listrik',                   'tipe' => 'kewajiban'],
            ['kode' => '2006.002', 'nama' => 'Hutang Konsumen',                               'tipe' => 'kewajiban'],
            ['kode' => '2006.005', 'nama' => 'Hutang Personal',                               'tipe' => 'kewajiban'],
            ['kode' => '2006.008', 'nama' => 'Hutang Ke PT. DPM',                             'tipe' => 'kewajiban'],
            ['kode' => '2006.009', 'nama' => 'Hutang Ke PT. DK',                              'tipe' => 'kewajiban'],
            ['kode' => '2006.022', 'nama' => 'Hutang BUM',                                    'tipe' => 'kewajiban'],
            ['kode' => '2006.023', 'nama' => 'Hutang Kepemegang Saham',                       'tipe' => 'kewajiban'],
            ['kode' => '2006.025', 'nama' => 'Hutang Sementara',                              'tipe' => 'kewajiban'],
            ['kode' => '2006.099', 'nama' => 'Hutang Lain-Lain',                              'tipe' => 'kewajiban'],
            ['kode' => '2009.001', 'nama' => 'Hutang Leasing',                                'tipe' => 'kewajiban'],
            ['kode' => '2011.001', 'nama' => 'Hutang Pajak 21',                               'tipe' => 'kewajiban'],
            ['kode' => '2011.002', 'nama' => 'Hutang Pajak PPn',                              'tipe' => 'kewajiban'],
            ['kode' => '2011.004', 'nama' => 'Hutang Pajak PPH Pasal 4(2)',                   'tipe' => 'kewajiban'],
            ['kode' => '2011.005', 'nama' => 'Hutang Pajak',                                  'tipe' => 'kewajiban'],
            ['kode' => '2012.001', 'nama' => 'Uang Titipan Legalitas',                        'tipe' => 'kewajiban'],
            ['kode' => '2012.002', 'nama' => 'Uang Titipan NoName',                           'tipe' => 'kewajiban'],
            ['kode' => '2012.003', 'nama' => 'Hutang Titipan Legalitas Intransit',            'tipe' => 'kewajiban'],
            ['kode' => '2012.004', 'nama' => 'Hutang Refund Konsumen',                        'tipe' => 'kewajiban'],
            ['kode' => '2012.005', 'nama' => 'Hutang ke Konsumen Akad UM lebih',              'tipe' => 'kewajiban'],
            ['kode' => '2016.001', 'nama' => 'Hutang By.Penjinjian/ijin Lokasi & Sertifikat Induk', 'tipe' => 'kewajiban'],
            ['kode' => '2016.002', 'nama' => 'Hutang By.Infrastruktur',                       'tipe' => 'kewajiban'],
            ['kode' => '2016.004', 'nama' => 'Hutang Persediaan Air Bersih',                  'tipe' => 'kewajiban'],
            ['kode' => '2016.005', 'nama' => 'Hutang Jaringan Tegangan Menengah & Rendah',    'tipe' => 'kewajiban'],
            ['kode' => '2080.001', 'nama' => 'Pinjaman Nobu PRK',                             'tipe' => 'kewajiban'],
            ['kode' => '2080.002', 'nama' => 'Pinjaman Nobu PT OD - 1',                       'tipe' => 'kewajiban'],
            ['kode' => '2080.003', 'nama' => 'Pinjaman Nobu PT OD - 2',                       'tipe' => 'kewajiban'],

            // MODAL
            ['kode' => '3001.001', 'nama' => 'Modal Saham PT. Dwiwahana Delta Megah (DDM)',   'tipe' => 'modal'],
            ['kode' => '3001.002', 'nama' => 'Modal Saham PT. Dharmawan Azka Pratama (DAP)',  'tipe' => 'modal'],
            ['kode' => '3001.003', 'nama' => 'Modal Saham Nur Cahyo Wibowo',                  'tipe' => 'modal'],
            ['kode' => '3001.004', 'nama' => 'Modal Saham Faisal Lutfi',                      'tipe' => 'modal'],
            ['kode' => '3001.005', 'nama' => 'Modal Saham Jonathan Fauzi Ba Abadh',           'tipe' => 'modal'],
            ['kode' => '3002.001', 'nama' => 'Laba/Rugi Ditahan',                             'tipe' => 'modal'],
            ['kode' => '3003.001', 'nama' => 'Laba/Rugi Tahun Berjalan',                      'tipe' => 'modal'],
            ['kode' => '3004.002', 'nama' => 'Deviden',                                       'tipe' => 'modal', 'saldo_normal' => 'debit'],

            // PENDAPATAN
            ['kode' => '4001.001', 'nama' => 'Penjualan Rumah Type 22',                       'tipe' => 'pendapatan'],
            ['kode' => '4001.003', 'nama' => 'Penjualan Rumah Type 30',                       'tipe' => 'pendapatan'],

            // HPP
            ['kode' => '5001.001', 'nama' => 'HPP Perolehan Tanah',                           'tipe' => 'beban'],
            ['kode' => '5002.001', 'nama' => 'HPP Perijinan/ijin Lokasi & Sertifikat Induk',  'tipe' => 'beban'],
            ['kode' => '5005.001', 'nama' => 'HPP Cut & Fill',                                'tipe' => 'beban'],
            ['kode' => '5006.001', 'nama' => 'HPP Jalan Dalam Konstruksi',                    'tipe' => 'beban'],
            ['kode' => '5006.002', 'nama' => 'HPP Saluran Dalam Konstruksi',                  'tipe' => 'beban'],
            ['kode' => '5006.003', 'nama' => 'HPP TURAP Dalam Konstruksi',                    'tipe' => 'beban'],
            ['kode' => '5006.004', 'nama' => 'HPP Taman & Lingkungan',                        'tipe' => 'beban'],
            ['kode' => '5006.005', 'nama' => 'HPP SARANA LAIN',                               'tipe' => 'beban'],
            ['kode' => '5006.017', 'nama' => 'HPP Persediaan Material',                       'tipe' => 'beban'],
            ['kode' => '5007.005', 'nama' => 'HPP Rumah Type 22',                             'tipe' => 'beban'],
            ['kode' => '5007.006', 'nama' => 'HPP Rumah Type 30',                             'tipe' => 'beban'],
            ['kode' => '5008.001', 'nama' => 'HPP Jaringan Tegangan Menengah & Rendah',       'tipe' => 'beban'],
            ['kode' => '5008.002', 'nama' => 'HPP Penerangan Jalan Umum(PJU),(UJL),(JI),(Konsuil)', 'tipe' => 'beban'],

            // BEBAN PROMOSI
            ['kode' => '6001.001', 'nama' => 'Brosur, Booklet, Leaflet',                      'tipe' => 'beban'],
            ['kode' => '6001.002', 'nama' => 'Spanduk, Umbul2 & Reklame',                     'tipe' => 'beban'],
            ['kode' => '6001.003', 'nama' => 'Pameran',                                       'tipe' => 'beban'],
            ['kode' => '6001.004', 'nama' => 'Iklan',                                         'tipe' => 'beban'],
            ['kode' => '6001.005', 'nama' => 'Komisi Sales Freelance',                        'tipe' => 'beban'],
            ['kode' => '6001.006', 'nama' => 'Perjalanan Dinas',                              'tipe' => 'beban'],

            // BEBAN OPERASIONAL
            ['kode' => '6002.001', 'nama' => 'Gaji',                                          'tipe' => 'beban'],
            ['kode' => '6002.002', 'nama' => 'Upah, Lembur & Insentif',                       'tipe' => 'beban'],
            ['kode' => '6002.003', 'nama' => 'Pengobatan',                                    'tipe' => 'beban'],
            ['kode' => '6002.004', 'nama' => 'THR, Bonus',                                    'tipe' => 'beban'],
            ['kode' => '6002.005', 'nama' => 'Seragam',                                       'tipe' => 'beban'],
            ['kode' => '6002.006', 'nama' => 'Fotocopy, Cetak & ATK',                         'tipe' => 'beban'],
            ['kode' => '6002.007', 'nama' => 'BBM/Transport, Tol & Parkir',                   'tipe' => 'beban'],
            ['kode' => '6002.008', 'nama' => 'PAM',                                           'tipe' => 'beban'],
            ['kode' => '6002.009', 'nama' => 'Listrik/PLN',                                   'tipe' => 'beban'],
            ['kode' => '6002.011', 'nama' => 'Telp, Fax, Voucher',                            'tipe' => 'beban'],
            ['kode' => '6002.012', 'nama' => 'Pos, Prangko & Materai',                        'tipe' => 'beban'],
            ['kode' => '6002.013', 'nama' => 'Koordinasi & Sumbangan',                        'tipe' => 'beban'],
            ['kode' => '6002.014', 'nama' => 'Keamanan & Kebersihan',                         'tipe' => 'beban'],
            ['kode' => '6002.016', 'nama' => 'Sewa Kantor/Mess',                              'tipe' => 'beban'],
            ['kode' => '6002.017', 'nama' => 'Jamuan/Entertaint',                             'tipe' => 'beban'],
            ['kode' => '6002.018', 'nama' => 'Asuransi',                                      'tipe' => 'beban'],
            ['kode' => '6002.019', 'nama' => 'RT Kantor',                                     'tipe' => 'beban'],
            ['kode' => '6002.021', 'nama' => 'Pemeliharaan Bangunan',                         'tipe' => 'beban'],
            ['kode' => '6002.022', 'nama' => 'Pemeliharaan Inventaris',                       'tipe' => 'beban'],
            ['kode' => '6002.023', 'nama' => 'Pemeliharaan Kendaraan',                        'tipe' => 'beban'],
            ['kode' => '6002.024', 'nama' => 'Pemeliharaan Mesin',                            'tipe' => 'beban'],
            ['kode' => '6002.025', 'nama' => 'Perlengkapan Kantor',                           'tipe' => 'beban'],
            ['kode' => '6002.026', 'nama' => 'Pengembangan SDM',                              'tipe' => 'beban'],
            ['kode' => '6002.027', 'nama' => 'Administrasi Kendaraan',                        'tipe' => 'beban'],
            ['kode' => '6002.028', 'nama' => 'Management Fee',                                'tipe' => 'beban'],
            ['kode' => '6002.029', 'nama' => 'BPJS Kesehatan',                                'tipe' => 'beban'],
            ['kode' => '6002.031', 'nama' => 'Pemeliharaan Sarana',                           'tipe' => 'beban'],
            ['kode' => '6002.032', 'nama' => 'BPJS Ketenagakerjaan',                          'tipe' => 'beban'],
            ['kode' => '6002.081', 'nama' => 'Selisih Pembulatan',                            'tipe' => 'beban'],
            ['kode' => '6002.082', 'nama' => 'Biaya Lain-Lain',                               'tipe' => 'beban'],

            // BEBAN BANK
            ['kode' => '6003.001', 'nama' => 'Bunga Bank',                                    'tipe' => 'beban'],
            ['kode' => '6003.002', 'nama' => 'Administrasi Bank',                             'tipe' => 'beban'],
            ['kode' => '6003.003', 'nama' => 'Provisi',                                       'tipe' => 'beban'],
            ['kode' => '6003.004', 'nama' => 'Pajak Bank',                                    'tipe' => 'beban'],
            ['kode' => '6003.005', 'nama' => 'Bunga Pemb. Pinjaman',                          'tipe' => 'beban'],
            ['kode' => '6003.006', 'nama' => 'Bunga Pinjaman PT - 0D1, PT - 0D 2 dan PRK Nobu', 'tipe' => 'beban'],

            // PENYUSUTAN
            ['kode' => '6004.005', 'nama' => 'Penyusutan Kendaraan',                          'tipe' => 'beban'],
            ['kode' => '6004.006', 'nama' => 'Penyusutan Inventaris Kantor',                  'tipe' => 'beban'],

            // TENAGA AHLI
            ['kode' => '6005.001', 'nama' => 'Biaya Notaris',                                 'tipe' => 'beban'],
            ['kode' => '6005.002', 'nama' => 'Biaya Wawancara',                               'tipe' => 'beban'],
            ['kode' => '6005.003', 'nama' => 'Insentif Petugas LPA BTN',                      'tipe' => 'beban'],

            // BIAYA PROSES
            ['kode' => '6006.001', 'nama' => 'Biaya Apraisal',                                'tipe' => 'beban'],
            ['kode' => '6006.003', 'nama' => 'Biaya Proses IMB',                              'tipe' => 'beban'],
            ['kode' => '6006.005', 'nama' => 'Biaya Proses BPHTB',                            'tipe' => 'beban'],
            ['kode' => '6006.007', 'nama' => 'Biaya Proses AJB',                              'tipe' => 'beban'],
            ['kode' => '6006.008', 'nama' => 'Biaya Proses Splitzing Sertifikat',             'tipe' => 'beban'],
            ['kode' => '6006.009', 'nama' => 'Biaya Proses KPR',                              'tipe' => 'beban'],
            ['kode' => '6006.011', 'nama' => 'Biaya PBB',                                     'tipe' => 'beban'],

            // PAJAK PPh
            ['kode' => '6020.011', 'nama' => 'Pajak PPh Psl 21 (Tahunan)',                    'tipe' => 'beban'],
            ['kode' => '6020.012', 'nama' => 'Pajak PPh Psl 23',                              'tipe' => 'beban'],
            ['kode' => '6020.013', 'nama' => 'Pajak PPh Psl 25 (Tahunan)',                    'tipe' => 'beban'],
            ['kode' => '6020.014', 'nama' => 'Denda Pajak',                                   'tipe' => 'beban'],

            // PENDAPATAN LAIN
            ['kode' => '7001.001', 'nama' => 'Pendapatan Jasa Giro & Bunga Bank',             'tipe' => 'pendapatan'],
            ['kode' => '7001.002', 'nama' => 'Pendapatan Lain-Lain',                          'tipe' => 'pendapatan'],
            ['kode' => '7001.003', 'nama' => 'Pendapatan Pembatalan',                         'tipe' => 'pendapatan'],
            ['kode' => '7001.004', 'nama' => 'Pendapatan Bunga Pinjaman',                     'tipe' => 'pendapatan'],
            ['kode' => '7001.005', 'nama' => 'Pendapatan JO',                                 'tipe' => 'pendapatan'],

            // PAJAK FINAL
            ['kode' => '8000.001', 'nama' => 'Pajak PPh Final',                               'tipe' => 'beban'],
        ];

        foreach ($accounts as $row) {
            // Parent = 4 digit pertama dari kode (mis. 1001.001 → 1001)
            $parentKode = substr($row['kode'], 0, 4);
            $parentId = $headerIdMap[$parentKode] ?? null;

            Coa::firstOrCreate(
                ['perusahaan_id' => $lmi->id, 'kode' => $row['kode']],
                [
                    'nama' => $row['nama'],
                    'tipe' => $row['tipe'],
                    'saldo_normal' => $row['saldo_normal'] ?? $defaultSaldoNormal[$row['tipe']],
                    'parent_id' => $parentId,
                    'is_header' => false,
                    'is_aktif' => true,
                ],
            );
        }
    }
}
