<?php

use App\Models\Master\Bank;
use App\Models\Master\Subcon;
use App\Models\Master\SubconDokumen;
use App\Models\Master\SubconRekening;
use App\Services\KtpOcrService;
use App\Services\SubconTarifPph;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Detail Subcon')] class extends Component
{
    use WithFileUploads;

    public int $subconId;

    // Identitas
    public string $i_nama = '';

    public string $i_badan_usaha = '';

    public string $i_nik = '';

    public string $i_alamat = '';

    public string $i_telepon = '';

    public $i_foto_ktp = null;

    public ?string $ocrPesan = null;

    public ?string $ocrJenis = null;

    // Jasa & pajak
    public string $j_jenis_jasa = '';

    public string $j_kualifikasi = '';

    public ?float $j_pph = null;

    public string $j_pph_catatan = '';

    public ?float $j_ppn = null;

    // Dokumen
    public string $d_jenis = '';

    public string $d_nomor = '';

    public string $d_berlaku = '';

    public $d_file = null;

    public ?int $d_hapusId = null;

    // Rekening
    public ?int $r_id = null;

    public ?int $r_bank_id = null;

    public string $r_bank_nama = '';

    public string $r_nomor = '';

    public string $r_atas_nama = '';

    public bool $r_utama = false;

    public ?int $r_hapusId = null;

    public bool $konfirmasiHapus = false;

    public function mount(int $id): void
    {
        $this->subconId = $id;
    }

    public function getSubconProperty(): Subcon
    {
        return Subcon::with(['dokumen', 'rekening.bank'])->findOrFail($this->subconId);
    }

    private function pastikanBoleh(): void
    {
        $user = Auth::user();
        abort_unless($user?->can('master.subcon.kelola') || $user?->can('master.kelola'), 403);
    }

    // ─── IDENTITAS ───────────────────────────────────────────────

    public function openIdentitas(): void
    {
        $this->pastikanBoleh();

        $s = $this->subcon;
        $this->i_nama = $s->nama;
        $this->i_badan_usaha = (string) $s->badan_usaha;
        $this->i_nik = (string) $s->nik;
        $this->i_alamat = (string) $s->alamat;
        $this->i_telepon = (string) $s->telepon;
        $this->i_foto_ktp = null;
        $this->reset(['ocrPesan', 'ocrJenis']);
        $this->resetErrorBag();
        Flux::modal('edit-identitas')->show();
    }

    /**
     * Begitu foto KTP diunggah, OCR mengisi tiga kolom yang memang ada di form ini:
     * nama, nomor KTP, dan alamat. Sisa hasil bacaan diabaikan — form subcon tidak
     * menyimpan tempat lahir, agama, dan seterusnya.
     *
     * Hasilnya SARAN: kolom yang sudah terisi tidak ditimpa, dan yang mengisi tetap
     * harus memeriksa sebelum menyimpan.
     */
    public function updatedIFotoKtp(): void
    {
        if (! $this->i_foto_ktp) {
            return;
        }

        try {
            $this->validate(['i_foto_ktp' => ['required', 'image', 'max:5120']]);
        } catch (\Throwable $e) {
            return;
        }

        $this->jalankanOcr();
    }

    public function jalankanOcr(): void
    {
        if (! $this->i_foto_ktp) {
            return;
        }

        $this->ocrPesan = null;
        $this->ocrJenis = null;

        try {
            $hasil = app(KtpOcrService::class)->read($this->i_foto_ktp->getRealPath());

            if (! $hasil['ok']) {
                $this->ocrPesan = $hasil['error'] ?? 'OCR gagal.';
                $this->ocrJenis = 'error';

                return;
            }

            $terisi = [];

            if ($hasil['nama'] && trim($this->i_nama) === '') {
                $this->i_nama = $hasil['nama'];
                $terisi[] = 'Nama';
            }

            if ($hasil['nik'] && strlen(preg_replace('/\D/', '', $this->i_nik)) !== 16) {
                $this->i_nik = $hasil['nik'];
                $terisi[] = 'Nomor KTP';
            }

            if ($hasil['alamat'] && trim($this->i_alamat) === '') {
                $this->i_alamat = $hasil['alamat'];
                $terisi[] = 'Alamat';
            }

            if ($terisi) {
                $this->ocrPesan = 'Terisi otomatis: '.implode(', ', $terisi).'. Mohon diperiksa dulu sebelum disimpan.';
                $this->ocrJenis = 'success';
            } elseif (! $hasil['nik'] && ! $hasil['nama']) {
                $this->ocrPesan = 'KTP tidak terbaca. Isi manual, atau unggah ulang dengan foto yang lebih jelas.';
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

    public function simpanIdentitas(): void
    {
        $this->pastikanBoleh();

        $this->validate([
            'i_nama' => ['required', 'string', 'max:150'],
            'i_badan_usaha' => ['nullable', 'string', 'max:150'],
            'i_nik' => ['nullable', 'string', 'max:20'],
            'i_alamat' => ['nullable', 'string', 'max:500'],
            'i_telepon' => ['nullable', 'string', 'max:30'],
            'i_foto_ktp' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'],
        ], [], [
            'i_nama' => 'nama', 'i_badan_usaha' => 'badan usaha', 'i_nik' => 'nomor KTP',
            'i_alamat' => 'alamat', 'i_telepon' => 'telepon', 'i_foto_ktp' => 'foto KTP',
        ]);

        $s = $this->subcon;
        $data = [
            'nama' => $this->i_nama,
            'badan_usaha' => $this->i_badan_usaha ?: null,
            'nik' => $this->i_nik ?: null,
            'alamat' => $this->i_alamat ?: null,
            'telepon' => $this->i_telepon ?: null,
            'updated_by_user_id' => Auth::id(),
        ];

        if ($this->i_foto_ktp) {
            // KTP masuk disk private — bukan berkas yang boleh diambil siapa pun.
            $data['foto_ktp_path'] = $this->i_foto_ktp->store("subcon/{$s->id}", 'private');
        }

        $s->update($data);

        Flux::modal('edit-identitas')->close();
        Flux::toast(variant: 'success', text: 'Data penanggung jawab diperbarui.');
        $this->reset(['i_foto_ktp', 'ocrPesan', 'ocrJenis']);
    }

    // ─── JASA & PAJAK ────────────────────────────────────────────

    public function openJasa(): void
    {
        $this->pastikanBoleh();

        $s = $this->subcon;
        $this->j_jenis_jasa = (string) $s->jenis_jasa;
        $this->j_kualifikasi = (string) $s->kualifikasi;
        $this->j_pph = $s->pph_persen !== null ? (float) $s->pph_persen : null;
        $this->j_pph_catatan = (string) $s->pph_catatan;
        $this->j_ppn = (float) $s->ppn_persen;
        $this->resetErrorBag();
        Flux::modal('edit-jasa')->show();
    }

    /** Isi tarif dengan saran sistem. */
    public function pakaiSaran(): void
    {
        $saran = app(SubconTarifPph::class)->saran($this->subconSementara());

        if ($saran !== null) {
            $this->j_pph = $saran;
            $this->j_pph_catatan = '';
        }
    }

    /**
     * Salinan subcon dengan jenis & kualifikasi dari form, supaya saran tarif
     * mengikuti yang sedang diketik — bukan yang masih tersimpan.
     */
    private function subconSementara(): Subcon
    {
        $s = $this->subcon;
        $s->jenis_jasa = $this->j_jenis_jasa ?: null;
        $s->kualifikasi = $this->j_kualifikasi ?: null;

        return $s;
    }

    public function simpanJasa(): void
    {
        $this->pastikanBoleh();

        $this->validate([
            'j_jenis_jasa' => ['nullable', 'in:pelaksana,perencana_pengawas,jasa,notaris'],
            'j_kualifikasi' => ['nullable', 'in:kecil,menengah,besar,pribadi'],
            'j_pph' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'j_ppn' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'j_pph_catatan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'j_jenis_jasa' => 'jenis jasa', 'j_kualifikasi' => 'kualifikasi',
            'j_pph' => 'tarif PPh', 'j_ppn' => 'tarif PPN', 'j_pph_catatan' => 'alasan',
        ]);

        // Tarif yang menyimpang dari saran wajib disertai alasan — supaya penyimpangan
        // tarif pajak tidak terjadi diam-diam.
        $tarif = app(SubconTarifPph::class);
        $sementara = $this->subconSementara();
        $sementara->pph_persen = $this->j_pph;

        if ($tarif->menyimpang($sementara) && trim($this->j_pph_catatan) === '') {
            $this->addError('j_pph_catatan', 'Tarif berbeda dari saran sistem — sebutkan alasannya.');

            return;
        }

        $this->subcon->update([
            'jenis_jasa' => $this->j_jenis_jasa ?: null,
            'kualifikasi' => $this->j_kualifikasi ?: null,
            'pph_persen' => $this->j_pph,
            'pph_catatan' => $this->j_pph_catatan ?: null,
            'ppn_persen' => $this->j_ppn ?? 0,
            'updated_by_user_id' => Auth::id(),
        ]);

        Flux::modal('edit-jasa')->close();
        Flux::toast(variant: 'success', text: 'Jenis jasa & tarif pajak diperbarui.');
    }

    // ─── DOKUMEN ─────────────────────────────────────────────────

    public function openDokumen(string $jenis): void
    {
        $this->pastikanBoleh();

        $d = $this->subcon->dokumenJenis($jenis);
        $this->d_jenis = $jenis;
        $this->d_nomor = (string) $d?->nomor;
        $this->d_berlaku = $d?->berlaku_sampai?->toDateString() ?? '';
        $this->d_file = null;
        $this->resetErrorBag();
        Flux::modal('edit-dokumen')->show();
    }

    public function simpanDokumen(): void
    {
        $this->pastikanBoleh();

        $this->validate([
            'd_jenis' => ['required', 'in:npwp,siup,sbu,siujk,pkp,ska'],
            'd_nomor' => ['required', 'string', 'max:100'],
            'd_berlaku' => ['nullable', 'date'],
            'd_file' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ], [], [
            'd_nomor' => 'nomor dokumen', 'd_berlaku' => 'masa berlaku', 'd_file' => 'berkas',
        ]);

        $s = $this->subcon;
        $data = [
            'nomor' => $this->d_nomor,
            'berlaku_sampai' => $this->d_berlaku ?: null,
            'updated_by_user_id' => Auth::id(),
        ];

        if ($this->d_file) {
            $data['file_path'] = $this->d_file->store("subcon/{$s->id}", 'private');
            $data['file_original_name'] = $this->d_file->getClientOriginalName();
        }

        SubconDokumen::updateOrCreate(
            ['subcon_id' => $s->id, 'jenis' => $this->d_jenis],
            $data,
        );

        Flux::modal('edit-dokumen')->close();
        Flux::toast(variant: 'success', text: strtoupper($this->d_jenis).' disimpan.');
        $this->reset(['d_file']);
    }

    public function konfirmasiHapusDokumen(int $id): void
    {
        $this->pastikanBoleh();
        $this->d_hapusId = $id;
    }

    public function batalHapusDokumen(): void
    {
        $this->d_hapusId = null;
    }

    public function hapusDokumen(): void
    {
        $this->pastikanBoleh();

        $d = SubconDokumen::where('subcon_id', $this->subconId)->findOrFail($this->d_hapusId);

        if ($d->file_path) {
            Storage::disk('private')->delete($d->file_path);
        }

        $label = $d->label();
        $d->delete();
        $this->d_hapusId = null;

        Flux::toast(variant: 'success', text: $label.' dihapus.');
    }

    public function unduhDokumen(int $id): ?Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->pastikanBoleh();

        $d = SubconDokumen::where('subcon_id', $this->subconId)->findOrFail($id);

        if (! $d->adaDiPenyimpanan()) {
            Flux::toast(variant: 'warning', text: 'Berkas tidak ditemukan di penyimpanan.');

            return null;
        }

        return Storage::disk('private')->download($d->file_path, $d->file_original_name ?: basename($d->file_path));
    }

    // ─── REKENING ────────────────────────────────────────────────

    public function openRekening(?int $id = null): void
    {
        $this->pastikanBoleh();

        $r = $id ? SubconRekening::where('subcon_id', $this->subconId)->findOrFail($id) : null;
        $this->r_id = $r?->id;
        $this->r_bank_id = $r?->bank_id;
        $this->r_bank_nama = (string) $r?->bank_nama;
        $this->r_nomor = (string) $r?->nomor_rekening;
        $this->r_atas_nama = $r?->atas_nama ?? $this->subcon->nama;
        $this->r_utama = (bool) $r?->is_utama;
        $this->resetErrorBag();
        Flux::modal('edit-rekening')->show();
    }

    public function simpanRekening(): void
    {
        $this->pastikanBoleh();

        $this->validate([
            'r_bank_id' => ['nullable', 'exists:bank,id'],
            'r_bank_nama' => ['nullable', 'string', 'max:100'],
            'r_nomor' => ['required', 'string', 'max:50'],
            'r_atas_nama' => ['required', 'string', 'max:150'],
        ], [], [
            'r_bank_id' => 'bank', 'r_bank_nama' => 'nama bank',
            'r_nomor' => 'nomor rekening', 'r_atas_nama' => 'atas nama',
        ]);

        if (! $this->r_bank_id && trim($this->r_bank_nama) === '') {
            $this->addError('r_bank_nama', 'Pilih bank dari daftar, atau ketik namanya.');

            return;
        }

        $data = [
            'subcon_id' => $this->subconId,
            'bank_id' => $this->r_bank_id,
            'bank_nama' => $this->r_bank_id ? null : $this->r_bank_nama,
            'nomor_rekening' => $this->r_nomor,
            'atas_nama' => $this->r_atas_nama,
            'is_utama' => $this->r_utama,
            'updated_by_user_id' => Auth::id(),
        ];

        $r = $this->r_id
            ? tap(SubconRekening::findOrFail($this->r_id))->update($data)
            : SubconRekening::create($data);

        // Hanya boleh ada satu rekening utama.
        if ($this->r_utama) {
            SubconRekening::where('subcon_id', $this->subconId)
                ->whereKeyNot($r->id)
                ->update(['is_utama' => false]);
        }

        Flux::modal('edit-rekening')->close();
        Flux::toast(variant: 'success', text: 'Rekening disimpan.');
        $this->reset(['r_id', 'r_bank_id', 'r_bank_nama', 'r_nomor', 'r_atas_nama', 'r_utama']);
    }

    public function konfirmasiHapusRekening(int $id): void
    {
        $this->pastikanBoleh();
        $this->r_hapusId = $id;
    }

    public function batalHapusRekening(): void
    {
        $this->r_hapusId = null;
    }

    public function hapusRekening(): void
    {
        $this->pastikanBoleh();

        SubconRekening::where('subcon_id', $this->subconId)->findOrFail($this->r_hapusId)->delete();
        $this->r_hapusId = null;

        Flux::toast(variant: 'success', text: 'Rekening dihapus.');
    }

    // ─── STATUS ──────────────────────────────────────────────────

    public function konfirmasiHapusSubcon(): void
    {
        $this->pastikanBoleh();
        $this->konfirmasiHapus = true;
    }

    public function batalHapusSubcon(): void
    {
        $this->konfirmasiHapus = false;
    }

    /**
     * Hapus subcon beserta dokumen, rekening, dan berkasnya.
     *
     * Ditolak kalau masih dipakai unit. Kolom rumah.subcon_id dipasang nullOnDelete,
     * jadi tanpa penjagaan ini penghapusan akan mengosongkan subcon di unit-unit itu
     * tanpa suara — dan tidak ada yang tahu unit tersebut dulu dibangun siapa.
     */
    public function hapusSubcon(): void
    {
        $this->pastikanBoleh();

        $s = $this->subcon;
        $dipakai = $s->rumah()->count();

        if ($dipakai > 0) {
            $this->konfirmasiHapus = false;
            Flux::toast(
                variant: 'danger',
                text: 'Masih dipakai '.$dipakai.' unit. Lepas dulu dari unit-unit itu, atau non-aktifkan saja.',
            );

            return;
        }

        // Berkas ikut dibuang supaya tidak jadi sampah yang tak terlacak.
        foreach ($s->dokumen as $d) {
            if ($d->file_path) {
                Storage::disk('private')->delete($d->file_path);
            }
        }

        if ($s->foto_ktp_path) {
            Storage::disk('private')->delete($s->foto_ktp_path);
        }

        $nama = $s->nama;
        $s->delete();

        Flux::toast(variant: 'success', text: 'Subcon '.$nama.' dihapus.');

        $this->redirectRoute('master.subcon.index', navigate: true);
    }

    public function toggleAktif(): void
    {
        $this->pastikanBoleh();

        $s = $this->subcon;
        $s->update(['is_aktif' => ! $s->is_aktif, 'updated_by_user_id' => Auth::id()]);

        Flux::toast(variant: 'success', text: $s->is_aktif ? 'Subcon diaktifkan.' : 'Subcon dinonaktifkan.');
    }

    public function with(): array
    {
        $s = $this->subcon;
        $tarif = app(SubconTarifPph::class);
        $user = Auth::user();

        // Saran mengikuti apa yang sedang diketik di form, bukan yang tersimpan.
        $sementara = $this->j_jenis_jasa !== '' || $this->j_kualifikasi !== ''
            ? $this->subconSementara()
            : $s;

        return [
            'subcon' => $s,
            // Dihitung di sini, bukan di dalam view. Berkas ini memakai bentuk sebaris
            // dari direktif PHP Blade, dan mencampurnya dengan bentuk blok membuat Blade
            // memasangkan pembuka & penutup yang salah lalu menelan directive di antaranya.
            'ocrWarna' => match ($this->ocrJenis) {
                'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-300',
                'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-300',
                default => 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-300',
            },
            'saranPph' => $tarif->saran($sementara),
            'alasanSaran' => $tarif->alasan($sementara),
            'menyimpang' => $tarif->menyimpang($s),
            'bankList' => Bank::orderBy('nama')->get(['id', 'nama']),
            'dipakaiUnit' => $s->rumah()->count(),
            'bolehKelola' => (bool) ($user?->can('master.subcon.kelola') || $user?->can('master.kelola')),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-4">
            <flux:button size="sm" variant="ghost" icon="arrow-left"
                         :href="route('master.subcon.index')" wire:navigate>
                Kembali ke daftar
            </flux:button>
        </div>

        {{-- KOP --}}
        <div class="mb-6 text-center">
            <flux:heading size="xl">{{ $subcon->nama }}</flux:heading>
            @if ($subcon->badan_usaha)
                <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ $subcon->badan_usaha }}</div>
            @else
                <div class="text-sm text-zinc-500">Perorangan</div>
            @endif
            <div class="mt-2">
                <flux:badge :color="$subcon->is_aktif ? 'emerald' : 'zinc'">
                    {{ $subcon->is_aktif ? 'Aktif' : 'Non aktif' }}
                </flux:badge>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">

            {{-- PENANGGUNG JAWAB --}}
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="sm">Data Penanggung Jawab</flux:heading>
                </div>
                <div class="space-y-2 p-4 text-sm">
                    <div class="flex gap-3">
                        <span class="w-28 shrink-0 text-zinc-500">Nomor KTP</span>
                        <span class="font-mono">{{ $subcon->nik ?: '—' }}</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="w-28 shrink-0 text-zinc-500">Foto KTP</span>
                        <span>{{ $subcon->foto_ktp_path ? 'sudah diunggah' : '—' }}</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="w-28 shrink-0 text-zinc-500">Alamat</span>
                        <span>{{ $subcon->alamat ?: '—' }}</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="w-28 shrink-0 text-zinc-500">Telp/HP</span>
                        <span>{{ $subcon->telepon ?: '—' }}</span>
                    </div>
                </div>
                @if ($bolehKelola)
                    <div class="border-t border-zinc-200 px-4 py-2 text-right dark:border-zinc-700">
                        <flux:button size="xs" variant="filled" wire:click="openIdentitas">Edit</flux:button>
                    </div>
                @endif
            </div>

            {{-- JASA & PAJAK --}}
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="sm">Jenis Jasa &amp; Pajak</flux:heading>
                </div>
                <div class="space-y-2 p-4 text-sm">
                    <div class="flex gap-3">
                        <span class="w-28 shrink-0 text-zinc-500">Jenis Jasa</span>
                        <span>{{ $subcon->labelJenisJasa() ?? '—' }}</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="w-28 shrink-0 text-zinc-500">Kualifikasi</span>
                        <span>{{ $subcon->labelKualifikasi() ?? '—' }}</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="w-28 shrink-0 text-zinc-500">Tarif PPh</span>
                        <span class="font-mono">
                            {{ $subcon->pph_persen !== null ? number_format((float) $subcon->pph_persen, 2, ',', '.').'%' : '—' }}
                        </span>
                    </div>
                    @if ($menyimpang)
                        <div class="rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-300">
                            Berbeda dari saran sistem{{ $saranPph !== null ? ' ('.number_format($saranPph, 2, ',', '.').'%)' : '' }}.
                            @if ($subcon->pph_catatan)
                                <div class="mt-0.5">{{ $subcon->pph_catatan }}</div>
                            @endif
                        </div>
                    @endif
                    <div class="flex gap-3">
                        <span class="w-28 shrink-0 text-zinc-500">Tarif PPN</span>
                        <span class="font-mono">
                            {{ $subcon->ppn_persen > 0 ? number_format((float) $subcon->ppn_persen, 2, ',', '.').'%' : '—' }}
                        </span>
                    </div>
                </div>
                @if ($bolehKelola)
                    <div class="border-t border-zinc-200 px-4 py-2 text-right dark:border-zinc-700">
                        <flux:button size="xs" variant="filled" wire:click="openJasa">Edit</flux:button>
                    </div>
                @endif
            </div>
        </div>

        {{-- DOKUMEN --}}
        <div class="mt-4 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">Dokumen Legalitas</flux:heading>
                <flux:subheading>NPWP &amp; PKP tidak punya masa berlaku; sisanya dipantau tanggalnya.</flux:subheading>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                            <th class="w-24 px-4 py-2 font-semibold">Jenis</th>
                            <th class="px-4 py-2 font-semibold">Nomor</th>
                            <th class="w-40 px-4 py-2 font-semibold">Berlaku Sampai</th>
                            <th class="px-4 py-2 font-semibold">Berkas</th>
                            <th class="w-40 px-4 py-2 text-right font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach (\App\Models\Master\SubconDokumen::LABEL_JENIS as $jenis => $label)
                            @php($d = $subcon->dokumenJenis($jenis))
                            <tr wire:key="dok-{{ $jenis }}">
                                <td class="px-4 py-2 font-semibold">{{ $label }}</td>
                                <td class="px-4 py-2 font-mono text-xs">
                                    {{ $d?->nomor ?: '—' }}
                                </td>
                                <td class="px-4 py-2 text-xs">
                                    @if (! $d)
                                        <span class="text-zinc-400">—</span>
                                    @elseif (! $d->perluMasaBerlaku())
                                        <span class="text-zinc-400">tidak berlaku kadaluarsa</span>
                                    @elseif (! $d->berlaku_sampai)
                                        <span class="text-zinc-400">belum diisi</span>
                                    @else
                                        <span @class([
                                            'font-medium' => $d->kadaluarsa() || $d->segeraKadaluarsa(),
                                            'text-rose-600 dark:text-rose-400' => $d->kadaluarsa(),
                                            'text-amber-600 dark:text-amber-400' => $d->segeraKadaluarsa(),
                                        ])>
                                            {{ $d->berlaku_sampai->translatedFormat('d/m/Y') }}
                                        </span>
                                        @if ($d->kadaluarsa())
                                            <div class="text-[11px] text-rose-600 dark:text-rose-400">kadaluarsa</div>
                                        @elseif ($d->segeraKadaluarsa())
                                            <div class="text-[11px] text-amber-600 dark:text-amber-400">segera habis</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs">
                                    @if ($d && $d->file_path)
                                        @php($dokId = $d->id)
                                        <button type="button" wire:click="unduhDokumen({{ $dokId }})"
                                                class="text-blue-600 hover:underline dark:text-blue-400">
                                            {{ $d->file_original_name ?: 'Unduh' }}
                                        </button>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right">
                                    @if ($bolehKelola)
                                        @php($dokId = $d?->id)
                                        @if ($d && $d_hapusId === $dokId)
                                            <div class="flex justify-end gap-1">
                                                <flux:button size="xs" variant="danger" wire:click="hapusDokumen">Hapus</flux:button>
                                                <flux:button size="xs" variant="ghost" wire:click="batalHapusDokumen">Batal</flux:button>
                                            </div>
                                        @else
                                            <div class="flex justify-end gap-1">
                                                <flux:button size="xs" variant="filled"
                                                             wire:click="openDokumen('{{ $jenis }}')">
                                                    {{ $d ? 'Edit' : 'Isi' }}
                                                </flux:button>
                                                @if ($d)
                                                    <flux:button size="xs" variant="ghost" icon="trash"
                                                                 wire:click="konfirmasiHapusDokumen({{ $dokId }})" />
                                                @endif
                                            </div>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- REKENING --}}
        <div class="mt-4 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">Rekening Bank</flux:heading>
                @if ($bolehKelola)
                    <flux:button size="xs" variant="filled" icon="plus" wire:click="openRekening">Tambah</flux:button>
                @endif
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($subcon->rekening as $r)
                        @php($rekId = $r->id)
                        <tr wire:key="rek-{{ $rekId }}">
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ $r->namaBank() }}</div>
                                <div class="font-mono text-xs text-zinc-500">{{ $r->nomor_rekening }}</div>
                            </td>
                            <td class="px-4 py-2 text-sm">{{ $r->atas_nama }}</td>
                            <td class="px-4 py-2">
                                @if ($r->is_utama)
                                    <flux:badge color="emerald" size="sm">Utama</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if ($bolehKelola)
                                    @if ($r_hapusId === $rekId)
                                        <div class="flex justify-end gap-1">
                                            <flux:button size="xs" variant="danger" wire:click="hapusRekening">Hapus</flux:button>
                                            <flux:button size="xs" variant="ghost" wire:click="batalHapusRekening">Batal</flux:button>
                                        </div>
                                    @else
                                        <div class="flex justify-end gap-1">
                                            <flux:button size="xs" variant="filled" wire:click="openRekening({{ $rekId }})">Edit</flux:button>
                                            <flux:button size="xs" variant="ghost" icon="trash"
                                                         wire:click="konfirmasiHapusRekening({{ $rekId }})" />
                                        </div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-sm italic text-zinc-400">
                                Belum ada rekening.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- STATUS --}}
        @if ($bolehKelola)
            <div class="mt-4 flex items-center justify-between rounded-lg border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                <div>
                    <div class="text-sm font-semibold">
                        {{ $subcon->is_aktif ? 'Subcon Aktif' : 'Subcon Non Aktif' }}
                    </div>
                    <div class="text-xs text-zinc-500">
                        Yang non aktif tidak muncul saat memilih subcon di data rumah.
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <flux:button size="sm" :variant="$subcon->is_aktif ? 'filled' : 'primary'" wire:click="toggleAktif">
                        {{ $subcon->is_aktif ? 'Non aktifkan' : 'Aktifkan' }}
                    </flux:button>

                    @if ($konfirmasiHapus)
                        <flux:button size="sm" variant="danger" wire:click="hapusSubcon">Ya, hapus</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="batalHapusSubcon">Batal</flux:button>
                    @else
                        <flux:button size="sm" variant="ghost" icon="trash"
                                     wire:click="konfirmasiHapusSubcon">Hapus</flux:button>
                    @endif
                </div>
            </div>

            @if ($dipakaiUnit > 0)
                <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-300">
                    Dipakai {{ $dipakaiUnit }} unit, jadi tidak bisa dihapus &mdash; menghapusnya akan
                    mengosongkan subcon di unit-unit itu tanpa jejak. Non-aktifkan saja kalau sudah
                    tidak dipakai lagi.
                </div>
            @endif
        @endif
    </div>

    {{-- MODAL IDENTITAS --}}
    <flux:modal name="edit-identitas" @class(['max-w-lg'])>
        <div class="space-y-4">
            <flux:heading size="lg">Data Penanggung Jawab</flux:heading>

            <flux:input wire:model="i_nama" label="Nama" />
            @error('i_nama') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <flux:input wire:model="i_badan_usaha" label="Badan Usaha (opsional)"
                        placeholder="kosongkan kalau perorangan" />

            <flux:input wire:model="i_nik" label="Nomor KTP" />
            @error('i_nik') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <flux:textarea wire:model="i_alamat" rows="2" label="Alamat" />

            <flux:input wire:model="i_telepon" label="Telp/HP" />

            <div>
                <label class="mb-1 block text-sm font-medium">Foto KTP</label>
                <input type="file" wire:model="i_foto_ktp" accept=".jpg,.jpeg,.png,.webp,.pdf"
                       class="block w-full text-xs file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs dark:file:bg-zinc-700" />
                <p class="mt-1 text-[11px] text-zinc-500">
                    Foto KTP dibaca otomatis untuk mengisi nama, nomor KTP, dan alamat.
                    Kolom yang sudah terisi tidak ditimpa.
                </p>

                <div wire:loading wire:target="i_foto_ktp,jalankanOcr" class="mt-2 text-xs text-zinc-500">
                    Mengunggah dan membaca KTP&hellip;
                </div>

                @if ($ocrPesan)
                    <div class="mt-2 rounded-lg border px-3 py-2 text-xs {{ $ocrWarna }}">
                        {{ $ocrPesan }}
                    </div>
                @endif

                @error('i_foto_ktp') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpanIdentitas">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL JASA & PAJAK --}}
    <flux:modal name="edit-jasa" @class(['max-w-lg'])>
        <div class="space-y-4">
            <flux:heading size="lg">Jenis Jasa &amp; Pajak</flux:heading>

            <flux:select wire:model.live="j_jenis_jasa" label="Jenis Jasa">
                <option value="">- pilih jenis -</option>
                @foreach (\App\Models\Master\Subcon::LABEL_JENIS_JASA as $kunci => $label)
                    <option value="{{ $kunci }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="j_kualifikasi" label="Kualifikasi">
                <option value="">- pilih kualifikasi -</option>
                @foreach (\App\Models\Master\Subcon::LABEL_KUALIFIKASI as $kunci => $label)
                    <option value="{{ $kunci }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            @if ($saranPph !== null)
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800 dark:border-blue-900/40 dark:bg-blue-950/30 dark:text-blue-300">
                    <div class="font-semibold">
                        Saran tarif PPh: {{ number_format($saranPph, 2, ',', '.') }}%
                    </div>
                    <div class="mt-0.5">{{ $alasanSaran }}</div>
                    <flux:button size="xs" variant="filled" class="mt-2" wire:click="pakaiSaran">
                        Pakai saran ini
                    </flux:button>
                </div>
            @else
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                    Sistem belum bisa menyarankan tarif &mdash; lengkapi jenis jasa dan kualifikasi
                    dulu. Untuk Jasa dan Notaris, tarifnya memang diisi manual.
                </div>
            @endif

            <flux:input type="number" step="0.01" min="0" max="100" wire:model="j_pph" label="Tarif PPh (%)" />
            @error('j_pph') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <flux:textarea wire:model="j_pph_catatan" rows="2"
                           label="Alasan kalau berbeda dari saran"
                           placeholder="wajib diisi kalau tarifnya menyimpang" />
            @error('j_pph_catatan') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <flux:input type="number" step="0.01" min="0" max="100" wire:model="j_ppn"
                        label="Tarif PPN (%)" placeholder="isi hanya kalau PKP" />
            @error('j_ppn') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpanJasa">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL DOKUMEN --}}
    <flux:modal name="edit-dokumen" @class(['max-w-lg'])>
        @php($labelDok = \App\Models\Master\SubconDokumen::LABEL_JENIS[$d_jenis] ?? strtoupper($d_jenis))
        <div class="space-y-4">
            <flux:heading size="lg">Data {{ $labelDok }}</flux:heading>

            <flux:input wire:model="d_nomor" label="Nomor {{ $labelDok }}" />
            @error('d_nomor') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            @unless (in_array($d_jenis, \App\Models\Master\SubconDokumen::TANPA_MASA_BERLAKU, true))
                <flux:input type="date" wire:model="d_berlaku" label="Berlaku Sampai" />
                @error('d_berlaku') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            @endunless

            <div>
                <label class="mb-1 block text-sm font-medium">Berkas (opsional)</label>
                <input type="file" wire:model="d_file" accept=".pdf,.jpg,.jpeg,.png,.webp"
                       class="block w-full text-xs file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs dark:file:bg-zinc-700" />
                <div wire:loading wire:target="d_file" class="mt-1 text-xs text-zinc-500">Mengunggah&hellip;</div>
                @error('d_file') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpanDokumen">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL REKENING --}}
    <flux:modal name="edit-rekening" @class(['max-w-lg'])>
        <div class="space-y-4">
            <flux:heading size="lg">Rekening Bank</flux:heading>

            <flux:select wire:model="r_bank_id" label="Bank">
                <option value="">- pilih dari master -</option>
                @foreach ($bankList as $b)
                    <option value="{{ $b->id }}">{{ $b->nama }}</option>
                @endforeach
            </flux:select>

            <flux:input wire:model="r_bank_nama" label="Atau ketik nama bank"
                        placeholder="kalau banknya belum ada di master" />
            @error('r_bank_nama') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <flux:input wire:model="r_nomor" label="Nomor Rekening" />
            @error('r_nomor') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <flux:input wire:model="r_atas_nama" label="Atas Nama" />
            @error('r_atas_nama') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="r_utama"
                       class="rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500 dark:border-zinc-600" />
                Jadikan rekening utama
            </label>

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpanRekening">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
