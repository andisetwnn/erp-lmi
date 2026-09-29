<?php

namespace App\Console\Commands;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lengkapi izin yang belum ada, tanpa menyentuh yang sudah diberikan.
 *
 * RolePermissionSeeder memakai syncPermissions: ia menegakkan matriks di kode
 * apa adanya, sehingga izin yang diberikan manual di lingkungan berjalan ikut
 * tercabut, dan beberapa role serta izin lama dihapus. Untuk produksi yang
 * isinya sudah disesuaikan tangan, itu terlalu keras.
 *
 * Perintah ini hanya menambahkan: izin yang belum ada dibuat, lalu diberikan ke
 * role yang seharusnya memilikinya. Tidak ada yang dicabut, tidak ada yang
 * dihapus — apa yang sudah ada di sana tetap seperti sekarang.
 *
 * Tanpa --commit hanya melaporkan.
 */
class LengkapiIzinCommand extends Command
{
    protected $signature = 'izin:lengkapi
        {--role= : Batasi ke satu role saja}
        {--commit : Simpan. Tanpa ini hanya melaporkan}';

    protected $description = 'Tambahkan izin yang belum ada ke role, tanpa mencabut yang sudah diberikan';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $izinBaru = $this->izinBelumTerdaftar();
        $tambahan = $this->tambahanPerRole();

        $this->laporkan($izinBaru, $tambahan);

        if ($izinBaru === [] && $tambahan === []) {
            $this->newLine();
            $this->info('Semua izin sudah lengkap. Tidak ada yang perlu ditambahkan.');

            return self::SUCCESS;
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->comment('Belum ada yang disimpan. Tambahkan --commit kalau laporan di atas sudah sesuai.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($izinBaru, $tambahan) {
            foreach ($izinBaru as $nama) {
                Permission::findOrCreate($nama, 'web');
            }

            foreach ($tambahan as $namaRole => $izin) {
                // givePermissionTo, bukan syncPermissions — yang sudah ada tetap.
                Role::findOrCreate($namaRole, 'web')->givePermissionTo($izin);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();
        $this->info(count($izinBaru).' izin dibuat, '.array_sum(array_map('count', $tambahan)).' pemberian ke role ditambahkan.');
        $this->comment('Pengguna yang sedang login perlu keluar lalu masuk lagi.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function izinBelumTerdaftar(): array
    {
        $ada = Permission::where('guard_name', 'web')->pluck('name')->all();

        return array_values(array_diff(RolePermissionSeeder::daftarIzin(), $ada));
    }

    /**
     * Izin yang seharusnya dimiliki tiap role tapi belum diberikan.
     *
     * @return array<string, list<string>>
     */
    private function tambahanPerRole(): array
    {
        $saring = (string) $this->option('role');
        $hasil = [];

        foreach (RolePermissionSeeder::matriksRole() as $namaRole => $seharusnya) {
            if ($saring !== '' && $namaRole !== $saring) {
                continue;
            }

            $role = Role::where('name', $namaRole)->where('guard_name', 'web')->first();
            $dimiliki = $role ? $role->permissions->pluck('name')->all() : [];

            $kurang = array_values(array_diff($seharusnya, $dimiliki));

            if ($kurang !== []) {
                $hasil[$namaRole] = $kurang;
            }
        }

        return $hasil;
    }

    /**
     * @param  list<string>  $izinBaru
     * @param  array<string, list<string>>  $tambahan
     */
    private function laporkan(array $izinBaru, array $tambahan): void
    {
        $this->newLine();
        $this->line('  Izin belum terdaftar : '.count($izinBaru));
        $this->line('  Role perlu tambahan  : '.count($tambahan));

        if ($izinBaru !== []) {
            $this->newLine();
            $this->line('  Izin yang akan dibuat:');
            foreach ($izinBaru as $nama) {
                $this->line("    + $nama");
            }
        }

        foreach ($tambahan as $namaRole => $izin) {
            $this->newLine();
            $this->line("  $namaRole — ".count($izin).' izin ditambahkan:');
            foreach ($izin as $nama) {
                $this->line("    + $nama");
            }
        }
    }
}
