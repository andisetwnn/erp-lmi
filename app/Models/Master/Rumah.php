<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Observer registered di AppServiceProvider::boot() (pattern existing project)
class Rumah extends Model
{
    protected $table = 'rumah';

    protected $fillable = [
        'proyek_id',
        'tipe_rumah_id',
        'blok',
        'nomor_unit',
        'biaya_tambahan',
        'discount',
        'ppn',
        'status',
        'progres_fisik',
        'lot',
        'subcon_id',
        'progres_updated_at',
        'progres_updated_by_user_id',
        'tanggal_launching',
        'siteplan_x',
        'siteplan_y',
        'siteplan_w',
        'siteplan_h',
        'siteplan_points',
        'updated_by_user_id',
    ];

    protected $casts = [
        'biaya_tambahan' => 'decimal:2',
        'discount' => 'decimal:2',
        'ppn' => 'decimal:2',
        'progres_fisik' => 'integer',
        'lot' => 'integer',
        'progres_updated_at' => 'datetime',
        'tanggal_launching' => 'date',
        'siteplan_x' => 'decimal:2',
        'siteplan_y' => 'decimal:2',
        'siteplan_w' => 'decimal:2',
        'siteplan_h' => 'decimal:2',
        'siteplan_points' => 'array',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function tipeRumah(): BelongsTo
    {
        return $this->belongsTo(TipeRumah::class);
    }

    /** Subkontraktor yang membangun unit ini. */
    public function subcon(): BelongsTo
    {
        return $this->belongsTo(Subcon::class);
    }

    public function virtualAccount(): HasMany
    {
        return $this->hasMany(VirtualAccount::class);
    }

    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function biayaTambahanRealisasi(): HasMany
    {
        return $this->hasMany(BiayaTambahanRealisasi::class);
    }

    public function progresLog(): HasMany
    {
        return $this->hasMany(RumahProgresLog::class)->orderByDesc('created_at');
    }

    public function progresUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'progres_updated_by_user_id');
    }

    public function bookingAktif(): ?Booking
    {
        return $this->booking()->where('status', 'aktif')->latest()->first();
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Bentuk baku nomor unit yang disimpan: tanpa nol di depan.
     *
     * "08" dan "8" adalah unit fisik yang sama, tapi kolomnya teks — tanpa
     * penyeragaman ini keduanya lolos sebagai dua baris berbeda dari
     * unique(proyek_id, blok, nomor_unit), dan satu kavling punya dua record.
     *
     * Nilai non-angka ("4A") dibiarkan apa adanya.
     */
    public static function normalNomorUnit(mixed $nomor): string
    {
        $n = trim((string) $nomor);

        return ctype_digit($n) ? (ltrim($n, '0') ?: '0') : $n;
    }

    /**
     * Kata kunci pencarian unit, disesuaikan ke bentuk simpanannya.
     *
     * Yang terbaca pengguna di layar "AF-08", yang tersimpan "AF-8". Tanpa
     * penyesuaian ini, mengetik persis seperti yang tertera justru tidak
     * menemukan apa pun — kesalahan yang menyalahkan pengguna atas bentuk
     * penyimpanan yang tidak pernah ia lihat.
     *
     * Yang dibuang hanya nol di awal kata, sesudah tanda hubung, atau sesudah
     * jokaritas "%" — sebagian pemanggil sudah membungkus kata kuncinya sebelum
     * sampai ke sini, dan "%08%" harus tetap tertangani.
     *
     * Nomor lain yang memuat nol — nomor SPR "00190", NIK — tidak ikut terpotong
     * selama fungsi ini dipakai pada kondisi nomor unit saja. Kata kunci yang
     * sama biasanya juga menyaring kolom-kolom itu, jadi jangan dipasang di
     * seluruh kondisi pencarian sekaligus.
     */
    public static function normalCari(string $kata): string
    {
        $kata = trim($kata);

        return preg_replace('/(^|-|%)0+(?=\d)/', '$1', $kata) ?? $kata;
    }

    /**
     * Penyeragaman dipasang di model, bukan di layar atau importer.
     *
     * Unit lahir dari tiga jalur — Master Rumah, importer SOP, dan importer
     * stok kavling — dan dua di antaranya dulu menyimpan versi berpadding
     * sementara yang satu lagi tidak. Selama aturannya dititipkan ke pemanggil,
     * cukup satu jalur lupa untuk melahirkan unit kembar.
     */
    protected function nomorUnit(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => self::normalNomorUnit($value),
        );
    }

    /**
     * Kode unit display, mis. "DC-08". Yang disimpan tanpa nol di depan,
     * yang ditampilkan selalu dua digit — supaya UI dan laporan operasional
     * seragam (DC-8 vs DC-08).
     */
    protected function kodeUnit(): Attribute
    {
        return Attribute::make(
            get: fn () => "{$this->blok}-".str_pad((string) $this->nomor_unit, 2, '0', STR_PAD_LEFT),
        );
    }

    /** Nomor unit dgn zero-pad 2 digit ("8" → "08"). Untuk display standalone. */
    protected function nomorUnitPadded(): Attribute
    {
        return Attribute::make(
            get: fn () => str_pad((string) $this->nomor_unit, 2, '0', STR_PAD_LEFT),
        );
    }

    /** Luas tanah = ambil dari tipe (semua unit tipe sama luasnya). */
    protected function luasTanah(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) ($this->tipeRumah?->luas_tanah ?? 0),
        );
    }

    /** Luas bangunan ikut tipe (gak berubah per-unit). */
    protected function luasBangunan(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) ($this->tipeRumah?->luas_bangunan ?? 0),
        );
    }

    /**
     * Harga jual efektif untuk unit ini = harga master tipe.
     * Field `biaya_tambahan` (hook/view/dll) diproses TERPISAH via tabel
     * `biaya_tambahan_realisasi`, tidak masuk ke SPR / total harga.
     */
    protected function hargaJual(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) ($this->tipeRumah?->harga_jual ?? 0),
        );
    }

    /**
     * Total nominal realisasi biaya tambahan yg sudah dibayar (tidak refunded).
     * Butuh eager-load biayaTambahanRealisasi supaya tidak N+1.
     */
    protected function totalRealisasiBiayaTambahan(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) $this->biayaTambahanRealisasi
                ->where('is_refunded', false)
                ->sum('jumlah'),
        );
    }

    /** Sisa biaya tambahan yg belum dibayar = biaya_tambahan − total realisasi. */
    protected function sisaBiayaTambahan(): Attribute
    {
        return Attribute::make(
            get: fn () => max(0, (float) $this->biaya_tambahan - $this->total_realisasi_biaya_tambahan),
        );
    }
}
