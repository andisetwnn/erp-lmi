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

                $sales = $this->simpanSales($data['dbos'], $kode, $password, [
                    'nama' => $data['nama'],
                    'jenis_sales_id' => $jenisAgent->id,
                    'sales_grup_id' => $grup->id,
                    'is_aktif' => true,
                ]);

                if (! empty($data['is_pimpinan'])) {
                    $pimpinanId = $sales->id;
                }

                $urut++;
            }

            if ($pimpinanId) {
                $grup->update(['pimpinan_id' => $pimpinanId]);
            }
        }

        // Sales solo (tidak bagian dari tim) — mis. Delta Kahuripan (DKH).
        // Ambil MAX kode SLS-* dari DB supaya tidak bentrok kalau ada sales lain yg
        // dibikin manual di luar seeder (mis. Andi).
        $maxKode = (int) preg_replace('/[^0-9]/', '', Sales::where('kode', 'like', 'SLS-%')->max('kode') ?: '0');
        $urutSolo = max($urut, $maxKode + 1);

        $solo = [
            ['nama' => 'Delta Kahuripan', 'dbos' => 'dkh'],
        ];
        foreach ($solo as $data) {
            $kode = 'SLS-'.str_pad((string) $urutSolo, 3, '0', STR_PAD_LEFT);
            $password = str_replace('.', '', $data['dbos']).'123';

            $this->simpanSales($data['dbos'], $kode, $password, [
                'nama' => $data['nama'],
                'jenis_sales_id' => $jenisAgent->id,
                'sales_grup_id' => null,
                'is_aktif' => true,
            ]);
            $urutSolo++;
        }
    }

    /**
     * Simpan sales tanpa menyentuh identitas sales yang sudah ada.
     *
     * Seeder ini juga dijalankan di server yang sudah hidup untuk menambah sales baru,
     * jadi `kode` dan `dbos_password` hanya diisi saat record pertama kali dibuat:
     *   - password ditimpa → sales yang sudah menggantinya sendiri terkunci keluar
     *   - kode ditimpa     → kode sales solo bergeser tiap kali seeder dijalankan ulang,
     *     karena nomor lanjutannya dihitung dari MAX(kode) yang ikut naik
     *
     * @param  array<string, mixed>  $atribut
     */
    private function simpanSales(string $dbosUsername, string $kode, string $passwordAwal, array $atribut): Sales
    {
        $sales = Sales::firstOrNew(['dbos_username' => $dbosUsername]);

        $sales->fill($atribut);
        if (! $sales->exists) {
            $sales->kode = $kode;
            $sales->dbos_password = $passwordAwal;
        }
        $sales->save();

        return $sales;
    }
}
