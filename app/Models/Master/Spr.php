<?php

namespace App\Models\Master;

use App\Models\User;
use App\Observers\SprObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

#[ObservedBy(SprObserver::class)]
class Spr extends Model
{
    protected $table = 'spr';

    /** Opsi jenis pembayaran. */
    public const JENIS_PEMBAYARAN = [
        'cash' => 'Cash',
        'cash_bertahap' => 'Cash Bertahap',
        'kpr' => 'KPR',
    ];

    /** Opsi metode UTJ. */
    public const METODE_UTJ = [
        'transfer' => 'Transfer',
        'tunai' => 'Tunai',
    ];

    /** Opsi status refund (saat pembatalan). */
    public const REFUND_STATUS = [
        'pending' => 'Pending',
        'tidak_ada_refund' => 'Tidak Dikembalikan',
        'partial' => 'Sebagian Dikembalikan',
        'full' => 'Dikembalikan Penuh',
    ];

    protected $fillable = [
        'booking_id',
        'sales_id',
        'prospect_customer_id',
        'rumah_id',
        'kategori',
        'nomor_spr',
        'tanggal_spr',
        'harga_jual',
        'biaya_tambahan',
        'ppn',
        'diskon',
        'kelebihan_tanah_m2',
        'harga_per_m2',
        'total_harga',
        'jenis_pembayaran',
        'bank_kpr_id',
        'dp_persen',
        'dp_nominal',
        'sbum',
        'tgl_cair_bum',
        'um_net',
        'nilai_kpr',
        'tgl_akad',
        'catatan_angsuran',
        'utj_nominal',
        'utj_tanggal_bayar',
        'utj_metode',
        'utj_bukti_path',
        'utj_keterangan',
        'utj_tanggal_transaksi',
        'utj_nominal_aktual',
        'utj_confirmed_by_user_id',
        'utj_confirmed_at',
        'status',
        'catatan',
        'approved_by_user_id',
        'approved_at',
        'alasan_reject',
        'alasan_pembatalan_id',
        'cancel_keterangan',
        'switched_from_spr_id',
        'switched_to_spr_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'refund_status',
        'refund_amount',
        'refund_at',
        'refund_keterangan',
        'ttd_sales_path',
        'ttd_finance_path',
        'ttd_pm_path',
        'dokumen_signed_path',
        'dokumen_signed_at',
        'dokumen_signed_by_user_id',
        'pm_approved_at',
        'pm_approved_by_user_id',
        'pm_catatan',
        // Fitur #6: Materai + TTD Digital Konsumen
        'materai_stamped_at',
        'materai_by_user_id',
        'materai_file_path',
        'konsumen_signing_link_hash',
        'konsumen_signing_link_expires_at',
        'konsumen_signed_at',
        'konsumen_ttd_path',
        'spr_finalized_at',
        'konsumen_download_link_hash',
        'konsumen_download_link_expires_at',
    ];

    protected $attributes = [
        'status' => 'submitted',
        'jenis_pembayaran' => 'kpr',
        'utj_metode' => 'transfer',
    ];

    protected $casts = [
        'tanggal_spr' => 'date',
        'utj_tanggal_bayar' => 'date',
        'utj_tanggal_transaksi' => 'date',
        'tgl_cair_bum' => 'date',
        'tgl_akad' => 'date',
        'utj_confirmed_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'pm_approved_at' => 'datetime',
        'refund_at' => 'date',
        'dokumen_signed_at' => 'datetime',
        // Fitur #6
        'materai_stamped_at' => 'datetime',
        'konsumen_signing_link_expires_at' => 'datetime',
        'konsumen_signed_at' => 'datetime',
        'spr_finalized_at' => 'datetime',
        'konsumen_download_link_expires_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'harga_jual' => 'decimal:2',
        'biaya_tambahan' => 'decimal:2',
        'ppn' => 'decimal:2',
        'diskon' => 'decimal:2',
        'kelebihan_tanah_m2' => 'decimal:2',
        'harga_per_m2' => 'decimal:2',
        'total_harga' => 'decimal:2',
        'dp_persen' => 'decimal:2',
        'dp_nominal' => 'decimal:2',
        'sbum' => 'decimal:2',
        'um_net' => 'decimal:2',
        'nilai_kpr' => 'decimal:2',
        'utj_nominal' => 'decimal:2',
        'utj_nominal_aktual' => 'decimal:2',
    ];

    // ====== Relations ======
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(Sales::class);
    }

    public function prospectCustomer(): BelongsTo
    {
        return $this->belongsTo(ProspectCustomer::class);
    }

    public function rumah(): BelongsTo
    {
        return $this->belongsTo(Rumah::class);
    }

    public function bankKpr(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_kpr_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function pmApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_approved_by_user_id');
    }

    public function utjConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utj_confirmed_by_user_id');
    }

    public function dokumenSignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dokumen_signed_by_user_id');
    }

    public function alasanPembatalan(): BelongsTo
    {
        return $this->belongsTo(AlasanPembatalan::class, 'alasan_pembatalan_id');
    }

    /** SPR ini berasal dari SPR mana (kalau ini hasil switching, arah ke SPR lama). */
    public function switchedFromSpr(): BelongsTo
    {
        return $this->belongsTo(Spr::class, 'switched_from_spr_id');
    }

    /** SPR ini pindah ke SPR mana (kalau ini voided karena switching, arah ke SPR baru). */
    public function switchedToSpr(): BelongsTo
    {
        return $this->belongsTo(Spr::class, 'switched_to_spr_id');
    }

    /**
     * Record SprSwitching yang mencatat event pindah kavling melibatkan SPR ini
     * (baik sebagai spr_lama_a/lama_b atau spr_baru_a/baru_b).
     */
    public function switching(): ?SprSwitching
    {
        return SprSwitching::query()
            ->where('spr_lama_a_id', $this->id)
            ->orWhere('spr_baru_a_id', $this->id)
            ->orWhere('spr_lama_b_id', $this->id)
            ->orWhere('spr_baru_b_id', $this->id)
            ->first();
    }

    /** Realisasi historis: kwitansi yg dulu tercatat di SPR ini sebelum dipindah karena switching. */
    public function realisasiHistoris(): Collection
    {
        $sw = $this->switching();
        if (! $sw) {
            return new Collection;
        }

        $sprBaruIds = [];
        if ($sw->spr_lama_a_id === $this->id) {
            $sprBaruIds[] = $sw->spr_baru_a_id;
        }
        if ($sw->spr_lama_b_id === $this->id) {
            $sprBaruIds[] = $sw->spr_baru_b_id;
        }
        if ($sprBaruIds === []) {
            return new Collection;
        }

        return SprRealisasiPembayaran::where('switching_id', $sw->id)
            ->whereIn('spr_id', $sprBaruIds)
            ->orderBy('tanggal_bayar')
            ->orderBy('id')
            ->get();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function terminPembayaran(): HasMany
    {
        return $this->hasMany(SprTerminPembayaran::class)
            ->orderByRaw(self::urutanJenisSql(['bf', 'um', 'sbum']).', urutan');
    }

    /**
     * Ekspresi urutan berdasarkan daftar nilai, mis. bf → um → sbum.
     *
     * FIELD() hanya ada di MySQL. SQLite yang dipakai test suite tidak mengenalnya,
     * jadi relasi ini dulu membuat setiap halaman yang memuat SPR gagal diuji.
     * Padanannya CASE WHEN, yang dipahami kedua-duanya.
     *
     * @param  array<int, string>  $nilai
     */
    public static function urutanJenisSql(array $nilai, string $kolom = 'jenis'): string
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $daftar = implode(',', array_map(fn ($v) => "'$v'", $nilai));

            return "FIELD($kolom, $daftar)";
        }

        $kasus = '';
        foreach ($nilai as $i => $v) {
            $kasus .= " WHEN '$v' THEN $i";
        }

        return "CASE $kolom$kasus ELSE ".count($nilai).' END';
    }

    public function realisasiPembayaran(): HasMany
    {
        return $this->hasMany(SprRealisasiPembayaran::class)
            ->orderBy('tanggal_bayar')
            ->orderBy('id');
    }

    public function pemberkasan(): HasOne
    {
        return $this->hasOne(SprPemberkasan::class);
    }

    /** Total pembayaran yang sudah cair dari semua termin (BF + UM cair). */
    public function totalDibayar(): float
    {
        return (float) $this->terminPembayaran()->whereNotNull('tanggal_realisasi')->sum('jumlah');
    }

    /** Persen progress pembayaran UM (BF + UM cair / um_net) */
    public function progressUm(): float
    {
        $umNet = (float) $this->um_net;
        if ($umNet <= 0) {
            return 0;
        }
        $dibayar = (float) $this->terminPembayaran()
            ->whereIn('jenis', ['bf', 'um'])
            ->whereNotNull('tanggal_realisasi')
            ->sum('jumlah');

        return round(($dibayar / $umNet) * 100, 0);
    }

    // ====== Helpers ======

    /**
     * Generate nomor SPR berikutnya format: SPR/YYYY/MM/XXXX.
     *
     * Suffix XXXX = **global counter** (bukan reset per bulan) supaya kontinu dgn
     * sistem legacy Grha Aryana yg pakai nomor global 00001..00235. Prefix YYYY/MM
     * tetap ada untuk info bulan penerbitan.
     *
     * Bisa di-boost dari data legacy lewat env LEGACY_MAX_NOMOR_SPR (lihat
     * config/legacy.php). Nomor baru = max(DB MAX, LEGACY MAX) + 1.
     *
     * Increment-safe pakai pessimistic lock (lockForUpdate). WAJIB dipanggil
     * di dalam DB::transaction — lock berlaku sampai transaction commit.
     */
    public static function generateNextNomor(?\DateTimeInterface $for = null): string
    {
        $for = $for ?: now();
        $prefix = sprintf('SPR/%s/%s/', $for->format('Y'), $for->format('m'));

        $driver = DB::connection()->getDriverName();
        // Extract suffix numeric global — MAX across all SPR, bukan per prefix.
        $suffixSql = $driver === 'mysql'
            ? "CAST(SUBSTRING_INDEX(nomor_spr, '/', -1) AS UNSIGNED)"
            : "CAST(substr(nomor_spr, length(nomor_spr) - instr(reverse(nomor_spr), '/') + 2) AS INTEGER)";

        $dbMax = (int) self::query()
            ->lockForUpdate()
            ->max(DB::raw($suffixSql));

        $lastNum = max($dbMax, (int) config('legacy.max_nomor_spr', 0));

        return $prefix.str_pad((string) ($lastNum + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Suffix nomor SPR untuk display (5 digit, mis. "00026").
     * Cetak/tampilan UI pakai ini; DB tetap simpan format lengkap SPR/YYYY/MM/XXXX.
     * Handle suffix duplicate: "SPR/2026/02/00043-A" → "00043-A".
     */
    public function getNomorDisplayAttribute(): string
    {
        return preg_match('#/(\d+)(-\w+)?$#', $this->nomor_spr, $m)
            ? str_pad($m[1], 5, '0', STR_PAD_LEFT).($m[2] ?? '')
            : $this->nomor_spr;
    }

    public function statusBadge(): array
    {
        // "Selesai" = approved + sudah ber-e-Materai (spr_finalized_at set)
        if ($this->status === 'approved' && $this->spr_finalized_at !== null) {
            return ['SELESAI', 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300'];
        }
        // "Diproses" mencakup submitted + approved yang belum final.
        // Detail step-nya sudah kelihatan dari progress bar Tahapan di kartu.
        if (in_array($this->status, ['submitted', 'approved'], true)) {
            return ['DIPROSES', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'];
        }

        return match ($this->status) {
            'draft' => ['DRAFT', 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'],
            'akad' => ['AKAD', 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300'],
            'rejected' => ['DITOLAK', 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300'],
            'cancelled' => ['DIBATALKAN', 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300'],
            default => [strtoupper($this->status), 'bg-zinc-100 text-zinc-700'],
        };
    }

    // ====== Fitur #6: TTD Digital Konsumen ======

    public function stampedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'materai_by_user_id');
    }

    /** SPR siap dikirim link TTD ke konsumen: sudah di-approve PM + belum di-sign konsumen. */
    public function isReadyForKonsumenSigning(): bool
    {
        return $this->status === 'approved'
            && $this->pm_approved_at !== null
            && $this->konsumen_signed_at === null;
    }

    /** Link TTD konsumen masih valid (belum expire + belum di-sign). */
    public function hasActiveSigningLink(): bool
    {
        return $this->konsumen_signing_link_hash !== null
            && $this->konsumen_signing_link_expires_at !== null
            && $this->konsumen_signing_link_expires_at->isFuture()
            && $this->konsumen_signed_at === null;
    }

    /** Generate hash token 40 karakter unik untuk signing link. */
    public static function generateSigningLinkHash(): string
    {
        do {
            $hash = bin2hex(random_bytes(20)); // 40 char hex
        } while (self::where('konsumen_signing_link_hash', $hash)->exists());

        return $hash;
    }

    /** Generate hash token 40 karakter unik untuk download link konsumen. */
    public static function generateDownloadLinkHash(): string
    {
        do {
            $hash = bin2hex(random_bytes(20));
        } while (self::where('konsumen_download_link_hash', $hash)->exists());

        return $hash;
    }

    /** SPR sudah final (ber-materai) — siap dikirim download link ke konsumen. */
    public function isFinal(): bool
    {
        return $this->spr_finalized_at !== null && $this->materai_stamped_at !== null;
    }

    /** Link download konsumen masih valid (belum expire). */
    public function hasActiveDownloadLink(): bool
    {
        return $this->konsumen_download_link_hash !== null
            && $this->konsumen_download_link_expires_at !== null
            && $this->konsumen_download_link_expires_at->isFuture();
    }

    /**
     * Progress step untuk indikator card DBOS (fitur #6).
     * Urutan: Verifikasi UTJ → Approval PM → TTD Konsumen → e-Materai → Selesai.
     * Materai paling akhir agar hash e-Materai Peruri tidak invalid oleh modifikasi PDF.
     */
    public function approvalSteps(): array
    {
        $verifDone = (bool) $this->utj_confirmed_at;
        $pmDone = (bool) $this->pm_approved_at;
        $konsumenDone = (bool) $this->konsumen_signed_at;
        $materaiDone = (bool) $this->materai_stamped_at;
        $finalDone = (bool) $this->spr_finalized_at;

        return [
            ['label' => 'Verifikasi Keuangan', 'done' => $verifDone,    'active' => ! $verifDone],
            ['label' => 'Approval PM',          'done' => $pmDone,        'active' => $verifDone && ! $pmDone],
            ['label' => 'TTD Konsumen',         'done' => $konsumenDone,  'active' => $pmDone && ! $konsumenDone],
            ['label' => 'e-Materai',            'done' => $materaiDone,   'active' => $konsumenDone && ! $materaiDone],
            ['label' => 'Selesai',              'done' => $finalDone,     'active' => $materaiDone && ! $finalDone],
        ];
    }
}
