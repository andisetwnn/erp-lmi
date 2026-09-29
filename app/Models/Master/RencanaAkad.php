<?php

namespace App\Models\Master;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RencanaAkad extends Model
{
    protected $table = 'rencana_akad';

    protected $fillable = [
        'proyek_id',
        'nomor',
        'tanggal_rencana',
        'tanggal_fix',
        'bank_kpr_id',
        'notaris_id',
        'status',
        'diajukan_at',
        'diajukan_by_user_id',
        'diketahui_at',
        'diketahui_by_user_id',
        'disetujui_at',
        'disetujui_by_user_id',
        'direksi_link_generated_at',
        'direksi_ttd_path',
        'direksi_setuju_ip',
        'direksi_setuju_user_agent',
        'fix_at',
        'fix_by_user_id',
        'alasan_tolak',
        'catatan',
        'created_by_user_id',
    ];

    /**
     * Default milik model, bukan cuma milik kolom database. Tanpa ini, objek yang
     * baru dibuat punya status null sampai di-refresh — dan status null membuat
     * pemeriksaan alur diam-diam meleset.
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $casts = [
        'tanggal_rencana' => 'date',
        'tanggal_fix' => 'date',
        'diajukan_at' => 'datetime',
        'diketahui_at' => 'datetime',
        'disetujui_at' => 'datetime',
        'direksi_link_generated_at' => 'datetime',
        'fix_at' => 'datetime',
    ];

    /** Status yang isinya masih boleh diubah. */
    public const STATUS_TERBUKA = ['draft'];

    /** Status yang berarti unitnya sedang dipegang rencana ini. */
    public const STATUS_MENGUNCI = ['draft', 'diajukan', 'diketahui', 'fix'];

    public const LABEL_STATUS = [
        'draft' => 'Draft',
        'diajukan' => 'Diajukan',
        'diketahui' => 'Diketahui',
        'fix' => 'Selesai',
        'batal' => 'Batal',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    /**
     * Bank KPR tempat berkasnya diurus — bukan master `bank` yang berisi
     * penerbit rekening. Biaya proses akad per unit diambil dari sini.
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(BankKpr::class, 'bank_kpr_id');
    }

    public function notaris(): BelongsTo
    {
        return $this->belongsTo(Notaris::class);
    }

    public function unit(): HasMany
    {
        return $this->hasMany(RencanaAkadUnit::class)->orderBy('urutan')->orderBy('id');
    }

    public function biayaSesi(): HasMany
    {
        return $this->hasMany(RencanaAkadBiayaSesi::class)->orderBy('urutan')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function diajukanBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_by_user_id');
    }

    public function diketahuiBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diketahui_by_user_id');
    }

    public function disetujuiBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_by_user_id');
    }

    public function fixBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fix_by_user_id');
    }

    public function bolehDiubah(): bool
    {
        return in_array($this->status, self::STATUS_TERBUKA, true);
    }

    public function sudahFix(): bool
    {
        return $this->status === 'fix';
    }

    /**
     * Tanggal yang berlaku: yang sudah pasti kalau ada, kalau belum ya usulannya.
     * Tipenya CarbonInterface karena proyek ini memakai CarbonImmutable.
     */
    public function tanggalBerlaku(): ?CarbonInterface
    {
        return $this->tanggal_fix ?? $this->tanggal_rencana;
    }

    public function labelStatus(): string
    {
        return self::LABEL_STATUS[$this->status] ?? $this->status;
    }
}
