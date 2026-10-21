<?php

namespace Database\Seeders;

use App\Models\Master\PromoPenjualan;
use App\Models\Master\Proyek;
use App\Services\PromoPenjualanService;
use Illuminate\Database\Seeder;

/**
 * Promo penjualan Oktober 2026 dari lembar rencana promo Marketing — bagian
 * rumah subsidi. Lokasi keduanya "All Project", jadi tiap promo dibuat sekali
 * per proyek untuk semua unit.
 *
 * 1. KEJORA 10.10 — booking fee spesial Rp101.000, 10–11 Okt. Target audience
 *    di lembarnya (usia 23–45, pendapatan, belum punya rumah) hanya sasaran
 *    pemasaran, bukan syarat: jenisnya booking fee tetap untuk semua konsumen.
 *    Voucher belanja untuk sales tidak ikut — itu insentif sales, bukan promo
 *    konsumen.
 *
 * 2. MUMPUNI: Muda Punya Rumah Sendiri — booking fee sesuai usia, 28–31 Okt.
 *    Di sini usia memang syarat: 23 sampai 40 tahun, booking fee = usia ×
 *    Rp10.000 (usia 23 → Rp230.000). Di luar rentang itu booking fee normal.
 *    Tabel generasi Casa Samala di lembar yang sama bukan untuk rumah subsidi,
 *    jadi tidak ikut.
 *
 * Aman diulang — promo yang sudah ada diperbarui, bukan digandakan.
 *
 * TIDAK dipanggil DatabaseSeeder — jalankan manual:
 *   php artisan db:seed --class=PromoOktober2026Seeder
 */
class PromoOktober2026Seeder extends Seeder
{
    public function run(PromoPenjualanService $service): void
    {
        foreach (Proyek::orderBy('id')->get() as $proyek) {
            foreach (self::daftarPromo() as $promo) {
                $tersimpan = $service->simpan(
                    data: [
                        'proyek_id' => $proyek->id,
                        'nama' => $promo['nama'],
                        'jenis' => $promo['jenis'],
                        'booking_fee_tetap' => $promo['booking_fee_tetap'] ?? null,
                        'berlaku_mulai' => $promo['mulai'],
                        'berlaku_sampai' => $promo['sampai'],
                        'dasar_usia' => 'usia',
                        'semua_unit' => true,
                        'keterangan' => $promo['tagline'],
                    ],
                    rumahIds: [],
                    tingkat: $promo['tingkat'] ?? [],
                    userId: null,
                    promo: PromoPenjualan::where('proyek_id', $proyek->id)->where('nama', $promo['nama'])->first(),
                );

                $this->command?->info("{$proyek->nama_proyek}: {$tersimpan->nama} · {$promo['mulai']} s/d {$promo['sampai']} · {$tersimpan->labelJenis()}");
            }
        }
    }

    /**
     * @return list<array{nama: string, jenis: string, mulai: string, sampai: string, tagline: string, booking_fee_tetap?: int, tingkat?: list<array{dari: int, sampai: int, booking_fee: int, label: ?string}>}>
     */
    private static function daftarPromo(): array
    {
        return [
            [
                'nama' => 'Promo KEJORA 10.10',
                'jenis' => 'booking_fee_tetap',
                'mulai' => '2026-10-10',
                'sampai' => '2026-10-11',
                'tagline' => 'Kejar KEJORA nya, Kejutan Oktober Beli Rumah',
                'booking_fee_tetap' => 101_000,
            ],
            [
                'nama' => 'MUMPUNI: Muda Punya Rumah Sendiri',
                'jenis' => 'booking_fee',
                'mulai' => '2026-10-28',
                'sampai' => '2026-10-31',
                'tagline' => 'Rumah di Masa Muda, Masa Depan Bahagia!',
                'tingkat' => collect(range(23, 40))
                    ->map(fn (int $usia) => ['dari' => $usia, 'sampai' => $usia, 'booking_fee' => $usia * 10_000, 'label' => null])
                    ->all(),
            ],
        ];
    }
}
