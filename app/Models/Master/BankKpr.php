<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank tempat berkas KPR diurus, beserta biaya proses akad per unitnya.
 *
 * Bedanya dengan {@see Bank}: yang ini cabang ("BTN KC Cibinong", "BTN
 * Syariah"), bukan penerbit rekening. Lihat catatan di migration-nya.
 */
class BankKpr extends Model
{
    protected $table = 'bank_kpr';

    protected $fillable = [
        'kode',
        'nama',
        'biaya_proses_akad',
        'lpa_wajib',
        'is_aktif',
        'keterangan',
        'updated_by_user_id',
    ];

    protected $casts = [
        'biaya_proses_akad' => 'decimal:2',
        'lpa_wajib' => 'boolean',
        'is_aktif' => 'boolean',
    ];

    protected $attributes = [
        'is_aktif' => true,
    ];

    public function rencanaAkad(): HasMany
    {
        return $this->hasMany(RencanaAkad::class, 'bank_kpr_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Daftar kode → nama untuk isian pilihan.
     *
     * Menggantikan konstanta yang dulu ditulis di dalam kode, supaya menambah
     * bank cukup lewat halaman master tanpa deploy ulang.
     *
     * @return array<string, string>
     */
    public static function pilihan(bool $hanyaAktif = true): array
    {
        return static::query()
            ->when($hanyaAktif, fn ($q) => $q->where('is_aktif', true))
            ->orderBy('nama')
            ->pluck('nama', 'kode')
            ->all();
    }

    /** Tarifnya sudah diisi atau belum — kosong bukan berarti gratis. */
    public function tarifDiketahui(): bool
    {
        return $this->biaya_proses_akad !== null;
    }

    /**
     * Seluruh isi master, diingat selama satu permintaan.
     *
     * Dipakai pemeriksaan per baris di tabel pemberkasan; tanpa ini satu
     * halaman berisi 25 berkas menembak database 25 kali untuk daftar yang
     * isinya cuma lima baris dan tidak berubah di tengah permintaan.
     *
     * @return \Illuminate\Support\Collection<string, static>
     */
    public static function terindeks(): \Illuminate\Support\Collection
    {
        return once(fn () => static::all()->keyBy('kode'));
    }

    /** Bank ini mensyaratkan LPA atau tidak. Kode asing dianggap tidak. */
    public static function wajibLpa(?string $kode): bool
    {
        return $kode !== null && (bool) static::terindeks()->get($kode)?->lpa_wajib;
    }

    /**
     * Kode bank yang mensyaratkan LPA — untuk menyaring di sisi database.
     *
     * @return list<string>
     */
    public static function kodeWajibLpa(): array
    {
        return static::terindeks()->where('lpa_wajib', true)->keys()->all();
    }
}
