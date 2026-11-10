<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permintaan Pembelian (PR). Status & transisinya dijaga PembelianService.
 */
class PermintaanPembelian extends Model
{
    protected $table = 'permintaan_pembelian';

    /** Departemen peminta — label saja di versi pertama (belum ada anggaran per departemen). */
    public const DEPARTEMEN = ['Teknik', 'Marketing', 'Keuangan', 'Legal', 'Umum'];

    public const LABEL_STATUS = [
        'draft' => 'Draft',
        'diajukan' => 'Menunggu review FAT',
        'ditinjau' => 'Menunggu persetujuan direktur',
        'disetujui' => 'Disetujui',
        'ditolak' => 'Ditolak',
    ];

    public const WARNA_STATUS = [
        'draft' => 'zinc',
        'diajukan' => 'amber',
        'ditinjau' => 'violet',
        'disetujui' => 'emerald',
        'ditolak' => 'rose',
    ];

    protected $fillable = [
        'nomor',
        'tanggal',
        'proyek_id',
        'departemen',
        'keperluan',
        'tanggal_dibutuhkan',
        'status',
        'alasan',
        'lampiran_path',
        'lampiran_nama',
        'diajukan_at',
        'diajukan_by_user_id',
        'ditinjau_at',
        'ditinjau_by_user_id',
        'disetujui_at',
        'disetujui_by_user_id',
        'ditolak_at',
        'ditolak_by_user_id',
        'created_by_user_id',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'tanggal_dibutuhkan' => 'date',
        'diajukan_at' => 'datetime',
        'ditinjau_at' => 'datetime',
        'disetujui_at' => 'datetime',
        'ditolak_at' => 'datetime',
    ];

    public function item(): HasMany
    {
        return $this->hasMany(PermintaanPembelianItem::class)->orderBy('id');
    }

    public function pesanan(): HasMany
    {
        return $this->hasMany(PesananPembelian::class)->orderBy('id');
    }

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function diajukanBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_by_user_id');
    }

    public function ditinjauBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditinjau_by_user_id');
    }

    public function disetujuiBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_by_user_id');
    }

    public function ditolakBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditolak_by_user_id');
    }

    /**
     * `disetujui_*` hanya terisi oleh direktur. PR yang nilainya cukup disetujui
     * FAT berstatus disetujui dengan kolom itu kosong.
     */
    public function disetujuiDirektur(): bool
    {
        return $this->disetujui_at !== null;
    }

    public function bolehDiubah(): bool
    {
        return $this->status === 'draft';
    }

    public function labelStatus(): string
    {
        return self::LABEL_STATUS[$this->status] ?? $this->status;
    }

    public function warnaStatus(): string
    {
        return self::WARNA_STATUS[$this->status] ?? 'zinc';
    }

    /** "Kantor pusat" bila PR tidak untuk proyek tertentu. */
    public function namaLokasi(): string
    {
        return $this->proyek?->nama_proyek ?? 'Kantor pusat';
    }

    /** Masih ada barang yang belum dipesan, jadi PO baru masih bisa diterbitkan. Butuh relasi item.itemPesanan.pesanan. */
    public function masihBisaDipesan(): bool
    {
        return $this->item->contains(fn (PermintaanPembelianItem $i) => $i->sisaBelumDipesan() > 0);
    }

    public function totalPerkiraan(): float
    {
        return (float) $this->item->sum(fn (PermintaanPembelianItem $i) => (float) $i->jumlah * (float) $i->harga_perkiraan);
    }
}
