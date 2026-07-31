<?php

namespace Database\Seeders;

use App\Models\Master\Bank;
use App\Models\Master\Notaris;
use App\Models\Master\NotarisBiayaAjbHistory;
use Illuminate\Database\Seeder;

class NotarisSeeder extends Seeder
{
    public function run(): void
    {
        $bca = Bank::where('nama', 'like', '%BCA%')->first();
        $mandiri = Bank::where('nama', 'like', '%Mandiri%')->first();
        $bri = Bank::where('nama', 'like', '%BRI%')->first();

        $data = [
            [
                'nama' => 'H. Ahmad Saefudin, S.H., M.Kn.',
                'nik' => '3275010101700001',
                'hp' => '081234500001',
                'alamat' => 'Jl. Ir. H. Juanda No. 45, Bekasi Timur',
                'bank' => $bca,
                'norek' => '0123456789',
                'ajb_history' => [
                    ['nominal' => 1500000, 'pph' => null,    'tgl' => '2023-01-01'],
                    ['nominal' => 1700000, 'pph' => 50000,   'tgl' => '2024-01-15'],
                    ['nominal' => 1900000, 'pph' => 75000,   'tgl' => '2024-10-17'],
                ],
            ],
            [
                'nama' => 'Hj. Sri Wahyuni, S.H., M.Kn.',
                'nik' => '3275020202750002',
                'hp' => '081234500002',
                'alamat' => 'Jl. Cut Meutia No. 12, Bekasi Selatan',
                'bank' => $mandiri,
                'norek' => '1330011223344',
                'ajb_history' => [
                    ['nominal' => 1800000, 'pph' => null, 'tgl' => '2023-06-01'],
                    ['nominal' => 2000000, 'pph' => 100000, 'tgl' => '2025-01-01'],
                ],
            ],
            [
                'nama' => 'Bambang Sutrisno, S.H., M.Kn.',
                'nik' => '3275030303680003',
                'hp' => '081234500003',
                'alamat' => 'Ruko Galaxy Blok C No. 8, Bekasi Selatan',
                'bank' => $bri,
                'norek' => '7755660011223',
                'ajb_history' => [
                    ['nominal' => 1650000, 'pph' => null, 'tgl' => '2024-03-10'],
                ],
            ],
            [
                'nama' => 'Rina Wijayanti, S.H., M.Kn.',
                'nik' => '3275040404820004',
                'hp' => '081234500004',
                'alamat' => null,
                'bank' => null,
                'norek' => null,
                'ajb_history' => [],
            ],
        ];

        foreach ($data as $row) {
            $notaris = Notaris::firstOrCreate(
                ['nik' => $row['nik']],
                [
                    'nama' => $row['nama'],
                    'hp' => $row['hp'],
                    'alamat' => $row['alamat'],
                    'bank_id' => $row['bank']?->id,
                    'nomor_rekening' => $row['norek'],
                ],
            );

            foreach ($row['ajb_history'] as $h) {
                NotarisBiayaAjbHistory::firstOrCreate(
                    [
                        'notaris_id' => $notaris->id,
                        'berlaku_mulai' => $h['tgl'],
                    ],
                    [
                        'nominal_biaya_ajb' => $h['nominal'],
                        'pph_promo_ajb' => $h['pph'],
                    ],
                );
            }
        }
    }
}
