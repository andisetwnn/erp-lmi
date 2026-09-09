<?php

namespace App\Console\Commands\Master;

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\TipeRumah;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Melengkapi master rumah dari daftar stok kavling siteplan.
 *
 * Sebagian kavling di siteplan belum pernah dimasukkan ke master, sehingga Total
 * Rencana Kavling di dashboard direksi lebih kecil dari kenyataan dan semua
 * persentase yang bersandar padanya ikut meleset.
 *
 * Tanpa --commit perintah ini hanya melaporkan. Jumlah unit yang ditambahkan
 * mengubah seluruh angka dashboard, jadi harus bisa dilihat dulu sebelum terjadi.
 */
class ImportStockKavling extends Command
{
    protected $signature = 'master:import-stock-kavling
        {--file=database/data/stock-kavling.csv : Berkas CSV relatif ke root project}
        {--proyek= : Kode atau nama proyek. Wajib kalau proyeknya lebih dari satu}
        {--status=draft : Status unit yang dibuat}
        {--commit : Simpan. Tanpa ini hanya melaporkan}';

    protected $description = 'Lengkapi master rumah dari daftar stok kavling siteplan';

    public function handle(): int
    {
        $path = $this->cariBerkas((string) $this->option('file'));

        if (! is_file($path)) {
            $this->error("Berkas tidak ditemukan: $path");

            return self::FAILURE;
        }

        $proyek = $this->pilihProyek();

        if (! $proyek instanceof Proyek) {
            return self::FAILURE;
        }

        $status = (string) $this->option('status');

        if (! in_array($status, ['draft', 'available'], true)) {
            $this->error('Status hanya boleh draft atau available.');

            return self::FAILURE;
        }

        $daftar = $this->bacaCsv($path);

        if ($daftar === []) {
            $this->error('Berkasnya kosong atau judul kolomnya tidak sesuai (blok,nomor,tipe,catatan).');

            return self::FAILURE;
        }

        $tipeMap = $this->petaTipe();
        $sudahAda = $this->unitYangAda($proyek->id);

        $akanDibuat = [];
        $lewati = [];
        $tipeTakDikenal = [];

        foreach ($daftar as $baris) {
            $kunci = $baris['blok'].'-'.ltrim($baris['nomor'], '0');

            if (isset($sudahAda[$kunci])) {
                $lewati[$kunci] = $sudahAda[$kunci];

                continue;
            }

            $tipe = $tipeMap[$baris['tipe']] ?? null;

            if (! $tipe) {
                $tipeTakDikenal[$baris['tipe']][] = $kunci;

                continue;
            }

            $akanDibuat[] = $baris + ['tipe_rumah_id' => $tipe->id, 'tipe_nama' => $tipe->tipe];
        }

        $this->laporkan($proyek, $daftar, $sudahAda, $lewati, $akanDibuat, $tipeTakDikenal, $status);

        if ($tipeTakDikenal !== []) {
            $this->newLine();
            $this->error('Ada tipe yang tidak dikenali. Perbaiki dulu berkasnya atau tambahkan tipenya di master — tidak ada yang disimpan.');

            return self::FAILURE;
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->comment('Belum ada yang disimpan. Tambahkan --commit kalau laporan di atas sudah sesuai.');

            return self::SUCCESS;
        }

        if ($akanDibuat === []) {
            $this->info('Tidak ada unit baru untuk ditambahkan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($akanDibuat, $proyek, $status) {
            foreach ($akanDibuat as $u) {
                Rumah::create([
                    'proyek_id' => $proyek->id,
                    'tipe_rumah_id' => $u['tipe_rumah_id'],
                    'blok' => $u['blok'],
                    // Nomor diseragamkan dua digit mengikuti mayoritas isi master.
                    'nomor_unit' => str_pad(ltrim($u['nomor'], '0'), 2, '0', STR_PAD_LEFT),
                    'status' => $status,
                ]);
            }
        });

        $this->newLine();
        $this->info(sprintf('%d unit ditambahkan dengan status %s.', count($akanDibuat), $status));

        return self::SUCCESS;
    }

    /**
     * Jalur boleh relatif terhadap root project atau mutlak — berkasnya kadang
     * ditaruh di luar folder aplikasi waktu dijalankan di server.
     */
    private function cariBerkas(string $jalur): string
    {
        return is_file($jalur) ? $jalur : base_path($jalur);
    }

    private function pilihProyek(): ?Proyek
    {
        $kunci = $this->option('proyek');

        if ($kunci) {
            $proyek = Proyek::where('kode', $kunci)->orWhere('nama_proyek', 'like', "%$kunci%")->first();

            if (! $proyek) {
                $this->error("Proyek tidak ketemu: $kunci");

                return null;
            }

            return $proyek;
        }

        $semua = Proyek::orderBy('nama_proyek')->get();

        if ($semua->count() === 1) {
            return $semua->first();
        }

        $this->error('Ada lebih dari satu proyek. Sebutkan yang mana lewat --proyek:');

        foreach ($semua as $p) {
            $this->line("  - {$p->kode} · {$p->nama_proyek}");
        }

        return null;
    }

    /**
     * @return list<array{blok: string, nomor: string, tipe: string, catatan: string}>
     */
    private function bacaCsv(string $path): array
    {
        $mentah = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $baris = array_map(fn (string $b): array => str_getcsv($b, ',', '"', ''), $mentah);
        $judul = array_map('strtolower', array_map('trim', array_shift($baris) ?: []));

        if (array_slice($judul, 0, 3) !== ['blok', 'nomor', 'tipe']) {
            return [];
        }

        $hasil = [];

        foreach ($baris as $b) {
            if (trim((string) ($b[0] ?? '')) === '') {
                continue;
            }

            $hasil[] = [
                'blok' => strtoupper(trim((string) $b[0])),
                'nomor' => trim((string) ($b[1] ?? '')),
                'tipe' => str_replace(' ', '', trim((string) ($b[2] ?? ''))),
                'catatan' => trim((string) ($b[3] ?? '')),
            ];
        }

        return $hasil;
    }

    /**
     * Label di berkas ("30/60", "21/78") dicocokkan ke nama tipe di master
     * ("Arjuna 30/60", "Nakula 21/78"). Label yang cocok ke lebih dari satu tipe
     * sengaja dibuang — lebih baik berhenti daripada salah menetapkan tipe.
     *
     * @return array<string, TipeRumah>
     */
    private function petaTipe(): array
    {
        $peta = [];

        foreach (TipeRumah::all() as $tipe) {
            $nama = str_replace(' ', '', (string) $tipe->tipe);

            if (preg_match('#[0-9]+\+?/[0-9]+#', $nama, $cocok) !== 1) {
                continue;
            }

            $label = $cocok[0];
            $peta[$label] = array_key_exists($label, $peta) ? null : $tipe;
        }

        return array_filter($peta);
    }

    /**
     * @return array<string, string> kunci "BLOK-NOMOR" tanpa nol depan => status
     */
    private function unitYangAda(int $proyekId): array
    {
        return Rumah::where('proyek_id', $proyekId)
            ->get(['blok', 'nomor_unit', 'status'])
            ->mapWithKeys(fn ($r) => [strtoupper($r->blok).'-'.ltrim($r->nomor_unit, '0') => $r->status])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $daftar
     * @param  array<string, string>  $sudahAda
     * @param  array<string, string>  $lewati
     * @param  list<array<string, mixed>>  $akanDibuat
     * @param  array<string, list<string>>  $tipeTakDikenal
     */
    private function laporkan(Proyek $proyek, array $daftar, array $sudahAda, array $lewati, array $akanDibuat, array $tipeTakDikenal, string $status): void
    {
        $this->newLine();
        $this->line("  Proyek           : {$proyek->nama_proyek}");
        $this->line('  Unit di master   : '.count($sudahAda));
        $this->line('  Baris di berkas  : '.count($daftar));
        $this->line('  Sudah ada        : '.count($lewati));
        $this->line('  Akan ditambahkan : '.count($akanDibuat)." (status $status)");
        $this->line('  Total sesudahnya : '.(count($sudahAda) + count($akanDibuat)));

        if ($akanDibuat !== []) {
            $this->newLine();
            $this->line('  Yang akan ditambahkan, per tipe:');

            foreach (collect($akanDibuat)->groupBy('tipe_nama') as $namaTipe => $unit) {
                $this->line(sprintf('    %-16s %2d unit', $namaTipe, $unit->count()));
                $this->line('      '.$unit->map(fn ($u) => $u['blok'].'-'.$u['nomor'])->implode(', '));
            }
        }

        $berCatatan = collect($akanDibuat)->filter(fn ($u) => $u['catatan'] !== '');

        if ($berCatatan->isNotEmpty()) {
            $this->newLine();
            // Tabel rumah belum punya kolom keterangan, jadi catatan di berkas
            // hanya ditampilkan di sini — tidak ikut tersimpan.
            $this->line('  Punya catatan di berkas (TIDAK ikut tersimpan):');
            foreach ($berCatatan as $u) {
                $this->line(sprintf('    %-8s %s', $u['blok'].'-'.$u['nomor'], $u['catatan']));
            }
        }

        if ($lewati !== []) {
            $this->newLine();
            $this->line('  Sudah ada di master, dilewati:');

            // preserveKeys: tanpa ini kode unitnya hilang dan yang tercetak cuma nomor urut.
            foreach (collect($lewati)->groupBy(fn ($s) => $s, true) as $st => $unit) {
                $this->line(sprintf('    %-10s %2d unit : %s', $st, $unit->count(), implode(', ', array_keys($unit->all()))));
            }
        }

        foreach ($tipeTakDikenal as $label => $unit) {
            $this->newLine();
            $this->line(sprintf('  Tipe "%s" tidak ada di master — %d unit: %s', $label, count($unit), implode(', ', $unit)));
        }
    }
}
