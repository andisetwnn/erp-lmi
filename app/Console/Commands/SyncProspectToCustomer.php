<?php

namespace App\Console\Commands;

use App\Models\Master\Customer;
use App\Models\Master\ProspectCustomer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync:prospect-to-customer {--dry-run : Tampilkan tanpa update}')]
#[Description('Backfill data Customer dari ProspectCustomer via NIK (biodata, BI, alamat, pekerjaan).')]
class SyncProspectToCustomer extends Command
{
    /** Field prospect => field customer (yang bisa di-sync). */
    private const SYNC_MAP = [
        'nama_lengkap' => 'nama_lengkap',
        'hp' => 'hp',
        'hp_2' => 'hp_2',
        'sumber' => 'sumber',
        'npwp' => 'npwp',
        'foto_ktp' => 'foto_ktp',
        'tempat_lahir' => 'tempat_lahir',
        'tanggal_lahir' => 'tanggal_lahir',
        'jenis_kelamin' => 'jenis_kelamin',
        'agama' => 'agama',
        'status_perkawinan' => 'status_perkawinan',
        'alamat' => 'alamat_ktp',
        'rt_rw' => 'rt_rw',
        'provinsi_code' => 'provinsi_code',
        'provinsi_nama' => 'provinsi_nama',
        'kota_code' => 'kota_code',
        'kota_nama' => 'kota_nama',
        'kecamatan_code' => 'kecamatan_code',
        'kecamatan_nama' => 'kecamatan_nama',
        'kelurahan_code' => 'kelurahan_code',
        'kelurahan_nama' => 'kelurahan_nama',
        'tempat_kerja_id' => 'tempat_kerja_id',
        'pekerjaan_ktp' => 'jenis_pekerjaan',
        'penghasilan_bulanan' => 'penghasilan_bulanan',
        'bank_id' => 'bank_id',
        'nomor_rekening' => 'nomor_rekening',
        'rekening_atas_nama' => 'rekening_atas_nama',
        'bi_kol' => 'bi_kol',
        'bi_dbr' => 'bi_dbr',
        'bi_keterangan' => 'bi_keterangan',
        'catatan' => 'catatan',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $prospects = ProspectCustomer::whereNotNull('nik')->get();

        $touched = 0;
        $skipped = 0;

        foreach ($prospects as $p) {
            $customer = Customer::where('nik', $p->nik)->first();
            if (! $customer) {
                $skipped++;
                continue;
            }

            $updates = [];
            foreach (self::SYNC_MAP as $prospectField => $customerField) {
                $val = $p->getAttribute($prospectField);
                if (filled($val) && blank($customer->getAttribute($customerField))) {
                    $updates[$customerField] = $val;
                }
            }

            if (empty($updates)) {
                continue;
            }

            $touched++;
            if ($dry) {
                $this->line("[DRY] {$customer->nama_lengkap} (NIK {$p->nik}): ".implode(', ', array_keys($updates)));
            } else {
                $customer->updateQuietly($updates);
            }
        }

        $this->info(($dry ? '[DRY] ' : '')."Selesai. Update: {$touched}, Skip (no customer): {$skipped}");

        return self::SUCCESS;
    }
}
