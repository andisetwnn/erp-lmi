<?php

use App\Models\Master\Bank;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerKontakDarurat;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Proyek;
use App\Models\Master\TempatKerja;
use App\Services\KtpOcrService;
use App\Support\FileOptimizer;
use App\Support\PhoneNumber;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravolt\Indonesia\Models\City;
use Laravolt\Indonesia\Models\District;
use Laravolt\Indonesia\Models\Province;
use Laravolt\Indonesia\Models\Village;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Tambah Database Konsumen'), Layout('layouts.dbos')] class extends Component {
    use WithFileUploads;

    public ?int $editId = null;

    // Data Konsumen
    public ?string $proyekId = null;

    public string $namaLengkap = '';

    public string $hp = ''; // user input tanpa prefix +62

    public string $hp_2 = ''; // optional, tanpa prefix +62

    public string $sumber = '';

    public ?string $nik = null;

    // ===== Biodata KTP (auto-fill dari OCR) =====
    public ?string $tempatLahir = null;

    public ?string $tanggalLahir = null;

    public ?string $jenisKelamin = null; // 'L' atau 'P'

    public ?string $agama = null;

    public ?string $statusPerkawinan = null;

    public ?string $pekerjaanKtp = null;

    public ?string $rtRw = null;

    public ?string $npwp = null;

    public $fotoKtp = null;

    public ?string $fotoKtpPath = null;

    // Pekerjaan
    public ?string $tempatKerjaId = null;

    public ?string $penghasilanBulanan = null;

    // Quick-add tempat kerja (kalau perusahaan belum ada di master)
    public string $newTempatKerjaNama = '';

    public ?string $newTempatKerjaBidang = null;

    public ?string $newTempatKerjaAlamat = null;

    // Rekening Customer
    public ?string $bankId = null;

    public ?string $nomorRekening = null;

    public ?string $rekeningAtasNama = null;

    // Kontak Darurat — default 3 row kosong (min 3 untuk FINISH)
    public array $kontakDarurat = [
        ['nama' => '', 'hubungan' => '', 'nomor_telepon' => ''],
        ['nama' => '', 'hubungan' => '', 'nomor_telepon' => ''],
        ['nama' => '', 'hubungan' => '', 'nomor_telepon' => ''],
    ];

    // Alamat
    public ?string $alamat = null;

    public ?string $provinsiCode = null;

    public ?string $kotaCode = null;

    public ?string $kecamatanCode = null;

    public ?string $kelurahanCode = null;

    public ?string $catatan = null;

    public bool $alamatExpanded = false;

    // BI Checking (opsional di awal, wajib untuk FINISH)
    public ?string $biKol = null;

    public ?string $biDbr = null;

    public ?string $biKeterangan = null;

    // OCR state
    public bool $ocrRunning = false;

    public ?string $ocrMessage = null;

    public ?string $ocrMessageType = null;

    public function mount(?int $id = null): void
    {
        if ($id) {
            $p = ProspectCustomer::where('sales_id', Auth::guard('sales')->id())
                ->with('kontakDarurat:id,prospect_customer_id,nama,hubungan,nomor_telepon')
                ->findOrFail($id);

            $this->editId = $p->id;
            $this->proyekId = (string) $p->proyek_id;
            $this->namaLengkap = $p->nama_lengkap;
            $this->hp = preg_replace('/^62/', '', $p->hp);
            $this->hp_2 = $p->hp_2 ? preg_replace('/^62/', '', $p->hp_2) : '';
            $this->sumber = $p->sumber;
            $this->nik = $p->nik;
            $this->tempatLahir = $p->tempat_lahir;
            $this->tanggalLahir = $p->tanggal_lahir?->format('Y-m-d');
            $this->jenisKelamin = $p->jenis_kelamin;
            $this->agama = $p->agama;
            $this->statusPerkawinan = $p->status_perkawinan;
            $this->pekerjaanKtp = $p->pekerjaan_ktp;
            $this->rtRw = $p->rt_rw;
            $this->npwp = $p->npwp;
            $this->fotoKtpPath = $p->foto_ktp;
            $this->tempatKerjaId = $p->tempat_kerja_id ? (string) $p->tempat_kerja_id : null;
            $this->penghasilanBulanan = $p->penghasilan_bulanan ? (string) $p->penghasilan_bulanan : null;
            $this->bankId = $p->bank_id ? (string) $p->bank_id : null;
            $this->nomorRekening = $p->nomor_rekening;
            $this->rekeningAtasNama = $p->rekening_atas_nama;
            $this->alamat = $p->alamat;
            $this->provinsiCode = $p->provinsi_code;
            $this->kotaCode = $p->kota_code;
            $this->kecamatanCode = $p->kecamatan_code;
            $this->kelurahanCode = $p->kelurahan_code;
            $this->catatan = $p->catatan;
            $this->biKol = $p->bi_kol;
            $this->biDbr = $p->bi_dbr !== null ? (string) $p->bi_dbr : null;
            $this->biKeterangan = $p->bi_keterangan;

            $this->alamatExpanded = (bool) ($p->alamat || $p->provinsi_code);

            // Load kontak darurat — minimal 3 row (kalau lebih sedikit, pad dengan empty)
            $existing = $p->kontakDarurat->map(fn ($k) => [
                'nama' => $k->nama,
                'hubungan' => $k->hubungan,
                'nomor_telepon' => preg_replace('/^62/', '', $k->nomor_telepon),
            ])->toArray();
            while (count($existing) < 3) {
                $existing[] = ['nama' => '', 'hubungan' => '', 'nomor_telepon' => ''];
            }
            $this->kontakDarurat = $existing;
        }
    }

    public function with(): array
    {
        return [
            'sumberOptions' => ProspectCustomer::SUMBER_OPTIONS,
            'proyekOptions' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek', 'nama_perumahan']),
            'tempatKerjaOptions' => TempatKerja::orderBy('nama')->get(['id', 'nama']),
            'bankOptions' => Bank::orderBy('nama')->get(['id', 'nama']),
            'hubunganOptions' => ProspectCustomerKontakDarurat::HUBUNGAN_OPTIONS,
            'provinsiOptions' => Province::orderBy('name')->get(['code', 'name']),
            'kotaOptions' => $this->provinsiCode
                ? City::where('province_code', $this->provinsiCode)->orderBy('name')->get(['code', 'name'])
                : collect(),
            'kecamatanOptions' => $this->kotaCode
                ? District::where('city_code', $this->kotaCode)->orderBy('name')->get(['code', 'name'])
                : collect(),
            'kelurahanOptions' => $this->kecamatanCode
                ? Village::where('district_code', $this->kecamatanCode)->orderBy('name')->get(['code', 'name'])
                : collect(),
        ];
    }

    // ============= QUICK-ADD TEMPAT KERJA =============
    public function openQuickAddTempatKerja(): void
    {
        $this->newTempatKerjaNama = '';
        $this->newTempatKerjaBidang = null;
        $this->newTempatKerjaAlamat = null;
        $this->resetErrorBag(['newTempatKerjaNama', 'newTempatKerjaBidang', 'newTempatKerjaAlamat']);
        Flux::modal('tempat-kerja-quick-add')->show();
    }

    public function saveQuickTempatKerja(): void
    {
        $validated = $this->validate([
            'newTempatKerjaNama' => ['required', 'string', 'max:255', 'unique:tempat_kerja,nama'],
            'newTempatKerjaBidang' => ['nullable', 'string', 'max:255'],
            'newTempatKerjaAlamat' => ['nullable', 'string', 'max:500'],
        ], [
            'newTempatKerjaNama.unique' => 'Perusahaan dengan nama itu sudah ada — pilih dari dropdown.',
        ], [
            'newTempatKerjaNama' => 'nama perusahaan',
            'newTempatKerjaBidang' => 'bidang usaha',
            'newTempatKerjaAlamat' => 'alamat',
        ]);

        $tk = TempatKerja::create([
            'nama' => $validated['newTempatKerjaNama'],
            'bidang_usaha' => $validated['newTempatKerjaBidang'] ?: null,
            'alamat' => $validated['newTempatKerjaAlamat'] ?: null,
            // updated_by_user_id sengaja null karena dibuat dari DBOS (sales guard, bukan user guard)
        ]);

        // Auto-pilih ke form
        $this->tempatKerjaId = (string) $tk->id;

        Flux::modal('tempat-kerja-quick-add')->close();
        $this->reset(['newTempatKerjaNama', 'newTempatKerjaBidang', 'newTempatKerjaAlamat']);

        Flux::toast(variant: 'success', text: "Perusahaan '{$tk->nama}' ditambah & dipilih.");
    }

    // ============= KONTAK DARURAT REPEATER =============
    public function addKontakDarurat(): void
    {
        if (count($this->kontakDarurat) >= 5) return; // batas wajar
        $this->kontakDarurat[] = ['nama' => '', 'hubungan' => '', 'nomor_telepon' => ''];
    }

    public function removeKontakDarurat(int $i): void
    {
        // Min 2 row, jangan hapus kalau cuma 2 (pertahankan minimal)
        if (count($this->kontakDarurat) <= 2) return;
        unset($this->kontakDarurat[$i]);
        $this->kontakDarurat = array_values($this->kontakDarurat);
    }

    // Auto-sanitasi HP saat user input/paste
    public function updatedHp($value): void
    {
        $clean = PhoneNumber::sanitize($value);
        if ($clean !== $this->hp) {
            $this->hp = $clean ?? '';
        }
    }

    public function updatedHp2($value): void
    {
        $clean = PhoneNumber::sanitize($value);
        if ($clean !== $this->hp_2) {
            $this->hp_2 = $clean ?? '';
        }
    }

    public function updatedKontakDarurat($value, $key): void
    {
        // key format "0.nomor_telepon", "1.nomor_telepon", dst
        if (! str_ends_with($key, '.nomor_telepon')) {
            return;
        }
        [$index, ] = explode('.', $key);
        $clean = PhoneNumber::sanitize($value);
        if ($clean !== ($this->kontakDarurat[$index]['nomor_telepon'] ?? null)) {
            $this->kontakDarurat[$index]['nomor_telepon'] = $clean ?? '';
        }
    }

    // Cascade reset child saat parent berubah
    public function updatedProvinsiCode(): void
    {
        $this->kotaCode = null;
        $this->kecamatanCode = null;
        $this->kelurahanCode = null;
    }

    public function updatedKotaCode(): void
    {
        $this->kecamatanCode = null;
        $this->kelurahanCode = null;
    }

    public function updatedKecamatanCode(): void
    {
        $this->kelurahanCode = null;
    }

    public function toggleAlamat(): void
    {
        $this->alamatExpanded = ! $this->alamatExpanded;
    }

    /**
     * Auto-trigger OCR setelah foto KTP di-upload.
     */
    public function updatedFotoKtp(): void
    {
        if (! $this->fotoKtp) {
            return;
        }

        try {
            $this->validate([
                'fotoKtp' => ['required', 'image', 'max:4096'],
            ]);
        } catch (\Throwable $e) {
            return;
        }

        $this->runOcr();
    }

    public function runOcr(): void
    {
        if (! $this->fotoKtp) return;

        $this->ocrRunning = true;
        $this->ocrMessage = null;
        $this->ocrMessageType = null;

        try {
            $tempPath = $this->fotoKtp->getRealPath();
            $service = app(KtpOcrService::class);
            $result = $service->read($tempPath);

            if (! $result['ok']) {
                $this->ocrMessage = $result['error'] ?? 'OCR gagal.';
                $this->ocrMessageType = 'error';
                return;
            }

            $filled = [];

            // NIK
            if ($result['nik'] && (empty($this->nik) || strlen($this->nik) !== 16)) {
                $this->nik = $result['nik'];
                $filled[] = 'NIK';
            }

            // Nama
            if ($result['nama'] && empty(trim($this->namaLengkap))) {
                $this->namaLengkap = $result['nama'];
                $filled[] = 'Nama';
            }

            // Tempat lahir
            if ($result['tempat_lahir'] && empty(trim($this->tempatLahir ?? ''))) {
                $this->tempatLahir = $result['tempat_lahir'];
                $filled[] = 'Tempat Lahir';
            }

            // Tanggal lahir — normalize ke Y-m-d (dari DD-MM-YYYY)
            if ($result['tanggal_lahir'] && empty($this->tanggalLahir)) {
                try {
                    $parts = explode('-', $result['tanggal_lahir']);
                    if (count($parts) === 3 && strlen($parts[2]) === 4) {
                        $this->tanggalLahir = $parts[2].'-'.$parts[1].'-'.$parts[0];
                        $filled[] = 'Tgl Lahir';
                    }
                } catch (\Throwable $e) {
                    // skip kalau format aneh
                }
            }

            // Jenis kelamin — convert 'Laki-laki'/'Perempuan' → 'L'/'P'
            if ($result['jenis_kelamin'] && empty($this->jenisKelamin)) {
                $jk = strtolower($result['jenis_kelamin']);
                $this->jenisKelamin = str_contains($jk, 'laki') || str_contains($jk, 'pria') ? 'L' : 'P';
                $filled[] = 'Jenis Kelamin';
            }

            // Agama
            if ($result['agama'] && empty(trim($this->agama ?? ''))) {
                $this->agama = $result['agama'];
                $filled[] = 'Agama';
            }

            // Status perkawinan
            if ($result['status_perkawinan'] && empty(trim($this->statusPerkawinan ?? ''))) {
                $this->statusPerkawinan = $result['status_perkawinan'];
                $filled[] = 'Status Perkawinan';
            }

            // Pekerjaan
            if ($result['pekerjaan'] && empty(trim($this->pekerjaanKtp ?? ''))) {
                $this->pekerjaanKtp = $result['pekerjaan'];
                $filled[] = 'Pekerjaan';
            }

            // RT/RW
            if ($result['rt_rw'] && empty(trim($this->rtRw ?? ''))) {
                $this->rtRw = $result['rt_rw'];
                $filled[] = 'RT/RW';
            }

            // Alamat (jalan) — pisah dari RT/RW (RT/RW disimpan di kolom sendiri)
            if ($result['alamat'] && empty(trim($this->alamat ?? ''))) {
                $this->alamat = $result['alamat'];
                $this->alamatExpanded = true;
                $filled[] = 'Alamat';
            }

            // === Auto-fill wilayah via lookup ke laravolt/indonesia ===
            // Strategi: cari case-insensitive di tabel laravolt. Kalau ketemu, set code.
            // Provinsi
            if ($result['provinsi'] && empty($this->provinsiCode)) {
                $province = Province::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($result['provinsi']).'%'])->first();
                if ($province) {
                    $this->provinsiCode = $province->code;
                    $filled[] = 'Provinsi';
                }
            }

            // Kota/Kabupaten
            if ($result['kota_kabupaten'] && $this->provinsiCode) {
                $city = City::where('province_code', $this->provinsiCode)
                    ->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($result['kota_kabupaten']).'%'])
                    ->first();
                if ($city) {
                    $this->kotaCode = $city->code;
                    $filled[] = 'Kota/Kab';
                }
            }

            // Kecamatan
            if ($result['kecamatan'] && $this->kotaCode) {
                $district = District::where('city_code', $this->kotaCode)
                    ->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($result['kecamatan']).'%'])
                    ->first();
                if ($district) {
                    $this->kecamatanCode = $district->code;
                    $filled[] = 'Kecamatan';
                }
            }

            // Kelurahan
            if ($result['kelurahan'] && $this->kecamatanCode) {
                $village = Village::where('district_code', $this->kecamatanCode)
                    ->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($result['kelurahan']).'%'])
                    ->first();
                if ($village) {
                    $this->kelurahanCode = $village->code;
                    $filled[] = 'Kelurahan';
                }
            }

            if (count($filled) > 0) {
                $this->ocrMessage = 'OCR berhasil. Kolom terisi otomatis: '.implode(', ', $filled).'. Mohon verifikasi.';
                $this->ocrMessageType = 'success';
            } elseif (empty($result['nik']) && empty($result['nama'])) {
                $this->ocrMessage = 'OCR tidak dapat membaca KTP. Isi data secara manual atau unggah ulang dengan kualitas foto yang lebih baik.';
                $this->ocrMessageType = 'warning';
            } else {
                $this->ocrMessage = 'OCR berhasil dijalankan. Data terkait telah terisi sebelumnya, mohon diverifikasi kembali.';
                $this->ocrMessageType = 'warning';
            }
        } catch (\Throwable $e) {
            $this->ocrMessage = 'Gagal memproses KTP: '.$e->getMessage();
            $this->ocrMessageType = 'error';
        } finally {
            $this->ocrRunning = false;
        }
    }

    public function dismissOcrMessage(): void
    {
        $this->ocrMessage = null;
        $this->ocrMessageType = null;
    }

    public function removeFotoKtp(): void
    {
        if (! $this->editId) {
            $this->fotoKtp = null;
            $this->fotoKtpPath = null;
            return;
        }

        $p = ProspectCustomer::where('sales_id', Auth::guard('sales')->id())
            ->findOrFail($this->editId);

        if ($p->foto_ktp && Storage::disk('public')->exists($p->foto_ktp)) {
            Storage::disk('public')->delete($p->foto_ktp);
        }

        $p->foto_ktp = null;
        $p->save();

        $this->fotoKtpPath = null;
        $this->fotoKtp = null;

        Flux::toast(variant: 'success', text: 'Foto KTP berhasil dihapus.');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'proyekId' => ['required', 'exists:proyek,id'],
            'namaLengkap' => ['required', 'string', 'max:255'],
            'hp' => ['required', 'string', 'min:7', 'max:20', 'regex:/^[0-9]+$/'],
            'hp_2' => ['nullable', 'string', 'min:7', 'max:20', 'regex:/^[0-9]+$/'],
            'sumber' => ['required', 'string', 'max:255'],
            'nik' => ['nullable', 'string', 'max:32'],
            'npwp' => ['nullable', 'string', 'max:32'],
            'fotoKtp' => ['nullable', 'image', 'max:4096'],
            'tempatKerjaId' => ['nullable', 'exists:tempat_kerja,id'],
            'penghasilanBulanan' => ['nullable', 'numeric', 'min:0'],
            'bankId' => ['nullable', 'exists:bank,id'],
            'nomorRekening' => ['nullable', 'string', 'max:50'],
            'rekeningAtasNama' => ['nullable', 'string', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'provinsiCode' => ['nullable', 'string', 'max:10'],
            'kotaCode' => ['nullable', 'string', 'max:10'],
            'kecamatanCode' => ['nullable', 'string', 'max:13'],
            'kelurahanCode' => ['nullable', 'string', 'max:16'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'biKol' => ['nullable', 'in:1,2,3,4,5'],
            'biDbr' => ['nullable', 'numeric', 'between:0,100'],
            'biKeterangan' => ['nullable', 'string', 'max:1000'],
            'kontakDarurat' => ['array', 'max:5'],
            'kontakDarurat.*.nama' => ['nullable', 'string', 'max:255'],
            'kontakDarurat.*.hubungan' => ['nullable', 'in:orang_tua,saudara,pasangan,anak,teman,lainnya'],
            'kontakDarurat.*.nomor_telepon' => ['nullable', 'string', 'min:7', 'max:20', 'regex:/^[0-9]+$/'],
        ], [
            'hp.regex' => 'Nomor HP hanya boleh angka (tanpa spasi, +, atau tanda lain).',
            'hp_2.regex' => 'Nomor HP 2 hanya boleh angka.',
            'biDbr.between' => 'DBR harus antara 0 dan 100 persen.',
            'kontakDarurat.*.nomor_telepon.regex' => 'Nomor telepon kontak darurat hanya boleh angka.',
        ], [
            'proyekId' => 'proyek',
            'namaLengkap' => 'nama lengkap',
            'hp' => 'nomor HP',
            'hp_2' => 'nomor HP 2',
            'sumber' => 'sumber database',
            'nik' => 'NIK',
            'npwp' => 'NPWP',
            'fotoKtp' => 'foto KTP',
            'tempatKerjaId' => 'perusahaan',
            'bankId' => 'bank',
            'nomorRekening' => 'nomor rekening',
            'rekeningAtasNama' => 'atas nama rekening',
            'alamat' => 'alamat',
            'provinsiCode' => 'provinsi',
            'kotaCode' => 'kota',
            'kecamatanCode' => 'kecamatan',
            'kelurahanCode' => 'kelurahan',
            'biKol' => 'BI KOL',
            'biDbr' => 'BI DBR',
            'biKeterangan' => 'BI keterangan',
        ]);

        $normalizeHp = function (string $hp): string {
            $hp = preg_replace('/[^0-9]/', '', $hp);
            if (str_starts_with($hp, '0')) return '62'.substr($hp, 1);
            if (! str_starts_with($hp, '62')) return '62'.$hp;
            return $hp;
        };

        $hp1 = $normalizeHp($validated['hp']);
        $hp2 = $validated['hp_2'] ? $normalizeHp($validated['hp_2']) : null;

        $provinsiNama = $this->provinsiCode ? Province::where('code', $this->provinsiCode)->value('name') : null;
        $kotaNama = $this->kotaCode ? City::where('code', $this->kotaCode)->value('name') : null;
        $kecamatanNama = $this->kecamatanCode ? District::where('code', $this->kecamatanCode)->value('name') : null;
        $kelurahanNama = $this->kelurahanCode ? Village::where('code', $this->kelurahanCode)->value('name') : null;

        DB::transaction(function () use ($validated, $hp1, $hp2, $provinsiNama, $kotaNama, $kecamatanNama, $kelurahanNama) {
            $payload = [
                'sales_id' => Auth::guard('sales')->id(),
                'proyek_id' => (int) $validated['proyekId'],
                'nama_lengkap' => $validated['namaLengkap'],
                'hp' => $hp1,
                'hp_2' => $hp2,
                'sumber' => $validated['sumber'],
                'nik' => $validated['nik'] ?: null,
                'tempat_lahir' => $this->tempatLahir ?: null,
                'tanggal_lahir' => $this->tanggalLahir ?: null,
                'jenis_kelamin' => $this->jenisKelamin ?: null,
                'agama' => $this->agama ?: null,
                'status_perkawinan' => $this->statusPerkawinan ?: null,
                'pekerjaan_ktp' => $this->pekerjaanKtp ?: null,
                'npwp' => $validated['npwp'] ?: null,
                'tempat_kerja_id' => $validated['tempatKerjaId'] ? (int) $validated['tempatKerjaId'] : null,
                'penghasilan_bulanan' => $validated['penghasilanBulanan'] !== null && $validated['penghasilanBulanan'] !== '' ? (float) $validated['penghasilanBulanan'] : null,
                'bank_id' => $validated['bankId'] ? (int) $validated['bankId'] : null,
                'nomor_rekening' => $validated['nomorRekening'] ?: null,
                'rekening_atas_nama' => $validated['rekeningAtasNama'] ?: null,
                'alamat' => $validated['alamat'] ?: null,
                'rt_rw' => $this->rtRw ?: null,
                'provinsi_code' => $validated['provinsiCode'] ?: null,
                'provinsi_nama' => $provinsiNama,
                'kota_code' => $validated['kotaCode'] ?: null,
                'kota_nama' => $kotaNama,
                'kecamatan_code' => $validated['kecamatanCode'] ?: null,
                'kecamatan_nama' => $kecamatanNama,
                'kelurahan_code' => $validated['kelurahanCode'] ?: null,
                'kelurahan_nama' => $kelurahanNama,
                'catatan' => $validated['catatan'] ?: null,
                'bi_kol' => $validated['biKol'] ?: null,
                'bi_dbr' => $validated['biDbr'] !== '' && $validated['biDbr'] !== null ? $validated['biDbr'] : null,
                'bi_keterangan' => $validated['biKeterangan'] ?: null,
            ];

            if ($this->editId) {
                $prospect = ProspectCustomer::where('sales_id', Auth::guard('sales')->id())
                    ->where('id', $this->editId)
                    ->firstOrFail();

                // Handle foto KTP upload (replace lama kalau ada)
                if ($this->fotoKtp) {
                    if ($prospect->foto_ktp && Storage::disk('public')->exists($prospect->foto_ktp)) {
                        Storage::disk('public')->delete($prospect->foto_ktp);
                    }
                    $payload['foto_ktp'] = FileOptimizer::storeOptimized($this->fotoKtp, 'prospect-ktp');
                }

                $prospect->update($payload);
            } else {
                if ($this->fotoKtp) {
                    $payload['foto_ktp'] = FileOptimizer::storeOptimized($this->fotoKtp, 'prospect-ktp');
                }

                $prospect = ProspectCustomer::create($payload);

                ProspectCustomerStatusLog::create([
                    'prospect_customer_id' => $prospect->id,
                    'status_dari' => null,
                    'status_ke' => $prospect->status,
                    'catatan' => 'Prospect baru ditambahkan.',
                    'changed_by_sales_id' => Auth::guard('sales')->id(),
                ]);
            }

            // Sync kontak darurat — delete-recreate pattern (simpler, idempotent)
            $prospect->kontakDarurat()->delete();
            foreach ($this->kontakDarurat as $k) {
                $nama = trim($k['nama'] ?? '');
                $hp = preg_replace('/[^0-9]/', '', $k['nomor_telepon'] ?? '');
                $hubungan = $k['hubungan'] ?? '';

                // Skip row kosong (sales mungkin tinggalin row blank)
                if ($nama === '' && $hp === '' && $hubungan === '') {
                    continue;
                }
                // Kalau ada salah satu yang diisi, semua wajib (validate at submit time, but defensive)
                if ($nama === '' || $hp === '' || $hubungan === '') {
                    continue;
                }

                // Normalize HP kontak darurat (sama dengan format hp utama)
                if (str_starts_with($hp, '0')) {
                    $hp = '62'.substr($hp, 1);
                } elseif (! str_starts_with($hp, '62')) {
                    $hp = '62'.$hp;
                }

                $prospect->kontakDarurat()->create([
                    'nama' => $nama,
                    'hubungan' => $hubungan,
                    'nomor_telepon' => $hp,
                ]);
            }
        });

        $msg = $this->editId ? 'Data konsumen berhasil diperbarui.' : 'Data konsumen berhasil ditambahkan.';
        session()->flash('toast', $msg);
        $this->redirect(route('dbos.database.index'), navigate: true);
    }
}; ?>

<section class="px-4 pb-24 pt-5">

    {{-- HEADER --}}
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('dbos.database.index') }}" wire:navigate
           class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
            <flux:icon.arrow-left class="size-5" />
        </a>
        <div class="flex-1">
            <div class="flex items-center gap-2">
                <flux:icon.user-plus class="size-5 text-orange-600" />
                <h1 class="text-lg font-bold text-zinc-900 dark:text-white">
                    {{ $editId ? __('Edit Database Konsumen') : __('Tambah Database Konsumen') }}
                </h1>
            </div>
            <p class="text-xs text-zinc-500">
                {{ __('Isi data konsumen. Kolom bertanda') }} <span class="text-red-500">*</span> {{ __('wajib diisi.') }}
            </p>
        </div>
    </div>

    <form wire:submit="save" class="space-y-4">

        {{-- ============== SECTION 1: MULAI DARI KTP (OCR) ============== --}}
        <div class="overflow-hidden rounded-2xl border-2 border-orange-200 bg-linear-to-br from-orange-50 to-amber-50 shadow-sm dark:border-orange-900/50 dark:from-orange-950/30 dark:to-amber-950/30">
            <div class="flex items-center gap-2 border-b border-orange-200/50 px-4 py-3 dark:border-orange-900/30">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-orange-500 text-white">
                    <flux:icon.camera class="size-4" />
                </div>
                <div class="flex-1">
                    <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Foto KTP') }}</h2>
                    <p class="text-[11px] text-orange-700 dark:text-orange-300">
                        {{ __('Pindai dokumen identitas dengan OCR') }}
                    </p>
                </div>
            </div>

            {{-- Client-side preview: pakai Alpine + URL.createObjectURL (bypass Livewire temporaryUrl yang 401 di Hostinger) --}}
            <div class="p-4"
                 x-data="{
                    clientPreview: null,
                    onFile(e) {
                        if (this.clientPreview) URL.revokeObjectURL(this.clientPreview);
                        const f = e.target.files[0];
                        this.clientPreview = f ? URL.createObjectURL(f) : null;
                    },
                    clear() {
                        if (this.clientPreview) URL.revokeObjectURL(this.clientPreview);
                        this.clientPreview = null;
                    },
                 }">
                @php
                    // Preview URL untuk foto SUDAH TERSIMPAN (edit mode). Untuk baru upload, pakai client-side.
                    $existingPreviewUrl = $fotoKtpPath ? asset('storage/'.$fotoKtpPath) : null;
                @endphp

                {{-- Preview: client-side (baru upload) prioritas, fallback existing --}}
                <div x-show="clientPreview || {{ $existingPreviewUrl ? 'true' : 'false' }}"
                     class="mb-3 overflow-hidden rounded-xl border-2 border-orange-300 bg-white dark:border-orange-700 dark:bg-zinc-900">
                    <div class="relative">
                        <img :src="clientPreview || '{{ $existingPreviewUrl }}'" alt="Preview KTP"
                             class="block w-full object-contain"
                             style="max-height: 240px;" />
                        <button type="button" @click="clear(); $wire.removeFotoKtp()"
                                class="absolute right-2 top-2 inline-flex h-8 w-8 items-center justify-center rounded-full bg-red-600 text-white shadow-lg hover:bg-red-700 active:scale-95">
                            <flux:icon.trash class="size-4" />
                        </button>
                    </div>
                    <div class="border-t border-orange-200 bg-orange-50 px-3 py-2 text-[11px] text-orange-800 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-200">
                        <span x-show="clientPreview">{{ __('Foto baru diunggah. Hasil OCR akan tampil di bawah.') }}</span>
                        <span x-show="!clientPreview">{{ __('Foto KTP tersimpan.') }}</span>
                    </div>
                </div>

                {{-- Upload zone — selalu visible, jadi entry point utama --}}
                <label for="ktp-upload-input"
                       class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-orange-300 bg-white/60 px-4 py-6 transition active:scale-[0.98] hover:border-orange-500 hover:bg-white dark:border-orange-700 dark:bg-zinc-900/40 dark:hover:bg-zinc-900">
                    <flux:icon.cloud-arrow-up class="size-8 text-orange-500" />
                    <div class="text-center">
                        <div class="text-sm font-bold text-zinc-900 dark:text-white">
                            <span x-show="clientPreview || {{ $existingPreviewUrl ? 'true' : 'false' }}">{{ __('Ganti Foto KTP') }}</span>
                            <span x-show="!(clientPreview || {{ $existingPreviewUrl ? 'true' : 'false' }})">{{ __('Unggah Foto KTP') }}</span>
                        </div>
                        <div class="mt-0.5 text-[11px] text-zinc-500">
                            {{ __('Pilih dari galeri atau ambil foto langsung dari kamera') }}
                        </div>
                    </div>
                </label>
                <input id="ktp-upload-input" type="file" wire:model="fotoKtp" accept="image/*"
                       @change="onFile($event)"
                       class="hidden" />

                <flux:error name="fotoKtp" class="mt-2" />

                {{-- Loading indicator --}}
                <div wire:loading wire:target="fotoKtp,runOcr"
                     class="mt-3 inline-flex items-center gap-2 rounded-md bg-blue-50 px-2 py-1 text-xs text-blue-700 dark:bg-blue-950/30 dark:text-blue-300">
                    <svg class="size-3 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="50.27" stroke-dashoffset="20"/>
                    </svg>
                    {{ __('Mengunggah dan memproses OCR...') }}
                </div>

                {{-- OCR message --}}
                @if ($ocrMessage)
                    @php
                        $ocrColor = match ($ocrMessageType) {
                            'success' => 'bg-green-100 border-green-300 text-green-900 dark:bg-green-950/40 dark:border-green-800 dark:text-green-200',
                            'warning' => 'bg-amber-100 border-amber-300 text-amber-900 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-200',
                            'error'   => 'bg-red-100 border-red-300 text-red-900 dark:bg-red-950/40 dark:border-red-800 dark:text-red-200',
                        };
                    @endphp
                    <div class="mt-3 flex items-start justify-between gap-2 rounded-lg border px-3 py-2 text-xs {{ $ocrColor }}">
                        <span>{{ $ocrMessage }}</span>
                        <button type="button" wire:click="dismissOcrMessage" class="text-current opacity-60 hover:opacity-100">
                            <flux:icon.x-mark class="size-3.5" />
                        </button>
                    </div>
                @endif

                @if (! $existingPreviewUrl && ! $ocrMessage)
                    <p class="mt-3 text-center text-[11px] text-zinc-500" x-show="!clientPreview">
                        {{ __('Lewati bagian ini jika foto KTP belum tersedia.') }}
                    </p>
                @endif
            </div>
        </div>

        {{-- ============== SECTION 2: DATA KONSUMEN ============== --}}
        <div class="rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500 text-white">
                    <flux:icon.identification class="size-4" />
                </div>
                <div class="flex-1">
                    <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Data Konsumen') }}</h2>
                    <p class="text-[11px] text-zinc-500">{{ __('Identitas dasar konsumen') }}</p>
                </div>
            </div>

            <div class="space-y-4 p-4">
                <flux:field>
                    <flux:label>{{ __('Proyek') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:select wire:model="proyekId" required>
                        <flux:select.option value="">{{ __('— Pilih Proyek —') }}</flux:select.option>
                        @foreach ($proyekOptions as $p)
                            <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="proyekId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Nama Lengkap') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="namaLengkap" required placeholder="Contoh: Budi Santoso" />
                    <flux:error name="namaLengkap" />
                </flux:field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Nomor HP / WA') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <div class="flex">
                            <span class="inline-flex h-10 items-center rounded-l-lg border border-r-0 border-zinc-200 bg-zinc-50 px-3 text-sm font-semibold text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                +62
                            </span>
                            <input type="tel" wire:model.blur="hp" required
                                   inputmode="numeric"
                                   placeholder="81234567890"
                                   class="block h-10 w-full rounded-r-lg border border-zinc-200 bg-white px-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                        </div>
                        <flux:error name="hp" />
                    </flux:field>

                    <flux:field>
                        <flux:label class="flex items-center gap-2">
                            {{ __('Nomor HP 2') }}
                            <span class="inline-flex items-center rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                {{ __('Opsional') }}
                            </span>
                        </flux:label>
                        <div class="flex">
                            <span class="inline-flex h-10 items-center rounded-l-lg border border-r-0 border-zinc-200 bg-zinc-50 px-3 text-sm font-semibold text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                +62
                            </span>
                            <input type="tel" wire:model.blur="hp_2"
                                   inputmode="numeric"
                                   placeholder="81234567890"
                                   class="block h-10 w-full rounded-r-lg border border-zinc-200 bg-white px-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                        </div>
                        <flux:error name="hp_2" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Sumber Info') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:select wire:model="sumber" required>
                        <flux:select.option value="">{{ __('— Pilih Sumber Info —') }}</flux:select.option>
                        @foreach ($sumberOptions as $opt)
                            <flux:select.option value="{{ $opt }}">{{ $opt }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="sumber" />
                </flux:field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label class="flex items-center gap-2">
                            {{ __('Nomor KTP / NIK') }}
                            <span class="inline-flex items-center rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                {{ __('Wajib FINISH') }}
                            </span>
                        </flux:label>
                        <flux:input wire:model="nik" inputmode="numeric" maxlength="16" placeholder="16 digit nomor KTP" />
                        <flux:error name="nik" />
                    </flux:field>

                    <flux:field>
                        <flux:label class="flex items-center gap-2">
                            {{ __('NPWP') }}
                            <span class="inline-flex items-center rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                {{ __('Wajib FINISH') }}
                            </span>
                        </flux:label>
                        <flux:input wire:model="npwp" inputmode="numeric" maxlength="20" placeholder="Contoh: 12.345.678.9-012.345" />
                        <flux:error name="npwp" />
                    </flux:field>
                </div>

                {{-- ===== BIODATA KTP (auto-fill dari OCR) ===== --}}
                <div class="mt-4 rounded-lg border border-dashed border-zinc-300 bg-zinc-50/40 p-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                    <div class="mb-3 flex items-center gap-2">
                        <flux:icon.identification class="size-4 text-blue-600" />
                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                            {{ __('Biodata Sesuai KTP') }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <flux:field>
                            <flux:label class="text-xs">{{ __('Tempat Lahir') }}</flux:label>
                            <flux:input wire:model="tempatLahir" placeholder="Contoh: Jakarta" />
                        </flux:field>

                        <flux:field>
                            <flux:label class="text-xs">{{ __('Tanggal Lahir') }}</flux:label>
                            <flux:input type="date" wire:model="tanggalLahir" />
                        </flux:field>

                        <flux:field>
                            <flux:label class="text-xs">{{ __('Jenis Kelamin') }}</flux:label>
                            <flux:select wire:model="jenisKelamin" :placeholder="__('— Pilih —')">
                                <flux:select.option value="">{{ __('— Pilih —') }}</flux:select.option>
                                <flux:select.option value="L">{{ __('Laki-laki') }}</flux:select.option>
                                <flux:select.option value="P">{{ __('Perempuan') }}</flux:select.option>
                            </flux:select>
                        </flux:field>

                        <flux:field>
                            <flux:label class="text-xs">{{ __('Agama') }}</flux:label>
                            <flux:select wire:model="agama" :placeholder="__('— Pilih —')">
                                <flux:select.option value="">{{ __('— Pilih —') }}</flux:select.option>
                                <flux:select.option value="Islam">Islam</flux:select.option>
                                <flux:select.option value="Kristen">Kristen</flux:select.option>
                                <flux:select.option value="Katholik">Katholik</flux:select.option>
                                <flux:select.option value="Hindu">Hindu</flux:select.option>
                                <flux:select.option value="Buddha">Buddha</flux:select.option>
                                <flux:select.option value="Konghucu">Konghucu</flux:select.option>
                            </flux:select>
                        </flux:field>

                        <flux:field>
                            <flux:label class="text-xs">{{ __('Status Perkawinan') }}</flux:label>
                            <flux:select wire:model="statusPerkawinan" :placeholder="__('— Pilih —')">
                                <flux:select.option value="">{{ __('— Pilih —') }}</flux:select.option>
                                <flux:select.option value="Belum Kawin">{{ __('Belum Kawin') }}</flux:select.option>
                                <flux:select.option value="Kawin">{{ __('Kawin') }}</flux:select.option>
                                <flux:select.option value="Cerai Hidup">{{ __('Cerai Hidup') }}</flux:select.option>
                                <flux:select.option value="Cerai Mati">{{ __('Cerai Mati') }}</flux:select.option>
                            </flux:select>
                        </flux:field>

                        <flux:field>
                            <flux:label class="text-xs">{{ __('Pekerjaan (KTP)') }}</flux:label>
                            <flux:input wire:model="pekerjaanKtp" placeholder="Contoh: Karyawan Swasta" />
                        </flux:field>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============== SECTION: ALAMAT (collapsible) ============== --}}
        <div class="rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <button type="button" wire:click="toggleAlamat"
                    class="flex w-full items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-green-500 text-white">
                        <flux:icon.map-pin class="size-4" />
                    </div>
                    <div class="flex items-center gap-2 text-left">
                        <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Alamat') }}</h2>
                        <span class="inline-flex items-center rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            {{ __('Opsional') }}
                        </span>
                    </div>
                </div>
                @if ($alamatExpanded)
                    <flux:icon.chevron-up class="size-5 text-zinc-400" />
                @else
                    <flux:icon.chevron-down class="size-5 text-zinc-400" />
                @endif
            </button>

            @if ($alamatExpanded)
                <div class="space-y-4 p-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('Jalan / Alamat') }}</flux:label>
                            <flux:textarea wire:model="alamat" rows="2"
                                           placeholder="Nama jalan, nomor rumah" />
                            <flux:error name="alamat" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('RT / RW') }}</flux:label>
                            <flux:input wire:model="rtRw" placeholder="003/005" maxlength="10" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Provinsi') }}</flux:label>
                        <flux:select wire:model.live="provinsiCode">
                            <flux:select.option value="">{{ __('— Pilih Provinsi —') }}</flux:select.option>
                            @foreach ($provinsiOptions as $opt)
                                <flux:select.option value="{{ $opt->code }}">{{ $opt->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="provinsiCode" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Kota / Kabupaten') }}</flux:label>
                        <flux:select wire:model.live="kotaCode" :disabled="! $provinsiCode">
                            <flux:select.option value="">
                                @if ($provinsiCode)
                                    {{ __('— Pilih Kota —') }}
                                @else
                                    {{ __('— Pilih Provinsi dulu —') }}
                                @endif
                            </flux:select.option>
                            @foreach ($kotaOptions as $opt)
                                <flux:select.option value="{{ $opt->code }}">{{ $opt->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="kotaCode" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Kecamatan') }}</flux:label>
                        <flux:select wire:model.live="kecamatanCode" :disabled="! $kotaCode">
                            <flux:select.option value="">
                                @if ($kotaCode)
                                    {{ __('— Pilih Kecamatan —') }}
                                @else
                                    {{ __('— Pilih Kota dulu —') }}
                                @endif
                            </flux:select.option>
                            @foreach ($kecamatanOptions as $opt)
                                <flux:select.option value="{{ $opt->code }}">{{ $opt->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="kecamatanCode" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Kelurahan / Desa') }}</flux:label>
                        <flux:select wire:model.live="kelurahanCode" :disabled="! $kecamatanCode">
                            <flux:select.option value="">
                                @if ($kecamatanCode)
                                    {{ __('— Pilih Kelurahan —') }}
                                @else
                                    {{ __('— Pilih Kecamatan dulu —') }}
                                @endif
                            </flux:select.option>
                            @foreach ($kelurahanOptions as $opt)
                                <flux:select.option value="{{ $opt->code }}">{{ $opt->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="kelurahanCode" />
                    </flux:field>
                </div>
            @endif
        </div>

        {{-- ============== SECTION: PEKERJAAN / PERUSAHAAN ============== --}}
        <div class="rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-500 text-white">
                    <flux:icon.briefcase class="size-4" />
                </div>
                <div class="flex flex-1 items-center gap-2">
                    <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Pekerjaan') }}</h2>
                    <span class="inline-flex items-center rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                        {{ __('Wajib FINISH') }}
                    </span>
                </div>
            </div>
            <div class="space-y-2 p-4">
                <flux:field>
                    <flux:label>{{ __('Perusahaan / Tempat Kerja') }}</flux:label>
                    <flux:select wire:model="tempatKerjaId">
                        <flux:select.option value="">{{ __('— Pilih Perusahaan —') }}</flux:select.option>
                        @foreach ($tempatKerjaOptions as $tk)
                            <flux:select.option value="{{ $tk->id }}">{{ $tk->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="tempatKerjaId" />
                </flux:field>

                <button type="button" wire:click="openQuickAddTempatKerja"
                        class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-indigo-300 bg-indigo-50/50 px-3 py-2.5 text-xs font-semibold text-indigo-700 transition hover:border-indigo-500 hover:bg-indigo-50 dark:border-indigo-900/50 dark:bg-indigo-950/20 dark:text-indigo-300">
                    <flux:icon.plus class="size-3.5" />
                    {{ __('Tidak ada di daftar? Tambah perusahaan baru') }}
                </button>

                <flux:field>
                    <flux:label>
                        {{ __('Pendapatan Bulanan') }}
                        <span class="ms-1 text-xs font-normal text-zinc-500">— {{ __('Rp / bulan') }}</span>
                    </flux:label>
                    <div class="flex">
                        <span class="inline-flex h-10 items-center rounded-l-lg border border-r-0 border-zinc-200 bg-zinc-50 px-3 text-sm font-semibold text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            Rp
                        </span>
                        <input type="number" wire:model="penghasilanBulanan"
                               inputmode="numeric" min="0" step="100000"
                               placeholder="5000000"
                               class="block h-10 w-full rounded-r-lg border border-zinc-200 bg-white px-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    </div>
                    <flux:description class="text-[10px]">
                        {{ __('Referensi untuk analisa kelayakan KPR.') }}
                    </flux:description>
                    <flux:error name="penghasilanBulanan" />
                </flux:field>
            </div>
        </div>

        {{-- ============== MODAL: Quick-add Tempat Kerja ============== --}}
        <flux:modal name="tempat-kerja-quick-add" class="md:w-md">
            <div class="space-y-4">
                <div>
                    <div class="flex items-center gap-2">
                        <flux:icon.plus-circle class="size-5 text-indigo-600" />
                        <flux:heading size="lg">{{ __('Tambah Perusahaan Baru') }}</flux:heading>
                    </div>
                    <flux:subheading>
                        {{ __('Akan ditambahkan ke master perusahaan & otomatis terpilih di form.') }}
                    </flux:subheading>
                </div>

                <flux:field>
                    <flux:label>{{ __('Nama Perusahaan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="newTempatKerjaNama" placeholder="PT Contoh Indonesia" />
                    <flux:error name="newTempatKerjaNama" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Bidang Usaha') }} <span class="ms-1 text-xs font-normal text-zinc-500">— opsional</span></flux:label>
                    <flux:input wire:model="newTempatKerjaBidang" placeholder="Contoh: Otomotif, Konstruksi, dll" />
                    <flux:error name="newTempatKerjaBidang" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Alamat Perusahaan') }} <span class="ms-1 text-xs font-normal text-zinc-500">— opsional</span></flux:label>
                    <flux:textarea wire:model="newTempatKerjaAlamat" rows="2" placeholder="Alamat lengkap perusahaan" />
                    <flux:error name="newTempatKerjaAlamat" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="button" wire:click="saveQuickTempatKerja"
                                 class="bg-indigo-600! hover:bg-indigo-700!">
                        {{ __('Simpan & Pilih') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- ============== SECTION: REKENING CUSTOMER ============== --}}
        <div class="rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-500 text-white">
                    <flux:icon.credit-card class="size-4" />
                </div>
                <div class="flex flex-1 items-center gap-2">
                    <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Rekening Customer') }}</h2>
                    <span class="inline-flex items-center rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ __('Opsional') }}
                    </span>
                </div>
            </div>
            <div class="space-y-4 p-4">
                <flux:field>
                    <flux:label>{{ __('Bank') }}</flux:label>
                    <flux:select wire:model="bankId">
                        <flux:select.option value="">{{ __('— Pilih Bank —') }}</flux:select.option>
                        @foreach ($bankOptions as $b)
                            <flux:select.option value="{{ $b->id }}">{{ $b->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="bankId" />
                </flux:field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Nomor Rekening') }}</flux:label>
                        <flux:input wire:model="nomorRekening" inputmode="numeric" placeholder="Contoh: 1234567890" />
                        <flux:error name="nomorRekening" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Atas Nama Rekening') }}</flux:label>
                        <flux:input wire:model="rekeningAtasNama" placeholder="Nama pemilik rekening" />
                        <flux:error name="rekeningAtasNama" />
                    </flux:field>
                </div>
            </div>
        </div>

        {{-- ============== SECTION: KONTAK DARURAT ============== --}}
        <div class="rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-500 text-white">
                    <flux:icon.phone-arrow-up-right class="size-4" />
                </div>
                <div class="flex flex-1 items-center gap-2">
                    <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Kontak Darurat') }}</h2>
                    <span class="inline-flex items-center rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                        {{ __('Min 3 untuk FINISH') }}
                    </span>
                </div>
            </div>
            <div class="space-y-3 p-4">
                @foreach ($kontakDarurat as $i => $kontak)
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                                {{ __('Kontak') }} #{{ $i + 1 }}
                            </span>
                            @if (count($kontakDarurat) > 2)
                                <button type="button" wire:click="removeKontakDarurat({{ $i }})"
                                        class="inline-flex h-6 w-6 items-center justify-center rounded-md text-zinc-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/30">
                                    <flux:icon.trash class="size-3.5" />
                                </button>
                            @endif
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <flux:field>
                                <flux:label class="text-xs">{{ __('Nama') }}</flux:label>
                                <flux:input wire:model="kontakDarurat.{{ $i }}.nama" placeholder="Nama lengkap" />
                                <flux:error name="kontakDarurat.{{ $i }}.nama" />
                            </flux:field>
                            <flux:field>
                                <flux:label class="text-xs">{{ __('Hubungan') }}</flux:label>
                                <flux:select wire:model="kontakDarurat.{{ $i }}.hubungan">
                                    <flux:select.option value="">— Pilih —</flux:select.option>
                                    @foreach ($hubunganOptions as $val => $label)
                                        <flux:select.option value="{{ $val }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="kontakDarurat.{{ $i }}.hubungan" />
                            </flux:field>
                            <flux:field>
                                <flux:label class="text-xs">{{ __('Nomor HP') }}</flux:label>
                                <div class="flex">
                                    <span class="inline-flex h-10 items-center rounded-l-lg border border-r-0 border-zinc-200 bg-zinc-100 px-2 text-xs font-semibold text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        +62
                                    </span>
                                    <input type="tel" wire:model.blur="kontakDarurat.{{ $i }}.nomor_telepon"
                                           inputmode="numeric"
                                           placeholder="81234567890"
                                           class="block h-10 w-full rounded-r-lg border border-zinc-200 bg-white px-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                                </div>
                                <flux:error name="kontakDarurat.{{ $i }}.nomor_telepon" />
                            </flux:field>
                        </div>
                    </div>
                @endforeach

                @if (count($kontakDarurat) < 5)
                    <button type="button" wire:click="addKontakDarurat"
                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-zinc-300 bg-white px-3 py-2.5 text-xs font-semibold text-zinc-600 transition hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                        <flux:icon.plus class="size-3.5" />
                        {{ __('Tambah Kontak Darurat') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- ============== SECTION: BI CHECKING (opsional di awal, wajib untuk FINISH) ============== --}}
        <div class="rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500 text-white">
                    <flux:icon.shield-check class="size-4" />
                </div>
                <div class="flex flex-1 items-center gap-2">
                    <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('BI Checking') }}</h2>
                    <span class="inline-flex items-center rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                        {{ __('Wajib FINISH') }}
                    </span>
                </div>
            </div>

            <div class="space-y-4 p-4">
                <flux:field>
                    <flux:label>{{ __('Kolektibilitas (KOL)') }}</flux:label>
                    <flux:select wire:model="biKol">
                        <flux:select.option value="">{{ __('— Belum diperiksa —') }}</flux:select.option>
                        <flux:select.option value="1">KOL 1 — Lancar</flux:select.option>
                        <flux:select.option value="2">KOL 2 — Dalam Perhatian Khusus (DPK)</flux:select.option>
                        <flux:select.option value="3">KOL 3 — Kurang Lancar</flux:select.option>
                        <flux:select.option value="4">KOL 4 — Diragukan</flux:select.option>
                        <flux:select.option value="5">KOL 5 — Macet</flux:select.option>
                    </flux:select>
                    <flux:error name="biKol" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Debt Burden Ratio (DBR)') }}</flux:label>
                    <div class="flex">
                        <input type="number" wire:model="biDbr" step="0.01" min="0" max="100"
                               inputmode="decimal"
                               placeholder="0.00"
                               class="block h-10 w-full rounded-l-lg border border-r-0 border-zinc-200 bg-white px-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                        <span class="inline-flex h-10 items-center rounded-r-lg border border-l-0 border-zinc-200 bg-zinc-50 px-3 text-sm font-semibold text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            %
                        </span>
                    </div>
                    <flux:error name="biDbr" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Keterangan BI') }}</flux:label>
                    <flux:textarea wire:model="biKeterangan" rows="2"
                                   placeholder="Catatan hasil pemeriksaan BI" />
                    <flux:error name="biKeterangan" />
                </flux:field>
            </div>
        </div>

        {{-- ============== SECTION: CATATAN ============== --}}
        <div class="rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-zinc-400 text-white">
                    <flux:icon.pencil-square class="size-4" />
                </div>
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Catatan') }}</h2>
                    <span class="inline-flex items-center rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                        {{ __('Opsional') }}
                    </span>
                </div>
            </div>
            <div class="p-4">
                <flux:textarea wire:model="catatan" rows="3"
                               placeholder="Catatan tambahan terkait konsumen" />
                <flux:error name="catatan" />
            </div>
        </div>

        {{-- ACTIONS --}}
        <div class="grid grid-cols-2 gap-3 pt-2">
            <a href="{{ route('dbos.database.index') }}" wire:navigate
               class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white text-sm font-semibold text-zinc-700 active:scale-95 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                <flux:icon.arrow-left class="size-4" />
                {{ __('Batal') }}
            </a>
            <button type="submit"
                    class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-orange-600 text-sm font-semibold text-white shadow active:scale-95">
                <flux:icon.bookmark class="size-4" />
                <span wire:loading.remove wire:target="save">{{ __('Simpan Data') }}</span>
                <span wire:loading wire:target="save">{{ __('Menyimpan...') }}</span>
            </button>
        </div>

    </form>

    {{-- ====== FULL-SCREEN LOADING OVERLAY saat save ====== --}}
    {{-- Muncul waktu Livewire proses save. Blokir semua interaksi supaya user tidak back --}}
    {{-- atau double-tap sebelum data tersimpan. --}}
    <div wire:loading.flex wire:target="save"
         class="fixed inset-0 z-9999 items-center justify-center bg-zinc-900/70 backdrop-blur-sm"
         style="display: none;">
        <div class="mx-4 flex max-w-xs flex-col items-center gap-4 rounded-2xl bg-white p-6 shadow-2xl dark:bg-zinc-900">
            {{-- Spinner --}}
            <div class="relative">
                <div class="h-14 w-14 rounded-full border-4 border-orange-100 dark:border-orange-950/50"></div>
                <div class="absolute inset-0 h-14 w-14 animate-spin rounded-full border-4 border-transparent border-t-orange-600"></div>
                <div class="absolute inset-0 flex items-center justify-center">
                    <flux:icon.bookmark class="size-5 text-orange-600" />
                </div>
            </div>
            <div class="text-center">
                <div class="text-base font-bold text-zinc-900 dark:text-white">
                    {{ __('Menyimpan data...') }}
                </div>
                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Mohon tunggu, jangan tutup atau kembali') }}
                </div>
            </div>
        </div>
    </div>

</section>
