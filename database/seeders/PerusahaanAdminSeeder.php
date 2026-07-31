<?php

namespace Database\Seeders;

use App\Models\Master\Perusahaan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PerusahaanAdminSeeder extends Seeder
{
    public function run(): void
    {
        Perusahaan::updateOrCreate(
            ['kode_surat' => 'LMI'],
            [
                'nama' => 'PT Langit Membangun Indonesia',
                'logo' => null,
                'alamat' => null,
                'no_telepon' => null,
            ],
        );

        // User admin (password: password) — via User::updateOrCreate karena bikin baru
        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Super Admin LMI',
                'email' => 'admin@lmi.test',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_aktif' => true,
            ],
        );
        if (! $admin->hasRole('super-admin')) {
            $admin->assignRole('super-admin');
        }

        // Real users PT LMI — password hash asli dari production, insert via DB
        // supaya tidak double-hash oleh User model cast.
        $realUsers = [
            [
                'name' => 'Febry Ferdinan',
                'email' => 'febri@lmi.com',
                'username' => 'febri',
                'password' => '$2y$12$o4a0Mclz4Q8niR5tCCnSrOdLF7NTudTNAasq2uiWmeOZ5xw5aaqsO',
                'tanda_tangan_path' => 'tanda-tangan/user/2-1783487043.png',
                'role' => 'project-manager',
            ],
            [
                'name' => 'Butet Uli Artha Panjaitan',
                'email' => 'uli@lmi.com',
                'username' => 'uli',
                'password' => '$2y$12$Xbyj58/1xTqYAatuJfvCRe86.S.ZeNKnUnv6/o8E2f9aFeMBAfN3m',
                'tanda_tangan_path' => 'tanda-tangan/user/3-1783486596.png',
                'role' => 'finance',
            ],
            [
                'name' => 'Septia Harzani',
                'email' => 'septia@lmi.com',
                'username' => 'septia',
                'password' => '$2y$12$9JxosBMlg1zQY5j6tOtG7.TiysRQqRQuVzSjiHqkNqiW7juI.snRC',
                'tanda_tangan_path' => null,
                'role' => 'finance',
            ],
            [
                'name' => 'Sunardi K Gunawan',
                'email' => 'sunardi@lmi.com',
                'username' => 'sunardi',
                'password' => '$2y$12$G4Gs9tcHjmGgvHJJLyaVCu18Jrj2mmvl5JaayVkhE5LCJotHtYpO2',
                'tanda_tangan_path' => null,
                'role' => 'direktur',
            ],
        ];

        foreach ($realUsers as $data) {
            $existing = User::where('username', $data['username'])->first();
            if (! $existing) {
                // Direct insert supaya password hash tidak di-re-hash
                $id = DB::table('users')->insertGetId([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'username' => $data['username'],
                    'password' => $data['password'],
                    'tanda_tangan_path' => $data['tanda_tangan_path'],
                    'is_aktif' => true,
                    'email_verified_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $user = User::find($id);
            } else {
                $user = $existing;
            }

            if (! $user->hasRole($data['role'])) {
                $user->assignRole($data['role']);
            }
        }
    }
}
