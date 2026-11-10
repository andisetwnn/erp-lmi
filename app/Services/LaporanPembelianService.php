<?php

namespace App\Services;

use App\Models\Master\PenerimaanBarangItem;
use App\Models\Master\PermintaanPembelianItem;
use App\Models\Master\PesananPembelian;
use App\Models\Master\PesananPembelianItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Laporan Pembelian: rekap realisasi PO, status pembayaran ke supplier, dan lead
 * time per barang dari PR dibuat sampai barangnya lengkap. Halaman laporan dan
 * unduhan Excel memakai kelas ini, jadi angkanya selalu sama.
 *
 * Saringan bersama ($saring):
 * - proyek: '' semua, 'pusat' PR kantor pusat (tanpa proyek), atau id proyek
 * - departemen: '' semua, atau nama departemen peminta
 * - supplier: '' semua, atau id supplier
 * - dari / sampai: Y-m-d. Rekap realisasi menyaring tanggal PO, lead time
 *   menyaring tanggal PR. Status pembayaran tidak memakai tanggal: PO lama yang
 *   belum lunas tetap harus terlihat.
 *
 * PO batal tidak pernah ikut. Persetujuan ada di PR, jadi setiap PO yang terbit
 * sudah disetujui.
 */
class LaporanPembelianService
{
    /** Lama proses PR diajukan → PO terbit di atas ini ditandai merah. */
    public const BATAS_LAMA_PROSES_HARI = 14;

    /** 'kelebihan' = PO ditutup yang uang lebihnya belum dikembalikan supplier. */
    public const LABEL_BAYAR = ['belum' => 'Belum dibayar', 'sebagian' => 'Dibayar sebagian', 'lunas' => 'Lunas', 'kelebihan' => 'Kelebihan bayar'];

    public const LABEL_BARANG = ['belum' => 'Belum datang', 'sebagian' => 'Sebagian', 'lengkap' => 'Lengkap', 'ditutup' => 'Ditutup'];

    /** Tahap satu barang PR, berurutan dari awal sampai selesai. */
    public const LABEL_TAHAP = [
        'persetujuan' => 'Menunggu persetujuan',
        'belum-po' => 'Belum dibuat PO',
        'menunggu' => 'Menunggu barang',
        'sebagian' => 'Datang sebagian',
        'lengkap' => 'Barang lengkap',
    ];

    public const LABEL_SLA = ['tepat' => 'Tepat waktu', 'terlambat' => 'Terlambat', 'proses' => 'Dalam proses'];

    /**
     * Satu baris per PO yang terbit pada rentang tanggal.
     *
     * @param  array{proyek: string, departemen: string, supplier: string, dari: string, sampai: string}  $saring
     * @return Collection<int, array<string, mixed>>
     */
    public function realisasi(array $saring): Collection
    {
        return $this->pesanan($saring)
            ->whereBetween('tanggal', [$saring['dari'], $saring['sampai'].' 23:59:59'])
            ->orderBy('tanggal')->orderBy('id')
            ->get()
            ->map(fn (PesananPembelian $po) => $this->barisPesanan($po))
            ->values();
    }

    /**
     * Semua PO yang belum batal, dikelompokkan menurut status pembayarannya.
     * Belum lunas diurutkan dari yang paling lama, lunas dari yang terakhir dibayar.
     * PO yang masih punya kelebihan bayar dipisah supaya pengembaliannya tertagih.
     *
     * @param  array{proyek: string, departemen: string, supplier: string, dari: string, sampai: string}  $saring
     * @return array{belum: Collection<int, array<string, mixed>>, sebagian: Collection<int, array<string, mixed>>, lunas: Collection<int, array<string, mixed>>, kelebihan: Collection<int, array<string, mixed>>}
     */
    public function pembayaran(array $saring): array
    {
        $semua = $this->pesanan($saring)
            ->orderBy('tanggal')->orderBy('id')
            ->get()
            ->map(fn (PesananPembelian $po) => $this->barisPesanan($po));

        return [
            'belum' => $semua->where('status_bayar', 'belum')->values(),
            'sebagian' => $semua->where('status_bayar', 'sebagian')->values(),
            'lunas' => $semua->where('status_bayar', 'lunas')->sortByDesc(fn ($b) => $b['bayar_terakhir']?->timestamp)->values(),
            'kelebihan' => $semua->where('status_bayar', 'kelebihan')->values(),
        ];
    }

    /**
     * Satu baris per barang PR (bukan draft / ditolak) yang dibuat pada rentang
     * tanggal. Lead time dihitung dari tanggal PR sampai barangnya lengkap; yang
     * belum lengkap dihitung sampai hari ini.
     *
     * @param  array{proyek: string, departemen: string, supplier: string, dari: string, sampai: string}  $saring
     * @param  array{tahap?: string, cari?: string, min?: ?int}  $opsi
     * @return Collection<int, array<string, mixed>>
     */
    public function leadTime(array $saring, array $opsi = []): Collection
    {
        $daftar = PermintaanPembelianItem::query()
            ->whereHas('permintaan', fn (Builder $q) => $this->saringPermintaan($q, $saring)
                ->whereNotIn('status', ['draft', 'ditolak'])
                ->whereBetween('tanggal', [$saring['dari'], $saring['sampai'].' 23:59:59']))
            ->when($saring['supplier'] !== '', fn (Builder $q) => $q->whereHas('itemPesanan.pesanan', fn (Builder $p) => $p
                ->where('status', '!=', 'batal')
                ->where('supplier_id', (int) $saring['supplier'])))
            ->with([
                'permintaan.proyek:id,nama_proyek',
                'permintaan.createdBy:id,name',
                'itemPesanan.pesanan.supplier:id,nama',
                'itemPesanan.pesanan.pembayaran',
            ])
            ->get();

        $terakhirDatang = PenerimaanBarangItem::query()
            ->join('penerimaan_barang', 'penerimaan_barang.id', '=', 'penerimaan_barang_item.penerimaan_barang_id')
            ->whereIn('pesanan_pembelian_item_id', $daftar->flatMap->itemPesanan->pluck('id'))
            ->groupBy('pesanan_pembelian_item_id')
            ->selectRaw('pesanan_pembelian_item_id, max(penerimaan_barang.tanggal) as terakhir')
            ->pluck('terakhir', 'pesanan_pembelian_item_id');

        $hariIni = CarbonImmutable::today();
        $cari = mb_strtolower(trim($opsi['cari'] ?? ''));

        return $daftar
            ->map(fn (PermintaanPembelianItem $i) => $this->barisLeadTime($i, $terakhirDatang, $hariIni))
            ->when(($opsi['tahap'] ?? '') !== '', fn (Collection $c) => $c->where('tahap', $opsi['tahap']))
            ->when(($opsi['min'] ?? null) !== null, fn (Collection $c) => $c->where('lead_time', '>=', $opsi['min']))
            ->when($cari !== '', fn (Collection $c) => $c->filter(fn ($b) => str_contains(
                mb_strtolower($b['nomor_pr'].' '.$b['barang'].' '.$b['nomor_po'].' '.$b['supplier']),
                $cari,
            )))
            ->sortBy([['tanggal_pr', 'asc'], ['nomor_pr', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Jumlah dan nilai PO per bulan dalam satu tahun (menurut tanggal PO).
     *
     * @param  array{proyek: string, departemen: string, supplier: string, dari: string, sampai: string}  $saring
     * @return array<int, array{jumlah: int, nilai: float}> bulan 1–12
     */
    public function grafikTahunan(int $tahun, array $saring): array
    {
        $per = $this->dasarPesanan($saring)
            ->whereBetween('tanggal', ["$tahun-01-01", "$tahun-12-31 23:59:59"])
            ->get(['tanggal', 'total'])
            ->groupBy(fn (PesananPembelian $po) => (int) $po->tanggal->month);

        return collect(range(1, 12))->mapWithKeys(fn (int $bulan) => [$bulan => [
            'jumlah' => $per->get($bulan)?->count() ?? 0,
            'nilai' => (float) ($per->get($bulan)?->sum('total') ?? 0),
        ]])->all();
    }

    /**
     * Isi unduhan Excel untuk satu tab, persis seperti yang tampil di layar.
     *
     * @param  array{proyek: string, departemen: string, supplier: string, dari: string, sampai: string}  $saring
     * @param  array{bayar?: string, tahap?: string, cari?: string, min?: ?int}  $opsi
     * @return array{judul: string, kolom: list<string>, baris: list<list<mixed>>}
     */
    public function excel(string $tab, array $saring, array $opsi = []): array
    {
        $tgl = fn (?CarbonImmutable $t) => $t?->format('Y-m-d') ?? '';

        return match ($tab) {
            'pembayaran' => [
                'judul' => 'Status Pembayaran',
                'kolom' => ['No PO', 'Tanggal PO', 'Supplier', 'Syarat Bayar', 'Proyek', 'Bagian', 'Total', 'Dibayar', 'Sisa', 'Kelebihan Bayar', 'Pembayaran Terakhir', 'Umur PO (hari)', 'Status Bayar'],
                'baris' => $this->pembayaran($saring)[$opsi['bayar'] ?? 'belum']->map(fn ($b) => [
                    $b['nomor'], $tgl($b['tanggal']), $b['supplier'], $b['syarat_bayar'], $b['proyek'], $b['departemen'],
                    $b['total'], $b['dibayar'], $b['sisa'], $b['kelebihan_bayar'], $tgl($b['bayar_terakhir']), $b['umur'], self::LABEL_BAYAR[$b['status_bayar']],
                ])->all(),
            ],
            'lead-time' => [
                'judul' => 'Lead Time Procurement',
                'kolom' => [
                    'Bagian', 'Pemohon', 'No PR', 'Proyek', 'Barang', 'Spesifikasi', 'Jumlah', 'Satuan',
                    'Harga Perkiraan', 'Harga PO', 'Selisih Harga', 'Tgl PR', 'Tgl Dibutuhkan', 'Tgl Disetujui',
                    'No PO', 'Supplier', 'Tgl PO', 'Tgl Lunas', 'Tgl Barang Lengkap', 'Lead Time (hari)', 'Tahap', 'SLA',
                ],
                'baris' => $this->leadTime($saring, $opsi)->map(fn ($b) => [
                    $b['departemen'], $b['pemohon'], $b['nomor_pr'], $b['proyek'], $b['barang'], $b['spesifikasi'], $b['jumlah'], $b['satuan'],
                    $b['harga_perkiraan'], $b['harga_po'] ?? '', $b['selisih'] ?? '', $tgl($b['tanggal_pr']), $tgl($b['tanggal_dibutuhkan']), $tgl($b['tanggal_disetujui']),
                    $b['nomor_po'], $b['supplier'], $tgl($b['tanggal_po']), $tgl($b['tanggal_lunas']), $tgl($b['tanggal_lengkap']),
                    $b['lead_time'], self::LABEL_TAHAP[$b['tahap']], $b['sla'] ? self::LABEL_SLA[$b['sla']] : '',
                ])->all(),
            ],
            default => [
                'judul' => 'Rekap Realisasi',
                'kolom' => ['No', 'No PO', 'Tanggal PO', 'No PR', 'Proyek', 'Supplier', 'Jumlah', 'Dibayar', 'Sisa', 'Lama Proses (hari)', 'Status Bayar', 'Barang', 'Bagian', 'Keperluan'],
                'baris' => $this->realisasi($saring)->values()->map(fn ($b, $n) => [
                    $n + 1, $b['nomor'], $tgl($b['tanggal']), $b['nomor_pr'], $b['proyek'], $b['supplier'], $b['total'], $b['dibayar'], $b['sisa'],
                    $b['lama_proses'], self::LABEL_BAYAR[$b['status_bayar']], self::LABEL_BARANG[$b['status_barang']], $b['departemen'], $b['keperluan'],
                ])->all(),
            ],
        };
    }

    /** @param  array{proyek: string, departemen: string, supplier: string}  $saring */
    private function dasarPesanan(array $saring): Builder
    {
        return PesananPembelian::query()
            ->where('status', '!=', 'batal')
            ->whereHas('permintaan', fn (Builder $q) => $this->saringPermintaan($q, $saring))
            ->when($saring['supplier'] !== '', fn (Builder $q) => $q->where('supplier_id', (int) $saring['supplier']));
    }

    /** @param  array{proyek: string, departemen: string, supplier: string}  $saring */
    private function pesanan(array $saring): Builder
    {
        return $this->dasarPesanan($saring)->with([
            'supplier:id,nama',
            'permintaan:id,nomor,tanggal,proyek_id,departemen,keperluan,diajukan_at',
            'permintaan.proyek:id,nama_proyek',
            'pembayaran',
            'pengembalian',
            'item',
        ]);
    }

    /** @param  array{proyek: string, departemen: string}  $saring */
    private function saringPermintaan(Builder $q, array $saring): Builder
    {
        return $q
            ->when($saring['proyek'] === 'pusat', fn (Builder $q) => $q->whereNull('proyek_id'))
            ->when(ctype_digit($saring['proyek']), fn (Builder $q) => $q->where('proyek_id', (int) $saring['proyek']))
            ->when($saring['departemen'] !== '', fn (Builder $q) => $q->where('departemen', $saring['departemen']));
    }

    /** @return array<string, mixed> */
    private function barisPesanan(PesananPembelian $po): array
    {
        $pr = $po->permintaan;
        $tanggal = CarbonImmutable::parse($po->tanggal)->startOfDay();
        $diajukan = CarbonImmutable::parse($pr->diajukan_at ?? $pr->tanggal)->startOfDay();
        $bayarTerakhir = $po->pembayaran->sortByDesc('tanggal')->first()?->tanggal;

        return [
            'id' => $po->id,
            'nomor' => $po->nomor,
            'tanggal' => $tanggal,
            'pr_id' => $pr->id,
            'nomor_pr' => $pr->nomor,
            'proyek' => $pr->namaLokasi(),
            'departemen' => $pr->departemen,
            'keperluan' => $pr->keperluan,
            'supplier' => $po->supplier?->nama,
            'syarat_bayar' => $po->syarat_bayar,
            'total' => (float) $po->total,
            // Dibayar = bersih setelah pengembalian supplier.
            'dibayar' => $po->totalDibayarBersih(),
            'dikembalikan' => $po->totalDikembalikan(),
            'kelebihan_bayar' => $po->kelebihanBayar(),
            'sisa' => $po->sisaBayar(),
            'status_bayar' => $po->kelebihanBayar() > 0 ? 'kelebihan' : $po->statusBayar(),
            'status_barang' => $po->ditutup() ? 'ditutup' : $po->statusBarang(),
            'bayar_terakhir' => $bayarTerakhir ? CarbonImmutable::parse($bayarTerakhir) : null,
            'lama_proses' => max(0, (int) $diajukan->diffInDays($tanggal)),
            'umur' => max(0, (int) $tanggal->diffInDays(CarbonImmutable::today())),
        ];
    }

    /**
     * @param  Collection<int, string>  $terakhirDatang  id baris PO => tanggal barang terakhir datang
     * @return array<string, mixed>
     */
    private function barisLeadTime(PermintaanPembelianItem $item, Collection $terakhirDatang, CarbonImmutable $hariIni): array
    {
        $pr = $item->permintaan;
        // Baris PO yang dipangkas habis saat PO ditutup tidak dihitung sebagai pesanan.
        $baris = $item->itemPesanan
            ->filter(fn (PesananPembelianItem $b) => $b->pesanan && $b->pesanan->status !== 'batal' && (float) $b->jumlah > 0)
            ->values();
        $pesanan = $baris->pluck('pesanan')->unique('id')->sortBy('tanggal')->values();
        $dipesan = (float) $baris->sum('jumlah');
        $diterima = (float) $baris->sum('jumlah_diterima');

        // Lengkap diukur dari jumlah di PR, bukan di PO: sisa dari PO yang ditutup
        // atau yang belum dipesan masih harus didatangkan.
        $tahap = match (true) {
            $pr->status !== 'disetujui' => 'persetujuan',
            $baris->isEmpty() => 'belum-po',
            $diterima + 0.005 >= (float) $item->jumlah => 'lengkap',
            $diterima > 0 => 'sebagian',
            default => 'menunggu',
        };

        $tanggal = fn ($nilai) => $nilai ? CarbonImmutable::parse($nilai)->startOfDay() : null;
        $lengkap = $tahap === 'lengkap'
            ? $baris->map(fn ($b) => $tanggal($terakhirDatang[$b->id] ?? null))->filter()->max()
            : null;
        $lunas = $pesanan->isNotEmpty() && $pesanan->every(fn (PesananPembelian $p) => $p->statusBayar() === 'lunas')
            ? $pesanan->map(fn (PesananPembelian $p) => $tanggal($p->pembayaran->max('tanggal')))->filter()->max()
            : null;
        $hargaPo = $dipesan > 0 ? round($baris->sum(fn ($b) => (float) $b->jumlah * (float) $b->harga) / $dipesan, 2) : null;
        $dibuat = $tanggal($pr->tanggal);
        $dibutuhkan = $tanggal($pr->tanggal_dibutuhkan);

        return [
            'id' => $item->id,
            'pr_id' => $pr->id,
            'nomor_pr' => $pr->nomor,
            'proyek' => $pr->namaLokasi(),
            'departemen' => $pr->departemen,
            'pemohon' => $pr->createdBy?->name,
            'barang' => $item->nama_barang,
            'spesifikasi' => $item->spesifikasi,
            'jumlah' => (float) $item->jumlah,
            'satuan' => $item->satuan,
            'harga_perkiraan' => (float) $item->harga_perkiraan,
            'harga_po' => $hargaPo,
            'selisih' => $hargaPo !== null ? round($hargaPo - (float) $item->harga_perkiraan, 2) : null,
            'tanggal_pr' => $dibuat,
            'tanggal_dibutuhkan' => $dibutuhkan,
            'tanggal_disetujui' => $pr->status === 'disetujui' ? $tanggal($pr->disetujui_at ?? $pr->ditinjau_at) : null,
            'po_id' => $pesanan->first()?->id,
            'nomor_po' => $pesanan->pluck('nomor')->implode(', '),
            'supplier' => $pesanan->map(fn ($p) => $p->supplier?->nama)->filter()->unique()->implode(', '),
            'tanggal_po' => $tanggal($pesanan->first()?->tanggal),
            'tanggal_lunas' => $lunas,
            'tanggal_lengkap' => $lengkap,
            'tahap' => $tahap,
            'lead_time' => max(0, (int) $dibuat->diffInDays($lengkap ?? $hariIni)),
            'berjalan' => $lengkap === null,
            'sla' => match (true) {
                $dibutuhkan === null => null,
                $lengkap !== null => $lengkap->lte($dibutuhkan) ? 'tepat' : 'terlambat',
                $hariIni->gt($dibutuhkan) => 'terlambat',
                default => 'proses',
            },
        ];
    }
}
