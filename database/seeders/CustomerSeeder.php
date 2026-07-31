<?php

namespace Database\Seeders;

use App\Models\Master\Bank;
use App\Models\Master\Customer;
use App\Models\Master\Proyek;
use App\Models\Master\TempatKerja;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $grhaAryana = Proyek::where('kode_surat', 'GA')->first();
        if (! $grhaAryana) {
            return;
        }

        $bca = Bank::where('nama', 'like', '%BCA%')->first();
        $bni = Bank::where('nama', 'like', '%BNI%')->first();
        $btn = Bank::where('nama', 'like', '%BTN%')->where('nama', 'not like', '%Syariah%')->first();
        $mandiri = Bank::where('nama', 'like', '%Mandiri%')->first();

        // Helper buat ambil tempat kerja by nama (dari seeder TempatKerjaSeeder)
        $sinarMas = TempatKerja::where('nama', 'PT Sinar Mas Tbk')->first();
        $dinasPendidikan = TempatKerja::where('nama', 'Dinas Pendidikan Kota Depok')->first();
        $telkom = TempatKerja::where('nama', 'PT Telkom Indonesia')->first();
        $astra = TempatKerja::where('nama', 'PT Astra Honda Motor')->first();

        $customers = [
            // Karyawan + pasangan + anak kontak darurat
            [
                'nama_lengkap' => 'Rizky Pratama Hidayat',
                'nik' => '3275010101900001',
                'npwp' => '12.345.678.9-012.000',
                'hp' => '081234600001',
                'alamat_ktp' => 'Jl. Mawar No. 12 RT 003/RW 005, Sukamaju, Cilodong, Depok',
                'jenis_pekerjaan' => 'Karyawan Swasta',
                'tempat_kerja' => $sinarMas,
                'nama_pasangan' => 'Anisa Maharani',
                'nik_pasangan' => '3275040404920002',
                'bank' => $bca,
                'norek' => '1234567890',
                'kontak' => [
                    ['nama' => 'H. Ahmad Hidayat', 'hubungan' => 'orang_tua', 'nomor_telepon' => '081234500111'],
                    ['nama' => 'Anisa Maharani', 'hubungan' => 'pasangan', 'nomor_telepon' => '081234600002'],
                ],
            ],
            // PNS
            [
                'nama_lengkap' => 'Sri Wahyuningsih, S.Pd.',
                'nik' => '3275020202850003',
                'npwp' => '23.456.789.0-123.000',
                'hp' => '081234600003',
                'alamat_ktp' => 'Jl. Melati Blok B No. 5, Cilodong, Depok',
                'jenis_pekerjaan' => 'PNS / ASN',
                'tempat_kerja' => $dinasPendidikan,
                'nama_pasangan' => 'Bambang Sutomo',
                'nik_pasangan' => '3275030303830004',
                'bank' => $bni,
                'norek' => '0099887701',
                'kontak' => [
                    ['nama' => 'Bambang Sutomo', 'hubungan' => 'pasangan', 'nomor_telepon' => '081234600004'],
                ],
            ],
            // Wiraswasta dengan badan usaha (tidak ada tempat_kerja)
            [
                'nama_lengkap' => 'Hendra Wijaya',
                'nik' => '3275050505780005',
                'npwp' => '34.567.890.1-234.000',
                'hp' => '081234600005',
                'alamat_ktp' => 'Jl. Anggrek No. 22, Sukmajaya, Depok',
                'jenis_pekerjaan' => 'Wiraswasta',
                'tempat_kerja' => null,
                'nama_perusahaan_bu' => 'Wijaya Mandiri',
                'bentuk_badan_usaha' => 'cv',
                'bidang_usaha' => 'Konstruksi & Material Bangunan',
                'alamat_perusahaan' => 'Ruko Margonda Blok C-12, Depok',
                'no_telp_kantor' => '02178901234',
                'nama_pasangan' => 'Linda Kartika',
                'nik_pasangan' => '3275060606800006',
                'bank' => $btn,
                'norek' => '00044010300012345',
                'kontak' => [
                    ['nama' => 'Linda Kartika', 'hubungan' => 'pasangan', 'nomor_telepon' => '081234600006'],
                    ['nama' => 'Hendra Wijaya Jr.', 'hubungan' => 'anak', 'nomor_telepon' => '081234600007'],
                    ['nama' => 'Dimas Wijaya', 'hubungan' => 'saudara', 'nomor_telepon' => '081234600008'],
                ],
            ],
            // Single, belum nikah — tempat kerja sama dgn customer lain
            [
                'nama_lengkap' => 'Putri Handayani',
                'nik' => '3275070707950007',
                'npwp' => null,
                'hp' => '081234600009',
                'alamat_ktp' => 'Jl. Kenanga No. 8 RT 001/RW 002, Cilodong, Depok',
                'jenis_pekerjaan' => 'Karyawan Swasta',
                'tempat_kerja' => $telkom,
                'bank' => $mandiri,
                'norek' => '1330077001234',
                'kontak' => [
                    ['nama' => 'Sri Maryani', 'hubungan' => 'orang_tua', 'nomor_telepon' => '081234600010'],
                    ['nama' => 'Rina Handayani', 'hubungan' => 'saudara', 'nomor_telepon' => '081234600011'],
                ],
            ],
            // Pasangan, info minim
            [
                'nama_lengkap' => 'Joko Susanto',
                'nik' => '3275080808880008',
                'npwp' => null,
                'hp' => '081234600012',
                'alamat_ktp' => 'Kp. Sawangan RT 005/RW 003, Cinangka, Depok',
                'jenis_pekerjaan' => 'Karyawan Swasta',
                'tempat_kerja' => $astra,
                'nama_pasangan' => 'Mariana Putri',
                'nik_pasangan' => '3275090909900009',
                'bank' => null,
                'norek' => null,
                'kontak' => [],
            ],
        ];

        foreach ($customers as $data) {
            $kontakList = $data['kontak'];
            unset($data['kontak']);
            $bank = $data['bank'] ?? null;
            unset($data['bank']);
            $norek = $data['norek'] ?? null;
            unset($data['norek']);
            $tempatKerja = $data['tempat_kerja'] ?? null;
            unset($data['tempat_kerja']);

            $customer = Customer::firstOrCreate(
                ['nik' => $data['nik']],
                array_merge($data, [
                    'proyek_id' => $grhaAryana->id,
                    'tempat_kerja_id' => $tempatKerja?->id,
                    'bank_id' => $bank?->id,
                    'nomor_rekening' => $norek,
                    'rekening_atas_nama' => $norek ? $data['nama_lengkap'] : null,
                ]),
            );

            foreach ($kontakList as $k) {
                $customer->kontakDarurat()->firstOrCreate(
                    ['nama' => $k['nama'], 'hubungan' => $k['hubungan']],
                    ['nomor_telepon' => $k['nomor_telepon']],
                );
            }
        }
    }
}
