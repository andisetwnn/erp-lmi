<?php

namespace App\Models\Akunting;

use App\Models\Master\Perusahaan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Jurnal extends Model
{
    protected $table = 'jurnal';

    protected $fillable = [
        'perusahaan_id',
        'tanggal',
        'no_bukti',
        'tipe',
        'kategori_bukti',
        'keterangan',
        'sumber_type',
        'sumber_id',
        'status',
        'posted_by_user_id',
        'posted_at',
        'reversed_from_jurnal_id',
        'created_by_user_id',
    ];

    /** 6 kategori bukti — untuk auto-generate prefix no bukti. */
    public const KATEGORI_BUKTI = [
        'BANK' => 'Bank (transaksi via rekening bank)',
        'KAS' => 'Kas (transaksi kas fisik)',
        'PENJ' => 'Penjualan (pengakuan pendapatan akad)',
        'HPP' => 'HPP (harga pokok penjualan rumah)',
        'AKM' => 'Akumulasi Penyusutan (AJP bulanan)',
        'RJE' => 'Reversing Journal Entry (jurnal balik AJP)',
    ];

    /**
     * Induk akun yang menentukan kategori, urut dari yang paling menentukan.
     * Jurnal kas + bank sekaligus (pengisian kas, belanja dibayar dari bank) ikut BANK.
     */
    private const KATEGORI_DARI_INDUK_AKUN = ['1501' => 'AKM', '1002' => 'BANK', '1001' => 'KAS'];

    /**
     * Kategori yang sesuai dengan akun-akun di jurnal — cocok dengan 3.042 dari 3.050 jurnal
     * buku Keuangan Des 2025–Sep 2026. Null kalau tidak ada akun kas, bank, atau akumulasi
     * penyusutan (PENJ, HPP, RJE tetap dipilih sendiri).
     *
     * @param  iterable<string>  $kodeAkun
     */
    public static function kategoriDariAkun(iterable $kodeAkun): ?string
    {
        $kode = collect($kodeAkun)->filter()->map(fn ($k) => (string) $k);

        foreach (self::KATEGORI_DARI_INDUK_AKUN as $induk => $kategori) {
            if ($kode->contains(fn ($k) => str_starts_with($k, $induk.'.'))) {
                return $kategori;
            }
        }

        return null;
    }

    protected $casts = [
        'tanggal' => 'date',
        'posted_at' => 'datetime',
    ];

    public function perusahaan(): BelongsTo
    {
        return $this->belongsTo(Perusahaan::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function detail(): HasMany
    {
        return $this->hasMany(JurnalDetail::class);
    }

    /** Berkas pendukung: invoice, bukti transfer, kwitansi. */
    public function lampiran(): HasMany
    {
        return $this->hasMany(JurnalLampiran::class)->latest('id');
    }

    /** Polymorphic sumber: SPR / Kwitansi / dll (kalau auto-generated). */
    public function sumber(): MorphTo
    {
        return $this->morphTo();
    }

    /** Jurnal asal kalau ini reversal. */
    public function reversedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_from_jurnal_id');
    }

    /** Jurnal reversal yg pernah dibuat untuk membalik ini. */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_from_jurnal_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function totalDebet(): float
    {
        return (float) $this->detail->sum('debet');
    }

    public function totalKredit(): float
    {
        return (float) $this->detail->sum('kredit');
    }

    public function isBalanced(): bool
    {
        return abs($this->totalDebet() - $this->totalKredit()) < 0.01;
    }
}
