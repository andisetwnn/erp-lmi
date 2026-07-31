<?php

namespace Database\Seeders;

use App\Models\Master\JenisSales;
use App\Models\Master\Sales;
use App\Models\Master\SalesGrup;
use Illuminate\Database\Seeder;

class SalesSeeder extends Seeder
{
    public function run(): void
    {
        $jenisAgent = JenisSales::firstOrCreate(['nama' => 'Agent']);

        // Definisi tim + anggotanya. Pimpinan = anggota pertama tiap tim.
        $tims = [
            'Tim Agus' => [
                ['nama' => 'Agus Solehudin', 'dbos' => 'agus.solehudin', 'is_pimpinan' => true],
                ['nama' => 'Hendra Maryono', 'dbos' => 'hendra.maryono'],
                ['nama' => 'Muhamad Ramdan', 'dbos' => 'muhamad.ramdan'],
                ['nama' => 'Arifin',         'dbos' => 'arifin'],
            ],
            'Tim Delon' => [
                ['nama' => 'Delon',         'dbos' => 'delon',       'is_pimpinan' => true],
                ['nama' => 'Ade Saputra',    'dbos' => 'ade.saputra'],
                ['nama' => 'Ridha Mustafa',  'dbos' => 'ridha.mustafa'],
                ['nama' => 'Nur Fitriana',   'dbos' => 'nur.fitriana'],
            ],
        ];

        $urut = 1;

        foreach ($tims as $namaGrup => $anggotaList) {
            $grup = SalesGrup::firstOrCreate(['nama' => $namaGrup]);
            $pimpinanId = null;

            foreach ($anggotaList as $data) {
                $kode = 'SLS-'.str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
                $password = str_replace('.', '', $data['dbos']).'123';

                $sales = Sales::updateOrCreate(
                    ['dbos_username' => $data['dbos']],
                    [
                        'kode' => $kode,
                        'nama' => $data['nama'],
                        'jenis_sales_id' => $jenisAgent->id,
                        'sales_grup_id' => $grup->id,
                        'is_aktif' => true,
                        'dbos_password' => $password,
                    ],
                );

                if (! empty($data['is_pimpinan'])) {
                    $pimpinanId = $sales->id;
                }

                $urut++;
            }

            if ($pimpinanId) {
                $grup->update(['pimpinan_id' => $pimpinanId]);
            }
        }
    }
}
