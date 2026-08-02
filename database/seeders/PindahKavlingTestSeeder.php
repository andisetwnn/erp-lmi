<?php

namespace Database\Seeders;

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TipeRumah;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeder khusus test fitur Pindah Kavling (Switching) — PR 2.
 *
 * Jalankan manual:
 *   php artisan db:seed --class=PindahKavlingTestSeeder
 *
 * Yang di-seed (idempotent — aman dijalankan berulang):
 *   - 8 rumah baru (4 subsidi O-02..O-05, 4 komersial P-02..P-05) dgn biaya_tambahan
 *     bervariasi biar total_harga & um_net beda-beda (harga naik dari O-02 ke O-05).
 *   - 4 prospect customer baru (Siti, Rizki, Dedi, Wati) — assign ke sales berbeda.
 *   - 4 SPR aktif dgn variasi tingkat pembayaran UM:
 *       * Siti  — subsidi O-03 — UM baru bayar 1 dari 4 termin (~25%)
 *       * Rizki — subsidi O-05 — UM sudah bayar 3 dari 4 termin (~75%)
 *       * Dedi  — komersial P-02 — cuma UTJ cair
 *       * Wati  — komersial P-04 — cuma UTJ cair
 *   - 4 unit sisa (O-02, O-04, P-03, P-05) di-set status "available" siap jadi
 *     unit tujuan Pindah Kavling.
 *
 * Skenario yg bisa dites:
 *   [Pindah Unit lebih murah] Rizki (O-05, UM cair 16.5jt) → O-02 (um_net 7jt)
 *     → OVERPAID 9.5jt, realisasi refund_pindah auto-dibuat (pending).
 *   [Pindah Unit lebih mahal] Siti (O-03, UM cair 3jt) → O-04 (um_net 17jt)
 *     → sisa UM 14jt otomatis re-split ke 4 termin baru.
 *   [Swap 2 SPR subsidi]      Rizki ↔ Siti — silang unit + realisasi UM ikut pindah.
 *   [Pindah Unit komersial]   Dedi (P-02) → P-05 (nilai KPR beda).
 *   [Swap 2 SPR komersial]    Dedi ↔ Wati.
 */
class PindahKavlingTestSeeder extends Seeder
{
    public function run(): void
    {
        $proyek = Proyek::first();
        $tipeSubsidi = TipeRumah::where('kategori', 'subsidi')->first();
        $tipeKomersial = TipeRumah::where('kategori', 'komersial')->first();

        if (! $proyek || ! $tipeSubsidi || ! $tipeKomersial) {
            $this->command->error('Proyek/tipe rumah belum lengkap. Jalankan ProyekSeeder + TipeRumahSeeder dulu.');

            return;
        }

        $sales = Sales::orderBy('id')->take(4)->get();
        if ($sales->count() < 4) {
            $this->command->error('Butuh minimal 4 sales. Jalankan SalesSeeder dulu.');

            return;
        }

        DB::transaction(function () use ($proyek, $tipeSubsidi, $tipeKomersial, $sales) {
            // ==================== RUMAH BARU ====================
            // Subsidi (harga naik dari O-02 ke O-05 karena biaya_tambahan)
            $unitO02 = $this->upsertRumah($proyek->id, $tipeSubsidi->id, 'O', '02', 5_000_000, 'available');
            $unitO03 = $this->upsertRumah($proyek->id, $tipeSubsidi->id, 'O', '03', 10_000_000, 'booking');
            $unitO04 = $this->upsertRumah($proyek->id, $tipeSubsidi->id, 'O', '04', 15_000_000, 'available');
            $unitO05 = $this->upsertRumah($proyek->id, $tipeSubsidi->id, 'O', '05', 20_000_000, 'booking');

            // Komersial
            $unitP02 = $this->upsertRumah($proyek->id, $tipeKomersial->id, 'P', '02', 0, 'booking');
            $unitP03 = $this->upsertRumah($proyek->id, $tipeKomersial->id, 'P', '03', 5_000_000, 'available');
            $unitP04 = $this->upsertRumah($proyek->id, $tipeKomersial->id, 'P', '04', 12_000_000, 'booking');
            $unitP05 = $this->upsertRumah($proyek->id, $tipeKomersial->id, 'P', '05', 20_000_000, 'available');

            // ==================== CUSTOMER BARU ====================
            $siti = $this->upsertProspect($sales[0]->id, $proyek->id, 'Siti Aminah', '3671010402850001', '081211110001', 6_500_000);
            $rizki = $this->upsertProspect($sales[1]->id, $proyek->id, 'Rizki Pratama', '3671011503820002', '081211110002', 7_500_000);
            $dedi = $this->upsertProspect($sales[2]->id, $proyek->id, 'Dedi Kurnia', '3671010209870003', '081211110003', 15_000_000);
            $wati = $this->upsertProspect($sales[3]->id, $proyek->id, 'Wati Susanti', '3671012105900004', '081211110004', 18_000_000);

            // ==================== SPR AKTIF ====================
            // 1) Siti — subsidi O-03 — UM baru cair 1 termin (~25%)
            $sprSiti = $this->upsertSpr(
                sales: $sales[0], prospect: $siti, rumah: $unitO03, tipe: $tipeSubsidi,
                status: 'approved', tanggal: now()->subDays(30),
            );
            $this->seedTerminSubsidi($sprSiti);
            $this->seedRealisasiUtj($sprSiti, now()->subDays(29));
            $this->seedRealisasiUm($sprSiti, jumlah: 3_000_000, tanggal: now()->subDays(10));

            // 2) Rizki — subsidi O-05 — UM cair 3 termin (~75%, tinggi biar pas pindah ke unit murah jadi overpaid)
            $sprRizki = $this->upsertSpr(
                sales: $sales[1], prospect: $rizki, rumah: $unitO05, tipe: $tipeSubsidi,
                status: 'approved', tanggal: now()->subDays(60),
            );
            $this->seedTerminSubsidi($sprRizki);
            $this->seedRealisasiUtj($sprRizki, now()->subDays(59));
            $this->seedRealisasiUm($sprRizki, jumlah: 5_500_000, tanggal: now()->subDays(45));
            $this->seedRealisasiUm($sprRizki, jumlah: 5_500_000, tanggal: now()->subDays(30));
            $this->seedRealisasiUm($sprRizki, jumlah: 5_500_000, tanggal: now()->subDays(15));

            // 3) Dedi — komersial P-02 — cuma UTJ cair
            $sprDedi = $this->upsertSpr(
                sales: $sales[2], prospect: $dedi, rumah: $unitP02, tipe: $tipeKomersial,
                status: 'submitted', tanggal: now()->subDays(10),
            );
            $this->seedTerminKomersial($sprDedi);
            $this->seedRealisasiUtj($sprDedi, now()->subDays(9));

            // 4) Wati — komersial P-04 — cuma UTJ cair
            $sprWati = $this->upsertSpr(
                sales: $sales[3], prospect: $wati, rumah: $unitP04, tipe: $tipeKomersial,
                status: 'approved', tanggal: now()->subDays(20),
            );
            $this->seedTerminKomersial($sprWati);
            $this->seedRealisasiUtj($sprWati, now()->subDays(19));
        });

        $this->command->info('✓ PindahKavlingTestSeeder selesai.');
        $this->printSummary();
    }

    /** Buat/update rumah berdasarkan (proyek, blok, unit). Reset status agar seeder idempotent. */
    private function upsertRumah(int $proyekId, int $tipeId, string $blok, string $unit, int $biayaTambahan, string $status): Rumah
    {
        return Rumah::updateOrCreate(
            ['proyek_id' => $proyekId, 'blok' => $blok, 'nomor_unit' => $unit],
            [
                'tipe_rumah_id' => $tipeId,
                'biaya_tambahan' => $biayaTambahan,
                'discount' => 0,
                'ppn' => 0,
                'status' => $status,
            ],
        );
    }

    private function upsertProspect(int $salesId, int $proyekId, string $nama, string $nik, string $hp, int $penghasilan): ProspectCustomer
    {
        return ProspectCustomer::updateOrCreate(
            ['nik' => $nik],
            [
                'sales_id' => $salesId,
                'proyek_id' => $proyekId,
                'nama_lengkap' => $nama,
                'hp' => $hp,
                'sumber' => 'Walk-in',
                'tempat_lahir' => 'Depok',
                'tanggal_lahir' => '1988-01-01',
                'jenis_kelamin' => 'L',
                'agama' => 'Islam',
                'status_perkawinan' => 'Kawin',
                'pekerjaan_ktp' => 'Karyawan Swasta',
                'penghasilan_bulanan' => $penghasilan,
                'foto_ktp' => 'test/dummy-ktp.jpg',
                'alamat' => 'Jl. Test Pindah Kavling',
                'bi_kol' => '1',
                'bi_dbr' => 25.00,
                'bi_keterangan' => 'BI check bersih.',
                'status' => 'finish',
                'catatan' => 'Seed test Pindah Kavling.',
            ],
        );
    }

    /** Bikin/reuse booking + SPR aktif (skip kalau SPR utk prospect+rumah ini sudah ada & belum dibatalkan). */
    private function upsertSpr(Sales $sales, ProspectCustomer $prospect, Rumah $rumah, TipeRumah $tipe, string $status, CarbonInterface $tanggal): Spr
    {
        $existing = Spr::where('prospect_customer_id', $prospect->id)
            ->where('rumah_id', $rumah->id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->first();
        if ($existing) {
            return $existing;
        }

        $booking = Booking::firstOrCreate(
            [
                'prospect_customer_id' => $prospect->id,
                'rumah_id' => $rumah->id,
            ],
            [
                'sales_id' => $sales->id,
                'proyek_id' => $rumah->proyek_id,
                'tanggal_booking' => $tanggal->toDateString(),
                'tanggal_expired' => $tanggal->copy()->addDays(7)->toDateString(),
                'status' => 'sukses',
            ],
        );

        $kategori = $tipe->kategori;
        $hargaJual = (float) $tipe->harga_jual;
        $biayaTambahan = (float) $rumah->biaya_tambahan;
        $totalHarga = $hargaJual + $biayaTambahan;

        if ($kategori === 'komersial') {
            $sbum = 0;
            $dpNominal = 0;
            $umNet = 0;
            $nilaiKpr = $totalHarga;
        } else {
            $sbum = (float) $tipe->sbum;
            $plafonKpr = (float) $tipe->plafon_kpr;
            $nilaiKpr = $plafonKpr;
            $dpNominal = max(0, $totalHarga - $plafonKpr);
            $umNet = max(0, $dpNominal - $sbum);
        }
        $dpPersen = $totalHarga > 0 ? round(($dpNominal / $totalHarga) * 100, 2) : 0;

        $utjNominal = (float) $tipe->utj;

        return Spr::create([
            'booking_id' => $booking->id,
            'sales_id' => $sales->id,
            'prospect_customer_id' => $prospect->id,
            'rumah_id' => $rumah->id,
            'kategori' => $kategori,
            // Nomor SPR pakai bulan saat seeding (bukan tanggal_spr backdated) supaya counter
            // tidak reset dan display 4-digit di UI tidak keliatan duplikat.
            'nomor_spr' => Spr::generateNextNomor(),
            'tanggal_spr' => $tanggal->toDateString(),
            'harga_jual' => $hargaJual,
            'biaya_tambahan' => $biayaTambahan,
            'ppn' => 0,
            'diskon' => 0,
            'kelebihan_tanah_m2' => 0,
            'harga_per_m2' => 0,
            'total_harga' => $totalHarga,
            'jenis_pembayaran' => 'kpr',
            'dp_persen' => $dpPersen,
            'dp_nominal' => $dpNominal,
            'sbum' => $sbum,
            'um_net' => $umNet,
            'nilai_kpr' => $nilaiKpr,
            'utj_nominal' => $utjNominal,
            'utj_tanggal_bayar' => $tanggal->toDateString(),
            'utj_tanggal_transaksi' => $tanggal->toDateString(),
            'utj_metode' => 'transfer',
            'utj_nominal_aktual' => $utjNominal,
            'utj_bukti_path' => 'test/dummy-utj.jpg',
            'utj_confirmed_at' => $tanggal->copy()->addDay(),
            'utj_confirmed_by_user_id' => 1,
            // Semua SPR test dibuat status SELESAI (approved + PM approved + bermeterai + finalized)
            // biar bisa dipakai test fitur Pindah Kavling — yang syaratnya SPR harus SELESAI.
            'status' => 'approved',
            'approved_at' => $tanggal->copy()->addDays(2),
            'approved_by_user_id' => 1,
            'pm_approved_at' => $tanggal->copy()->addDays(3),
            'pm_approved_by_user_id' => 1,
            'materai_stamped_at' => $tanggal->copy()->addDays(4),
            'materai_by_user_id' => 1,
            'spr_finalized_at' => $tanggal->copy()->addDays(4),
            'catatan' => 'Seed test Pindah Kavling.',
        ]);
    }

    /** Jadwal termin subsidi: BF + 4 UM + SBUM (mirror pola form SPR). */
    private function seedTerminSubsidi(Spr $spr): void
    {
        if ($spr->terminPembayaran()->exists()) {
            return;
        }

        $spr->terminPembayaran()->create([
            'jenis' => 'bf',
            'urutan' => 0,
            'jumlah_jadwal' => (float) $spr->utj_nominal,
        ]);

        if ((float) $spr->um_net > 0) {
            $perTermin = round((float) $spr->um_net / 4, 0);
            $anchor = Carbon::parse($spr->tanggal_spr);
            for ($n = 1; $n <= 4; $n++) {
                $spr->terminPembayaran()->create([
                    'jenis' => 'um',
                    'urutan' => $n,
                    'tanggal_jadwal' => $anchor->copy()->addMonthsNoOverflow($n),
                    'jumlah_jadwal' => $perTermin,
                ]);
            }
        }

        if ((float) $spr->sbum > 0) {
            $spr->terminPembayaran()->create([
                'jenis' => 'sbum',
                'urutan' => 0,
                'jumlah_jadwal' => (float) $spr->sbum,
            ]);
        }
    }

    /** Jadwal termin komersial: cuma BF (UTJ). */
    private function seedTerminKomersial(Spr $spr): void
    {
        if ($spr->terminPembayaran()->exists()) {
            return;
        }

        $spr->terminPembayaran()->create([
            'jenis' => 'bf',
            'urutan' => 0,
            'jumlah_jadwal' => (float) $spr->utj_nominal,
        ]);
    }

    /** Realisasi UTJ (jenis=bf). Skip kalau sudah ada realisasi bf utk SPR ini. */
    private function seedRealisasiUtj(Spr $spr, CarbonInterface $tanggal): void
    {
        $exists = SprRealisasiPembayaran::where('spr_id', $spr->id)->where('jenis', 'bf')->exists();
        if ($exists) {
            return;
        }

        SprRealisasiPembayaran::create([
            'spr_id' => $spr->id,
            'jenis' => 'bf',
            'tanggal_bayar' => $tanggal->toDateString(),
            'jumlah' => (float) $spr->utj_nominal,
            'nomor_kwitansi' => 'KW-SEED-BF-'.$spr->id,
            'metode' => 'transfer',
            'keterangan' => 'UTJ (seed test Pindah Kavling).',
        ]);
    }

    /** Realisasi UM 1 cicilan. */
    private function seedRealisasiUm(Spr $spr, int $jumlah, CarbonInterface $tanggal): void
    {
        $urut = SprRealisasiPembayaran::where('spr_id', $spr->id)->where('jenis', 'um')->count() + 1;

        SprRealisasiPembayaran::create([
            'spr_id' => $spr->id,
            'jenis' => 'um',
            'tanggal_bayar' => $tanggal->toDateString(),
            'jumlah' => $jumlah,
            'nomor_kwitansi' => 'KW-SEED-UM-'.$spr->id.'-'.$urut,
            'metode' => 'transfer',
            'keterangan' => 'Cicilan UM ke-'.$urut.' (seed test Pindah Kavling).',
        ]);
    }

    private function printSummary(): void
    {
        $this->command->info('');
        $this->command->info('  Unit available (tujuan Pindah Kavling):');
        Rumah::with('tipeRumah')->where('status', 'available')->get()->each(function ($r) {
            $tipe = $r->tipeRumah;
            $total = (float) ($tipe?->harga_jual ?? 0) + (float) $r->biaya_tambahan;
            $this->command->line(sprintf('   • %s-%s  %-10s  total Rp %s',
                $r->blok, $r->nomor_unit, $tipe?->kategori, number_format($total)));
        });

        $this->command->info('');
        $this->command->info('  SPR aktif (siap dipindah / di-swap):');
        Spr::with(['rumah.tipeRumah', 'prospectCustomer'])
            ->whereIn('status', ['submitted', 'approved'])
            ->orderBy('id')
            ->get()
            ->each(function ($s) {
                $umCair = (float) SprRealisasiPembayaran::where('spr_id', $s->id)->where('jenis', 'um')->sum('jumlah');
                $unit = $s->rumah?->blok.'-'.$s->rumah?->nomor_unit;
                $this->command->line(sprintf('   • %s  %-15s  %s  %-10s  UM cair Rp %s / %s',
                    $s->nomor_spr,
                    $s->prospectCustomer?->nama_lengkap ?? '-',
                    $unit,
                    $s->kategori,
                    number_format($umCair),
                    number_format((float) $s->um_net),
                ));
            });
    }
}
