<?php

namespace App\Observers;

use App\Models\Master\Customer;
use App\Models\Master\ProspectCustomer;

class ProspectCustomerObserver
{
    /**
     * Field prospect → field customer yang di-sync (subset yg overlap).
     */
    private const SYNC_MAP = [
        'nama_lengkap' => 'nama_lengkap',
        'hp' => 'hp',
        'hp_2' => 'hp_2',
        'sumber' => 'sumber',
        'npwp' => 'npwp',
        'foto_ktp' => 'foto_ktp',
        // Biodata KTP
        'tempat_lahir' => 'tempat_lahir',
        'tanggal_lahir' => 'tanggal_lahir',
        'jenis_kelamin' => 'jenis_kelamin',
        'agama' => 'agama',
        'status_perkawinan' => 'status_perkawinan',
        // Alamat (Prospect pakai 'alamat', Customer pakai 'alamat_ktp')
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
        // Pekerjaan (Prospect pakai 'pekerjaan_ktp', Customer pakai 'jenis_pekerjaan')
        'tempat_kerja_id' => 'tempat_kerja_id',
        'pekerjaan_ktp' => 'jenis_pekerjaan',
        'penghasilan_bulanan' => 'penghasilan_bulanan',
        // Rekening
        'bank_id' => 'bank_id',
        'nomor_rekening' => 'nomor_rekening',
        'rekening_atas_nama' => 'rekening_atas_nama',
        // BI Checking
        'bi_kol' => 'bi_kol',
        'bi_dbr' => 'bi_dbr',
        'bi_keterangan' => 'bi_keterangan',
        // Catatan
        'catatan' => 'catatan',
    ];

    public function updated(ProspectCustomer $prospect): void
    {
        if (! $prospect->nik) {
            return;
        }

        $changed = array_intersect_key($prospect->getChanges(), self::SYNC_MAP);
        if (empty($changed)) {
            return;
        }

        $customer = Customer::where('nik', $prospect->nik)->first();
        if (! $customer) {
            return;
        }

        $updates = [];
        foreach ($changed as $prospectField => $newValue) {
            $customerField = self::SYNC_MAP[$prospectField];
            // Hanya isi kalau customer belum punya value (jangan overwrite yg sudah diedit manual di master)
            if (blank($customer->getAttribute($customerField))) {
                $updates[$customerField] = $newValue;
            }
        }

        if (! empty($updates)) {
            $customer->updateQuietly($updates);
        }
    }

    public function created(ProspectCustomer $prospect): void
    {
        // Untuk record baru, cek apakah sudah ada Customer dgn NIK sama — kalau iya sync juga
        if (! $prospect->nik) {
            return;
        }

        $customer = Customer::where('nik', $prospect->nik)->first();
        if (! $customer) {
            return;
        }

        $updates = [];
        foreach (self::SYNC_MAP as $prospectField => $customerField) {
            $value = $prospect->getAttribute($prospectField);
            if (filled($value) && blank($customer->getAttribute($customerField))) {
                $updates[$customerField] = $value;
            }
        }

        if (! empty($updates)) {
            $customer->updateQuietly($updates);
        }
    }
}
