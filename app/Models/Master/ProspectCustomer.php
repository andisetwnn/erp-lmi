<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProspectCustomer extends Model
{
    protected $table = 'prospect_customer';

    /** Sumber info pilihan (untuk dropdown). */
    public const SUMBER_OPTIONS = [
        'Instagram',
        'TikTok',
        'Facebook',
        'WhatsApp Broadcast',
        'Referral',
        'Pameran',
        'Brosur',
        'Iklan Online',
        'Walk-in',
        'Kantor',
        'Telemarketing',
        'Lainnya',
    ];

    protected $fillable = [
        'sales_id',
        'proyek_id',
        'nama_lengkap',
        'hp',
        'hp_2',
        'sumber',
        'nik',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'agama',
        'status_perkawinan',
        'pekerjaan_ktp',
        'penghasilan_bulanan',
        'npwp',
        'foto_ktp',
        'tempat_kerja_id',
        'bank_id',
        'nomor_rekening',
        'rekening_atas_nama',
        'bi_kol',
        'bi_dbr',
        'bi_keterangan',
        'alamat',
        'rt_rw',
        'provinsi_code',
        'provinsi_nama',
        'kota_code',
        'kota_nama',
        'kecamatan_code',
        'kecamatan_nama',
        'kelurahan_code',
        'kelurahan_nama',
        'status',
        'catatan',
    ];

    protected $attributes = [
        'status' => 'cold',
    ];

    protected $casts = [
        'bi_dbr' => 'decimal:2',
        'penghasilan_bulanan' => 'decimal:2',
        'tanggal_lahir' => 'date',
    ];

    public function sales(): BelongsTo
    {
        return $this->belongsTo(Sales::class);
    }

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function statusLog(): HasMany
    {
        return $this->hasMany(ProspectCustomerStatusLog::class)->orderByDesc('created_at');
    }

    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function tempatKerja(): BelongsTo
    {
        return $this->belongsTo(TempatKerja::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function kontakDarurat(): HasMany
    {
        return $this->hasMany(ProspectCustomerKontakDarurat::class);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'cold' => 'blue',
            'warm' => 'amber',
            'hot' => 'red',
            'finish' => 'green',
            'archive' => 'stone',
            default => 'zinc',
        };
    }

    public function alamatLengkap(): string
    {
        $parts = array_filter([
            $this->alamat,
            $this->kelurahan_nama,
            $this->kecamatan_nama,
            $this->kota_nama,
            $this->provinsi_nama,
        ]);

        return implode(', ', $parts);
    }

    public function biCheckingComplete(): bool
    {
        return $this->bi_kol !== null && $this->bi_dbr !== null;
    }

    /**
     * Cek apakah prospect sudah lengkap untuk naik ke status FINISH.
     */
    public function readyForFinish(): bool
    {
        return empty($this->missingForFinish());
    }

    /**
     * Checklist lengkap untuk status FINISH.
     * Return: [ ['label' => '...', 'ok' => bool], ... ]
     * Single source of truth — dipakai di modal, toast, dan validation.
     */
    public function finishChecklist(): array
    {
        $kontakCount = $this->relationLoaded('kontakDarurat')
            ? $this->kontakDarurat->count()
            : $this->kontakDarurat()->count();

        return [
            ['label' => 'NIK',                     'ok' => ! empty($this->nik)],
            ['label' => 'NPWP',                    'ok' => ! empty($this->npwp)],
            ['label' => 'Foto KTP',                'ok' => ! empty($this->foto_ktp)],
            ['label' => 'Alamat KTP',              'ok' => ! empty($this->alamat)],
            ['label' => 'BI Checking KOL',         'ok' => $this->bi_kol !== null],
            ['label' => 'BI Checking DBR',         'ok' => $this->bi_dbr !== null],
            ['label' => 'Perusahaan',              'ok' => ! empty($this->tempat_kerja_id)],
            ['label' => 'Kontak Darurat (min 3)',  'ok' => $kontakCount >= 3],
        ];
    }

    /**
     * Field yang masih perlu dilengkapi untuk FINISH.
     */
    public function missingForFinish(): array
    {
        return collect($this->finishChecklist())
            ->reject(fn ($c) => $c['ok'])
            ->pluck('label')
            ->all();
    }
}
