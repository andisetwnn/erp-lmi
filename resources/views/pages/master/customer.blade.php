<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Bank;
use App\Models\Master\Customer;
use App\Models\Master\CustomerKontakDarurat;
use App\Models\Master\Proyek;
use App\Models\Master\TempatKerja;
use App\Support\FileOptimizer;
use App\Support\PhoneNumber;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Master Customer')] class extends Component {
    use Sortable;
    use WithFileUploads;
    use WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'nama_lengkap';
    }

    #[Url(as: 'tab')]
    public string $tab = 'customer';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'proyek')]
    public string $filterProyek = '';

    #[Url(as: 'qtk')]
    public string $searchTempatKerja = '';

    #[Url(as: 'per')]
    public string $perPage = '10';

    // ===== FORM CUSTOMER =====
    public ?int $editId = null;

    public ?string $proyekId = null;

    public string $namaLengkap = '';

    public string $nik = '';

    public ?string $npwp = null;

    public string $hp = '';

    public ?string $hp2 = null;

    public ?string $sumber = null;

    // Biodata KTP
    public ?string $tempatLahir = null;

    public ?string $tanggalLahir = null;

    public ?string $jenisKelamin = null;

    public ?string $agama = null;

    public ?string $statusPerkawinan = null;

    public ?string $tempatKerjaId = null;

    // Quick add tempat kerja (inline modal)
    public string $newTempatKerjaNama = '';

    public ?string $newTempatKerjaBidang = null;

    public ?string $newTempatKerjaAlamat = null;

    public string $alamatKtp = '';

    // Alamat detail (opsional, terstruktur)
    public ?string $rtRw = null;

    public ?string $provinsiCode = null;

    public ?string $provinsiNama = null;

    public ?string $kotaCode = null;

    public ?string $kotaNama = null;

    public ?string $kecamatanCode = null;

    public ?string $kecamatanNama = null;

    public ?string $kelurahanCode = null;

    public ?string $kelurahanNama = null;

    public ?string $catatan = null;

    // BI Checking
    public ?string $biKol = null;

    public ?string $biDbr = null;

    public ?string $biKeterangan = null;

    public $fotoKtp = null;

    public ?string $fotoKtpPath = null;

    public ?string $bankId = null;

    public ?string $nomorRekening = null;

    public ?string $rekeningAtasNama = null;

    public ?string $namaPasangan = null;

    public ?string $nikPasangan = null;

    public ?string $namaPerusahaanBu = null;

    public ?string $bentukBadanUsaha = null;

    public ?string $alamatPerusahaan = null;

    public ?string $noTelpKantor = null;

    public ?string $jenisPekerjaan = null;

    public ?string $penghasilanBulanan = null;

    public ?string $bidangUsaha = null;

    // Kontak darurat: array of ['nama', 'hubungan', 'nomor_telepon']
    public array $kontakDarurat = [];

    // ===== TAB TEMPAT KERJA - state CRUD =====
    public ?int $tkEditId = null;

    public string $tkNama = '';

    public ?string $tkBidangUsaha = null;

    public ?string $tkAlamat = null;

    public ?string $tkNoTelepon = null;

    // ===== DELETE (shared) =====
    public ?string $deleteEntity = null; // 'customer' | 'tempat_kerja'

    public ?int $deleteId = null;

    public ?string $deleteNama = null;

    public function with(): array
    {
        $per = $this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage);

        return [
            'bankOptions' => Bank::orderBy('nama')->get(['id', 'nama']),
            'proyekOptions' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek', 'nama_perumahan']),
            'tempatKerjaOptions' => TempatKerja::orderBy('nama')->get(['id', 'nama']),

            // Hanya jalan kalau filterProyek sudah dipilih (bukan string kosong)
            'customerList' => $this->filterProyek === '' ? null : $this->applySort(
                Customer::query()
                    ->with(['proyek:id,nama_proyek,nama_perumahan', 'bank:id,nama', 'tempatKerja:id,nama', 'updatedByUser:id,name'])
                    ->withCount('kontakDarurat')
                    ->when($this->filterProyek !== 'all', fn ($q) => $q->where('proyek_id', $this->filterProyek))
                    ->when($this->search, function ($q) {
                        $term = '%'.$this->search.'%';
                        $q->where(function ($qq) use ($term) {
                            $qq->where('nama_lengkap', 'like', $term)
                                ->orWhere('nik', 'like', $term)
                                ->orWhere('hp', 'like', $term)
                                ->orWhere('npwp', 'like', $term)
                                ->orWhereHas('tempatKerja', fn ($tk) => $tk->where('nama', 'like', $term));
                        });
                    }),
                ['nama_lengkap', 'nik', 'hp', 'created_at']
            )->paginate($per, ['*'], 'customerPage'),

            'tempatKerjaList' => TempatKerja::query()
                ->withCount('customer')
                ->with('updatedByUser:id,name')
                ->when($this->searchTempatKerja, function ($q) {
                    $term = '%'.$this->searchTempatKerja.'%';
                    $q->where(function ($qq) use ($term) {
                        $qq->where('nama', 'like', $term)
                            ->orWhere('bidang_usaha', 'like', $term)
                            ->orWhere('alamat', 'like', $term);
                    });
                })
                ->orderBy('nama')
                ->paginate($per, ['*'], 'tempatKerjaPage'),

            'customerCount' => Customer::count(),
            'tempatKerjaCount' => TempatKerja::count(),
        ];
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage('customerPage');
        $this->resetPage('tempatKerjaPage');
    }

    public function updatingSearch(): void { $this->resetPage('customerPage'); }

    public function updatingFilterProyek(): void { $this->resetPage('customerPage'); }

    // Auto-sanitasi HP
    public function updatedHp($value): void
    {
        $clean = PhoneNumber::sanitize($value);
        if ($clean !== $this->hp) {
            $this->hp = $clean ?? '';
        }
    }

    public function updatedKontakDarurat($value, $key): void
    {
        if (! str_ends_with($key, '.nomor_telepon')) return;
        [$index, ] = explode('.', $key);
        $clean = PhoneNumber::sanitize($value);
        if ($clean !== ($this->kontakDarurat[$index]['nomor_telepon'] ?? null)) {
            $this->kontakDarurat[$index]['nomor_telepon'] = $clean ?? '';
        }
    }

    public function updatingSearchTempatKerja(): void { $this->resetPage('tempatKerjaPage'); }

    public function updatingPerPage(): void
    {
        $this->resetPage('customerPage');
        $this->resetPage('tempatKerjaPage');
    }

    public function create(): void
    {
        $this->resetForm();
        $this->addKontakDarurat(); // start dengan 1 row kosong
        Flux::modal('customer-form')->show();
    }

    public function edit(int $id): void
    {
        $c = Customer::with('kontakDarurat')->findOrFail($id);

        $this->editId = $c->id;
        $this->proyekId = (string) $c->proyek_id;
        $this->namaLengkap = $c->nama_lengkap;
        $this->nik = $c->nik;
        $this->npwp = $c->npwp;
        $this->hp = $c->hp;
        $this->hp2 = $c->hp_2;
        $this->sumber = $c->sumber;
        $this->tempatLahir = $c->tempat_lahir;
        $this->tanggalLahir = $c->tanggal_lahir?->format('Y-m-d');
        $this->jenisKelamin = $c->jenis_kelamin;
        $this->agama = $c->agama;
        $this->statusPerkawinan = $c->status_perkawinan;
        $this->tempatKerjaId = $c->tempat_kerja_id ? (string) $c->tempat_kerja_id : null;
        $this->alamatKtp = $c->alamat_ktp;
        $this->rtRw = $c->rt_rw;
        $this->provinsiCode = $c->provinsi_code;
        $this->provinsiNama = $c->provinsi_nama;
        $this->kotaCode = $c->kota_code;
        $this->kotaNama = $c->kota_nama;
        $this->kecamatanCode = $c->kecamatan_code;
        $this->kecamatanNama = $c->kecamatan_nama;
        $this->kelurahanCode = $c->kelurahan_code;
        $this->kelurahanNama = $c->kelurahan_nama;
        $this->catatan = $c->catatan;
        $this->biKol = $c->bi_kol;
        $this->biDbr = $c->bi_dbr ? (string) $c->bi_dbr : null;
        $this->biKeterangan = $c->bi_keterangan;
        $this->fotoKtpPath = $c->foto_ktp;
        $this->fotoKtp = null;
        $this->bankId = $c->bank_id ? (string) $c->bank_id : null;
        $this->nomorRekening = $c->nomor_rekening;
        $this->rekeningAtasNama = $c->rekening_atas_nama;
        $this->namaPasangan = $c->nama_pasangan;
        $this->nikPasangan = $c->nik_pasangan;
        $this->namaPerusahaanBu = $c->nama_perusahaan_bu;
        $this->bentukBadanUsaha = $c->bentuk_badan_usaha;
        $this->alamatPerusahaan = $c->alamat_perusahaan;
        $this->noTelpKantor = $c->no_telp_kantor;
        $this->jenisPekerjaan = $c->jenis_pekerjaan;
        $this->penghasilanBulanan = $c->penghasilan_bulanan ? (string) $c->penghasilan_bulanan : null;
        $this->bidangUsaha = $c->bidang_usaha;

        $this->kontakDarurat = $c->kontakDarurat
            ->map(fn ($k) => [
                'id' => $k->id,
                'nama' => $k->nama,
                'hubungan' => $k->hubungan,
                'nomor_telepon' => $k->nomor_telepon,
            ])
            ->toArray();
        if (empty($this->kontakDarurat)) {
            $this->addKontakDarurat();
        }

        $this->resetErrorBag();
        Flux::modal('customer-form')->show();
    }

    public function addKontakDarurat(): void
    {
        $this->kontakDarurat[] = [
            'id' => null,
            'nama' => '',
            'hubungan' => 'orang_tua',
            'nomor_telepon' => '',
        ];
    }

    public function removeKontakDarurat(int $index): void
    {
        unset($this->kontakDarurat[$index]);
        $this->kontakDarurat = array_values($this->kontakDarurat);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'proyekId' => ['required', 'exists:proyek,id'],
            'namaLengkap' => ['required', 'string', 'max:255'],
            'nik' => [
                'required', 'string', 'max:32',
                'unique:customer,nik'.($this->editId ? ','.$this->editId : ''),
            ],
            'npwp' => ['nullable', 'string', 'max:32'],
            'hp' => ['required', 'string', 'max:30'],
            'hp2' => ['nullable', 'string', 'max:30'],
            'sumber' => ['nullable', 'string', 'max:100'],
            'tempatLahir' => ['nullable', 'string', 'max:100'],
            'tanggalLahir' => ['nullable', 'date'],
            'jenisKelamin' => ['nullable', 'in:L,P'],
            'agama' => ['nullable', 'string', 'max:20'],
            'statusPerkawinan' => ['nullable', 'string', 'max:20'],
            'tempatKerjaId' => ['nullable', 'exists:tempat_kerja,id'],
            'alamatKtp' => ['required', 'string', 'max:500'],
            'rtRw' => ['nullable', 'string', 'max:20'],
            'provinsiCode' => ['nullable', 'string', 'max:10'],
            'provinsiNama' => ['nullable', 'string', 'max:100'],
            'kotaCode' => ['nullable', 'string', 'max:10'],
            'kotaNama' => ['nullable', 'string', 'max:100'],
            'kecamatanCode' => ['nullable', 'string', 'max:10'],
            'kecamatanNama' => ['nullable', 'string', 'max:100'],
            'kelurahanCode' => ['nullable', 'string', 'max:10'],
            'kelurahanNama' => ['nullable', 'string', 'max:100'],
            'catatan' => ['nullable', 'string'],
            'biKol' => ['nullable', 'in:1,2,3,4,5'],
            'biDbr' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'biKeterangan' => ['nullable', 'string'],
            'fotoKtp' => ['nullable', 'image', 'max:2048'],
            'bankId' => ['nullable', 'exists:bank,id'],
            'nomorRekening' => ['nullable', 'string', 'max:50'],
            'rekeningAtasNama' => ['nullable', 'string', 'max:255'],
            'namaPasangan' => ['nullable', 'string', 'max:255'],
            'nikPasangan' => ['nullable', 'string', 'max:32'],
            'namaPerusahaanBu' => ['nullable', 'string', 'max:255'],
            'bentukBadanUsaha' => ['nullable', 'in:pt,cv,yayasan,perorangan,lainnya'],
            'alamatPerusahaan' => ['nullable', 'string', 'max:500'],
            'noTelpKantor' => ['nullable', 'string', 'max:30'],
            'jenisPekerjaan' => ['nullable', 'string', 'max:255'],
            'penghasilanBulanan' => ['nullable', 'numeric', 'min:0'],
            'bidangUsaha' => ['nullable', 'string', 'max:255'],
            'kontakDarurat' => ['array'],
            'kontakDarurat.*.nama' => ['nullable', 'string', 'max:255'],
            'kontakDarurat.*.hubungan' => ['nullable', 'in:orang_tua,saudara,pasangan,anak,teman,lainnya'],
            'kontakDarurat.*.nomor_telepon' => ['nullable', 'string', 'max:30'],
        ], [], [
            'proyekId' => 'proyek',
            'namaLengkap' => 'nama lengkap',
            'nik' => 'NIK',
            'npwp' => 'NPWP',
            'hp' => 'HP',
            'hp2' => 'HP alternatif',
            'sumber' => 'sumber',
            'tempatLahir' => 'tempat lahir',
            'tanggalLahir' => 'tanggal lahir',
            'jenisKelamin' => 'jenis kelamin',
            'agama' => 'agama',
            'statusPerkawinan' => 'status perkawinan',
            'tempatKerjaId' => 'tempat kerja',
            'alamatKtp' => 'alamat KTP',
            'rtRw' => 'RT/RW',
            'provinsiNama' => 'provinsi',
            'kotaNama' => 'kota',
            'kecamatanNama' => 'kecamatan',
            'kelurahanNama' => 'kelurahan',
            'catatan' => 'catatan',
            'biKol' => 'BI Kol',
            'biDbr' => 'BI DBR',
            'biKeterangan' => 'BI keterangan',
            'fotoKtp' => 'foto KTP',
            'bankId' => 'bank',
            'nomorRekening' => 'nomor rekening',
            'rekeningAtasNama' => 'rekening atas nama',
            'namaPasangan' => 'nama pasangan',
            'nikPasangan' => 'NIK pasangan',
            'namaPerusahaanBu' => 'nama perusahaan',
            'bentukBadanUsaha' => 'bentuk badan usaha',
            'alamatPerusahaan' => 'alamat perusahaan',
            'noTelpKantor' => 'no. telepon kantor',
            'jenisPekerjaan' => 'jenis pekerjaan',
            'penghasilanBulanan' => 'penghasilan bulanan',
            'bidangUsaha' => 'bidang usaha',
        ]);

        // Validasi: kontak darurat — kalau ada nama, wajib telepon (dan sebaliknya)
        foreach ($validated['kontakDarurat'] as $i => $kd) {
            $hasNama = ! empty($kd['nama']);
            $hasTelp = ! empty($kd['nomor_telepon']);
            if ($hasNama && ! $hasTelp) {
                $this->addError("kontakDarurat.$i.nomor_telepon", 'Nomor telepon wajib diisi.');
            }
            if (! $hasNama && $hasTelp) {
                $this->addError("kontakDarurat.$i.nama", 'Nama wajib diisi.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        DB::transaction(function () use ($validated) {
            $c = $this->editId
                ? Customer::findOrFail($this->editId)
                : new Customer;

            // Upload foto KTP
            if ($this->fotoKtp) {
                if ($c->foto_ktp && Storage::disk('public')->exists($c->foto_ktp)) {
                    Storage::disk('public')->delete($c->foto_ktp);
                }
                $c->foto_ktp = FileOptimizer::storeOptimized($this->fotoKtp, 'customer-ktp');
            }

            $c->fill([
                'proyek_id' => (int) $validated['proyekId'],
                'nama_lengkap' => $validated['namaLengkap'],
                'nik' => $validated['nik'],
                'npwp' => $validated['npwp'] ?: null,
                'hp' => $validated['hp'],
                'hp_2' => $validated['hp2'] ?: null,
                'sumber' => $validated['sumber'] ?: null,
                'tempat_lahir' => $validated['tempatLahir'] ?: null,
                'tanggal_lahir' => $validated['tanggalLahir'] ?: null,
                'jenis_kelamin' => $validated['jenisKelamin'] ?: null,
                'agama' => $validated['agama'] ?: null,
                'status_perkawinan' => $validated['statusPerkawinan'] ?: null,
                'tempat_kerja_id' => $validated['tempatKerjaId'] ? (int) $validated['tempatKerjaId'] : null,
                'alamat_ktp' => $validated['alamatKtp'],
                'rt_rw' => $validated['rtRw'] ?: null,
                'provinsi_code' => $validated['provinsiCode'] ?: null,
                'provinsi_nama' => $validated['provinsiNama'] ?: null,
                'kota_code' => $validated['kotaCode'] ?: null,
                'kota_nama' => $validated['kotaNama'] ?: null,
                'kecamatan_code' => $validated['kecamatanCode'] ?: null,
                'kecamatan_nama' => $validated['kecamatanNama'] ?: null,
                'kelurahan_code' => $validated['kelurahanCode'] ?: null,
                'kelurahan_nama' => $validated['kelurahanNama'] ?: null,
                'catatan' => $validated['catatan'] ?: null,
                'bi_kol' => $validated['biKol'] ?: null,
                'bi_dbr' => $validated['biDbr'] !== null && $validated['biDbr'] !== '' ? (float) $validated['biDbr'] : null,
                'bi_keterangan' => $validated['biKeterangan'] ?: null,
                'bank_id' => $validated['bankId'] ? (int) $validated['bankId'] : null,
                'nomor_rekening' => $validated['nomorRekening'] ?: null,
                'rekening_atas_nama' => $validated['rekeningAtasNama'] ?: null,
                'nama_pasangan' => $validated['namaPasangan'] ?: null,
                'nik_pasangan' => $validated['nikPasangan'] ?: null,
                'nama_perusahaan_bu' => $validated['namaPerusahaanBu'] ?: null,
                'bentuk_badan_usaha' => $validated['bentukBadanUsaha'] ?: null,
                'alamat_perusahaan' => $validated['alamatPerusahaan'] ?: null,
                'no_telp_kantor' => $validated['noTelpKantor'] ?: null,
                'jenis_pekerjaan' => $validated['jenisPekerjaan'] ?: null,
                'penghasilan_bulanan' => $validated['penghasilanBulanan'] !== null && $validated['penghasilanBulanan'] !== '' ? (float) $validated['penghasilanBulanan'] : null,
                'bidang_usaha' => $validated['bidangUsaha'] ?: null,
                'updated_by_user_id' => Auth::id(),
            ])->save();

            // Sync kontak darurat (delete missing, upsert rest)
            $keepIds = [];
            foreach ($validated['kontakDarurat'] as $kd) {
                if (empty($kd['nama']) && empty($kd['nomor_telepon'])) {
                    continue; // skip baris kosong
                }

                $existingId = $kd['id'] ?? null;
                if ($existingId) {
                    $row = CustomerKontakDarurat::where('customer_id', $c->id)
                        ->where('id', $existingId)
                        ->first();
                    if ($row) {
                        $row->update([
                            'nama' => $kd['nama'],
                            'hubungan' => $kd['hubungan'],
                            'nomor_telepon' => $kd['nomor_telepon'],
                        ]);
                        $keepIds[] = $row->id;
                    }
                } else {
                    $new = $c->kontakDarurat()->create([
                        'nama' => $kd['nama'],
                        'hubungan' => $kd['hubungan'],
                        'nomor_telepon' => $kd['nomor_telepon'],
                    ]);
                    $keepIds[] = $new->id;
                }
            }
            // Hapus yg tidak ada di payload
            $c->kontakDarurat()->whereNotIn('id', $keepIds)->delete();
        });

        Flux::modal('customer-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Data customer diperbarui.'
            : 'Customer baru ditambahkan.');
    }

    // Quick-add tempat kerja tanpa keluar dari form customer
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
        ], [], [
            'newTempatKerjaNama' => 'nama',
            'newTempatKerjaBidang' => 'bidang usaha',
            'newTempatKerjaAlamat' => 'alamat',
        ]);

        $tk = TempatKerja::create([
            'nama' => $validated['newTempatKerjaNama'],
            'bidang_usaha' => $validated['newTempatKerjaBidang'] ?: null,
            'alamat' => $validated['newTempatKerjaAlamat'] ?: null,
            'updated_by_user_id' => Auth::id(),
        ]);

        // Auto-pilih ke form customer
        $this->tempatKerjaId = (string) $tk->id;

        Flux::modal('tempat-kerja-quick-add')->close();
        $this->reset(['newTempatKerjaNama', 'newTempatKerjaBidang', 'newTempatKerjaAlamat']);

        Flux::toast(variant: 'success', text: "Tempat kerja '{$tk->nama}' ditambah & dipilih.");
    }

    public function removeFotoKtp(): void
    {
        if (! $this->editId) {
            $this->fotoKtp = null;
            $this->fotoKtpPath = null;
            return;
        }

        $c = Customer::findOrFail($this->editId);
        if ($c->foto_ktp && Storage::disk('public')->exists($c->foto_ktp)) {
            Storage::disk('public')->delete($c->foto_ktp);
        }
        $c->foto_ktp = null;
        $c->updated_by_user_id = Auth::id();
        $c->save();

        $this->fotoKtpPath = null;
        $this->fotoKtp = null;

        Flux::toast(variant: 'success', text: 'Foto KTP dihapus.');
    }

    public function confirmDeleteCustomer(int $id): void
    {
        $c = Customer::find($id);
        if (! $c) {
            return;
        }

        $this->deleteEntity = 'customer';
        $this->deleteId = $c->id;
        $this->deleteNama = $c->nama_lengkap;

        Flux::modal('customer-delete-confirm')->show();
    }

    // ===== TEMPAT KERJA CRUD =====
    public function createTempatKerja(): void
    {
        $this->resetTempatKerjaForm();
        Flux::modal('tempat-kerja-form')->show();
    }

    public function editTempatKerja(int $id): void
    {
        $tk = TempatKerja::findOrFail($id);

        $this->tkEditId = $tk->id;
        $this->tkNama = $tk->nama;
        $this->tkBidangUsaha = $tk->bidang_usaha;
        $this->tkAlamat = $tk->alamat;
        $this->tkNoTelepon = $tk->no_telepon;
        $this->resetErrorBag();

        Flux::modal('tempat-kerja-form')->show();
    }

    public function saveTempatKerja(): void
    {
        $validated = $this->validate([
            'tkNama' => [
                'required', 'string', 'max:255',
                'unique:tempat_kerja,nama'.($this->tkEditId ? ','.$this->tkEditId : ''),
            ],
            'tkBidangUsaha' => ['nullable', 'string', 'max:255'],
            'tkAlamat' => ['nullable', 'string', 'max:500'],
            'tkNoTelepon' => ['nullable', 'string', 'max:30'],
        ], [], [
            'tkNama' => 'nama',
            'tkBidangUsaha' => 'bidang usaha',
            'tkAlamat' => 'alamat',
            'tkNoTelepon' => 'no. telepon',
        ]);

        $tk = $this->tkEditId
            ? TempatKerja::findOrFail($this->tkEditId)
            : new TempatKerja;

        $tk->fill([
            'nama' => $validated['tkNama'],
            'bidang_usaha' => $validated['tkBidangUsaha'] ?: null,
            'alamat' => $validated['tkAlamat'] ?: null,
            'no_telepon' => $validated['tkNoTelepon'] ?: null,
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('tempat-kerja-form')->close();
        $wasEdit = (bool) $this->tkEditId;
        $this->resetTempatKerjaForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Tempat kerja diperbarui.'
            : 'Tempat kerja baru ditambahkan.');
    }

    public function confirmDeleteTempatKerja(int $id): void
    {
        $tk = TempatKerja::withCount('customer')->find($id);
        if (! $tk) {
            return;
        }

        if ($tk->customer_count > 0) {
            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa hapus',
                text: "Tempat kerja '{$tk->nama}' masih dipakai oleh {$tk->customer_count} customer.",
            );
            return;
        }

        $this->deleteEntity = 'tempat_kerja';
        $this->deleteId = $tk->id;
        $this->deleteNama = $tk->nama;

        Flux::modal('customer-delete-confirm')->show();
    }

    protected function resetTempatKerjaForm(): void
    {
        $this->reset(['tkEditId', 'tkNama', 'tkBidangUsaha', 'tkAlamat', 'tkNoTelepon']);
        $this->resetErrorBag();
    }

    // SHARED DELETE
    public function delete(): void
    {
        if (! $this->deleteEntity || ! $this->deleteId) {
            return;
        }

        if ($this->deleteEntity === 'customer') {
            $c = Customer::findOrFail($this->deleteId);
            if ($c->foto_ktp && Storage::disk('public')->exists($c->foto_ktp)) {
                Storage::disk('public')->delete($c->foto_ktp);
            }
            $c->delete();
            $label = 'Customer';
        } else {
            TempatKerja::findOrFail($this->deleteId)->delete();
            $label = 'Tempat kerja';
        }

        $this->deleteEntity = null;
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::modal('customer-delete-confirm')->close();
        Flux::toast(variant: 'success', text: "{$label} dihapus.");
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editId',
            'proyekId',
            'namaLengkap',
            'nik',
            'npwp',
            'hp',
            'hp2',
            'sumber',
            'tempatLahir',
            'tanggalLahir',
            'jenisKelamin',
            'agama',
            'statusPerkawinan',
            'tempatKerjaId',
            'alamatKtp',
            'rtRw',
            'provinsiCode',
            'provinsiNama',
            'kotaCode',
            'kotaNama',
            'kecamatanCode',
            'kecamatanNama',
            'kelurahanCode',
            'kelurahanNama',
            'catatan',
            'biKol',
            'biDbr',
            'biKeterangan',
            'fotoKtp',
            'fotoKtpPath',
            'bankId',
            'nomorRekening',
            'rekeningAtasNama',
            'namaPasangan',
            'nikPasangan',
            'namaPerusahaanBu',
            'bentukBadanUsaha',
            'alamatPerusahaan',
            'noTelpKantor',
            'jenisPekerjaan',
            'penghasilanBulanan',
            'bidangUsaha',
            'kontakDarurat',
        ]);
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6">
            <flux:heading size="xl">{{ __('Master Customer') }}</flux:heading>
        </div>

        {{-- TAB SWITCHER --}}
        @php
            $tabs = [
                ['key' => 'customer',    'label' => __('Data Customer'), 'count' => $customerCount],
                ['key' => 'tempatKerja', 'label' => __('Tempat Kerja'),  'count' => $tempatKerjaCount],
            ];
        @endphp
        <div class="mb-5 flex flex-wrap items-center gap-2 border-b border-zinc-200 dark:border-zinc-700">
            @foreach ($tabs as $t)
                @php
                    $isActive = $tab === $t['key'];
                    $btnClass = $isActive
                        ? 'border-blue-500 text-blue-700 dark:border-blue-400 dark:text-blue-300'
                        : 'border-transparent text-zinc-500 hover:text-zinc-900 dark:hover:text-white';
                    $badgeClass = $isActive
                        ? 'bg-blue-500 text-white'
                        : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
                @endphp
                <button type="button" wire:click="setTab('{{ $t['key'] }}')"
                        @class([
                            'inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition -mb-px',
                            $btnClass,
                        ])>
                    {{ $t['label'] }}
                    <span @class([
                        'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold',
                        $badgeClass,
                    ])>{{ $t['count'] }}</span>
                </button>
            @endforeach
        </div>

        {{-- ============================================= --}}
        {{-- TAB: DATA CUSTOMER --}}
        {{-- ============================================= --}}
        @if ($tab === 'customer')

        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex-1 sm:max-w-md">
                <flux:select wire:model.live="filterProyek" placeholder="{{ __('-- Pilih proyek dulu --') }}">
                    <flux:select.option value="all">{{ __('Semua Proyek') }}</flux:select.option>
                    @foreach ($proyekOptions as $p)
                        <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }} — {{ $p->nama_perumahan }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="create" class="self-start sm:self-auto">
                {{ __('Tambah Customer') }}
            </flux:button>
        </div>

        @if ($filterProyek === '')
            <div class="rounded-lg border-2 border-dashed border-zinc-200 bg-zinc-50 px-8 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.home-modern class="mx-auto size-12 text-zinc-400" />
                <flux:heading class="mt-4">{{ __('Pilih proyek dulu') }}</flux:heading>
                <p class="mt-1 text-sm text-zinc-500">
                    {{ __('Pilih proyek tertentu atau "Semua Proyek" di dropdown atas untuk menampilkan data customer.') }}
                </p>
            </div>
        @else

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nama, NIK, HP, NPWP, tempat kerja...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <flux:table class="px-4">
                    <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                        <flux:table.column>{{ __('Proyek') }}</flux:table.column>
                        <flux:table.column>{{ __('Foto KTP') }}</flux:table.column>
                        <x-sortable-column field="nama_lengkap" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama / NIK / HP') }}</x-sortable-column>
                        <flux:table.column>{{ __('Pekerjaan') }}</flux:table.column>
                        <flux:table.column>{{ __('Pasangan') }}</flux:table.column>
                        <flux:table.column>{{ __('Rekening') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('Kontak Darurat') }}</flux:table.column>
                        <x-sortable-column field="created_at" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Diupdate') }}</x-sortable-column>
                        <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($customerList as $row)
                            <flux:table.row :key="'customer-'.$row->id">
                                <flux:table.cell class="text-zinc-500">{{ $loop->index + ($customerList->firstItem() ?? 1) }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    @if ($row->proyek)
                                        <flux:badge color="zinc" size="sm" icon="home-modern">{{ $row->proyek->nama_proyek }}</flux:badge>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($row->foto_ktp)
                                        <a href="{{ asset('storage/'.$row->foto_ktp) }}" target="_blank">
                                            <img src="{{ asset('storage/'.$row->foto_ktp) }}" alt="KTP"
                                                 class="h-12 w-16 rounded border border-zinc-200 object-cover dark:border-zinc-700" />
                                        </a>
                                    @else
                                        <div class="flex h-12 w-16 items-center justify-center rounded border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                            <flux:icon.identification class="size-5 text-zinc-400" />
                                        </div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    <div class="font-semibold">{{ $row->nama_lengkap }}</div>
                                    <div class="font-mono text-xs text-zinc-500">{{ __('NIK:') }} {{ $row->nik }}</div>
                                    <div class="font-mono text-xs text-zinc-500">{{ __('HP:') }} {{ $row->hp }}</div>
                                    @if ($row->npwp)
                                        <div class="font-mono text-xs text-zinc-400">{{ __('NPWP:') }} {{ $row->npwp }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    @if ($row->jenis_pekerjaan || $row->tempatKerja)
                                        <div class="font-medium">{{ $row->jenis_pekerjaan ?: '—' }}</div>
                                        <div class="text-zinc-500">{{ $row->tempatKerja?->nama ?: '—' }}</div>
                                    @elseif ($row->nama_perusahaan_bu)
                                        <div class="font-medium">{{ strtoupper($row->bentuk_badan_usaha ?? '') }} {{ $row->nama_perusahaan_bu }}</div>
                                        <div class="text-zinc-500">{{ $row->bidang_usaha ?: '—' }}</div>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                    @if ($row->penghasilan_bulanan)
                                        <div class="mt-0.5 text-emerald-700 dark:text-emerald-400">
                                            Rp {{ number_format((float) $row->penghasilan_bulanan, 0, ',', '.') }}<span class="text-zinc-400">/bln</span>
                                        </div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    @if ($row->nama_pasangan)
                                        <div class="font-medium">{{ $row->nama_pasangan }}</div>
                                        @if ($row->nik_pasangan)
                                            <div class="font-mono text-zinc-500">{{ $row->nik_pasangan }}</div>
                                        @endif
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    @if ($row->bank)
                                        <div class="font-medium">{{ $row->bank->nama }}</div>
                                        <div class="font-mono text-zinc-500">{{ $row->nomor_rekening ?: '—' }}</div>
                                        <div class="text-zinc-400">a/n {{ $row->rekening_atas_nama ?: $row->nama_lengkap }}</div>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    @if ($row->kontak_darurat_count > 0)
                                        <flux:badge color="blue" size="sm" icon="user-group">{{ $row->kontak_darurat_count }}</flux:badge>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs text-zinc-500">
                                    <div>{{ $row->updated_at?->format('d/m/Y H:i') ?? '—' }}</div>
                                    @if ($row->updatedByUser)
                                        <div class="text-zinc-400">{{ $row->updatedByUser->name }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $row->id }})">
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteCustomer({{ $row->id }})">
                                                {{ __('Hapus') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="10" class="py-8 text-center text-zinc-500">
                                    @if ($search || $filterProyek)
                                        {{ __('Tidak ada customer yang cocok dengan filter.') }}
                                    @else
                                        {{ __('Belum ada customer. Klik "Tambah Customer" untuk menambahkan.') }}
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $customerList])

        @endif {{-- /filterProyek != '' --}}

        @endif {{-- /tab customer --}}

        {{-- ============================================= --}}
        {{-- TAB: TEMPAT KERJA --}}
        {{-- ============================================= --}}
        @if ($tab === 'tempatKerja')

        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex-1">
                <flux:input wire:model.live.debounce.300ms="searchTempatKerja" icon="magnifying-glass"
                            :placeholder="__('Cari nama, bidang usaha, atau alamat...')" />
            </div>
            <flux:button variant="primary" icon="plus" wire:click="createTempatKerja" class="self-start sm:self-auto">
                {{ __('Tambah Tempat Kerja') }}
            </flux:button>
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <flux:table class="px-4">
                <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                    <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                    <flux:table.column>{{ __('Nama') }}</flux:table.column>
                    <flux:table.column>{{ __('Bidang Usaha') }}</flux:table.column>
                    <flux:table.column>{{ __('Alamat') }}</flux:table.column>
                    <flux:table.column>{{ __('Telepon') }}</flux:table.column>
                    <flux:table.column align="center">{{ __('Jumlah Customer') }}</flux:table.column>
                    <flux:table.column>{{ __('Diupdate') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($tempatKerjaList as $row)
                        <flux:table.row :key="'tk-'.$row->id">
                            <flux:table.cell class="text-zinc-500">{{ $loop->index + ($tempatKerjaList->firstItem() ?? 1) }}</flux:table.cell>
                            <flux:table.cell variant="strong" class="whitespace-nowrap">{{ $row->nama }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-zinc-600 dark:text-zinc-300">{{ $row->bidang_usaha ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="max-w-md truncate text-xs text-zinc-600 dark:text-zinc-300" title="{{ $row->alamat }}">
                                {{ $row->alamat ?: '—' }}
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap font-mono text-xs">{{ $row->no_telepon ?: '—' }}</flux:table.cell>
                            <flux:table.cell align="center">
                                @if ($row->customer_count > 0)
                                    <flux:badge color="blue" size="sm" icon="users">{{ $row->customer_count }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">0</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-xs text-zinc-500">
                                <div>{{ $row->updated_at?->format('d/m/Y H:i') ?? '—' }}</div>
                                @if ($row->updatedByUser)
                                    <div class="text-zinc-400">{{ $row->updatedByUser->name }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="editTempatKerja({{ $row->id }})">
                                            {{ __('Edit') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteTempatKerja({{ $row->id }})">
                                            {{ __('Hapus') }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8" class="py-8 text-center text-zinc-500">
                                @if ($searchTempatKerja)
                                    {{ __('Tidak ada tempat kerja yang cocok.') }}
                                @else
                                    {{ __('Belum ada tempat kerja. Klik "Tambah Tempat Kerja".') }}
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $tempatKerjaList])

        @endif {{-- /tab tempatKerja --}}

        {{-- FORM MODAL --}}
        <flux:modal name="customer-form" class="md:w-[95vw]! lg:w-[85vw]! xl:w-[80vw]! max-w-300!" focusable>
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editId ? __('Edit Customer') : __('Tambah Customer') }}</flux:heading>
                    <flux:subheading>{{ __('Identitas, pekerjaan, dan rekening customer.') }}</flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                {{-- PROYEK --}}
                <flux:field>
                    <flux:label>{{ __('Proyek') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:select wire:model="proyekId" required>
                        <flux:select.option value="">{{ __('-- Pilih proyek --') }}</flux:select.option>
                        @foreach ($proyekOptions as $p)
                            <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }} — {{ $p->nama_perumahan }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:description>{{ __('Customer ini terikat ke proyek tertentu (booking unit di proyek ini).') }}</flux:description>
                    <flux:error name="proyekId" />
                </flux:field>

                <flux:separator />

                {{-- DATA DIRI --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Data Diri') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Nama Lengkap') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="namaLengkap" required />
                            <flux:error name="namaLengkap" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('NIK') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="nik" required placeholder="16 digit nomor KTP" />
                            <flux:error name="nik" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('HP') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model.blur="hp" required placeholder="08xxxxxxxxxx" />
                            <flux:error name="hp" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('HP Alternatif') }}</flux:label>
                            <flux:input wire:model="hp2" placeholder="opsional" />
                            <flux:error name="hp2" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('NPWP') }}</flux:label>
                            <flux:input wire:model="npwp" placeholder="opsional" />
                            <flux:error name="npwp" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Sumber') }}</flux:label>
                            <flux:input wire:model="sumber" placeholder="Instagram / Referral / Walk-in / dll" />
                            <flux:error name="sumber" />
                        </flux:field>
                    </div>

                    {{-- Biodata KTP --}}
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Tempat Lahir') }}</flux:label>
                            <flux:input wire:model="tempatLahir" placeholder="mis. Jakarta" />
                            <flux:error name="tempatLahir" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Tanggal Lahir') }}</flux:label>
                            <flux:input type="date" wire:model="tanggalLahir" />
                            <flux:error name="tanggalLahir" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Jenis Kelamin') }}</flux:label>
                            <flux:select wire:model="jenisKelamin">
                                <flux:select.option value="">{{ __('-- Pilih --') }}</flux:select.option>
                                <flux:select.option value="L">{{ __('Laki-laki') }}</flux:select.option>
                                <flux:select.option value="P">{{ __('Perempuan') }}</flux:select.option>
                            </flux:select>
                            <flux:error name="jenisKelamin" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Agama') }}</flux:label>
                            <flux:select wire:model="agama">
                                <flux:select.option value="">{{ __('-- Pilih --') }}</flux:select.option>
                                <flux:select.option value="Islam">Islam</flux:select.option>
                                <flux:select.option value="Kristen">Kristen</flux:select.option>
                                <flux:select.option value="Katolik">Katolik</flux:select.option>
                                <flux:select.option value="Hindu">Hindu</flux:select.option>
                                <flux:select.option value="Buddha">Buddha</flux:select.option>
                                <flux:select.option value="Konghucu">Konghucu</flux:select.option>
                            </flux:select>
                            <flux:error name="agama" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Status Perkawinan') }}</flux:label>
                            <flux:select wire:model="statusPerkawinan">
                                <flux:select.option value="">{{ __('-- Pilih --') }}</flux:select.option>
                                <flux:select.option value="Belum Kawin">{{ __('Belum Kawin') }}</flux:select.option>
                                <flux:select.option value="Kawin">{{ __('Kawin') }}</flux:select.option>
                                <flux:select.option value="Cerai Hidup">{{ __('Cerai Hidup') }}</flux:select.option>
                                <flux:select.option value="Cerai Mati">{{ __('Cerai Mati') }}</flux:select.option>
                            </flux:select>
                            <flux:error name="statusPerkawinan" />
                        </flux:field>
                    </div>

                    <flux:textarea wire:model="alamatKtp" :label="__('Alamat KTP')" rows="2" required />
                    <flux:error name="alamatKtp" />

                    {{-- Alamat detail terstruktur (opsional) --}}
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <flux:field>
                            <flux:label>{{ __('RT/RW') }}</flux:label>
                            <flux:input wire:model="rtRw" placeholder="005/003" />
                            <flux:error name="rtRw" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Kelurahan') }}</flux:label>
                            <flux:input wire:model="kelurahanNama" />
                            <flux:error name="kelurahanNama" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Kecamatan') }}</flux:label>
                            <flux:input wire:model="kecamatanNama" />
                            <flux:error name="kecamatanNama" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Kota / Kabupaten') }}</flux:label>
                            <flux:input wire:model="kotaNama" />
                            <flux:error name="kotaNama" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Provinsi') }}</flux:label>
                            <flux:input wire:model="provinsiNama" />
                            <flux:error name="provinsiNama" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Foto KTP') }}</flux:label>
                        @if ($fotoKtpPath)
                            <div class="mb-2 flex items-center gap-3">
                                <img src="{{ asset('storage/'.$fotoKtpPath) }}" alt="KTP"
                                     class="h-20 w-32 rounded border border-zinc-200 object-cover dark:border-zinc-700" />
                                <flux:button size="sm" variant="ghost" type="button" wire:click="removeFotoKtp">
                                    {{ __('Hapus Foto') }}
                                </flux:button>
                            </div>
                        @endif
                        <input type="file" wire:model="fotoKtp" accept="image/*"
                               class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200" />
                        <flux:description>{{ __('Opsional. PNG/JPG, maks 2MB.') }}</flux:description>
                        <flux:error name="fotoKtp" />
                        <div wire:loading wire:target="fotoKtp" class="mt-1 text-sm text-zinc-500">
                            {{ __('Mengunggah...') }}
                        </div>
                    </flux:field>
                </div>

                <flux:separator />

                {{-- PEKERJAAN --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Pekerjaan') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Jenis Pekerjaan') }}</flux:label>
                            <flux:input wire:model="jenisPekerjaan" placeholder="Karyawan / PNS / Wiraswasta / dll" />
                            <flux:error name="jenisPekerjaan" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Tempat Kerja') }}</flux:label>
                            <div class="flex items-stretch gap-2">
                                <div class="flex-1">
                                    <flux:select wire:model="tempatKerjaId">
                                        <flux:select.option value="">{{ __('-- Pilih tempat kerja --') }}</flux:select.option>
                                        @foreach ($tempatKerjaOptions as $tk)
                                            <flux:select.option value="{{ $tk->id }}">{{ $tk->nama }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                                <flux:button type="button" size="sm" variant="filled" icon="plus" wire:click="openQuickAddTempatKerja">
                                    {{ __('Baru') }}
                                </flux:button>
                            </div>
                            <flux:description>{{ __('Tidak ada di daftar? Klik "Baru" untuk tambah tempat kerja.') }}</flux:description>
                            <flux:error name="tempatKerjaId" />
                        </flux:field>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Penghasilan Bulanan') }}</flux:label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-zinc-500">
                                    Rp
                                </span>
                                <input type="number" wire:model="penghasilanBulanan"
                                       inputmode="numeric" min="0" step="100000"
                                       placeholder="mis. 8000000"
                                       class="block w-full rounded-lg border border-zinc-200 bg-white py-1.5 pl-9 pr-3 text-sm text-zinc-900 shadow-sm placeholder:text-zinc-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                            </div>
                            <flux:description>{{ __('Digunakan untuk simulasi KPR / analisa kemampuan bayar.') }}</flux:description>
                            <flux:error name="penghasilanBulanan" />
                        </flux:field>
                    </div>
                </div>

                <flux:separator />

                {{-- BI CHECKING --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('BI Checking (SLIK)') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Kolektibilitas (Kol)') }}</flux:label>
                            <flux:select wire:model="biKol">
                                <flux:select.option value="">{{ __('-- Pilih --') }}</flux:select.option>
                                <flux:select.option value="1">1 — Lancar</flux:select.option>
                                <flux:select.option value="2">2 — DPK</flux:select.option>
                                <flux:select.option value="3">3 — Kurang Lancar</flux:select.option>
                                <flux:select.option value="4">4 — Diragukan</flux:select.option>
                                <flux:select.option value="5">5 — Macet</flux:select.option>
                            </flux:select>
                            <flux:error name="biKol" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('DBR (%)') }}</flux:label>
                            <flux:input type="number" wire:model="biDbr" step="0.01" min="0" max="100" placeholder="mis. 35.50" />
                            <flux:description>{{ __('Debt Burden Ratio, 0-100%.') }}</flux:description>
                            <flux:error name="biDbr" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Keterangan BI') }}</flux:label>
                        <flux:textarea wire:model="biKeterangan" rows="2" placeholder="Catatan tambahan hasil BI Checking (opsional)" />
                        <flux:error name="biKeterangan" />
                    </flux:field>
                </div>

                <flux:separator />

                {{-- BADAN USAHA --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Badan Usaha (jika wiraswasta)') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Bentuk Badan Usaha') }}</flux:label>
                            <flux:select wire:model="bentukBadanUsaha">
                                <flux:select.option value="">{{ __('-- Pilih --') }}</flux:select.option>
                                <flux:select.option value="pt">PT</flux:select.option>
                                <flux:select.option value="cv">CV</flux:select.option>
                                <flux:select.option value="yayasan">{{ __('Yayasan') }}</flux:select.option>
                                <flux:select.option value="perorangan">{{ __('Perorangan') }}</flux:select.option>
                                <flux:select.option value="lainnya">{{ __('Lainnya') }}</flux:select.option>
                            </flux:select>
                            <flux:error name="bentukBadanUsaha" />
                        </flux:field>

                        <div class="md:col-span-2">
                            <flux:field>
                                <flux:label>{{ __('Nama Perusahaan / Badan Usaha') }}</flux:label>
                                <flux:input wire:model="namaPerusahaanBu" />
                                <flux:error name="namaPerusahaanBu" />
                            </flux:field>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Bidang Usaha') }}</flux:label>
                            <flux:input wire:model="bidangUsaha" placeholder="Konstruksi / Retail / dll" />
                            <flux:error name="bidangUsaha" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('No. Telepon Kantor') }}</flux:label>
                            <flux:input wire:model="noTelpKantor" />
                            <flux:error name="noTelpKantor" />
                        </flux:field>
                    </div>

                    <flux:textarea wire:model="alamatPerusahaan" :label="__('Alamat Perusahaan')" rows="2" />
                    <flux:error name="alamatPerusahaan" />
                </div>

                <flux:separator />

                {{-- PASANGAN --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Data Pasangan') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Nama Pasangan') }}</flux:label>
                            <flux:input wire:model="namaPasangan" />
                            <flux:error name="namaPasangan" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('NIK Pasangan') }}</flux:label>
                            <flux:input wire:model="nikPasangan" />
                            <flux:error name="nikPasangan" />
                        </flux:field>
                    </div>
                </div>

                <flux:separator />

                {{-- REKENING --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Rekening') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Bank') }}</flux:label>
                        <flux:select wire:model="bankId">
                            <flux:select.option value="">{{ __('-- Pilih bank --') }}</flux:select.option>
                            @foreach ($bankOptions as $b)
                                <flux:select.option value="{{ $b->id }}">{{ $b->nama }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="bankId" />
                    </flux:field>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Nomor Rekening') }}</flux:label>
                            <flux:input wire:model="nomorRekening" />
                            <flux:error name="nomorRekening" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Atas Nama') }}</flux:label>
                            <flux:input wire:model="rekeningAtasNama" />
                            <flux:description>{{ __('Kosongkan jika sama dengan nama customer.') }}</flux:description>
                            <flux:error name="rekeningAtasNama" />
                        </flux:field>
                    </div>
                </div>

                <flux:separator />

                {{-- KONTAK DARURAT --}}
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Kontak Darurat') }}</flux:heading>
                        <flux:button type="button" size="sm" variant="filled" icon="plus" wire:click="addKontakDarurat">
                            {{ __('Tambah Baris') }}
                        </flux:button>
                    </div>

                    @if (empty($kontakDarurat))
                        <p class="text-sm text-zinc-500">{{ __('Belum ada kontak darurat. Klik "Tambah Baris".') }}</p>
                    @else
                        <div class="space-y-2">
                            @foreach ($kontakDarurat as $i => $kd)
                                <div wire:key="kd-{{ $i }}"
                                     class="grid grid-cols-1 gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-3 md:grid-cols-[1.5fr_1fr_1.5fr_auto] md:items-end dark:border-zinc-700 dark:bg-zinc-800/50">
                                    <flux:field>
                                        <flux:label class="text-xs">{{ __('Nama') }}</flux:label>
                                        <flux:input wire:model="kontakDarurat.{{ $i }}.nama" />
                                        <flux:error :name="'kontakDarurat.'.$i.'.nama'" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label class="text-xs">{{ __('Hubungan') }}</flux:label>
                                        <flux:select wire:model="kontakDarurat.{{ $i }}.hubungan">
                                            <flux:select.option value="orang_tua">{{ __('Orang Tua') }}</flux:select.option>
                                            <flux:select.option value="saudara">{{ __('Saudara') }}</flux:select.option>
                                            <flux:select.option value="pasangan">{{ __('Pasangan') }}</flux:select.option>
                                            <flux:select.option value="anak">{{ __('Anak') }}</flux:select.option>
                                            <flux:select.option value="teman">{{ __('Teman') }}</flux:select.option>
                                            <flux:select.option value="lainnya">{{ __('Lainnya') }}</flux:select.option>
                                        </flux:select>
                                        <flux:error :name="'kontakDarurat.'.$i.'.hubungan'" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label class="text-xs">{{ __('Nomor Telepon') }}</flux:label>
                                        <flux:input wire:model.blur="kontakDarurat.{{ $i }}.nomor_telepon" />
                                        <flux:error :name="'kontakDarurat.'.$i.'.nomor_telepon'" />
                                    </flux:field>
                                    <flux:button type="button" size="sm" variant="danger" icon="trash" wire:click="removeKontakDarurat({{ $i }})" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <flux:separator />

                {{-- CATATAN --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Catatan') }}</flux:heading>
                    <flux:field>
                        <flux:textarea wire:model="catatan" rows="3" placeholder="{{ __('Catatan internal (opsional)') }}" />
                        <flux:error name="catatan" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- QUICK ADD TEMPAT KERJA --}}
        <flux:modal name="tempat-kerja-quick-add" class="md:w-lg">
            <form wire:submit="saveQuickTempatKerja" class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-950">
                        <flux:icon.building-office class="size-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Tambah Tempat Kerja Baru') }}</flux:heading>
                        <flux:subheading>{{ __('Setelah disimpan otomatis terpilih di form customer.') }}</flux:subheading>
                    </div>
                </div>

                <flux:field>
                    <flux:label>{{ __('Nama Tempat Kerja') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="newTempatKerjaNama" required placeholder="PT XYZ Indonesia" />
                    <flux:error name="newTempatKerjaNama" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Bidang Usaha') }}</flux:label>
                    <flux:input wire:model="newTempatKerjaBidang" placeholder="Manufaktur / Perbankan / dll" />
                    <flux:error name="newTempatKerjaBidang" />
                </flux:field>

                <flux:textarea wire:model="newTempatKerjaAlamat" :label="__('Alamat')" rows="2"
                               :description="__('Opsional.')" />
                <flux:error name="newTempatKerjaAlamat" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" icon="plus">{{ __('Simpan & Pilih') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- FORM TEMPAT KERJA (FULL CRUD dari tab) --}}
        <flux:modal name="tempat-kerja-form" class="md:w-lg" focusable>
            <form wire:submit="saveTempatKerja" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $tkEditId ? __('Edit Tempat Kerja') : __('Tambah Tempat Kerja') }}</flux:heading>
                    <flux:subheading>{{ __('Daftar referensi perusahaan/instansi.') }}</flux:subheading>
                </div>

                <flux:field>
                    <flux:label>{{ __('Nama') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="tkNama" required autofocus placeholder="PT XYZ Indonesia" />
                    <flux:error name="tkNama" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Bidang Usaha') }}</flux:label>
                    <flux:input wire:model="tkBidangUsaha" placeholder="Manufaktur / Perbankan / dll" />
                    <flux:error name="tkBidangUsaha" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('No. Telepon') }}</flux:label>
                    <flux:input wire:model="tkNoTelepon" />
                    <flux:error name="tkNoTelepon" />
                </flux:field>

                <flux:textarea wire:model="tkAlamat" :label="__('Alamat')" rows="2"
                               :description="__('Opsional.')" />
                <flux:error name="tkAlamat" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- DELETE CONFIRM (shared) --}}
        <flux:modal name="customer-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">
                            {{ $deleteEntity === 'tempat_kerja' ? __('Hapus Tempat Kerja?') : __('Hapus Customer?') }}
                        </flux:heading>
                        <flux:subheading>
                            @if ($deleteEntity === 'customer')
                                {{ __('Anda akan menghapus :nama beserta kontak daruratnya. Tindakan ini tidak dapat dibatalkan.', ['nama' => $deleteNama]) }}
                            @else
                                {{ __('Anda akan menghapus :nama. Tindakan ini tidak dapat dibatalkan.', ['nama' => $deleteNama]) }}
                            @endif
                        </flux:subheading>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="button" icon="trash" wire:click="delete">
                        {{ __('Hapus') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

    </div>
</section>
