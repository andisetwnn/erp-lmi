<?php

namespace App\Services;

use App\Models\Master\PembayaranPembelian;
use App\Models\Master\PenerimaanBarang;
use App\Models\Master\PengembalianPembelian;
use App\Models\Master\PermintaanPembelian;
use App\Models\Master\Perusahaan;
use App\Models\Master\PesananPembelian;
use App\Models\Master\PesananPembelianItem;
use App\Support\BusinessActivityLogger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alur Modul Pembelian (konfirmasi FAT 30 Okt 2026, matrix approval 3 Nov 2026):
 * PR → review FAT → [direktur, bila di atas batas] → PO → bayar dulu → barang datang.
 *
 * Batasnya `perusahaan.batas_persetujuan_direktur` (bawaan Rp 50 jt). PR sampai
 * batas itu cukup disetujui FAT. Total PO juga dijaga: PR yang hanya disetujui
 * FAT tidak boleh dipesan melewati batas tanpa diteruskan ke direktur dulu.
 *
 * Semua perubahan status lewat sini, di dalam transaksi, supaya aturan alurnya
 * tidak bisa dilompati dari halaman mana pun, dan tiap langkahnya tercatat di
 * Monitoring (kategori Pembelian). Belum membuat jurnal — Keuangan menjurnal
 * manual saat uang keluar.
 */
class PembelianService
{
    public const BATAS_PERSETUJUAN_BAWAAN = 50000000;

    /** Nilai PR/PO tertinggi yang cukup disetujui FAT; di atasnya perlu direktur. */
    public function batasPersetujuanDirektur(): float
    {
        return (float) (Perusahaan::query()->value('batas_persetujuan_direktur') ?? self::BATAS_PERSETUJUAN_BAWAAN);
    }

    /** Umur PO tanpa pembayaran sebelum dibatalkan otomatis; null = tidak aktif. */
    public function batasPoGantungHari(): ?int
    {
        $hari = Perusahaan::query()->value('batas_po_gantung_hari');

        return $hari ? (int) $hari : null;
    }

    /**
     * PO gantung: masih terbit, belum dibayar sama sekali, belum ada barang datang,
     * dan umurnya sudah lebih dari batas hari. Hanya PO seperti ini yang boleh
     * dibatalkan — sama dengan aturan batal manual.
     */
    public function poGantung(int $batasHari): Builder
    {
        return PesananPembelian::query()
            ->where('status', 'terbit')
            ->where('tanggal', '<', today()->subDays($batasHari)->toDateString())
            ->whereDoesntHave('pembayaran')
            ->whereDoesntHave('penerimaan');
    }

    /**
     * Batalkan otomatis PO gantung (dijalankan terjadwal tiap hari). PO yang sempat
     * dibayar atau diterima di sela pengecekan dilewati.
     *
     * @return Collection<int, PesananPembelian> PO yang dibatalkan
     */
    public function batalkanPoGantung(): Collection
    {
        $batas = $this->batasPoGantungHari();
        if ($batas === null) {
            return collect();
        }

        return $this->poGantung($batas)->orderBy('tanggal')->get()
            ->map(function (PesananPembelian $po) use ($batas) {
                try {
                    return $this->batalkanPesanan($po, "Dibatalkan otomatis: lewat {$batas} hari tanpa pembayaran.", null);
                } catch (ValidationException) {
                    return null;
                }
            })
            ->filter()
            ->values();
    }

    /**
     * Nomor dokumen berikutnya: `{urut}/{kode perusahaan}/{PR|PO}/{bulan}/{tahun}`,
     * urut ulang tiap bulan. WAJIB dipanggil di dalam DB::transaction — barisnya
     * dikunci sampai transaksi selesai supaya dua orang tidak mendapat nomor sama.
     *
     * @param  class-string<Model>  $model
     */
    public function nomorBerikutnya(string $model, string $jenis, CarbonInterface $tanggal): string
    {
        $kode = Perusahaan::query()->value('kode_surat') ?: 'LMI';
        $akhiran = sprintf('/%s/%s/%02d/%d', $kode, $jenis, $tanggal->month, $tanggal->year);

        $urutTerakhir = $model::query()
            ->where('nomor', 'like', '%'.$akhiran)
            ->lockForUpdate()
            ->pluck('nomor')
            ->map(fn (string $nomor) => (int) strtok($nomor, '/'))
            ->max() ?? 0;

        return ($urutTerakhir + 1).$akhiran;
    }

    /**
     * Buat PR baru (status draft).
     *
     * @param  array{tanggal: string, proyek_id: ?int, departemen: string, keperluan: string, tanggal_dibutuhkan: ?string}  $data
     * @param  list<array{nama_barang: string, spesifikasi: ?string, jumlah: float|string, satuan: string, harga_perkiraan: float|string}>  $item
     */
    public function buatPermintaan(array $data, array $item, ?int $userId): PermintaanPembelian
    {
        $this->pastikanAdaBarang($item);

        return DB::transaction(function () use ($data, $item, $userId) {
            $permintaan = PermintaanPembelian::create($data + [
                'nomor' => $this->nomorBerikutnya(PermintaanPembelian::class, 'PR', Carbon::parse($data['tanggal'])),
                'status' => 'draft',
                'created_by_user_id' => $userId,
            ]);
            $permintaan->item()->createMany($item);

            return $permintaan;
        });
    }

    /**
     * Ubah PR yang masih draft. Barangnya diganti seluruhnya dengan daftar baru.
     *
     * @param  array{tanggal: string, proyek_id: ?int, departemen: string, keperluan: string, tanggal_dibutuhkan: ?string}  $data
     * @param  list<array{nama_barang: string, spesifikasi: ?string, jumlah: float|string, satuan: string, harga_perkiraan: float|string}>  $item
     */
    public function perbaruiPermintaan(PermintaanPembelian $permintaan, array $data, array $item): PermintaanPembelian
    {
        $this->pastikanAdaBarang($item);

        return DB::transaction(function () use ($permintaan, $data, $item) {
            $permintaan = PermintaanPembelian::lockForUpdate()->findOrFail($permintaan->id);
            $this->pastikanStatus($permintaan, ['draft'], 'PR hanya bisa diubah selama masih draft.');

            $permintaan->update($data);
            $permintaan->item()->delete();
            $permintaan->item()->createMany($item);

            return $permintaan;
        });
    }

    public function ajukan(PermintaanPembelian $permintaan, ?int $userId): PermintaanPembelian
    {
        return DB::transaction(function () use ($permintaan, $userId) {
            $permintaan = PermintaanPembelian::lockForUpdate()->findOrFail($permintaan->id);
            $this->pastikanStatus($permintaan, ['draft'], 'PR ini sudah diajukan.');

            if (! $permintaan->item()->exists()) {
                throw ValidationException::withMessages(['item' => 'Tambahkan minimal satu barang sebelum mengajukan.']);
            }

            $permintaan->update([
                'status' => 'diajukan',
                'alasan' => null,
                'diajukan_at' => now(),
                'diajukan_by_user_id' => $userId,
            ]);
            $this->catatPermintaan($permintaan, ['pr.diajukan', 'diajukan ke FAT · '.$permintaan->departemen.' · '.Str::limit($permintaan->keperluan, 60)], $userId);

            return $permintaan;
        });
    }

    /**
     * Review Keuangan (FAT): setujui, kembalikan (ke draft), atau tolak. PR yang
     * disetujui langsung siap dibuatkan PO, kecuali nilainya di atas batas —
     * itu diteruskan ke direktur.
     */
    public function tinjau(PermintaanPembelian $permintaan, string $keputusan, ?string $alasan, ?int $userId): PermintaanPembelian
    {
        return DB::transaction(function () use ($permintaan, $keputusan, $alasan, $userId) {
            $permintaan = PermintaanPembelian::lockForUpdate()->findOrFail($permintaan->id);
            $this->pastikanStatus($permintaan, ['diajukan'], 'PR ini tidak sedang menunggu review FAT.');
            $this->pastikanAlasan($keputusan, $alasan, ['kembalikan', 'tolak']);

            $permintaan->update(match ($keputusan) {
                'setujui' => [
                    'status' => $permintaan->totalPerkiraan() > $this->batasPersetujuanDirektur() ? 'ditinjau' : 'disetujui',
                    'alasan' => null,
                    'ditinjau_at' => now(),
                    'ditinjau_by_user_id' => $userId,
                ],
                'kembalikan' => ['status' => 'draft', 'alasan' => $alasan, 'diajukan_at' => null, 'diajukan_by_user_id' => null],
                'tolak' => ['status' => 'ditolak', 'alasan' => $alasan, 'ditolak_at' => now(), 'ditolak_by_user_id' => $userId],
                default => throw ValidationException::withMessages(['keputusan' => 'Keputusan tidak dikenal.']),
            });
            $this->catatPermintaan($permintaan, match (true) {
                $keputusan === 'kembalikan' => ['pr.dikembalikan', 'dikembalikan ke peminta: '.$alasan],
                $keputusan === 'tolak' => ['pr.ditolak', 'ditolak FAT: '.$alasan],
                $permintaan->status === 'ditinjau' => ['pr.diteruskan', 'disetujui FAT, diteruskan ke direktur karena di atas Rp '.$this->rupiah($this->batasPersetujuanDirektur())],
                default => ['pr.disetujui', 'disetujui FAT'],
            }, $userId);

            return $permintaan;
        });
    }

    /**
     * Persetujuan direktur untuk PR di atas batas: setujui (PO boleh terbit) atau tolak.
     */
    public function putuskanAnggaran(PermintaanPembelian $permintaan, string $keputusan, ?string $alasan, ?int $userId): PermintaanPembelian
    {
        return DB::transaction(function () use ($permintaan, $keputusan, $alasan, $userId) {
            $permintaan = PermintaanPembelian::lockForUpdate()->findOrFail($permintaan->id);
            $this->pastikanStatus($permintaan, ['ditinjau'], 'PR ini tidak sedang menunggu persetujuan direktur.');
            $this->pastikanAlasan($keputusan, $alasan, ['tolak']);

            $permintaan->update(match ($keputusan) {
                'setujui' => ['status' => 'disetujui', 'alasan' => null, 'disetujui_at' => now(), 'disetujui_by_user_id' => $userId],
                'tolak' => ['status' => 'ditolak', 'alasan' => $alasan, 'ditolak_at' => now(), 'ditolak_by_user_id' => $userId],
                default => throw ValidationException::withMessages(['keputusan' => 'Keputusan tidak dikenal.']),
            });
            $this->catatPermintaan($permintaan, $keputusan === 'tolak' ? ['pr.ditolak', 'ditolak direktur: '.$alasan] : ['pr.disetujui', 'disetujui direktur'], $userId);

            return $permintaan;
        });
    }

    /**
     * Teruskan PR yang tadinya cukup disetujui FAT ke direktur — dipakai saat
     * harga final di PO ternyata membuat totalnya melewati batas.
     */
    public function teruskanKeDirektur(PermintaanPembelian $permintaan, ?int $userId = null): PermintaanPembelian
    {
        return DB::transaction(function () use ($permintaan, $userId) {
            $permintaan = PermintaanPembelian::lockForUpdate()->findOrFail($permintaan->id);
            $this->pastikanStatus($permintaan, ['disetujui'], 'Hanya PR yang sudah disetujui yang bisa diteruskan ke direktur.');

            if ($permintaan->disetujuiDirektur()) {
                throw ValidationException::withMessages(['status' => 'PR ini sudah disetujui direktur.']);
            }

            $permintaan->update(['status' => 'ditinjau']);
            $this->catatPermintaan($permintaan, ['pr.diteruskan', 'diteruskan ke direktur: total PO melewati batas persetujuan FAT'], $userId);

            return $permintaan;
        });
    }

    /**
     * Terbitkan PO dari PR yang sudah disetujui. Satu PR boleh dipecah ke
     * beberapa supplier, jadi barang dan jumlahnya dipilih per PO.
     *
     * @param  array{supplier_id: int, tanggal: string, tanggal_kirim: ?string, syarat_bayar: ?string, ppn_terpisah: bool, ppn_persen: float|string, catatan: ?string}  $data
     * @param  list<array{permintaan_pembelian_item_id: ?int, nama_barang: string, spesifikasi: ?string, jumlah: float|string, satuan: string, harga: float|string}>  $item
     */
    public function terbitkanPesanan(PermintaanPembelian $permintaan, array $data, array $item, ?int $userId): PesananPembelian
    {
        $item = array_values(array_filter($item, fn ($i) => (float) $i['jumlah'] > 0));
        $this->pastikanAdaBarang($item);

        return DB::transaction(function () use ($permintaan, $data, $item, $userId) {
            $permintaan = PermintaanPembelian::lockForUpdate()->findOrFail($permintaan->id);
            $this->pastikanStatus($permintaan, ['disetujui'], 'PO hanya bisa diterbitkan dari PR yang sudah disetujui.');
            $this->pastikanTidakMelebihiPermintaan($permintaan, $item);

            $subtotal = round(array_sum(array_map(fn ($i) => (float) $i['jumlah'] * (float) $i['harga'], $item)), 2);
            $ppnPersen = $data['ppn_terpisah'] ? (float) $data['ppn_persen'] : 0.0;
            $ppn = round($subtotal * $ppnPersen / 100, 2);

            if (! $permintaan->disetujuiDirektur()) {
                $batas = $this->batasPersetujuanDirektur();
                $totalPo = (float) $permintaan->pesanan()->where('status', '!=', 'batal')->sum('total') + $subtotal + $ppn;
                if ($totalPo > $batas) {
                    throw ValidationException::withMessages(['total' => sprintf(
                        'Total PO dari PR ini menjadi Rp %s, melewati batas Rp %s yang cukup disetujui FAT. Teruskan PR ke direktur dulu.',
                        number_format($totalPo, 0, ',', '.'),
                        number_format($batas, 0, ',', '.'),
                    )]);
                }
            }

            $pesanan = PesananPembelian::create([
                'nomor' => $this->nomorBerikutnya(PesananPembelian::class, 'PO', Carbon::parse($data['tanggal'])),
                'tanggal' => $data['tanggal'],
                'permintaan_pembelian_id' => $permintaan->id,
                'supplier_id' => $data['supplier_id'],
                'tanggal_kirim' => $data['tanggal_kirim'] ?? null,
                'syarat_bayar' => $data['syarat_bayar'] ?? null,
                'ppn_terpisah' => (bool) $data['ppn_terpisah'],
                'ppn_persen' => $ppnPersen,
                'subtotal' => $subtotal,
                'ppn' => $ppn,
                'total' => $subtotal + $ppn,
                'status' => 'terbit',
                'catatan' => $data['catatan'] ?? null,
                'created_by_user_id' => $userId,
            ]);
            $pesanan->item()->createMany($item);
            $this->catatPesanan('po.diterbitkan', $pesanan, 'diterbitkan ke '.$pesanan->supplier?->nama.' · dari PR '.$permintaan->nomor, [], $userId);

            return $pesanan;
        });
    }

    /**
     * Catat pembayaran ke supplier. Boleh berkali-kali (uang muka / bertahap),
     * tapi totalnya tidak boleh melebihi nilai PO. PO yang sudah selesai (barang
     * lengkap atau PO ditutup) masih bisa dilunasi sisanya.
     *
     * @param  array{tanggal: string, jumlah: float|string, rekening_coa_id: ?int, bukti_path: ?string, bukti_nama: ?string, keterangan: ?string}  $data
     */
    public function catatPembayaran(PesananPembelian $pesanan, array $data, ?int $userId): PembayaranPembelian
    {
        return DB::transaction(function () use ($pesanan, $data, $userId) {
            $pesanan = PesananPembelian::lockForUpdate()->with('pembayaran')->findOrFail($pesanan->id);
            $this->pastikanStatus($pesanan, ['terbit', 'selesai'], 'PO ini sudah '.strtolower($pesanan->labelStatus()).'.');

            $jumlah = round((float) $data['jumlah'], 2);
            if ($jumlah <= 0) {
                throw ValidationException::withMessages(['jumlah' => 'Jumlah pembayaran harus lebih dari nol.']);
            }
            if ($jumlah > $pesanan->sisaBayar() + 0.005) {
                throw ValidationException::withMessages([
                    'jumlah' => 'Melebihi sisa yang harus dibayar (Rp '.number_format($pesanan->sisaBayar(), 0, ',', '.').').',
                ]);
            }

            $sisa = max(0, round($pesanan->sisaBayar() - $jumlah, 2));
            $bayar = $pesanan->pembayaran()->create(['jumlah' => $jumlah, 'input_by_user_id' => $userId] + $data);
            $this->catatPesanan('po.dibayar', $pesanan, 'dibayar ke '.$pesanan->supplier?->nama.($sisa > 0 ? ' · sisa Rp '.$this->rupiah($sisa) : ' · lunas'), ['nilai' => $jumlah, 'sisa' => $sisa], $userId);

            return $bayar;
        });
    }

    /**
     * Catat uang kelebihan bayar yang dikembalikan supplier (setelah PO ditutup).
     * Boleh dicicil, tapi totalnya tidak melebihi kelebihan bayar.
     *
     * @param  array{tanggal: string, jumlah: float|string, rekening_coa_id: ?int, keterangan: ?string}  $data
     */
    public function catatPengembalian(PesananPembelian $pesanan, array $data, ?int $userId): PengembalianPembelian
    {
        return DB::transaction(function () use ($pesanan, $data, $userId) {
            $pesanan = PesananPembelian::lockForUpdate()->with(['pembayaran', 'pengembalian'])->findOrFail($pesanan->id);
            $kelebihan = $pesanan->kelebihanBayar();

            if ($kelebihan <= 0) {
                throw ValidationException::withMessages(['jumlah' => 'PO ini tidak punya kelebihan bayar yang perlu dikembalikan.']);
            }

            $jumlah = round((float) $data['jumlah'], 2);
            if ($jumlah <= 0) {
                throw ValidationException::withMessages(['jumlah' => 'Jumlah pengembalian harus lebih dari nol.']);
            }
            if ($jumlah > $kelebihan + 0.005) {
                throw ValidationException::withMessages([
                    'jumlah' => 'Melebihi kelebihan bayar yang belum dikembalikan (Rp '.$this->rupiah($kelebihan).').',
                ]);
            }

            $kembali = $pesanan->pengembalian()->create(['jumlah' => $jumlah, 'input_by_user_id' => $userId] + $data);
            $sisa = max(0, round($kelebihan - $jumlah, 2));
            $this->catatPesanan('po.dikembalikan', $pesanan, 'pengembalian dari '.$pesanan->supplier?->nama.($sisa > 0 ? ' · masih kurang Rp '.$this->rupiah($sisa) : ' · selesai dikembalikan'), [
                'nilai' => $jumlah,
                'sisa_kelebihan' => $sisa,
            ], $userId);

            return $kembali;
        });
    }

    /**
     * Catat satu kedatangan barang. Supplier dibayar dulu, jadi kedatangan baru
     * bisa dicatat setelah ada pembayaran. PO selesai otomatis begitu semua
     * barangnya sudah diterima.
     *
     * @param  array{tanggal: string, nomor_surat_jalan: ?string, foto_path: ?string, foto_nama: ?string, catatan: ?string}  $data
     * @param  array<int, float|string>  $jumlahPerItem  id baris PO => jumlah yang datang
     */
    public function catatPenerimaan(PesananPembelian $pesanan, array $data, array $jumlahPerItem, ?int $userId): PenerimaanBarang
    {
        return DB::transaction(function () use ($pesanan, $data, $jumlahPerItem, $userId) {
            $pesanan = PesananPembelian::lockForUpdate()->with('pembayaran')->findOrFail($pesanan->id);
            $this->pastikanStatus($pesanan, ['terbit'], 'PO ini sudah '.strtolower($pesanan->labelStatus()).'.');

            if ($pesanan->pembayaran->isEmpty()) {
                throw ValidationException::withMessages(['pembayaran' => 'Supplier dibayar dulu sebelum barang dikirim — catat pembayarannya lebih dulu.']);
            }

            $baris = PesananPembelianItem::where('pesanan_pembelian_id', $pesanan->id)->lockForUpdate()->get()->keyBy('id');
            $datang = [];
            foreach ($jumlahPerItem as $itemId => $jumlah) {
                $jumlah = round((float) $jumlah, 2);
                if ($jumlah <= 0) {
                    continue;
                }
                $item = $baris->get((int) $itemId);
                if (! $item) {
                    throw ValidationException::withMessages(['item' => 'Barang tidak termasuk PO ini.']);
                }
                if ($jumlah > $item->sisaDiterima() + 0.005) {
                    throw ValidationException::withMessages([
                        "jumlah.$itemId" => "{$item->nama_barang}: melebihi sisa yang belum datang ({$this->angka($item->sisaDiterima())} {$item->satuan}).",
                    ]);
                }
                $datang[$item->id] = $jumlah;
            }

            if ($datang === []) {
                throw ValidationException::withMessages(['item' => 'Isi jumlah minimal satu barang yang datang.']);
            }

            $penerimaan = $pesanan->penerimaan()->create(['input_by_user_id' => $userId] + $data);
            foreach ($datang as $itemId => $jumlah) {
                $penerimaan->item()->create(['pesanan_pembelian_item_id' => $itemId, 'jumlah' => $jumlah]);
                $baris[$itemId]->increment('jumlah_diterima', $jumlah);
            }

            if ($pesanan->item()->get()->every(fn (PesananPembelianItem $i) => $i->sisaDiterima() <= 0)) {
                $pesanan->update(['status' => 'selesai']);
            }

            $daftar = collect($datang)->map(fn ($n, $id) => $baris[$id]->nama_barang.' '.$this->angka($n).' '.$baris[$id]->satuan)->implode(', ');
            $this->catatPesanan('po.barang_datang', $pesanan, 'barang datang'.(! empty($data['nomor_surat_jalan']) ? ' (SJ '.$data['nomor_surat_jalan'].')' : '').': '.$daftar.($pesanan->status === 'selesai' ? ' · PO selesai' : ''), [
                'nilai' => round((float) collect($datang)->sum(fn ($n, $id) => $n * (float) $baris[$id]->harga), 2),
                'surat_jalan' => $data['nomor_surat_jalan'] ?? null,
            ], $userId);

            return $penerimaan;
        });
    }

    /**
     * Batalkan PO yang belum dibayar dan belum ada barang datang. PO yang sudah
     * dibayar menyangkut uang di supplier — penanganannya belum termasuk versi ini.
     */
    public function batalkanPesanan(PesananPembelian $pesanan, string $alasan, ?int $userId): PesananPembelian
    {
        return DB::transaction(function () use ($pesanan, $alasan, $userId) {
            $pesanan = PesananPembelian::lockForUpdate()->findOrFail($pesanan->id);
            $this->pastikanStatus($pesanan, ['terbit'], 'PO ini sudah '.strtolower($pesanan->labelStatus()).'.');

            if (trim($alasan) === '') {
                throw ValidationException::withMessages(['alasan' => 'Tuliskan alasan pembatalan.']);
            }
            if ($pesanan->pembayaran()->exists() || $pesanan->penerimaan()->exists()) {
                throw ValidationException::withMessages(['status' => 'PO yang sudah dibayar atau sudah ada barang datang tidak bisa dibatalkan.']);
            }

            $pesanan->update(['status' => 'batal', 'alasan_batal' => $alasan]);
            $this->catatPesanan('po.dibatalkan', $pesanan, 'dibatalkan: '.Str::limit($alasan, 120), [], $userId);

            return $pesanan;
        });
    }

    /**
     * Tutup PO yang supplier-nya tidak sanggup mengirim sisa barang. Jumlah tiap
     * barang dipangkas menjadi yang sudah datang (jumlah semula disimpan), nilai PO
     * dihitung ulang, dan sisa barangnya kembali bisa dipesan dari PR. Kelebihan
     * bayar ditagih Keuangan ke supplier; kekurangan bayar masih bisa dicatat.
     *
     * PO yang belum dibayar dan belum ada barang datang dibatalkan, bukan ditutup.
     */
    public function tutupPesanan(PesananPembelian $pesanan, string $alasan, ?int $userId): PesananPembelian
    {
        return DB::transaction(function () use ($pesanan, $alasan, $userId) {
            $pesanan = PesananPembelian::lockForUpdate()->with(['item', 'pembayaran', 'penerimaan'])->findOrFail($pesanan->id);
            $this->pastikanStatus($pesanan, ['terbit'], 'Hanya PO yang masih berjalan yang bisa ditutup.');

            if (trim($alasan) === '') {
                throw ValidationException::withMessages(['alasan' => 'Tuliskan alasan PO ditutup.']);
            }
            if ($pesanan->pembayaran->isEmpty() && $pesanan->penerimaan->isEmpty()) {
                throw ValidationException::withMessages(['status' => 'PO ini belum dibayar dan belum ada barang datang — batalkan saja, tidak perlu ditutup.']);
            }

            $totalAwal = (float) $pesanan->total;
            $tidakDikirim = [];
            foreach ($pesanan->item as $i) {
                if ($i->sisaDiterima() > 0) {
                    $tidakDikirim[] = $i->nama_barang.' '.$this->angka($i->sisaDiterima()).' '.$i->satuan;
                    $i->update(['jumlah_awal' => $i->jumlah, 'jumlah' => $i->jumlah_diterima]);
                }
            }

            $subtotal = round((float) $pesanan->item->sum(fn (PesananPembelianItem $i) => (float) $i->jumlah * (float) $i->harga), 2);
            $ppn = $pesanan->ppn_terpisah ? round($subtotal * (float) $pesanan->ppn_persen / 100, 2) : 0.0;
            $pesanan->update([
                'subtotal' => $subtotal,
                'ppn' => $ppn,
                'total' => $subtotal + $ppn,
                'total_awal' => $totalAwal,
                'status' => 'selesai',
                'alasan_tutup' => $alasan,
                'ditutup_at' => now(),
                'ditutup_by_user_id' => $userId,
            ]);

            $this->catatPesanan('po.ditutup', $pesanan, 'ditutup: '.Str::limit($alasan, 100).' · nilai Rp '.$this->rupiah($totalAwal).' → Rp '.$this->rupiah((float) $pesanan->total), [
                'nilai_awal' => $totalAwal,
                'tidak_dikirim' => $tidakDikirim,
                'kelebihan_bayar' => $pesanan->kelebihanBayar(),
            ], $userId);

            return $pesanan;
        });
    }

    /**
     * Jumlah tiap barang di PO baru tidak boleh melebihi yang belum dipesan di PR.
     * PR sudah dikunci pemanggil, jadi dua PO yang diterbitkan bersamaan tidak lolos dua-duanya.
     *
     * @param  list<array<string, mixed>>  $item
     */
    private function pastikanTidakMelebihiPermintaan(PermintaanPembelian $permintaan, array $item): void
    {
        $barisPr = $permintaan->item()->with('itemPesanan.pesanan:id,status')->get()->keyBy('id');

        foreach ($item as $i) {
            $barang = $barisPr->get((int) ($i['permintaan_pembelian_item_id'] ?? 0));
            if ($barang && (float) $i['jumlah'] > $barang->sisaBelumDipesan() + 0.005) {
                throw ValidationException::withMessages(['item' => sprintf(
                    '%s: melebihi yang belum dipesan di PR (%s %s).',
                    $barang->nama_barang,
                    $this->angka($barang->sisaBelumDipesan()),
                    $barang->satuan,
                )]);
            }
        }
    }

    /** @param  list<array<string, mixed>>  $item */
    private function pastikanAdaBarang(array $item): void
    {
        if ($item === []) {
            throw ValidationException::withMessages(['item' => 'Tambahkan minimal satu barang.']);
        }
    }

    /** @param  list<string>  $boleh */
    private function pastikanStatus(Model $dokumen, array $boleh, string $pesan): void
    {
        if (! in_array($dokumen->status, $boleh, true)) {
            throw ValidationException::withMessages(['status' => $pesan]);
        }
    }

    /** @param  list<string>  $wajibAlasan */
    private function pastikanAlasan(string $keputusan, ?string $alasan, array $wajibAlasan): void
    {
        if (in_array($keputusan, $wajibAlasan, true) && trim((string) $alasan) === '') {
            throw ValidationException::withMessages(['alasan' => 'Tuliskan alasannya supaya peminta tahu apa yang harus diperbaiki.']);
        }
    }

    /** @param  array{0: string, 1: string}  $catatan  [event, kalimat sesudah nomor PR] */
    private function catatPermintaan(PermintaanPembelian $permintaan, array $catatan, ?int $userId): void
    {
        [$event, $kalimat] = $catatan;
        BusinessActivityLogger::pembelian($event, "PR {$permintaan->nomor} {$kalimat}", [
            'nomor' => $permintaan->nomor,
            'lokasi' => $permintaan->namaLokasi(),
            'departemen' => $permintaan->departemen,
            'nilai' => $permintaan->totalPerkiraan(),
        ], $permintaan, $userId);
    }

    /** @param  array<string, mixed>  $tambahan  menimpa nilai bawaan (mis. 'nilai' = jumlah dibayar) */
    private function catatPesanan(string $event, PesananPembelian $pesanan, string $kalimat, array $tambahan, ?int $userId): void
    {
        BusinessActivityLogger::pembelian($event, "PO {$pesanan->nomor} {$kalimat}", $tambahan + [
            'nomor' => $pesanan->nomor,
            'supplier' => $pesanan->supplier?->nama,
            'pr' => $pesanan->permintaan?->nomor,
            'nilai' => (float) $pesanan->total,
        ], $pesanan, $userId);
    }

    private function rupiah(float $nilai): string
    {
        return number_format($nilai, 0, ',', '.');
    }

    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 2, ',', '.'), '0'), ',');
    }
}
