<?php

use App\Models\Master\Sertifikat;
use App\Support\BusinessActivityLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Input Data Sertifikat — mencatat sertifikat yang sudah terbit.
 *
 * Diisi per unit karena nomornya berbeda-beda. Surat Ukur sengaja tidak ada:
 * sertifikat sudah elektronik, yang dicatat cukup nomor, NIB, dan HGB induk.
 */
new #[Title('Input Data Sertifikat')] class extends Component
{
    use WithFileUploads;

    #[Url(as: 'proyek')]
    public ?int $proyekId = null;

    public string $cari = '';

    public ?int $editId = null;

    public string $nomorSertifikat = '';

    public string $nib = '';

    public string $hgbInduk = '';

    public string $tanggalTerbit = '';

    public string $luasSertifikat = '';

    public $berkas = null;

    public ?string $namaBerkasLama = null;

    public ?string $labelUnit = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('legal.sertifikat.kelola'), 403);

        $this->proyekId = \App\Support\ProyekAktif::sinkron($this->proyekId);
    }

    #[On('active-proyek-changed')]
    public function syncProyek(int $proyekId): void
    {
        $this->proyekId = $proyekId;
    }

    public function openIsi(int $id): void
    {
        abort_unless(Auth::user()?->can('legal.sertifikat.kelola'), 403);

        $s = Sertifikat::with('rumah:id,blok,nomor_unit')->findOrFail($id);

        $this->editId = $s->id;
        $this->labelUnit = $s->rumah?->kode_unit;
        $this->nomorSertifikat = (string) $s->nomor_sertifikat;
        $this->nib = (string) $s->nib;
        $this->hgbInduk = (string) $s->hgb_induk;
        $this->tanggalTerbit = $s->tanggal_terbit?->toDateString() ?? '';
        $this->luasSertifikat = $s->luas_sertifikat !== null ? (string) (float) $s->luas_sertifikat : '';
        $this->namaBerkasLama = $s->file_original_name;
        $this->berkas = null;

        $this->resetErrorBag();
        Flux::modal('isi-sertifikat')->show();
    }

    public function simpan(): void
    {
        abort_unless(Auth::user()?->can('legal.sertifikat.kelola'), 403);

        $data = $this->validate([
            'nomorSertifikat' => ['required', 'string', 'max:60'],
            'nib' => ['nullable', 'string', 'max:40'],
            'hgbInduk' => ['nullable', 'string', 'max:60'],
            'tanggalTerbit' => ['nullable', 'date'],
            'luasSertifikat' => ['nullable', 'numeric', 'min:0'],
            'berkas' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ], [], [
            'nomorSertifikat' => 'nomor sertifikat',
            'hgbInduk' => 'HGB induk',
            'luasSertifikat' => 'luas sertifikat',
            'berkas' => 'berkas sertifikat',
        ]);

        $s = Sertifikat::with('rumah:id,blok,nomor_unit')->findOrFail($this->editId);

        $pertamaKali = ! $s->sudahTerbit();
        $sebelum = $this->cuplikan($s);

        $isi = [
            'nomor_sertifikat' => $data['nomorSertifikat'],
            'nib' => $data['nib'] ?: null,
            'hgb_induk' => $data['hgbInduk'] ?: null,
            'tanggal_terbit' => $data['tanggalTerbit'] ?: null,
            'luas_sertifikat' => $data['luasSertifikat'] !== '' ? (float) $data['luasSertifikat'] : null,
            'updated_by_user_id' => Auth::id(),
        ];

        if ($this->berkas) {
            $isi['file_path'] = $this->berkas->store('sertifikat/'.$s->rumah_id, 'private');
            $isi['file_original_name'] = $this->berkas->getClientOriginalName();
        }

        $s->update($isi);

        // Pengisian pertama dicatat sebagai terbitnya sertifikat; sesudah itu
        // yang dicatat cuma selisihnya, supaya riwayatnya terbaca sebagai
        // "apa yang dikoreksi" dan bukan daftar isian yang diulang-ulang.
        if ($pertamaKali) {
            BusinessActivityLogger::sertifikatTerbit($s);
        } else {
            BusinessActivityLogger::sertifikatDiperbarui($s, $this->selisih($sebelum, $this->cuplikan($s->fresh())));
        }

        Flux::modal('isi-sertifikat')->close();
        $this->reset(['editId', 'nomorSertifikat', 'nib', 'hgbInduk', 'tanggalTerbit', 'luasSertifikat', 'berkas', 'namaBerkasLama', 'labelUnit']);

        Flux::toast(variant: 'success', text: 'Data sertifikat tersimpan.');
    }

    /**
     * Isian sebagaimana terlihat pengguna, dinormalkan jadi teks.
     *
     * Dibandingkan sebagai teks tampilan, bukan nilai mentah: tanggal dan
     * desimal berubah bentuk saat melewati basis data, dan perbandingan mentah
     * akan melaporkan perubahan yang sebetulnya tidak ada.
     *
     * @return array<string, string>
     */
    protected function cuplikan(Sertifikat $s): array
    {
        return [
            'No. Sertifikat' => (string) $s->nomor_sertifikat,
            'No. NIB' => (string) $s->nib,
            'No. HGB Induk' => (string) $s->hgb_induk,
            'Tanggal terbit' => $s->tanggal_terbit?->format('d/m/Y') ?? '',
            'Luas sertifikat' => $s->luas_sertifikat !== null
                ? rtrim(rtrim(number_format((float) $s->luas_sertifikat, 2, ',', '.'), '0'), ',')
                : '',
            'Berkas sertifikat' => (string) $s->file_original_name,
        ];
    }

    /**
     * @param  array<string, string>  $sebelum
     * @param  array<string, string>  $sesudah
     * @return array<string, array{0: string, 1: string}>
     */
    protected function selisih(array $sebelum, array $sesudah): array
    {
        $beda = [];

        foreach ($sesudah as $label => $baru) {
            $lama = $sebelum[$label] ?? '';

            if ($lama !== $baru) {
                $beda[$label] = [$lama !== '' ? $lama : '—', $baru !== '' ? $baru : '—'];
            }
        }

        return $beda;
    }

    public function with(): array
    {
        return ['daftar' => $this->proyekId ? $this->belumTerbit() : collect()];
    }

    protected function belumTerbit()
    {
        return Sertifikat::query()
            ->whereNull('nomor_sertifikat')
            ->whereHas('rumah', fn ($q) => $q->where('proyek_id', $this->proyekId))
            ->when($this->cari !== '', fn ($q) => $q->whereHas('rumah', fn ($r) => $r->where('blok', 'like', '%'.$this->cari.'%')))
            ->with(['rumah:id,blok,nomor_unit,proyek_id', 'pengajuan:id,nomor,tanggal_pengajuan'])
            ->get()
            ->sortBy(fn (Sertifikat $s) => sprintf('%s-%04d', $s->rumah?->blok, (int) $s->rumah?->nomor_unit))
            ->values();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-5 flex items-start gap-3">
            <a href="{{ route('perizinan.sertifikat.index') }}" wire:navigate
               class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
               title="{{ __('Kembali ke Data Sertifikat') }}">
                <flux:icon.arrow-left class="size-4" />
            </a>
            <div>
                <flux:heading size="xl">{{ __('Input Data Sertifikat') }}</flux:heading>
                <flux:subheading>{{ __('Unit yang sudah diajukan dan menunggu nomor sertifikatnya.') }}</flux:subheading>
            </div>
        </div>

        @if (! $proyekId)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                {{ __('Pilih proyek dulu di picker "Proyek Aktif" pada sidebar kiri.') }}
            </div>
        @else
            <div class="mb-4">
                <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" :placeholder="__('Cari blok...')" />
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">{{ __('Unit') }}</th>
                            <th class="px-3 py-2 text-left font-semibold">{{ __('Pengajuan') }}</th>
                            <th class="px-3 py-2 text-right font-semibold">{{ __('Luas diajukan') }}</th>
                            <th class="px-3 py-2 text-right font-semibold">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($daftar as $s)
                            <tr wire:key="b-{{ $s->id }}">
                                <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold">
                                    {{ $s->rumah?->kode_unit }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs text-zinc-500">
                                    {{ $s->pengajuan?->nomor }}
                                    <div class="text-zinc-400">{{ $s->pengajuan?->tanggal_pengajuan?->format('d/m/Y') }}</div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">
                                    {{ $s->luas_diajukan !== null ? number_format((float) $s->luas_diajukan, 2, ',', '.') : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <flux:button size="xs" variant="primary" wire:click="openIsi({{ $s->id }})">
                                        {{ __('Isi data') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-3 py-12 text-center text-sm italic text-zinc-400">
                                    {{ __('Tidak ada pengajuan yang menunggu data sertifikat.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        <flux:modal name="isi-sertifikat" class="md:w-xl" focusable>
            <form wire:submit="simpan" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Data Sertifikat') }} {{ $labelUnit ? '— '.$labelUnit : '' }}</flux:heading>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('No. Sertifikat') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input wire:model="nomorSertifikat" required autofocus placeholder="SHGB 01234" />
                        <flux:error name="nomorSertifikat" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('No. NIB') }}</flux:label>
                        <flux:input wire:model="nib" placeholder="64885" />
                        <flux:error name="nib" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('No. HGB Induk') }}</flux:label>
                        <flux:input wire:model="hgbInduk" placeholder="HGB 9/Sukamaju" />
                        <flux:error name="hgbInduk" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Tanggal terbit') }}</flux:label>
                        <flux:input type="date" wire:model="tanggalTerbit" />
                        <flux:error name="tanggalTerbit" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Luas sertifikat (m²)') }}</flux:label>
                        <flux:input wire:model="luasSertifikat" inputmode="decimal" placeholder="72.40" />
                        <flux:description class="text-[10px]">
                            {{ __('Isi sesuai hasil ukur resmi, meski berbeda dari yang diajukan.') }}
                        </flux:description>
                        <flux:error name="luasSertifikat" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Unggah sertifikat') }}</flux:label>
                    <input type="file" wire:model="berkas" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />
                    <flux:description>{{ __('PDF/JPG/PNG, maksimal 5 MB.') }}</flux:description>

                    {{-- Unggahan berjalan di belakang layar begitu berkas dipilih,
                         dan belum tentu selesai saat Simpan ditekan. Tanpa penanda
                         ini berkasnya hilang tanpa pesan galat apa pun: isian lain
                         tetap tersimpan, hanya lampirannya yang tidak ikut. --}}
                    <div wire:loading wire:target="berkas"
                         class="mt-1.5 flex items-center gap-1.5 text-[11px] font-medium text-amber-600 dark:text-amber-400">
                        <flux:icon.arrow-path class="size-3 animate-spin" />
                        {{ __('Mengunggah — tunggu sampai selesai sebelum menyimpan.') }}
                    </div>

                    @if ($berkas)
                        <div wire:loading.remove wire:target="berkas"
                             class="mt-1.5 flex items-center gap-1.5 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                            <flux:icon.check-circle class="size-3 shrink-0" />
                            <span class="truncate">{{ $berkas->getClientOriginalName() }}</span>
                            <span class="shrink-0 text-zinc-500">— {{ __('siap disimpan') }}</span>
                        </div>
                    @elseif ($namaBerkasLama)
                        <div class="mt-1.5 text-[11px] text-zinc-600 dark:text-zinc-400">
                            <flux:icon.paper-clip class="inline size-3" /> {{ $namaBerkasLama }} — {{ __('unggahan baru akan menggantikannya') }}
                        </div>
                    @endif

                    <flux:error name="berkas" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit"
                                 wire:loading.attr="disabled" wire:target="berkas">
                        {{ __('Simpan') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

    </div>
</section>
