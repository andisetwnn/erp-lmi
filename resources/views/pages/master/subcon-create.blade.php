<?php

use App\Models\Master\Subcon;
use App\Models\Master\SubconDokumen;
use App\Services\KtpOcrService;
use App\Services\SubconTarifPph;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Registrasi Subcon')] class extends Component
{
    use WithFileUploads;

    public int $langkah = 1;

    // ── Langkah 1: data diri & perusahaan ──
    public $fileKtp = null;

    public string $nik = '';

    public string $nama = '';

    public string $alamat = '';

    public string $telepon = '';

    public bool $punyaBadanUsaha = false;

    public string $badanUsaha = '';

    public string $pemilik = '';

    public string $alamatPemilik = '';

    public $fileSiup = null;

    public string $nomorSiup = '';

    public string $berlakuSiup = '';

    public ?string $ocrPesan = null;

    public ?string $ocrJenis = null;

    // ── Langkah 2: data pajak ──
    public string $jenisJasa = '';

    public string $kualifikasi = '';

    public ?float $pph = null;

    public string $pphCatatan = '';

    public ?float $ppn = null;

    public function mount(): void
    {
        abort_unless($this->bolehKelola(), 403);
    }

    private function bolehKelola(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->can('master.subcon.kelola') || $user?->can('master.kelola'));
    }

    /**
     * Foto KTP dibaca begitu diunggah. Hanya tiga kolom yang diisi — nama, NIK,
     * alamat — karena hanya itu yang disimpan form ini. Hasilnya saran, bukan
     * kebenaran: yang mengisi tetap memeriksa sebelum lanjut.
     */
    public function updatedFileKtp(): void
    {
        if (! $this->fileKtp) {
            return;
        }

        try {
            $this->validate(['fileKtp' => ['required', 'image', 'max:5120']]);
        } catch (\Throwable $e) {
            return;
        }

        $this->ocrPesan = null;
        $this->ocrJenis = null;

        try {
            $hasil = app(KtpOcrService::class)->read($this->fileKtp->getRealPath());

            if (! $hasil['ok']) {
                $this->ocrPesan = $hasil['error'] ?? 'OCR gagal.';
                $this->ocrJenis = 'error';

                return;
            }

            $terisi = [];

            if ($hasil['nama'] && trim($this->nama) === '') {
                $this->nama = $hasil['nama'];
                $terisi[] = 'Nama';
            }

            if ($hasil['nik'] && strlen(preg_replace('/\D/', '', $this->nik)) !== 16) {
                $this->nik = $hasil['nik'];
                $terisi[] = 'Nomor KTP';
            }

            if ($hasil['alamat'] && trim($this->alamat) === '') {
                $this->alamat = $hasil['alamat'];
                $terisi[] = 'Alamat';
            }

            if ($terisi) {
                $this->ocrPesan = 'Terisi otomatis: '.implode(', ', $terisi).'. Mohon diperiksa.';
                $this->ocrJenis = 'success';
            } elseif (! $hasil['nik'] && ! $hasil['nama']) {
                $this->ocrPesan = 'KTP tidak terbaca. Isi manual, atau unggah ulang dengan foto lebih jelas.';
                $this->ocrJenis = 'warning';
            } else {
                $this->ocrPesan = 'KTP terbaca, tapi kolomnya sudah terisi — tidak ada yang ditimpa.';
                $this->ocrJenis = 'warning';
            }
        } catch (\Throwable $e) {
            $this->ocrPesan = 'Gagal memproses KTP: '.$e->getMessage();
            $this->ocrJenis = 'error';
        }
    }

    public function keLangkah(int $tujuan): void
    {
        // Maju wajib lolos pemeriksaan; mundur bebas.
        if ($tujuan > $this->langkah) {
            $this->periksaLangkah($this->langkah);
        }

        $this->langkah = max(1, min(3, $tujuan));
    }

    private function periksaLangkah(int $langkah): void
    {
        if ($langkah === 1) {
            $aturan = [
                'nama' => ['required', 'string', 'max:150'],
                'nik' => ['nullable', 'digits:16'],
                'alamat' => ['nullable', 'string', 'max:500'],
                'telepon' => ['nullable', 'string', 'max:30'],
                'fileKtp' => ['nullable', 'image', 'max:5120'],
            ];

            if ($this->punyaBadanUsaha) {
                $aturan['badanUsaha'] = ['required', 'string', 'max:150'];
                $aturan['pemilik'] = ['nullable', 'string', 'max:150'];
                $aturan['alamatPemilik'] = ['nullable', 'string', 'max:500'];
                $aturan['nomorSiup'] = ['nullable', 'string', 'max:100'];
                $aturan['berlakuSiup'] = ['nullable', 'date'];
                $aturan['fileSiup'] = ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'];
            }

            $this->validate($aturan, [], [
                'nama' => 'nama', 'nik' => 'nomor KTP', 'alamat' => 'alamat',
                'telepon' => 'telepon', 'fileKtp' => 'scan KTP',
                'badanUsaha' => 'nama badan usaha', 'pemilik' => 'pemilik',
                'alamatPemilik' => 'alamat pemilik', 'nomorSiup' => 'nomor SIUP',
                'berlakuSiup' => 'masa berlaku SIUP', 'fileSiup' => 'scan SIUP',
            ]);
        }

        if ($langkah === 2) {
            $this->validate([
                'jenisJasa' => ['nullable', 'in:pelaksana,perencana_pengawas,jasa,notaris'],
                'kualifikasi' => ['nullable', 'in:kecil,menengah,besar,pribadi'],
                'pph' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'ppn' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'pphCatatan' => ['nullable', 'string', 'max:500'],
            ], [], [
                'jenisJasa' => 'jenis jasa', 'kualifikasi' => 'kualifikasi',
                'pph' => 'tarif PPh', 'ppn' => 'tarif PPN', 'pphCatatan' => 'alasan',
            ]);

            // Tarif yang menyimpang dari saran wajib beralasan, sama seperti di halaman detail.
            if ($this->tarifMenyimpang() && trim($this->pphCatatan) === '') {
                // Dilempar langsung, bukan addError lalu lempar kosong — kantong pesan
                // yang kosong justru menghapus pesan yang baru ditambahkan.
                throw ValidationException::withMessages([
                    'pphCatatan' => 'Tarif berbeda dari saran sistem — sebutkan alasannya.',
                ]);
            }
        }
    }

    /** Subcon sementara untuk menghitung saran tarif dari isian yang sedang diketik. */
    private function subconSementara(): Subcon
    {
        $s = new Subcon([
            'nama' => $this->nama ?: 'sementara',
            'jenis_jasa' => $this->jenisJasa ?: null,
            'kualifikasi' => $this->kualifikasi ?: null,
        ]);
        $s->pph_persen = $this->pph;

        // Sertifikat belum ada saat registrasi — SBU/SKA diisi belakangan di detail.
        $s->setRelation('dokumen', collect());

        return $s;
    }

    public function pakaiSaran(): void
    {
        $saran = app(SubconTarifPph::class)->saran($this->subconSementara());

        if ($saran !== null) {
            $this->pph = $saran;
            $this->pphCatatan = '';
        }
    }

    private function tarifMenyimpang(): bool
    {
        return app(SubconTarifPph::class)->menyimpang($this->subconSementara());
    }

    public function simpan(): void
    {
        abort_unless($this->bolehKelola(), 403);

        $this->periksaLangkah(1);
        $this->periksaLangkah(2);

        $subcon = Subcon::create([
            'nama' => $this->nama,
            'badan_usaha' => $this->punyaBadanUsaha ? ($this->badanUsaha ?: null) : null,
            'pemilik' => $this->punyaBadanUsaha ? ($this->pemilik ?: null) : null,
            'alamat_pemilik' => $this->punyaBadanUsaha ? ($this->alamatPemilik ?: null) : null,
            'nik' => $this->nik ?: null,
            'alamat' => $this->alamat ?: null,
            'telepon' => $this->telepon ?: null,
            'jenis_jasa' => $this->jenisJasa ?: null,
            'kualifikasi' => $this->kualifikasi ?: null,
            'pph_persen' => $this->pph,
            'pph_catatan' => $this->pphCatatan ?: null,
            'ppn_persen' => $this->ppn ?? 0,
            'created_by_user_id' => Auth::id(),
        ]);

        if ($this->fileKtp) {
            $subcon->update([
                'foto_ktp_path' => $this->fileKtp->store("subcon/{$subcon->id}", 'private'),
            ]);
        }

        // SIUP langsung jadi dokumen, bukan kolom lepas — supaya masa berlakunya ikut dipantau.
        if ($this->punyaBadanUsaha && trim($this->nomorSiup) !== '') {
            SubconDokumen::create([
                'subcon_id' => $subcon->id,
                'jenis' => 'siup',
                'nomor' => $this->nomorSiup,
                'berlaku_sampai' => $this->berlakuSiup ?: null,
                'file_path' => $this->fileSiup?->store("subcon/{$subcon->id}", 'private'),
                'file_original_name' => $this->fileSiup?->getClientOriginalName(),
                'updated_by_user_id' => Auth::id(),
            ]);
        }

        Flux::toast(variant: 'success', text: 'Subcon terdaftar. Lengkapi dokumen & rekening di sini.');

        $this->redirectRoute('master.subcon.show', $subcon->id, navigate: true);
    }

    public function with(): array
    {
        $tarif = app(SubconTarifPph::class);

        return [
            'saranPph' => $tarif->saran($this->subconSementara()),
            'alasanSaran' => $tarif->alasan($this->subconSementara()),
            'ocrWarna' => match ($this->ocrJenis) {
                'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-300',
                'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-300',
                default => 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-300',
            },
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- KOP --}}
        <div class="mb-6 text-center">
            <flux:heading size="xl">Form Registrasi Sub Kontraktor</flux:heading>
            <flux:subheading>Lengkapi data penanggung jawab dan badan usaha</flux:subheading>
        </div>

        {{-- LANGKAH --}}
        <div class="mb-6 flex flex-wrap items-center justify-center gap-2">
            @foreach ([1 => 'Data Diri & Perusahaan', 2 => 'Data Pajak', 3 => 'Cek Data'] as $nomor => $judul)
                <button type="button" wire:click="keLangkah({{ $nomor }})"
                        @class([
                            'rounded-lg px-4 py-2 text-sm font-medium transition',
                            'bg-blue-600 text-white' => $langkah === $nomor,
                            'text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800' => $langkah !== $nomor,
                        ])>
                    {{ $nomor }}. {{ $judul }}
                </button>
            @endforeach
        </div>

        {{-- ══ LANGKAH 1 ══ --}}
        @if ($langkah === 1)
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <flux:heading size="sm">Data Penanggung Jawab Proyek</flux:heading>
                    </div>
                    <div class="space-y-4 p-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Scan KTP</label>
                            <input type="file" wire:model="fileKtp" accept=".jpg,.jpeg,.png,.webp"
                                   class="block w-full text-xs file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs dark:file:bg-zinc-700" />
                            <div wire:loading wire:target="fileKtp" class="mt-1 text-xs text-zinc-500">
                                Mengunggah dan membaca KTP&hellip;
                            </div>
                            @error('fileKtp') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <flux:input wire:model="nik" label="Nomor KTP" placeholder="Nomor KTP" />
                            <p class="mt-1 text-xs text-zinc-500">16 digit (tanpa spasi)</p>
                            @error('nik') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <flux:input wire:model="nama" label="Nama" placeholder="Nama (sesuai KTP)" />
                            <p class="mt-1 text-xs text-zinc-500">sesuai KTP</p>
                            @error('nama') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <flux:textarea wire:model="alamat" rows="2" label="Alamat" placeholder="Alamat Tempat Tinggal" />
                            <p class="mt-1 text-xs text-zinc-500">sesuai KTP</p>
                            @error('alamat') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                        </div>

                        <flux:input wire:model="telepon" label="Telp/HP" placeholder="nomor telp/hp" />
                        @error('telepon') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- Pratinjau & status baca --}}
                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 px-4 py-3 text-center dark:border-zinc-700">
                        <flux:heading size="sm">Pratinjau &amp; Status Baca</flux:heading>
                    </div>
                    <div class="space-y-4 p-4">
                        <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-600">
                            @if ($fileKtp)
                                <img src="{{ $fileKtp->temporaryUrl() }}" alt="Scan KTP"
                                     class="mx-auto max-h-48 rounded object-contain" />
                            @else
                                <flux:icon.identification class="mx-auto size-8 text-zinc-300" />
                                <div class="mt-2 text-sm text-zinc-500">Belum ada file KTP dipilih</div>
                            @endif
                            <div class="mt-2 text-xs text-zinc-400">Hasil Scan KTP</div>
                        </div>

                        @if ($ocrPesan)
                            <div class="rounded-lg border px-3 py-2 text-xs {{ $ocrWarna }}">
                                {{ $ocrPesan }}
                            </div>
                        @endif

                        @if ($punyaBadanUsaha)
                            <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-600">
                                @if ($fileSiup)
                                    <flux:icon.document-check class="mx-auto size-8 text-emerald-500" />
                                    <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                        {{ $fileSiup->getClientOriginalName() }}
                                    </div>
                                @else
                                    <flux:icon.document class="mx-auto size-8 text-zinc-300" />
                                    <div class="mt-2 text-sm text-zinc-500">Belum ada file SIUP dipilih</div>
                                @endif
                                <div class="mt-2 text-xs text-zinc-400">Berkas SIUP</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- BADAN USAHA --}}
            <div class="mt-4 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="sm">Data Badan Usaha / Perusahaan</flux:heading>
                </div>
                <div class="space-y-4 p-4">
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" wire:model.live="punyaBadanUsaha"
                               class="mt-0.5 rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600" />
                        <span>
                            <span class="font-medium">Subcon memiliki badan usaha/perusahaan</span>
                            <span class="block text-xs text-rose-600 dark:text-rose-400">
                                Jika tidak dicentang, data SIUP &amp; perusahaan disembunyikan untuk mempercepat input.
                            </span>
                        </span>
                    </label>

                    @if ($punyaBadanUsaha)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <flux:input wire:model="badanUsaha" label="Nama Badan Usaha"
                                            placeholder="mis. CV Alfa Omega Perkasa" />
                                @error('badanUsaha') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                            </div>
                            <flux:input wire:model="pemilik" label="Pemilik" placeholder="Nama pemilik" />
                            <div class="sm:col-span-2">
                                <flux:textarea wire:model="alamatPemilik" rows="2"
                                               label="Alamat Pemilik" placeholder="Alamat pemilik" />
                            </div>
                        </div>

                        <div class="grid gap-4 border-t border-zinc-200 pt-4 sm:grid-cols-2 dark:border-zinc-700">
                            <flux:input wire:model="nomorSiup" label="Nomor SIUP" placeholder="opsional" />
                            <flux:input type="date" wire:model="berlakuSiup" label="SIUP Berlaku Sampai" />
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-sm font-medium">Scan SIUP</label>
                                <input type="file" wire:model="fileSiup" accept=".pdf,.jpg,.jpeg,.png,.webp"
                                       class="block w-full text-xs file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs dark:file:bg-zinc-700" />
                                <p class="mt-1 text-[11px] text-zinc-500">
                                    Berkasnya disimpan, tapi nomornya diketik manual &mdash; pembacaan
                                    otomatis baru tersedia untuk KTP.
                                </p>
                                @error('fileSiup') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <flux:button variant="ghost" icon="chevron-left"
                             :href="route('master.subcon.index')" wire:navigate>Batal</flux:button>
                <flux:button variant="primary" wire:click="keLangkah(2)">Lanjut ke Data Pajak</flux:button>
            </div>
        @endif

        {{-- ══ LANGKAH 2 ══ --}}
        @if ($langkah === 2)
            <div class="mx-auto max-w-2xl rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="sm">Jenis Jasa &amp; Pajak</flux:heading>
                </div>
                <div class="space-y-4 p-4">
                    <flux:select wire:model.live="jenisJasa" label="Jenis Jasa">
                        <option value="">- pilih jenis -</option>
                        @foreach (\App\Models\Master\Subcon::LABEL_JENIS_JASA as $kunci => $label)
                            <option value="{{ $kunci }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="kualifikasi" label="Kualifikasi">
                        <option value="">- pilih kualifikasi -</option>
                        @foreach (\App\Models\Master\Subcon::LABEL_KUALIFIKASI as $kunci => $label)
                            <option value="{{ $kunci }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>

                    @if ($saranPph !== null)
                        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800 dark:border-blue-900/40 dark:bg-blue-950/30 dark:text-blue-300">
                            <div class="font-semibold">Saran tarif PPh: {{ number_format($saranPph, 2, ',', '.') }}%</div>
                            <div class="mt-0.5">{{ $alasanSaran }}</div>
                            <div class="mt-1 text-[11px]">
                                Sertifikat SBU/SKA belum dicatat saat registrasi, jadi saran ini
                                menganggap belum bersertifikat. Perbarui di halaman detail setelah
                                dokumennya diisi.
                            </div>
                            <flux:button size="xs" variant="filled" class="mt-2" wire:click="pakaiSaran">
                                Pakai saran ini
                            </flux:button>
                        </div>
                    @endif

                    <flux:input type="number" step="0.01" min="0" max="100" wire:model="pph" label="Tarif PPh (%)" />
                    @error('pph') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

                    <flux:textarea wire:model="pphCatatan" rows="2" label="Alasan kalau berbeda dari saran"
                                   placeholder="wajib diisi kalau tarifnya menyimpang" />
                    @error('pphCatatan') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

                    <flux:input type="number" step="0.01" min="0" max="100" wire:model="ppn"
                                label="Tarif PPN (%)" placeholder="isi hanya kalau PKP" />
                    @error('ppn') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="mx-auto mt-4 flex max-w-2xl items-center justify-between">
                <flux:button variant="ghost" icon="chevron-left" wire:click="keLangkah(1)">Kembali</flux:button>
                <flux:button variant="primary" wire:click="keLangkah(3)">Lanjut ke Cek Data</flux:button>
            </div>
        @endif

        {{-- ══ LANGKAH 3 ══ --}}
        @if ($langkah === 3)
            <div class="mx-auto max-w-2xl rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="sm">Cek Data</flux:heading>
                    <flux:subheading>Periksa sekali lagi sebelum disimpan.</flux:subheading>
                </div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ([
                            'Nama' => $nama ?: '—',
                            'Nomor KTP' => $nik ?: '—',
                            'Alamat' => $alamat ?: '—',
                            'Telp/HP' => $telepon ?: '—',
                            'Scan KTP' => $fileKtp ? 'ada' : 'belum diunggah',
                            'Badan Usaha' => $punyaBadanUsaha ? ($badanUsaha ?: '—') : 'Perorangan',
                            'Pemilik' => $punyaBadanUsaha ? ($pemilik ?: '—') : '—',
                            'Nomor SIUP' => $punyaBadanUsaha ? ($nomorSiup ?: '—') : '—',
                            'Jenis Jasa' => \App\Models\Master\Subcon::LABEL_JENIS_JASA[$jenisJasa] ?? '—',
                            'Kualifikasi' => \App\Models\Master\Subcon::LABEL_KUALIFIKASI[$kualifikasi] ?? '—',
                            'Tarif PPh' => $pph !== null ? number_format($pph, 2, ',', '.').'%' : '—',
                            'Tarif PPN' => $ppn ? number_format($ppn, 2, ',', '.').'%' : '—',
                        ] as $label => $nilai)
                            <tr>
                                <td class="w-44 px-4 py-2 text-zinc-500">{{ $label }}</td>
                                <td class="px-4 py-2">{{ $nilai }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700">
                    Dokumen lain (NPWP, SBU, SIUJK, PKP, SKA) dan rekening bank diisi di
                    halaman detail setelah tersimpan.
                </div>
            </div>

            <div class="mx-auto mt-4 flex max-w-2xl items-center justify-between">
                <flux:button variant="ghost" icon="chevron-left" wire:click="keLangkah(2)">Kembali</flux:button>
                <flux:button variant="primary" wire:click="simpan">Registrasi</flux:button>
            </div>
        @endif
    </div>
</section>
