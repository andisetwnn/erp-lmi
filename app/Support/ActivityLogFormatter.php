<?php

namespace App\Support;

use App\Models\Master\Proyek;
use App\Models\Master\TipeRumah;
use Carbon\Carbon;

/**
 * Formatter untuk menampilkan changes activity_log dengan label field ramah user
 * dan value ter-format (Rupiah untuk uang, tanggal, aktif/nonaktif untuk boolean, dll).
 */
class ActivityLogFormatter
{
    /**
     * Label ramah untuk field name (mapping kolom DB → label UI).
     */
    protected const FIELD_LABEL = [
        // Rumah
        'proyek_id' => 'Proyek',
        'tipe_rumah_id' => 'Tipe Rumah',
        'blok' => 'Blok',
        'nomor_unit' => 'Nomor Unit',
        'biaya_tambahan' => 'Biaya Tambahan',
        'discount' => 'Discount',
        'ppn' => 'PPN',
        'tanggal_launching' => 'Tanggal Launching',
        // TipeRumah
        'tipe' => 'Kode Tipe',
        'nama_tipe' => 'Nama Tipe',
        'kategori' => 'Kategori',
        'luas_tanah' => 'Luas Tanah',
        'luas_bangunan' => 'Luas Bangunan',
        'harga_jual' => 'Harga Jual',
        'harga_all_in' => 'Harga All In',
        'plafon_kpr' => 'Plafon KPR',
        'biaya_administrasi' => 'Biaya Administrasi',
        'utj' => 'UTJ',
        'sbum' => 'SBUM',
        'spesifikasi' => 'Spesifikasi',
        'is_aktif' => 'Status Aktif',
    ];

    /** Field yang bertipe Rupiah (format ribuan + prefix Rp). */
    protected const MONEY_FIELDS = [
        'harga_jual', 'harga_all_in', 'plafon_kpr', 'biaya_administrasi',
        'utj', 'sbum', 'biaya_tambahan', 'discount', 'ppn',
    ];

    /** Field yang bertipe luas (append satuan m²). */
    protected const AREA_FIELDS = ['luas_tanah', 'luas_bangunan'];

    /** Field yang bertipe tanggal (format d M Y). */
    protected const DATE_FIELDS = ['tanggal_launching'];

    /** Field yang bertipe boolean (aktif/nonaktif). */
    protected const BOOL_FIELDS = ['is_aktif'];

    /** Field foreign key — nilai di-resolve ke label dari tabel referensi. */
    protected const FK_FIELDS = [
        'proyek_id' => [Proyek::class, 'nama_proyek'],
        'tipe_rumah_id' => [TipeRumah::class, 'tipe'],
    ];

    public static function labelFor(string $field): string
    {
        return self::FIELD_LABEL[$field] ?? ucwords(str_replace('_', ' ', $field));
    }

    public static function formatValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (in_array($field, self::MONEY_FIELDS, true)) {
            return 'Rp '.number_format((float) $value, 0, ',', '.');
        }

        if (in_array($field, self::AREA_FIELDS, true)) {
            $num = (float) $value;

            return rtrim(rtrim(number_format($num, 2, ',', '.'), '0'), ',').' m²';
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            try {
                return Carbon::parse($value)->translatedFormat('d M Y');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        if (in_array($field, self::BOOL_FIELDS, true)) {
            return ((bool) $value) ? 'Aktif' : 'Non-Aktif';
        }

        if (isset(self::FK_FIELDS[$field])) {
            [$modelClass, $labelColumn] = self::FK_FIELDS[$field];
            $ref = $modelClass::find($value);

            return $ref?->{$labelColumn} ? "{$ref->{$labelColumn}} (#{$value})" : "#{$value}";
        }

        return (string) $value;
    }

    /**
     * Ubah properties[changes] jadi array display:
     * [['field' => 'Harga Jual', 'from' => 'Rp 100jt', 'to' => 'Rp 150jt'], ...]
     */
    public static function formatChanges(array $changes): array
    {
        $rows = [];
        foreach ($changes as $field => $ft) {
            $rows[] = [
                'field' => self::labelFor($field),
                'from' => self::formatValue($field, $ft['from'] ?? null),
                'to' => self::formatValue($field, $ft['to'] ?? null),
            ];
        }

        return $rows;
    }
}
