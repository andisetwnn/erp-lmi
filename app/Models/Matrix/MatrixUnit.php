<?php

namespace App\Models\Matrix;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatrixUnit extends Model
{
    protected $table = 'matrix_unit';

    /**
     * Urutan tahap mengikuti perjalanan berkas, bukan susunan sheet atau abjad:
     * berkas dilengkapi dulu, baru wawancara, lalu SP3K terbit, sampai akad.
     * Empat terakhir di luar alur itu — tunai, tertolak, batal, dan belum terjual.
     */
    public const URUTAN_SEKSI = [
        'BERKAS BELUM',
        'WAWANCARA',
        'ACC SP3K',
        'SUDAH AKAD',
        'CASH',
        'TOLAK BANK',
        'BATAL',
        'STOCK',
    ];

    public const LABEL_KATEGORI = [
        'mikro' => 'Mikro (100%)',
        'makro_a' => 'Makro A (≥ 50%)',
        'makro_b' => 'Makro B (< 50%)',
        'non_lot' => 'Non Lot',
        'akad' => 'Sudah Akad',
    ];

    /**
     * Kisaran progres yang dijanjikan judul tiap sheet.
     *
     * Sebagian unit tidak diisi angka progresnya di berkas — hampir semuanya di
     * Makro B. Kalau disaring memakai angka yang memang tidak ada, unit-unit itu
     * hilang dari semua tab. Judul sheet-nya sendiri sudah menyebut kisarannya,
     * jadi itu yang dipakai sebagai gantinya.
     */
    public const BAND_PROGRES = [
        'mikro' => [100.0, 100.0],
        'makro_a' => [50.0, 99.99],
        'makro_b' => [0.0, 49.99],
        'non_lot' => [0.0, 100.0],
        'akad' => [0.0, 100.0],
    ];

    /**
     * Sheet mana saja yang kisarannya bersinggungan dengan rentang yang diminta.
     *
     * @return list<string>
     */
    public static function kategoriDalamRentang(float $min, float $maks): array
    {
        return array_values(array_keys(array_filter(
            self::BAND_PROGRES,
            fn (array $band) => $band[1] >= $min && $band[0] <= $maks,
        )));
    }

    protected $fillable = [
        'matrix_import_id', 'kategori', 'seksi', 'urutan',
        'nama', 'blok', 'nomor_unit', 'sales', 'tanggal',
        'luas_bangunan', 'luas_tanah', 'tipe', 'lot', 'subcon', 'progres',
        'ajb_notaris', 'total_harga_jual', 'kpr', 'bba', 'sbum',
        'total_um', 'tgl_bayar_terakhir', 'akumulasi_um', 'persen_um', 'sisa_um',
        'bm', 'wcr', 'sp3k', 'exp_sp3k', 'lpa', 'rencana_akad',
        'bank_ko', 'bank_fl', 'bank_ta',
        'legal_imb', 'legal_slf', 'legal_hgb',
        'teknik_sa', 'teknik_ja', 'teknik_kw', 'teknik_sb',
        'sikumbang', 'ktp', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'luas_bangunan' => 'integer',
            'luas_tanah' => 'integer',
            'progres' => 'decimal:3',
            'ajb_notaris' => 'decimal:2',
            'total_harga_jual' => 'decimal:2',
            'kpr' => 'decimal:2',
            'bba' => 'decimal:2',
            'sbum' => 'decimal:2',
            'total_um' => 'decimal:2',
            'akumulasi_um' => 'decimal:2',
            'persen_um' => 'decimal:4',
            'sisa_um' => 'decimal:2',
            'tanggal' => 'date',
            'tgl_bayar_terakhir' => 'date',
            'bm' => 'date',
            'wcr' => 'date',
            'sp3k' => 'date',
            'exp_sp3k' => 'date',
            'lpa' => 'date',
            'rencana_akad' => 'date',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(MatrixImport::class, 'matrix_import_id');
    }

    public function scopeKategori(Builder $query, string|array $kategori): Builder
    {
        return $query->whereIn('kategori', (array) $kategori);
    }

    /** "AE-11" — dipakai di judul baris laporan. */
    public function getKodeUnitAttribute(): string
    {
        return trim(($this->blok ?? '').'-'.($this->nomor_unit ?? ''), '-');
    }
}
