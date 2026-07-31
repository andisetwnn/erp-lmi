<?php

namespace Database\Seeders;

use App\Models\Master\JenisSales;
use App\Models\Master\Sales;
use Illuminate\Database\Seeder;

class SalesSeeder extends Seeder
{
    public function run(): void
    {
        // Semua sales GRHA ARYANA jenisnya Agent. Grup belum digunakan (nullable).
        $jenisAgent = JenisSales::firstOrCreate(['nama' => 'Agent']);

        // ---- SALES ----
        // Kode auto-generate SLS-XXX. Data riil dari sistem lama GRHA ARYANA.
        // Password DBOS pattern: <nama_lower>123 (mis. arifin → arifin123).
        $sales = [
            ['kode' => 'SLS-001', 'nama' => 'Arifin', 'dbos' => 'arifin'],
            ['kode' => 'SLS-002', 'nama' => 'Ana',    'dbos' => 'ana'],
            ['kode' => 'SLS-003', 'nama' => 'Dkh',    'dbos' => 'dkh'],
            ['kode' => 'SLS-004', 'nama' => 'Ridho',  'dbos' => 'ridho'],
            ['kode' => 'SLS-005', 'nama' => 'Ramdan', 'dbos' => 'ramdan'],
            ['kode' => 'SLS-006', 'nama' => 'Hendra', 'dbos' => 'hendra'],
            ['kode' => 'SLS-007', 'nama' => 'Delon',  'dbos' => 'delon'],
            ['kode' => 'SLS-008', 'nama' => 'Bima',   'dbos' => 'bima'],
            ['kode' => 'SLS-009', 'nama' => 'Viktor', 'dbos' => 'viktor'],
        ];

        foreach ($sales as $row) {
            $password = strtolower($row['dbos']).'123';

            // Dedup priority: kode → dbos_username.
            $s = Sales::where('kode', $row['kode'])->first();
            if (! $s) {
                $s = Sales::where('dbos_username', $row['dbos'])->first();
            }

            if (! $s) {
                Sales::create([
                    'kode' => $row['kode'],
                    'nama' => $row['nama'],
                    'jenis_sales_id' => $jenisAgent->id,
                    'sales_grup_id' => null,
                    'is_aktif' => true,
                    'dbos_username' => $row['dbos'],
                    'dbos_password' => $password,
                ]);
            } else {
                // Existing → refresh jenis + password + kosongkan grup
                $s->update([
                    'jenis_sales_id' => $jenisAgent->id,
                    'sales_grup_id' => null,
                    'dbos_password' => $password,
                ]);
            }
        }
    }
}
