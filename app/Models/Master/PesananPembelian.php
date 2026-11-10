<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase Order (PO). Status bayar & status barang dihitung dari pembayaran dan
 * penerimaan; kolom status hanya siklus dokumen (terbit / selesai / batal).
 * PO yang ditutup (supplier tidak sanggup mengirim sisanya) berstatus selesai
 * dengan ditutup_at terisi; jumlah dan nilai semulanya ada di kolom *_awal.
 */
class PesananPembelian extends Model
{
    protected $table = 'pesanan_pembelian';

    public const LABEL_STATUS = [
        'terbit' => 'Berjalan',
        'selesai' => 'Selesai',
        'batal' => 'Batal',
    ];

    protected $fillable = [
        'nomor',
        'tanggal',
        'permintaan_pembelian_id',
        'supplier_id',
        'tanggal_kirim',
        'syarat_bayar',
        'ppn_terpisah',
        'ppn_persen',
        'subtotal',
        'ppn',
        'total',
        'total_awal',
        'status',
        'catatan',
        'alasan_batal',
        'alasan_tutup',
        'ditutup_at',
        'ditutup_by_user_id',
        'created_by_user_id',
    ];

    protected $attributes = [
        'status' => 'terbit',
        'ppn_terpisah' => false,
    ];

    protected $casts = [
        'tanggal' => 'date',
        'tanggal_kirim' => 'date',
        'ppn_terpisah' => 'boolean',
        'ppn_persen' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'ppn' => 'decimal:2',
        'total' => 'decimal:2',
        'total_awal' => 'decimal:2',
        'ditutup_at' => 'datetime',
    ];

    public function item(): HasMany
    {
        return $this->hasMany(PesananPembelianItem::class)->orderBy('id');
    }

    public function permintaan(): BelongsTo
    {
        return $this->belongsTo(PermintaanPembelian::class, 'permintaan_pembelian_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(PembayaranPembelian::class)->orderBy('tanggal')->orderBy('id');
    }

    /** Uang kelebihan bayar yang sudah dikembalikan supplier. */
    public function pengembalian(): HasMany
    {
        return $this->hasMany(PengembalianPembelian::class)->orderBy('tanggal')->orderBy('id');
    }

    public function penerimaan(): HasMany
    {
        return $this->hasMany(PenerimaanBarang::class)->orderBy('tanggal')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function ditutupBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditutup_by_user_id');
    }

    /** Ditutup sebelum semua barang datang karena supplier tidak sanggup memenuhi sisanya. */
    public function ditutup(): bool
    {
        return $this->ditutup_at !== null;
    }

    /**
     * Nilai barang yang sudah datang, termasuk PPN bila dicantumkan terpisah —
     * menjadi nilai PO kalau PO ditutup sekarang.
     */
    public function nilaiBarangDatang(): float
    {
        $subtotal = round((float) $this->item->sum(fn (PesananPembelianItem $i) => (float) $i->jumlah_diterima * (float) $i->harga), 2);

        return $subtotal + ($this->ppn_terpisah ? round($subtotal * (float) $this->ppn_persen / 100, 2) : 0.0);
    }

    /**
     * Uang yang sudah keluar melebihi nilai PO dan belum dikembalikan supplier —
     * muncul setelah PO ditutup karena barangnya tidak terkirim semua.
     */
    public function kelebihanBayar(): float
    {
        return max(0, round($this->totalDibayarBersih() - (float) $this->total, 2));
    }

    /** Semua pembayaran ke supplier, sebelum dikurangi pengembalian. */
    public function totalDibayar(): float
    {
        return (float) $this->pembayaran->sum('jumlah');
    }

    public function totalDikembalikan(): float
    {
        return (float) $this->pengembalian->sum('jumlah');
    }

    /** Uang yang benar-benar dipakai untuk PO ini: dibayar dikurangi yang dikembalikan supplier. */
    public function totalDibayarBersih(): float
    {
        return round($this->totalDibayar() - $this->totalDikembalikan(), 2);
    }

    public function sisaBayar(): float
    {
        return max(0, round((float) $this->total - $this->totalDibayarBersih(), 2));
    }

    /** belum / sebagian / lunas */
    public function statusBayar(): string
    {
        return match (true) {
            $this->totalDibayar() <= 0 => 'belum',
            $this->totalDibayarBersih() + 0.005 < (float) $this->total => 'sebagian',
            default => 'lunas',
        };
    }

    /** belum / sebagian / lengkap */
    public function statusBarang(): string
    {
        $dipesan = (float) $this->item->sum('jumlah');
        $diterima = (float) $this->item->sum('jumlah_diterima');

        return match (true) {
            $diterima <= 0 => 'belum',
            $diterima + 0.005 < $dipesan => 'sebagian',
            default => 'lengkap',
        };
    }

    public function labelStatus(): string
    {
        if ($this->ditutup()) {
            return 'Ditutup';
        }

        return self::LABEL_STATUS[$this->status] ?? $this->status;
    }
}
