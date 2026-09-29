<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SprPemberkasan extends Model
{
    protected $table = 'spr_pemberkasan';

    protected $fillable = [
        'spr_id',
        'bank_kode',
        'bm_tanggal', 'bm_file_path', 'bm_file_original_name',
        'wcr_tanggal', 'wcr_catatan',
        'sp3k_tanggal', 'sp3k_nomor', 'sp3k_expired', 'sp3k_nominal',
        'lpa_tanggal',
        'rencana_akad_tanggal',
        'catatan',
        'input_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'bm_tanggal' => 'date',
        'wcr_tanggal' => 'date',
        'sp3k_tanggal' => 'date',
        'sp3k_expired' => 'date',
        'lpa_tanggal' => 'date',
        'rencana_akad_tanggal' => 'date',
        'sp3k_nominal' => 'decimal:2',
    ];

    /**
     * Pilihan bank KPR — kunci kode, nilai nama.
     *
     * Dulu daftarnya konstanta di kelas ini, sehingga menambah bank berarti
     * mengubah kode dan deploy ulang. Sekarang dari master `bank_kpr`.
     *
     * @return array<string, string>
     */
    public static function pilihanBank(): array
    {
        return BankKpr::pilihan();
    }

    /** LPA hanya diurus untuk bank yang mensyaratkannya — ditandai di master. */
    public function lpaRequired(): bool
    {
        return BankKpr::wajibLpa($this->bank_kode);
    }

    /** Hitung berapa tahap sudah lengkap (dari total 5). LPA cuma dihitung kalau bank = CBN. */
    public function progressCount(): int
    {
        $count = 0;
        if ($this->bm_tanggal) {
            $count++;
        }
        if ($this->wcr_tanggal) {
            $count++;
        }
        if ($this->sp3k_tanggal) {
            $count++;
        }
        if ($this->lpaRequired()) {
            if ($this->lpa_tanggal) {
                $count++;
            }
        }
        if ($this->rencana_akad_tanggal) {
            $count++;
        }

        return $count;
    }

    /**
     * Tahap terjauh yang tanggalnya sudah tercatat. Null kalau belum satu pun.
     *
     * Dibaca dari belakang: yang paling jauh menentukan posisi berkasnya,
     * bukan yang paling awal. Tahap yang terlewat tidak menggugurkan — data
     * historis memang banyak yang hanya punya tanggal SP3K.
     */
    public function tahapTerakhir(): ?string
    {
        $tahap = [
            'Rencana Akad' => $this->rencana_akad_tanggal,
            'LPA' => $this->lpa_tanggal,
            'SP3K' => $this->sp3k_tanggal,
            'Wawancara' => $this->wcr_tanggal,
            'Berkas Lengkap' => $this->bm_tanggal,
        ];

        foreach ($tahap as $nama => $tanggal) {
            if ($tanggal) {
                return $nama;
            }
        }

        return null;
    }

    /** Total tahap wajib (5 kalau BTN, 4 kalau selain BTN). */
    public function totalTahap(): int
    {
        return $this->lpaRequired() ? 5 : 4;
    }

    /** Sisa hari sampai SP3K expired. Null kalau belum ada SP3K expired. Negatif kalau sudah lewat. */
    public function sp3kExpiredIn(): ?int
    {
        if (! $this->sp3k_expired) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->sp3k_expired, false);
    }

    public function spr(): BelongsTo
    {
        return $this->belongsTo(Spr::class);
    }

    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
