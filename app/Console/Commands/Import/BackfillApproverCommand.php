<?php

namespace App\Console\Commands\Import;

use App\Models\Master\Spr;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Isi ulang user approver pada SPR hasil import yang terlanjur kosong.
 *
 * Dokumen SPR mengambil tanda tangan dari user yang menyetujui
 * (`ttd_pm_path ?: pmApprovedBy->tanda_tangan_path`), bukan dari kolom path. Kalau
 * importer gagal menemukan user approver, dokumennya tercetak tanpa tanda tangan
 * meski tanggal persetujuannya terisi.
 *
 * Hanya mengisi kolom yang NULL dan hanya pada baris yang tanggal persetujuannya
 * sudah ada — SPR yang dibuat lewat aplikasi tidak tersentuh.
 */
class BackfillApproverCommand extends Command
{
    protected $signature = 'import:backfill-approver
        {--pm= : Username user PM (default: cari febri/febry)}
        {--finance= : Username user Finance (default: cari uli)}
        {--dry-run : Tampilkan yang akan diperbaiki tanpa menyimpan}';

    protected $description = 'Isi user approver yang kosong pada SPR hasil import agar tanda tangan tercetak';

    /** Kolom user yang diisi, dipasangkan dengan kolom tanggal penandanya. */
    private const PASANGAN_PM = [
        'approved_by_user_id' => 'approved_at',
        'pm_approved_by_user_id' => 'pm_approved_at',
        'materai_by_user_id' => 'materai_stamped_at',
    ];

    private const PASANGAN_FINANCE = [
        'utj_confirmed_by_user_id' => 'utj_confirmed_at',
    ];

    public function handle(): int
    {
        $pm = $this->resolve($this->option('pm'), ['febri', 'febry'], 'Febry');
        $finance = $this->resolve($this->option('finance'), ['uli'], 'Uli');

        $this->info('BACKFILL APPROVER SPR');

        if (! $pm || ! $finance) {
            $this->error('User approver tidak ditemukan.');
            $this->line('Username yang ada di database:');
            foreach (User::orderBy('id')->get(['id', 'name', 'username']) as $u) {
                $this->line(sprintf('  #%-3s %-28s %s', $u->id, $u->name, $u->username));
            }

            return self::FAILURE;
        }

        $this->line("  PM      : #{$pm->id} {$pm->name} (".($pm->tanda_tangan_path ? 'punya tanda tangan' : 'BELUM punya tanda tangan').')');
        $this->line("  Finance : #{$finance->id} {$finance->name} (".($finance->tanda_tangan_path ? 'punya tanda tangan' : 'BELUM punya tanda tangan').')');
        $this->newLine();

        $rencana = [];
        foreach (self::PASANGAN_PM as $kolomUser => $kolomTanggal) {
            $rencana[$kolomUser] = ['user' => $pm, 'tanggal' => $kolomTanggal];
        }
        foreach (self::PASANGAN_FINANCE as $kolomUser => $kolomTanggal) {
            $rencana[$kolomUser] = ['user' => $finance, 'tanggal' => $kolomTanggal];
        }

        $total = 0;
        foreach ($rencana as $kolomUser => $info) {
            $jml = Spr::whereNull($kolomUser)->whereNotNull($info['tanggal'])->count();
            $this->line(sprintf('  %-26s %4d baris kosong  → #%s', $kolomUser, $jml, $info['user']->id));
            $total += $jml;
        }

        if ($total === 0) {
            $this->newLine();
            $this->info('Tidak ada yang perlu diisi.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('DRY-RUN: tidak ada perubahan disimpan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rencana) {
            foreach ($rencana as $kolomUser => $info) {
                // updateQuietly: ini perbaikan atribusi, bukan perubahan status —
                // jangan memicu observer maupun log aktivitas.
                Spr::whereNull($kolomUser)
                    ->whereNotNull($info['tanggal'])
                    ->update([$kolomUser => $info['user']->id]);
            }
        });

        $this->newLine();
        $this->info("✓ $total kolom approver diisi. Dokumen SPR sekarang menampilkan tanda tangan.");

        if (! $pm->tanda_tangan_path) {
            $this->newLine();
            $this->warn('Catatan: user PM belum punya berkas tanda tangan, jadi dokumennya');
            $this->warn('tetap kosong sampai tanda tangannya diunggah lewat menu profil.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $kandidat
     */
    private function resolve(?string $pilihan, array $kandidat, string $awalNama): ?User
    {
        if ($pilihan) {
            return User::where('username', $pilihan)->first();
        }

        return User::whereIn('username', $kandidat)->first()
            ?? User::where('name', 'like', $awalNama.'%')->first();
    }
}
