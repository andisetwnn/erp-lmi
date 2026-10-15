<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sertifikat satu unit rumah.
 *
 * Lahir saat pengajuan dibuat dengan nomor masih kosong, lalu dilengkapi
 * setelah terbit. Statusnya dihitung dari isian, bukan disimpan — lihat
 * catatan di migration-nya.
 */
class Sertifikat extends Model
{
    protected $table = 'sertifikat';

    protected $fillable = [
        'rumah_id',
        'sertifikat_pengajuan_id',
        'luas_diajukan',
        'luas_sertifikat',
        'nomor_sertifikat',
        'nib',
        'hgb_induk',
        'tanggal_terbit',
        'file_path',
        'file_original_name',
        'sertifikat_serah_terima_id',
        'catatan',
        'updated_by_user_id',
    ];

    protected $casts = [
        'luas_diajukan' => 'decimal:2',
        'luas_sertifikat' => 'decimal:2',
        'tanggal_terbit' => 'date',
    ];

    /** Urutan tahap, dipakai saringan dan lencana status. */
    public const STATUS = [
        'diajukan' => 'Diajukan',
        'terbit' => 'Terbit',
        'di_bank' => 'Di Bank',
    ];

    public function rumah(): BelongsTo
    {
        return $this->belongsTo(Rumah::class);
    }

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(SertifikatPengajuan::class, 'sertifikat_pengajuan_id');
    }

    public function serahTerima(): BelongsTo
    {
        return $this->belongsTo(SertifikatSerahTerima::class, 'sertifikat_serah_terima_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** Sertifikatnya sudah terbit — nomornya yang menentukan, bukan tanggalnya. */
    public function sudahTerbit(): bool
    {
        return filled($this->nomor_sertifikat);
    }

    public function sudahDiBank(): bool
    {
        return $this->sertifikat_serah_terima_id !== null;
    }

    /** Status dibaca dari belakang: yang paling jauh menentukan posisinya. */
    public function status(): string
    {
        return match (true) {
            $this->sudahDiBank() => 'di_bank',
            $this->sudahTerbit() => 'terbit',
            default => 'diajukan',
        };
    }

    public function labelStatus(): string
    {
        return self::STATUS[$this->status()] ?? $this->status();
    }

    /**
     * Luas yang berlaku: yang terbit kalau ada, kalau belum ya yang diajukan.
     * Hasil ukur BPN kerap berbeda dari yang diajukan.
     */
    public function luasBerlaku(): ?float
    {
        return $this->luas_sertifikat !== null
            ? (float) $this->luas_sertifikat
            : ($this->luas_diajukan !== null ? (float) $this->luas_diajukan : null);
    }
}
