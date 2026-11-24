<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\BankKpr;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Models\Master\SprPemberkasan;
use App\Support\BusinessActivityLogger;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Input Pemberkasan')] class extends Component
{
    use Sortable, WithFileUploads, WithPagination;

    #[Url(as: 'proyek')]
    public ?int $selectedProyekId = null;

    /**
     * proses | akad | semua.
     *
     * Bawaannya hanya SPR yang masih berproses: separuh lebih isi tabel ini sudah
     * akad dan jarang disentuh lagi, padahal pekerjaan harian ada di sisanya.
     */
    #[Url(as: 'tab')]
    public string $tab = 'proses';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $filterStatus = '';

    #[Url(as: 'bank')]
    public string $filterBank = '';

    #[Url(as: 'sales')]
    public string $filterSales = '';

    #[Url(as: 'akad')]
    public string $filterAkadPeriode = ''; // '' | 'week' | 'month' | 'passed'

    #[Url(as: 'per')]
    public string $perPage = '25';

    // Universal edit-field modal state
    public ?int $editSprId = null;

    public ?int $editPemberkasanId = null;

    /** Field yang sedang di-edit: bank|bm|wcr|sp3k|lpa */
    public ?string $editingField = null;

    /** Nomor SPR untuk title modal. */
    public ?string $editingSprLabel = null;

    // Field values (dynamic based on editingField)
    public ?string $val_bank_kode = null;

    public ?string $val_bm_tanggal = null;

    public ?string $val_wcr_tanggal = null;

    public ?string $val_wcr_catatan = null;

    public ?string $val_sp3k_tanggal = null;

    public ?string $val_sp3k_nomor = null;

    public ?string $val_sp3k_expired = null;

    public string $val_sp3k_nominal = '0';

    public ?string $val_lpa_tanggal = null;

    // Upload (per file — dipakai kalau editingField = bm/sp3k)
    public $uploadFile = null;

    public ?string $existingFileName = null;

    // Isi cepat langsung di sel tabel (tanggal satu-satunya isian: BM, WCR, LPA, atau bank)
    public ?int $inlineSprId = null;

    public ?string $inlineField = null;

    public ?string $inlineValue = null;

    /**
     * Peringatan urutan tanggal yang menahan penyimpanan sampai dikonfirmasi.
     *
     * @var list<string>
     */
    public array $peringatanUrutan = [];

    /** Kolom tabel per field yang bisa diisi langsung di sel. */
    private const KOLOM_CEPAT = ['bank' => 'bank_kode', 'bm' => 'bm_tanggal', 'wcr' => 'wcr_tanggal', 'lpa' => 'lpa_tanggal'];

    private const LABEL_FIELD = ['bank' => 'Bank', 'bm' => 'Berkas Lengkap', 'wcr' => 'Wawancara', 'sp3k' => 'SP3K', 'lpa' => 'LPA'];

    /**
     * Ringkasan tahap di atas tabel: kode filter => [judul, keterangan, warna].
     *
     * Tahap dihitung dari yang terjauh. SPR yang sudah wawancara atau SP3K tidak
     * dihitung "belum berkas" walau tanggal berkas lengkapnya tidak tercatat.
     */
    public const RINGKASAN = [
        'belum_berkas' => ['Belum berkas', 'belum ada tahap', 'zinc'],
        'belum_wawancara' => ['Berkas lengkap', 'belum wawancara', 'sky'],
        'belum_sp3k' => ['Sudah wawancara', 'belum SP3K', 'blue'],
        'sudah_sp3k' => ['SP3K terbit', 'semua', 'emerald'],
        'expiring' => ['SP3K ≤ 30 hari', 'segera habis', 'amber'],
        'sp3k_expired' => ['SP3K kedaluwarsa', 'belum akad', 'rose'],
        'lpa_wajib' => ['LPA BTN', 'belum diisi', 'violet'],
        'tidak_runtut' => ['Tanggal tidak runtut', 'perlu dicek', 'rose'],
        'akad_diset' => ['Rencana akad', 'sudah diset', 'zinc'],
    ];

    public function mount(): void
    {
        $this->selectedProyekId = \App\Support\ProyekAktif::sinkron($this->selectedProyekId);

        if (! in_array($this->tab, ['proses', 'akad', 'semua'], true)) {
            $this->tab = 'proses';
        }
    }

    public function pilihTab(string $tab): void
    {
        if (! in_array($tab, ['proses', 'akad', 'semua'], true)) {
            return;
        }

        $this->tab = $tab;
        $this->filterStatus = '';
        $this->batalInline();
        $this->resetPage();
    }

    public function pakaiTahap(string $kode): void
    {
        $this->filterStatus = $this->filterStatus === $kode ? '' : $kode;
        $this->resetPage();
    }

    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->selectedProyekId = $proyekId;
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'filterStatus', 'filterBank', 'filterSales', 'filterAkadPeriode', 'perPage', 'selectedProyekId'])) {
            $this->resetPage();
        }
        // Tanggal diganti: peringatan untuk tanggal sebelumnya tidak berlaku lagi.
        if (str_starts_with($property, 'val_') || $property === 'inlineValue') {
            $this->peringatanUrutan = [];
        }
        // Auto-fill sp3k_expired = sp3k_tanggal + 90 hari kalau belum di-set
        if ($property === 'val_sp3k_tanggal' && $this->val_sp3k_tanggal && ! $this->val_sp3k_expired) {
            $this->val_sp3k_expired = \Carbon\Carbon::parse($this->val_sp3k_tanggal)->addDays(90)->toDateString();
        }
        // Auto-format Nominal SP3K dengan separator ribuan (179000000 -> 179.000.000)
        if ($property === 'val_sp3k_nominal') {
            $digits = preg_replace('/\D/', '', (string) $this->val_sp3k_nominal);
            $this->val_sp3k_nominal = $digits !== '' ? number_format((int) $digits, 0, ',', '.') : '';
        }
    }

    /**
     * Buka modal edit field spesifik.
     * $field: bank | bm | wcr | sp3k | lpa (rencana akad diisi dari menu terpisah, bukan di sini)
     */
    public function openField(int $sprId, string $field): void
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola'), 403);

        $spr = Spr::with('pemberkasan')->findOrFail($sprId);
        $p = $spr->pemberkasan;

        // Guard: LPA cuma untuk BTN (CBN konvensional + BSY syariah)
        if ($field === 'lpa' && ! BankKpr::wajibLpa($p?->bank_kode)) {
            Flux::toast(variant: 'warning', text: 'LPA hanya wajib untuk Bank BTN (CBN / BSY). Pilih bank BTN dulu.');

            return;
        }

        $this->batalInline();
        $this->peringatanUrutan = [];
        $this->editSprId = $spr->id;
        $this->editPemberkasanId = $p?->id;
        $this->editingField = $field;
        $this->editingSprLabel = $spr->nomor_display;
        $this->uploadFile = null;
        $this->existingFileName = null;

        // Load current value(s) sesuai field
        match ($field) {
            'bank' => $this->val_bank_kode = $p?->bank_kode,
            'bm' => [
                $this->val_bm_tanggal = $p?->bm_tanggal?->toDateString(),
                $this->existingFileName = $p?->bm_file_original_name,
            ],
            'wcr' => [
                $this->val_wcr_tanggal = $p?->wcr_tanggal?->toDateString(),
                $this->val_wcr_catatan = $p?->wcr_catatan,
            ],
            'sp3k' => [
                $this->val_sp3k_tanggal = $p?->sp3k_tanggal?->toDateString(),
                $this->existingFileName = $p?->sp3k_file_original_name,
                $this->val_sp3k_nomor = $p?->sp3k_nomor,
                $this->val_sp3k_expired = $p?->sp3k_expired?->toDateString(),
                $this->val_sp3k_nominal = $p && (float) $p->sp3k_nominal > 0
                    ? number_format((float) $p->sp3k_nominal, 0, ',', '.')
                    : '',
            ],
            'lpa' => $this->val_lpa_tanggal = $p?->lpa_tanggal?->toDateString(),
            default => null,
        };

        $this->resetErrorBag();
        Flux::modal('edit-field')->show();
    }

    public function simpanField(bool $abaikanUrutan = false): void
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola'), 403);

        // Validate per field. Upload berkas untuk Berkas Lengkap dan SP3K.
        $berkas = ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'];
        $rules = match ($this->editingField) {
            'bank' => ['val_bank_kode' => ['required', 'exists:bank_kpr,kode']],
            'bm' => ['val_bm_tanggal' => ['required', 'date'], 'uploadFile' => $berkas],
            'wcr' => [
                'val_wcr_tanggal' => ['required', 'date'],
                'val_wcr_catatan' => ['nullable', 'string', 'max:255'],
            ],
            'sp3k' => [
                'val_sp3k_tanggal' => ['required', 'date'],
                'val_sp3k_nomor' => ['nullable', 'string', 'max:50'],
                'val_sp3k_expired' => ['nullable', 'date'],
                'val_sp3k_nominal' => ['required', 'string'],
                'uploadFile' => $berkas,
            ],
            'lpa' => ['val_lpa_tanggal' => ['required', 'date']],
            default => [],
        };
        $this->validate($rules);

        $spr = Spr::findOrFail($this->editSprId);

        $data = match ($this->editingField) {
            'bank' => ['bank_kode' => $this->val_bank_kode],
            'bm' => ['bm_tanggal' => $this->val_bm_tanggal],
            'wcr' => [
                'wcr_tanggal' => $this->val_wcr_tanggal,
                // Dikosongkan berarti catatannya dihapus, bukan dibiarkan yang lama.
                'wcr_catatan' => trim((string) $this->val_wcr_catatan) ?: null,
            ],
            'sp3k' => [
                'sp3k_tanggal' => $this->val_sp3k_tanggal,
                'sp3k_nomor' => $this->val_sp3k_nomor,
                'sp3k_expired' => $this->val_sp3k_expired,
                // Strip dot separator sebelum cast ("179.000.000" -> 179000000)
                'sp3k_nominal' => (float) preg_replace('/\D/', '', (string) $this->val_sp3k_nominal),
            ],
            'lpa' => ['lpa_tanggal' => $this->val_lpa_tanggal],
            default => [],
        };

        // Dicek sebelum berkas disimpan, supaya berkas tidak tertinggal saat penyimpanan ditahan.
        $this->peringatanUrutan = $abaikanUrutan ? [] : $this->cekUrutan($spr, $data);
        if ($this->peringatanUrutan !== []) {
            return;
        }

        if ($this->uploadFile && in_array($this->editingField, ['bm', 'sp3k'], true)) {
            $data[$this->editingField.'_file_path'] = $this->uploadFile->store("pemberkasan/{$spr->id}", 'private');
            $data[$this->editingField.'_file_original_name'] = $this->uploadFile->getClientOriginalName();
        }

        $this->simpanData($spr, $this->editingField, $data);

        Flux::modal('edit-field')->close();
        $this->resetFieldState();
    }

    /** Buka isian tanggal (atau bank) langsung di sel tabel. */
    public function mulaiInline(int $sprId, string $field): void
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola'), 403);

        if (! array_key_exists($field, self::KOLOM_CEPAT)) {
            return;
        }

        $p = SprPemberkasan::where('spr_id', $sprId)->first();

        if ($field === 'lpa' && ! BankKpr::wajibLpa($p?->bank_kode)) {
            Flux::toast(variant: 'warning', text: 'LPA hanya wajib untuk Bank BTN (CBN / BSY). Pilih bank BTN dulu.');

            return;
        }

        $this->batalInline();
        $this->inlineSprId = $sprId;
        $this->inlineField = $field;
        $nilai = $p?->getAttribute(self::KOLOM_CEPAT[$field]);
        $this->inlineValue = $nilai instanceof \DateTimeInterface ? $nilai->format('Y-m-d') : $nilai;
    }

    public function simpanInline(bool $abaikanUrutan = false): void
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola'), 403);

        $kolom = self::KOLOM_CEPAT[$this->inlineField] ?? null;

        if (! $this->inlineSprId || ! $kolom) {
            return;
        }

        $this->validate(
            ['inlineValue' => $this->inlineField === 'bank' ? ['required', 'exists:bank_kpr,kode'] : ['required', 'date']],
            [],
            ['inlineValue' => strtolower(self::LABEL_FIELD[$this->inlineField])],
        );

        $spr = Spr::findOrFail($this->inlineSprId);
        $data = [$kolom => $this->inlineValue];

        $this->peringatanUrutan = $abaikanUrutan ? [] : $this->cekUrutan($spr, $data);
        if ($this->peringatanUrutan !== []) {
            return;
        }

        $this->simpanData($spr, $this->inlineField, $data);
        $this->batalInline();
    }

    public function batalInline(): void
    {
        $this->reset(['inlineSprId', 'inlineField', 'inlineValue', 'peringatanUrutan']);
        $this->resetErrorBag('inlineValue');
    }

    /** Dari isian cepat ke jendela lengkap (unggah berkas, catatan wawancara). */
    public function bukaDetailInline(): void
    {
        [$sprId, $field] = [$this->inlineSprId, $this->inlineField];

        if ($sprId && $field) {
            $this->openField($sprId, $field);
        }
    }

    /** Simpan perubahan satu tahap beserta jejaknya di Monitoring. */
    private function simpanData(Spr $spr, string $field, array $data): void
    {
        $data['updated_by_user_id'] = Auth::id();
        $sebelum = SprPemberkasan::where('spr_id', $spr->id)->first();
        $nilaiLama = $this->nilaiPemberkasan($sebelum);

        if ($sebelum) {
            $sebelum->update($data);
            $sesudah = $sebelum;
        } else {
            $data['spr_id'] = $spr->id;
            $data['input_by_user_id'] = Auth::id();
            $sesudah = SprPemberkasan::create($data);
        }

        BusinessActivityLogger::pemberkasan(
            $spr,
            self::TAHAP_LOG[$field] ?? $field,
            $this->bedaNilai($nilaiLama, $this->nilaiPemberkasan($sesudah->fresh())),
        );

        Flux::toast(variant: 'success', text: "SPR {$spr->nomor_display} — ".self::LABEL_FIELD[$field].' disimpan.');
    }

    /**
     * Tanggal yang janggal untuk tahap yang sedang diisi.
     *
     * Hanya pasangan yang melibatkan tanggal yang diubah yang diperiksa, supaya
     * mengisi satu tahap tidak terhalang oleh kejanggalan lama di tahap lain.
     * Peringatan menahan penyimpanan sampai pengguna memilih "Tetap simpan".
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function cekUrutan(Spr $spr, array $data): array
    {
        $p = SprPemberkasan::where('spr_id', $spr->id)->first();
        $nilai = fn (string $kolom): ?string => array_key_exists($kolom, $data)
            ? ($data[$kolom] ? Carbon::parse($data[$kolom])->toDateString() : null)
            : $p?->getAttribute($kolom)?->toDateString();
        $tgl = fn (string $t): string => Carbon::parse($t)->format('d/m/Y');
        $label = ['bm_tanggal' => 'Berkas lengkap', 'wcr_tanggal' => 'Wawancara', 'sp3k_tanggal' => 'SP3K', 'lpa_tanggal' => 'LPA'];
        $kecil = ['bm_tanggal' => 'berkas lengkap', 'wcr_tanggal' => 'wawancara', 'sp3k_tanggal' => 'SP3K'];
        $pesan = [];

        foreach ($label as $kolom => $nama) {
            if (array_key_exists($kolom, $data) && $nilai($kolom) && $nilai($kolom) > today()->toDateString()) {
                $pesan[] = "{$nama} {$tgl($nilai($kolom))} belum terjadi.";
            }
        }

        foreach ([['bm_tanggal', 'wcr_tanggal'], ['wcr_tanggal', 'sp3k_tanggal'], ['bm_tanggal', 'sp3k_tanggal']] as [$awal, $akhir]) {
            $diubah = array_key_exists($awal, $data) || array_key_exists($akhir, $data);
            if ($diubah && $nilai($awal) && $nilai($akhir) && $nilai($akhir) < $nilai($awal)) {
                $pesan[] = "{$label[$akhir]} ({$tgl($nilai($akhir))}) lebih awal dari {$kecil[$awal]} ({$tgl($nilai($awal))}).";
            }
        }

        if (array_key_exists('sp3k_tanggal', $data) && $nilai('sp3k_tanggal') && $spr->tgl_akad
            && $nilai('sp3k_tanggal') > $spr->tgl_akad->toDateString()) {
            $pesan[] = "SP3K sesudah tanggal akad ({$spr->tgl_akad->format('d/m/Y')}).";
        }

        return $pesan;
    }

    /** Nama event Monitoring per field yang diedit. */
    private const TAHAP_LOG = ['bank' => 'bank', 'bm' => 'berkas', 'wcr' => 'wawancara', 'sp3k' => 'sp3k', 'lpa' => 'lpa'];

    private const KOLOM_PEMBERKASAN = [
        'bank_kode' => 'Bank',
        'bm_tanggal' => 'Tanggal berkas lengkap',
        'bm_file_original_name' => 'Berkas',
        'wcr_tanggal' => 'Tanggal wawancara',
        'wcr_catatan' => 'Catatan wawancara',
        'sp3k_tanggal' => 'Tanggal SP3K',
        'sp3k_nomor' => 'Nomor SP3K',
        'sp3k_expired' => 'SP3K berlaku sampai',
        'sp3k_nominal' => 'Nominal SP3K',
        'sp3k_file_original_name' => 'Berkas SP3K',
        'lpa_tanggal' => 'Tanggal LPA',
    ];

    /** @return array<string, ?string> */
    private function nilaiPemberkasan(?SprPemberkasan $p): array
    {
        $nilai = [];
        foreach (self::KOLOM_PEMBERKASAN as $kolom => $label) {
            $isi = $p?->getAttribute($kolom);
            $nilai[$label] = match (true) {
                $isi === null || $isi === '' => null,
                $isi instanceof \DateTimeInterface => $isi->format('d/m/Y'),
                $kolom === 'sp3k_nominal' => (float) $isi > 0 ? 'Rp '.number_format((float) $isi, 0, ',', '.') : null,
                default => (string) $isi,
            };
        }

        return $nilai;
    }

    /**
     * @param  array<string, ?string>  $lama
     * @param  array<string, ?string>  $baru
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    private function bedaNilai(array $lama, array $baru): array
    {
        $beda = [];
        foreach ($baru as $label => $nilai) {
            if (($lama[$label] ?? null) !== $nilai) {
                $beda[$label] = [$lama[$label] ?? null, $nilai];
            }
        }

        return $beda;
    }

    protected function fieldLabel(): string
    {
        return self::LABEL_FIELD[$this->editingField] ?? '';
    }

    protected function resetFieldState(): void
    {
        $this->reset([
            'editSprId', 'editPemberkasanId', 'editingField', 'editingSprLabel',
            'val_bank_kode', 'val_bm_tanggal', 'val_wcr_tanggal', 'val_wcr_catatan',
            'val_sp3k_tanggal', 'val_sp3k_nomor', 'val_sp3k_expired', 'val_sp3k_nominal',
            'val_lpa_tanggal',
            'uploadFile', 'existingFileName', 'peringatanUrutan',
        ]);
    }

    /** Unduh berkas lengkap (bm) atau lembar SP3K (sp3k). */
    public function downloadBerkas(int $pemberkasanId, string $jenis = 'bm'): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola') || Auth::user()?->can('pemberkasan.lihat'), 403);
        abort_unless(in_array($jenis, ['bm', 'sp3k'], true), 404);

        $p = SprPemberkasan::findOrFail($pemberkasanId);
        $path = $p->getAttribute($jenis.'_file_path');

        if (! $path || ! Storage::disk('private')->exists($path)) {
            Flux::toast(variant: 'warning', text: 'File tidak ditemukan.');

            return null;
        }

        return Storage::disk('private')->download($path, $p->getAttribute($jenis.'_file_original_name') ?? basename($path));
    }

    public function resetAllFilters(): void
    {
        $this->reset(['search', 'filterStatus', 'filterBank', 'filterSales', 'filterAkadPeriode']);
        $this->resetPage();
    }

    /**
     * SPR yang tampil sebelum disaring tahap: proyek, tab, pencarian, bank, sales.
     *
     * Dipisah dari tahap supaya angka ringkasan tetap menunjukkan sebaran meski
     * satu tahap sedang dipilih.
     */
    private function queryDasar(bool $pakaiTab = true): Builder
    {
        $q = Spr::query()
            ->whereIn('status', ['approved', 'akad'])
            ->where('jenis_pembayaran', 'kpr');

        // Selalu dibatasi proyek: tanpa proyek terpilih hasilnya kosong, bukan semua proyek.
        $q->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->selectedProyekId ?? 0));

        if ($pakaiTab) {
            match ($this->tab) {
                'proses' => $q->where('status', 'approved'),
                'akad' => $q->where('status', 'akad'),
                default => null,
            };
        }

        if ($this->search !== '') {
            $s = "%{$this->search}%";
            $q->where(function ($qq) use ($s) {
                $qq->where('nomor_spr', 'like', $s)
                    ->orWhereHas('prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', $s)->orWhere('nik', 'like', $s))
                    ->orWhereHas('rumah', fn ($r) => $r->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", [Rumah::normalCari($s)]));
            });
        }

        // Filter bank
        if ($this->filterBank !== '') {
            if ($this->filterBank === '__EMPTY__') {
                $q->where(function ($qq) {
                    $qq->whereDoesntHave('pemberkasan')
                        ->orWhereHas('pemberkasan', fn ($p) => $p->whereNull('bank_kode'));
                });
            } else {
                $q->whereHas('pemberkasan', fn ($p) => $p->where('bank_kode', $this->filterBank));
            }
        }

        // Filter sales
        if ($this->filterSales !== '') {
            $q->where('sales_id', $this->filterSales);
        }

        return $q;
    }

    /**
     * Saring per tahap. "Belum" dihitung dari tahap terjauh yang sudah tercapai dan
     * hanya untuk SPR yang belum akad — SPR yang sudah wawancara tidak lagi terhitung
     * belum berkas hanya karena tanggal berkas lengkapnya tidak tercatat.
     */
    private function terapkanTahap(Builder $q, string $kode): Builder
    {
        match ($kode) {
            'belum_berkas' => $q->where('status', 'approved')->where(fn ($qq) => $qq
                ->whereDoesntHave('pemberkasan')
                ->orWhereHas('pemberkasan', fn ($p) => $p->whereNull('bm_tanggal')->whereNull('wcr_tanggal')->whereNull('sp3k_tanggal'))),
            'belum_wawancara' => $q->where('status', 'approved')->whereHas('pemberkasan', fn ($p) => $p
                ->whereNotNull('bm_tanggal')->whereNull('wcr_tanggal')->whereNull('sp3k_tanggal')),
            'belum_sp3k' => $q->where('status', 'approved')->whereHas('pemberkasan', fn ($p) => $p
                ->whereNotNull('wcr_tanggal')->whereNull('sp3k_tanggal')),
            'sudah_sp3k' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('sp3k_tanggal')),
            'expiring' => $q->where('status', 'approved')->whereHas('pemberkasan', fn ($p) => $p
                ->whereNotNull('sp3k_expired')->whereBetween('sp3k_expired', [today(), today()->addDays(30)])),
            'sp3k_expired' => $q->where('status', 'approved')->whereHas('pemberkasan', fn ($p) => $p
                ->whereNotNull('sp3k_expired')->where('sp3k_expired', '<', today())),
            'lpa_wajib' => $q->whereHas('pemberkasan', fn ($p) => $p->whereIn('bank_kode', BankKpr::kodeWajibLpa())
                ->whereNotNull('sp3k_tanggal')->whereNull('lpa_tanggal')),
            'tidak_runtut' => $q->whereHas('pemberkasan', fn ($p) => $p->where(fn ($w) => $w
                ->whereColumn('wcr_tanggal', '<', 'bm_tanggal')
                ->orWhereColumn('sp3k_tanggal', '<', 'wcr_tanggal')
                ->orWhereColumn('sp3k_tanggal', '<', 'bm_tanggal'))),
            'akad_diset' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('rencana_akad_tanggal')),
            default => null,
        };

        return $q;
    }

    /** @return array<string, callable> */
    private function kolomUrut(): array
    {
        $berkas = fn (string $kolom) => fn ($q, string $arah) => $q->orderBy(
            SprPemberkasan::select($kolom)->whereColumn('spr_pemberkasan.spr_id', 'spr.id')->limit(1), $arah);
        $rumah = fn () => Rumah::query()->whereColumn('rumah.id', 'spr.rumah_id')->limit(1);

        return [
            'spr' => fn ($q, string $arah) => $q->orderBy('nomor_spr', $arah),
            // Nomor unit disimpan sebagai teks: diurutkan per panjang dulu supaya AE-9 tidak mendahului AE-10.
            'blok' => fn ($q, string $arah) => $q->orderBy($rumah()->select('blok'), $arah)
                ->orderBy($rumah()->selectRaw('LENGTH(nomor_unit)'), $arah)
                ->orderBy($rumah()->select('nomor_unit'), $arah),
            'customer' => fn ($q, string $arah) => $q->orderBy(ProspectCustomer::select('nama_lengkap')
                ->whereColumn('prospect_customer.id', 'spr.prospect_customer_id')->limit(1), $arah),
            'bm' => $berkas('bm_tanggal'),
            'wcr' => $berkas('wcr_tanggal'),
            'sp3k' => $berkas('sp3k_tanggal'),
            'lpa' => $berkas('lpa_tanggal'),
            'rencana_akad' => $berkas('rencana_akad_tanggal'),
            'akad' => fn ($q, string $arah) => $q->orderBy('tgl_akad', $arah),
        ];
    }

    public function with(): array
    {
        $dasar = $this->queryDasar();
        $q = (clone $dasar)->with(['prospectCustomer:id,nama_lengkap,nik', 'sales:id,nama',
            'rumah:id,proyek_id,blok,nomor_unit', 'pemberkasan',
            'realisasiPembayaran:id,spr_id,jenis,jumlah']);

        $this->terapkanTahap($q, $this->filterStatus);

        // Filter rencana akad periode
        match ($this->filterAkadPeriode) {
            'week' => $q->whereHas('pemberkasan', fn ($p) => $p->whereBetween('rencana_akad_tanggal', [now(), now()->addDays(7)])),
            'month' => $q->whereHas('pemberkasan', fn ($p) => $p->whereBetween('rencana_akad_tanggal', [now(), now()->addDays(30)])),
            'passed' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('rencana_akad_tanggal')
                ->where('rencana_akad_tanggal', '<', now())),
            default => null,
        };

        if ($this->sortBy !== '') {
            $this->applySort($q, $this->kolomUrut());
            $q->orderByDesc('spr.id');
        } else {
            $q->orderByDesc('tanggal_spr')->orderByDesc('id');
        }

        $tanpaTab = $this->queryDasar(false);

        return [
            'sprs' => $q->paginate((int) $this->perPage),
            'jumlahTab' => [
                'proses' => (clone $tanpaTab)->where('status', 'approved')->count(),
                'akad' => (clone $tanpaTab)->where('status', 'akad')->count(),
                'semua' => (clone $tanpaTab)->count(),
            ],
            'ringkasan' => collect(self::RINGKASAN)
                ->map(fn ($info, $kode) => $this->terapkanTahap(clone $dasar, $kode)->count())
                ->all(),
            'proyekList' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek']),
            'salesList' => \App\Models\Master\Sales::where('is_aktif', true)->orderBy('nama')->get(['id', 'nama']),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-orange-500 to-orange-700 text-white shadow-sm">
                    <flux:icon.folder-open class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Input Pemberkasan') }}</flux:heading>
                </div>
            </div>
            <x-tombol-panduan modul="pemberkasan" class="self-center" />
        </div>

        {{-- FILTER --}}
        <div class="mb-4 space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            {{-- Row 1: Search + Reset + Per Page --}}
            <div class="flex flex-wrap items-center gap-3">
                <div class="min-w-64 flex-1">
                    <flux:input wire:model.live.debounce.300ms="search" placeholder="Cari SPR / customer / NIK / blok..." icon="magnifying-glass" />
                </div>
                @if ($search || $filterStatus || $filterBank || $filterSales || $filterAkadPeriode)
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="resetAllFilters">Reset</flux:button>
                @endif
                <flux:select wire:model.live="perPage" size="sm">
                    <flux:select.option value="25">25 baris</flux:select.option>
                    <flux:select.option value="50">50 baris</flux:select.option>
                    <flux:select.option value="100">100 baris</flux:select.option>
                    <flux:select.option value="500">500 baris</flux:select.option>
                </flux:select>
            </div>

            {{-- Row 2: Dropdown filters --}}
            <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-end sm:gap-3">
                <div class="sm:min-w-40">
                    <flux:select wire:model.live="filterBank" placeholder="Semua Bank" size="sm">
                        <flux:select.option value="">Semua Bank</flux:select.option>
                        <flux:select.option value="__EMPTY__">— (Belum di-set)</flux:select.option>
                        @foreach (\App\Models\Master\SprPemberkasan::pilihanBank() as $kode => $label)
                            <flux:select.option value="{{ $kode }}">{{ $kode }} — {{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="sm:min-w-40">
                    <flux:select wire:model.live="filterSales" placeholder="Semua Sales" size="sm">
                        <flux:select.option value="">Semua Sales</flux:select.option>
                        @foreach ($salesList as $sl)
                            <flux:select.option value="{{ $sl->id }}">{{ $sl->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="col-span-2 sm:min-w-44">
                    <flux:select wire:model.live="filterAkadPeriode" placeholder="Rencana Akad" size="sm">
                        <flux:select.option value="">Rencana Akad — Semua</flux:select.option>
                        <flux:select.option value="week">Minggu Ini (7h)</flux:select.option>
                        <flux:select.option value="month">Bulan Ini (30h)</flux:select.option>
                        <flux:select.option value="passed">Sudah Lewat</flux:select.option>
                    </flux:select>
                </div>
            </div>
        </div>

        @if (! $selectedProyekId)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                Pilih proyek dulu di picker "Proyek Aktif" pada sidebar kiri untuk menampilkan data pemberkasan.
            </div>
        @else
            {{-- TAB: yang masih berproses dipisah dari yang sudah akad --}}
            <div class="mb-3 flex w-full rounded-full border border-zinc-200 bg-white p-1 sm:inline-flex sm:w-auto dark:border-zinc-700 dark:bg-zinc-900">
                @foreach (['proses' => 'Proses KPR', 'akad' => 'Sudah akad', 'semua' => 'Semua'] as $kode => $label)
                    <button type="button" wire:click="pilihTab('{{ $kode }}')"
                            @class([
                                'flex-1 whitespace-nowrap rounded-full px-2 py-1.5 text-xs font-semibold transition-colors sm:flex-none sm:px-4',
                                'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900' => $tab === $kode,
                                'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $tab !== $kode,
                            ])>
                        {{ $label }}
                        <span @class(['ml-1 font-mono tabular-nums', 'opacity-70' => $tab === $kode, 'text-zinc-400' => $tab !== $kode])>{{ $jumlahTab[$kode] }}</span>
                    </button>
                @endforeach
            </div>

            {{-- RINGKASAN TAHAP — klik untuk menyaring, klik lagi untuk melepas --}}
            @php
                $warnaAngka = [
                    'zinc' => 'text-zinc-700 dark:text-zinc-300',
                    'sky' => 'text-sky-700 dark:text-sky-400',
                    'blue' => 'text-blue-700 dark:text-blue-400',
                    'emerald' => 'text-emerald-700 dark:text-emerald-400',
                    'amber' => 'text-amber-700 dark:text-amber-400',
                    'rose' => 'text-rose-700 dark:text-rose-400',
                    'violet' => 'text-violet-700 dark:text-violet-400',
                ];
            @endphp
            <div class="mb-4 flex gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0">
                @foreach ($this::RINGKASAN as $kode => [$judul, $keterangan, $warna])
                    @continue(($ringkasan[$kode] ?? 0) === 0 && $filterStatus !== $kode)
                    @php $aktif = $filterStatus === $kode; @endphp
                    <button type="button" wire:click="pakaiTahap('{{ $kode }}')"
                            @class([
                                'flex shrink-0 items-baseline gap-2 whitespace-nowrap rounded-lg border bg-white px-3 py-2 text-left transition dark:bg-zinc-900',
                                'border-zinc-900 ring-1 ring-zinc-900 dark:border-white dark:ring-white' => $aktif,
                                'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700' => ! $aktif,
                            ])>
                        <span class="font-mono text-lg font-bold tabular-nums {{ $warnaAngka[$warna] }}">{{ $ringkasan[$kode] ?? 0 }}</span>
                        <span class="leading-tight">
                            <span class="block text-xs font-semibold text-zinc-800 dark:text-zinc-200">{{ $judul }}</span>
                            <span class="block text-[10px] text-zinc-500">{{ $keterangan }}</span>
                        </span>
                    </button>
                @endforeach
            </div>

            {{-- TABLE — setiap cell editable (klik langsung).
                 Kolom Identitas dibekukan di kiri supaya konsumennya tetap terbaca saat
                 tabel digeser ke kolom tahapan. Lebarnya tetap karena posisi tempel
                 tiap kolom dihitung dari lebar kolom sebelumnya. Di layar HP keempatnya
                 lebih lebar dari layar, jadi yang dibekukan hanya Blok. --}}
            @php
                $bekuEdge = 'md:shadow-[inset_-1px_0_0_var(--color-zinc-300)] dark:md:shadow-[inset_-1px_0_0_var(--color-zinc-600)]';
                $beku = [
                    'spr' => 'md:sticky md:left-0 md:z-10 w-20 min-w-20 max-w-20',
                    'blok' => 'sticky left-0 z-10 md:left-20 w-16 min-w-16 max-w-16 max-md:shadow-[inset_-1px_0_0_var(--color-zinc-300)] dark:max-md:shadow-[inset_-1px_0_0_var(--color-zinc-600)]',
                    'customer' => 'md:sticky md:left-36 md:z-10 w-40 min-w-40 max-w-40',
                    'sales' => 'md:sticky md:left-76 md:z-10 w-32 min-w-32 max-w-32 '.$bekuEdge,
                ];
                $bekuIsi = 'bg-white dark:bg-zinc-900';
                $bekuKepala = 'bg-zinc-50 dark:bg-zinc-800';
            @endphp
            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            {{-- Row 1: Group category --}}
                            <tr class="border-b border-zinc-200 bg-zinc-100 text-center uppercase text-[10px] font-bold text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                <th colspan="4" class="bg-zinc-100 px-3 py-1.5 md:sticky md:left-0 md:z-10 dark:bg-zinc-800 {{ $bekuEdge }}">Identitas</th>
                                <th colspan="5" class="border-l border-zinc-300 px-3 py-1.5 dark:border-zinc-600">Harga &amp; UM</th>
                                <th colspan="3" class="border-l border-zinc-300 px-3 py-1.5 dark:border-zinc-600">KPR</th>
                                <th colspan="6" class="border-l border-zinc-300 px-3 py-1.5 dark:border-zinc-600">Tahapan Pemberkasan</th>
                            </tr>
                            {{-- Row 2: Detail column --}}
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-left uppercase text-[10px] font-semibold text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                                <x-th-urut field="spr" :sort-by="$sortBy" :sort-dir="$sortDir" class="{{ $beku['spr'] }} {{ $bekuKepala }}">SPR</x-th-urut>
                                <x-th-urut field="blok" :sort-by="$sortBy" :sort-dir="$sortDir" class="{{ $beku['blok'] }} {{ $bekuKepala }}">Blok</x-th-urut>
                                <x-th-urut field="customer" :sort-by="$sortBy" :sort-dir="$sortDir" class="{{ $beku['customer'] }} {{ $bekuKepala }}">Customer</x-th-urut>
                                <th class="{{ $beku['sales'] }} {{ $bekuKepala }} px-3 py-2.5">Sales</th>
                                <th class="border-l border-zinc-300 px-3 py-2.5 text-right dark:border-zinc-600">Harga Jual AJB</th>
                                <th class="px-3 py-2.5 text-right">Harga All In</th>
                                <th class="px-3 py-2.5 text-right">Besaran UM</th>
                                <th class="px-3 py-2.5 text-right">UM Dibayar</th>
                                <th class="px-3 py-2.5 text-right">%</th>
                                <th class="border-l border-zinc-300 px-3 py-2.5 dark:border-zinc-600">Bank</th>
                                <th class="px-3 py-2.5 text-right">Pengajuan KPR</th>
                                <th class="px-3 py-2.5 text-right">Setuju Bank</th>
                                <x-th-urut field="bm" :sort-by="$sortBy" :sort-dir="$sortDir" class="border-l border-zinc-300 dark:border-zinc-600">Berkas Lengkap</x-th-urut>
                                <x-th-urut field="wcr" :sort-by="$sortBy" :sort-dir="$sortDir">Wawancara</x-th-urut>
                                <x-th-urut field="sp3k" :sort-by="$sortBy" :sort-dir="$sortDir">SP3K</x-th-urut>
                                <x-th-urut field="lpa" :sort-by="$sortBy" :sort-dir="$sortDir">LPA</x-th-urut>
                                <x-th-urut field="rencana_akad" :sort-by="$sortBy" :sort-dir="$sortDir">Rencana Akad</x-th-urut>
                                <x-th-urut field="akad" :sort-by="$sortBy" :sort-dir="$sortDir">Akad</x-th-urut>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @php
                                $fmt = fn ($v) => number_format((float) $v, 0, ',', '.');
                                $canEdit = Auth::user()?->can('pemberkasan.kelola');
                                $editableCell = 'group relative cursor-pointer transition hover:bg-blue-50 dark:hover:bg-blue-950/20';
                                $editPencil = '<svg xmlns="http://www.w3.org/2000/svg" class="ml-1 inline size-3 opacity-0 group-hover:opacity-60" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>';
                            @endphp
                            @forelse ($sprs as $s)
                                @php
                                    $p = $s->pemberkasan;
                                    $sp3kExpIn = $p?->sp3kExpiredIn();
                                    $sp3kCls = match (true) {
                                        $sp3kExpIn === null => '',
                                        $sp3kExpIn < 0 => 'text-rose-700 bg-rose-50 dark:text-rose-400 dark:bg-rose-950/30',
                                        $sp3kExpIn <= 7 => 'text-rose-700 bg-rose-50 dark:text-rose-400 dark:bg-rose-950/30',
                                        $sp3kExpIn <= 14 => 'text-orange-700 bg-orange-50 dark:text-orange-400 dark:bg-orange-950/30',
                                        $sp3kExpIn <= 30 => 'text-amber-700 bg-amber-50 dark:text-amber-400 dark:bg-amber-950/30',
                                        default => 'text-zinc-700',
                                    };
                                    // Progress UM (BF + UM) — bantu admin cek DP sebelum acc SP3K
                                    $umTarget = $s->umKonsumen();
                                    $umTerbayar = (float) $s->realisasiPembayaran->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
                                    $umPersen = $umTarget > 0 ? round($umTerbayar / $umTarget * 100, 1) : 0;
                                    // Kelebihan bayar ditulis nominalnya, bukan cuma jadi persen di atas 100.
                                    // Uang itu hak refund konsumen, jadi admin perlu lihat angkanya langsung.
                                    $umLebih = $umTarget > 0 ? max(0, $umTerbayar - $umTarget) : 0;
                                    $umPersenCls = match (true) {
                                        $umPersen >= 100 => 'text-emerald-700 dark:text-emerald-400',
                                        $umPersen >= 50 => 'text-blue-700 dark:text-blue-400',
                                        $umPersen > 0 => 'text-amber-700 dark:text-amber-400',
                                        default => 'text-zinc-400',
                                    };
                                    // Sel yang sedang diisi langsung (tanpa jendela).
                                    $sedang = fn (string $f) => $inlineSprId === $s->id && $inlineField === $f;
                                    // Tahap sesudahnya sudah jalan tapi tanggal berkas lengkap tidak pernah dicatat.
                                    $bmTakTercatat = ! $p?->bm_tanggal && ($p?->wcr_tanggal || $p?->sp3k_tanggal || $s->status === 'akad');
                                    $mundur = fn ($awal, $akhir) => $awal && $akhir && $akhir->lt($awal);
                                    $wcrJanggal = $mundur($p?->bm_tanggal, $p?->wcr_tanggal);
                                    $sp3kJanggal = $mundur($p?->wcr_tanggal, $p?->sp3k_tanggal) || $mundur($p?->bm_tanggal, $p?->sp3k_tanggal);
                                    $ikonJanggal = '<svg xmlns="http://www.w3.org/2000/svg" class="ml-1 inline size-3 text-rose-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>';
                                @endphp
                                <tr>
                                    {{-- SPR (link ke detail) --}}
                                    <td class="{{ $beku['spr'] }} {{ $bekuIsi }} whitespace-nowrap px-3 py-2 font-mono">
                                        <a href="{{ route('marketing.spr.show', $s->id) }}" class="text-blue-600 hover:underline dark:text-blue-400" wire:navigate>
                                            {{ $s->nomor_display }}
                                        </a>
                                    </td>
                                    <td class="{{ $beku['blok'] }} {{ $bekuIsi }} whitespace-nowrap px-3 py-2 font-mono">{{ $s->rumah?->kode_unit }}</td>
                                    <td class="{{ $beku['customer'] }} {{ $bekuIsi }} px-3 py-2">{{ $s->prospectCustomer?->nama_lengkap ?? '—' }}</td>
                                    <td class="{{ $beku['sales'] }} {{ $bekuIsi }} px-3 py-2">{{ $s->sales?->nama ?? '—' }}</td>

                                    {{-- Harga Jual AJB (read-only dari spr.harga_jual) — awal grup Harga & UM --}}
                                    <td class="whitespace-nowrap border-l border-zinc-200 px-3 py-2 text-right font-mono tabular-nums dark:border-zinc-700">{{ $fmt($s->harga_jual) }}</td>

                                    {{-- Harga All In (read-only dari spr.total_harga) --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums font-semibold">{{ $fmt($s->total_harga) }}</td>

                                    {{-- Besaran UM (target UM dari spr.um_net) --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">{{ $umTarget > 0 ? $fmt($umTarget) : '—' }}</td>

                                    {{-- UM Sudah Dibayar (jumlah realisasi BF + UM) --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums {{ $umTerbayar > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-400' }}">
                                        {{ $umTerbayar > 0 ? $fmt($umTerbayar) : '—' }}
                                        @if ($umLebih > 0)
                                            <div class="text-[10px] font-semibold text-amber-700 dark:text-amber-400" title="{{ __('Kelebihan bayar — perlu dikembalikan ke konsumen') }}">
                                                {{ __('lebih') }} {{ $fmt($umLebih) }}
                                            </div>
                                        @endif
                                    </td>

                                    {{-- Persen progress UM --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums font-semibold {{ $umPersenCls }}">
                                        {{ $umTarget > 0 ? number_format($umPersen, 1, ',', '.').'%' : '—' }}
                                    </td>

                                    {{-- Bank (editable) — awal grup KPR --}}
                                    <td @class(['whitespace-nowrap border-l border-zinc-200 px-3 py-2 dark:border-zinc-700', $editableCell => $canEdit && ! $sedang('bank')])
                                        @if ($canEdit && ! $sedang('bank')) wire:click="mulaiInline({{ $s->id }}, 'bank')" title="Klik untuk pilih bank" @endif>
                                        @if ($sedang('bank'))
                                            <x-pemberkasan.isi-cepat jenis="bank" :peringatan="$peringatanUrutan" />
                                        @else
                                            @if ($p?->bank_kode)
                                                <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ $p->bank_kode }}</span>
                                            @else
                                                <span class="text-zinc-400">—</span>
                                            @endif
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @endif
                                    </td>

                                    {{-- Pengajuan KPR (spr.nilai_kpr) --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">{{ $fmt($s->nilai_kpr) }}</td>

                                    {{-- KPR Setuju Bank (nominal SP3K — pindah ke grup KPR) --}}
                                    <td @class(['whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums', $editableCell => $canEdit])
                                        @if ($canEdit) wire:click="openField({{ $s->id }}, 'sp3k')" title="Klik untuk edit nominal SP3K" @endif>
                                        {{ $p && (float) $p->sp3k_nominal > 0 ? $fmt($p->sp3k_nominal) : '—' }}
                                        @if ($canEdit) {!! $editPencil !!} @endif
                                    </td>

                                    {{-- Berkas Lengkap (editable) — awal grup Tahapan Pemberkasan --}}
                                    <td @class(['whitespace-nowrap border-l border-zinc-200 px-3 py-2 dark:border-zinc-700', $editableCell => $canEdit && ! $sedang('bm')])
                                        @if ($canEdit && ! $sedang('bm')) wire:click="mulaiInline({{ $s->id }}, 'bm')" title="Klik untuk isi tanggal berkas lengkap" @endif>
                                        @if ($sedang('bm'))
                                            <x-pemberkasan.isi-cepat detail="Unggah berkas lengkap" :peringatan="$peringatanUrutan" />
                                        @elseif (! $p?->bm_tanggal)
                                            @if ($bmTakTercatat)
                                                <span class="italic text-zinc-400" title="Tahap sesudahnya sudah jalan, tapi tanggal berkas lengkap belum dicatat">tidak tercatat</span>
                                            @else
                                                <span class="text-zinc-400">—</span>
                                            @endif
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @else
                                            <span>{{ $p->bm_tanggal?->format('d/m/y') }}</span>
                                            @if ($p->bm_file_path)
                                                <button type="button" wire:click.stop="downloadBerkas({{ $p->id }}, 'bm')" title="{{ $p->bm_file_original_name }}" class="ml-1 text-blue-500 hover:text-blue-700">
                                                    <flux:icon.paper-clip class="inline size-3" />
                                                </button>
                                            @endif
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @endif
                                    </td>

                                    {{-- WCR (editable) --}}
                                    <td @class(['whitespace-nowrap px-3 py-2', $editableCell => $canEdit && ! $sedang('wcr')])
                                        @if ($canEdit && ! $sedang('wcr')) wire:click="mulaiInline({{ $s->id }}, 'wcr')" title="Klik untuk isi tanggal wawancara" @endif>
                                        @if ($sedang('wcr'))
                                            <x-pemberkasan.isi-cepat detail="Tambah catatan wawancara" :peringatan="$peringatanUrutan" />
                                        @elseif ($p?->wcr_tanggal)
                                            <div>
                                                {{ $p->wcr_tanggal->format('d/m/y') }}
                                                @if ($wcrJanggal) <span title="Lebih awal dari tanggal berkas lengkap">{!! $ikonJanggal !!}</span> @endif
                                                @if ($canEdit) {!! $editPencil !!} @endif
                                            </div>
                                            @if ($p->wcr_catatan)
                                                {{-- Dipotong supaya kolom tidak melebar; teks penuhnya muncul saat ditunjuk. --}}
                                                <div class="max-w-36 truncate text-[10px] leading-tight text-zinc-500 dark:text-zinc-400"
                                                    title="{{ $p->wcr_catatan }}">{{ $p->wcr_catatan }}</div>
                                            @endif
                                        @else
                                            <span class="text-zinc-400">—</span>
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @endif
                                    </td>

                                    {{-- SP3K (editable) — tanggal + expired badge --}}
                                    <td @class(['whitespace-nowrap px-3 py-2', $editableCell => $canEdit])
                                        @if ($canEdit) wire:click="openField({{ $s->id }}, 'sp3k')" title="Klik untuk input SP3K" @endif>
                                        @if ($p?->sp3k_tanggal)
                                            <div>
                                                {{ $p->sp3k_tanggal->format('d/m/y') }}
                                                @if ($sp3kJanggal) <span title="Lebih awal dari tanggal wawancara atau berkas lengkap">{!! $ikonJanggal !!}</span> @endif
                                                @if ($p->sp3k_file_path)
                                                    <button type="button" wire:click.stop="downloadBerkas({{ $p->id }}, 'sp3k')" title="{{ $p->sp3k_file_original_name }}" class="ml-1 text-blue-500 hover:text-blue-700">
                                                        <flux:icon.paper-clip class="inline size-3" />
                                                    </button>
                                                @endif
                                            </div>
                                            @if ($p->sp3k_expired && $sp3kExpIn !== null)
                                                <div class="text-[10px] rounded px-1 inline-block {{ $sp3kCls }}">
                                                    @if ($sp3kExpIn < 0)
                                                        Expired {{ abs($sp3kExpIn) }}h lalu
                                                    @else
                                                        Exp {{ $sp3kExpIn }}h lagi
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @else
                                            <span class="text-zinc-400">—</span>
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @endif
                                    </td>

                                    {{-- LPA — cuma untuk CBN (editable) --}}
                                    <td @class(['whitespace-nowrap px-3 py-2', $editableCell => $canEdit && BankKpr::wajibLpa($p?->bank_kode) && ! $sedang('lpa')])
                                        @if ($canEdit && BankKpr::wajibLpa($p?->bank_kode) && ! $sedang('lpa')) wire:click="mulaiInline({{ $s->id }}, 'lpa')" title="Klik untuk isi tanggal LPA" @endif>
                                        @if ($sedang('lpa'))
                                            <x-pemberkasan.isi-cepat :peringatan="$peringatanUrutan" />
                                        @elseif (BankKpr::wajibLpa($p?->bank_kode))
                                            @if ($p->lpa_tanggal)
                                                {{ $p->lpa_tanggal->format('d/m/y') }}
                                            @else
                                                <span class="text-zinc-400">—</span>
                                            @endif
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @else
                                            <span class="text-zinc-400" title="LPA hanya untuk BTN (CBN/BSY)">N/A</span>
                                        @endif
                                    </td>

                                    {{-- Rencana Akad — READONLY (auto-fill dari menu Rencana Akad, dibuat terpisah) --}}
                                    <td class="whitespace-nowrap px-3 py-2" title="Diisi otomatis dari menu Rencana Akad">
                                        @if ($p?->rencana_akad_tanggal)
                                            {{ $p->rencana_akad_tanggal->format('d/m/y') }}
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>

                                    {{-- Akad — READONLY (auto-fill dari SPR.tgl_akad setelah akad AJB dilakukan) --}}
                                    <td class="whitespace-nowrap px-3 py-2" title="Diisi otomatis dari tanggal akad AJB di SPR">
                                        @if ($s->tgl_akad)
                                            <span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $s->tgl_akad->format('d/m/y') }}</span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="18" class="px-4 py-12 text-center text-zinc-400">
                                        Tidak ada SPR KPR yang butuh pemberkasan di proyek ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    {{ $sprs->links() }}
                </div>
            </div>
        @endif
    </div>

    {{-- MODAL EDIT PER FIELD --}}
    <flux:modal name="edit-field" class="md:w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Edit {{ $this->fieldLabel() }}</flux:heading>
                <flux:subheading>SPR {{ $editingSprLabel }}</flux:subheading>
            </div>

            {{-- BANK --}}
            @if ($editingField === 'bank')
                <flux:select wire:model="val_bank_kode" label="Bank KPR" required>
                    <flux:select.option value="">-- Pilih Bank --</flux:select.option>
                    @foreach (\App\Models\Master\SprPemberkasan::pilihanBank() as $kode => $label)
                        <flux:select.option value="{{ $kode }}">{{ $kode }} — {{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                @error('val_bank_kode') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            @endif

            {{-- Berkas Lengkap --}}
            @if ($editingField === 'bm')
                <flux:input type="date" wire:model="val_bm_tanggal" label="Tanggal Berkas Lengkap Diterima" required />
                @error('val_bm_tanggal') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                <div>
                    <label class="mb-1 block text-sm font-medium">Upload Berkas Lengkap (PDF/JPG, max 5MB, opsional)</label>
                    <input type="file" wire:model="uploadFile" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />

                    {{-- Unggahan berjalan di belakang layar begitu berkas dipilih; tanpa
                         penanda ini Simpan bisa ditekan sebelum berkasnya selesai terunggah. --}}
                    <div wire:loading wire:target="uploadFile"
                         class="mt-1.5 flex items-center gap-1.5 text-[11px] font-medium text-amber-600 dark:text-amber-400">
                        <flux:icon.arrow-path class="size-3 animate-spin" />
                        Mengunggah — tunggu sampai selesai sebelum menyimpan.
                    </div>
                    @if ($uploadFile)
                        <div wire:loading.remove wire:target="uploadFile"
                             class="mt-1.5 flex items-center gap-1.5 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                            <flux:icon.check-circle class="size-3 shrink-0" />
                            <span class="truncate">{{ $uploadFile->getClientOriginalName() }}</span>
                            <span class="shrink-0 text-zinc-500">— siap disimpan</span>
                        </div>
                    @endif
                    @if ($existingFileName)
                        <div class="mt-1 text-[11px] text-zinc-600 dark:text-zinc-400">
                            <flux:icon.paper-clip class="inline size-3" /> {{ $existingFileName }} (upload baru akan replace)
                        </div>
                    @endif
                    @error('uploadFile') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
                </div>
            @endif

            {{-- WCR --}}
            @if ($editingField === 'wcr')
                <flux:input type="date" wire:model="val_wcr_tanggal" label="Tanggal Wawancara" required />
                @error('val_wcr_tanggal') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                <flux:input
                    wire:model="val_wcr_catatan"
                    label="Catatan Wawancara"
                    placeholder="opsional"
                    maxlength="255"
                    description="Tampil di bawah tanggal pada tabel. Mis. wawancara ulang, diwakilkan pasangan." />
                @error('val_wcr_catatan') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            @endif

            {{-- SP3K: tanggal + nomor + expired + nominal, lembar SP3K opsional --}}
            @if ($editingField === 'sp3k')
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <flux:input type="date" wire:model.live="val_sp3k_tanggal" label="Tanggal SP3K" required />
                    <flux:input wire:model="val_sp3k_nomor" label="Nomor SP3K" placeholder="opsional" />
                    <flux:input type="date" wire:model="val_sp3k_expired" label="Expired (auto +90h)" />
                    <flux:input wire:model.live.debounce.300ms="val_sp3k_nominal" label="Nominal KPR Disetujui (Rp)" placeholder="0" required inputmode="numeric" description="Ketik angka saja, separator dot otomatis." />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Upload Lembar SP3K (PDF/JPG, max 5MB, opsional)</label>
                    <input type="file" wire:model="uploadFile" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />
                    <div wire:loading wire:target="uploadFile"
                         class="mt-1.5 flex items-center gap-1.5 text-[11px] font-medium text-amber-600 dark:text-amber-400">
                        <flux:icon.arrow-path class="size-3 animate-spin" />
                        Mengunggah — tunggu sampai selesai sebelum menyimpan.
                    </div>
                    @if ($uploadFile)
                        <div wire:loading.remove wire:target="uploadFile"
                             class="mt-1.5 flex items-center gap-1.5 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                            <flux:icon.check-circle class="size-3 shrink-0" />
                            <span class="truncate">{{ $uploadFile->getClientOriginalName() }}</span>
                            <span class="shrink-0 text-zinc-500">— siap disimpan</span>
                        </div>
                    @endif
                    @if ($existingFileName)
                        <div class="mt-1 text-[11px] text-zinc-600 dark:text-zinc-400">
                            <flux:icon.paper-clip class="inline size-3" /> {{ $existingFileName }} (upload baru akan replace)
                        </div>
                    @endif
                    @error('uploadFile') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
                </div>
            @endif

            {{-- LPA (tanpa upload — cuma tanggal) --}}
            @if ($editingField === 'lpa')
                <flux:input type="date" wire:model="val_lpa_tanggal" label="Tanggal LPA" required />
                @error('val_lpa_tanggal') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            @endif

            @if ($peringatanUrutan && $editingField)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                    <p class="font-semibold">Tanggalnya janggal — periksa dulu:</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-4">
                        @foreach ($peringatanUrutan as $pesan)
                            <li>{{ $pesan }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                @if ($peringatanUrutan && $editingField)
                    <flux:button variant="filled" wire:click="simpanField(true)" wire:loading.attr="disabled" wire:target="uploadFile">Tetap simpan</flux:button>
                @else
                    <flux:button variant="primary" wire:click="simpanField" wire:loading.attr="disabled" wire:target="uploadFile">Simpan</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</section>
