<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Subcon extends Model
{
    protected $table = 'subcon';

    protected $fillable = [
        'nama',
        'badan_usaha',
        'pemilik',
        'alamat_pemilik',
        'nik',
        'foto_ktp_path',
        'alamat',
        'telepon',
        'jenis_jasa',
        'kualifikasi',
        'pph_persen',
        'pph_catatan',
        'ppn_persen',
        'is_aktif',
        'catatan',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $attributes = [
        'is_aktif' => true,
        'ppn_persen' => 0,
    ];

    protected $casts = [
        'pph_persen' => 'decimal:2',
        'ppn_persen' => 'decimal:2',
        'is_aktif' => 'boolean',
    ];

    public const LABEL_JENIS_JASA = [
        'pelaksana' => 'Pelaksana konstruksi',
        'perencana_pengawas' => 'Perencanaan dan pengawasan konstruksi',
        'jasa' => 'Jasa',
        'notaris' => 'Notaris',
    ];

    public const LABEL_KUALIFIKASI = [
        'kecil' => 'Kecil',
        'menengah' => 'Menengah',
        'besar' => 'Besar',
        'pribadi' => 'Pribadi',
    ];

    /** Dokumen yang membuktikan kompetensi — menentukan tarif PPh. */
    public const DOKUMEN_SERTIFIKAT = ['sbu', 'ska'];

    public function dokumen(): HasMany
    {
        return $this->hasMany(SubconDokumen::class);
    }

    public function rekening(): HasMany
    {
        return $this->hasMany(SubconRekening::class)->orderByDesc('is_utama')->orderBy('id');
    }

    public function rekeningUtama(): HasOne
    {
        return $this->hasOne(SubconRekening::class)->where('is_utama', true);
    }

    public function rumah(): HasMany
    {
        return $this->hasMany(Rumah::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Perorangan kalau tidak menyertakan badan usaha. */
    public function perorangan(): bool
    {
        return blank($this->badan_usaha);
    }

    public function dokumenJenis(string $jenis): ?SubconDokumen
    {
        return $this->dokumen->firstWhere('jenis', $jenis);
    }

    public function punyaDokumen(string $jenis): bool
    {
        return $this->dokumenJenis($jenis) !== null;
    }

    /** Punya SBU atau SKA yang masih berlaku. */
    public function bersertifikat(): bool
    {
        foreach (self::DOKUMEN_SERTIFIKAT as $jenis) {
            if ($this->dokumenJenis($jenis)?->masihBerlaku()) {
                return true;
            }
        }

        return false;
    }

    public function pkp(): bool
    {
        return $this->punyaDokumen('pkp');
    }

    /**
     * Dokumen yang sudah lewat masa berlakunya.
     *
     * @return Collection<int, SubconDokumen>
     */
    public function dokumenKadaluarsa(): Collection
    {
        return $this->dokumen->filter(fn (SubconDokumen $d) => $d->kadaluarsa());
    }

    public function labelJenisJasa(): ?string
    {
        return self::LABEL_JENIS_JASA[$this->jenis_jasa] ?? null;
    }

    public function labelKualifikasi(): ?string
    {
        return self::LABEL_KUALIFIKASI[$this->kualifikasi] ?? null;
    }
}
