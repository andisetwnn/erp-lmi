<?php

use App\Models\Master\Proyek;
use App\Models\Master\SertifikatSerahTerima;
use App\Services\SertifikatService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Serah Terima Bank — mencatat penyerahan fisik sertifikat beserta BAST-nya.
 *
 * Satu penyerahan memuat banyak sertifikat tapi hanya satu dokumen. Yang belum
 * terbit atau sudah tercatat di bank ditolak seluruhnya, bukan dilewati
 * diam-diam — operator perlu tahu bahwa yang ia centang tidak semuanya ikut.
 */
new #[Title('Serah Terima Bank')] class extends Component
{
    use WithFileUploads;

    #[Url(as: 'proyek')]
    public ?int $proyekId = null;

    public string $tanggal = '';

    public string $cari = '';

    /** @var array<int, bool> */
    public array $pilih = [];

    public $berkas = null;

    public ?int $hapusId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('legal.serahterima.kelola'), 403);

        $this->proyekId = \App\Support\ProyekAktif::sinkron($this->proyekId);

        $this->tanggal = now()->toDateString();
    }

    #[On('active-proyek-changed')]
    public function syncProyek(int $proyekId): void
    {
        $this->proyekId = $proyekId;
        $this->reset(['pilih']);
    }

    protected function svc(): SertifikatService
    {
        return app(SertifikatService::class);
    }

    public function simpan(): void
    {
        abort_unless(Auth::user()?->can('legal.serahterima.kelola'), 403);

        $this->validate([
            'tanggal' => ['required', 'date'],
            'berkas' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ], [], ['tanggal' => 'tanggal serah terima', 'berkas' => 'dokumen serah terima']);

        $proyek = $this->proyekId ? Proyek::find($this->proyekId) : null;

        if (! $proyek) {
            Flux::toast(variant: 'warning', text: 'Pilih proyek dulu di sidebar.');

            return;
        }

        $path = $nama = null;

        if ($this->berkas) {
            $path = $this->berkas->store('bast-sertifikat/'.$proyek->id, 'private');
            $nama = $this->berkas->getClientOriginalName();
        }

        try {
            $serah = $this->svc()->serahkanKeBank(
                $proyek,
                $this->tanggal,
                array_map('intval', array_keys(array_filter($this->pilih))),
                $path, $nama, '', Auth::id(),
            );
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: $e->validator->errors()->first());

            return;
        }

        $this->reset(['pilih', 'berkas']);

        Flux::toast(variant: 'success', text: $serah->sertifikat()->count().' sertifikat tercatat diserahkan ke bank.');
    }

    public function konfirmasiHapus(int $id): void
    {
        abort_unless(Auth::user()?->can('legal.serahterima.kelola'), 403);

        $this->hapusId = $id;
        Flux::modal('hapus-serah')->show();
    }

    public function hapus(): void
    {
        abort_unless(Auth::user()?->can('legal.serahterima.kelola'), 403);

        if (! $this->hapusId) {
            return;
        }

        $this->svc()->batalkanSerahTerima(SertifikatSerahTerima::findOrFail($this->hapusId));

        Flux::modal('hapus-serah')->close();
        $this->hapusId = null;

        Flux::toast(variant: 'success', text: 'Penyerahan dibatalkan — sertifikatnya kembali berstatus Terbit.');
    }

    public function unduhBast(int $id): mixed
    {
        abort_unless(Auth::user()?->canAny(['legal.lihat', 'legal.serahterima.kelola']), 403);

        $t = SertifikatSerahTerima::findOrFail($id);

        abort_unless($t->file_path && Storage::disk('private')->exists($t->file_path), 404);

        return Storage::disk('private')->download($t->file_path, $t->file_original_name ?? 'bast.pdf');
    }

    public function with(): array
    {
        return [
            'siap' => $this->proyekId ? $this->svc()->siapDiserahkan($this->proyekId, $this->cari)->get() : collect(),
            'riwayat' => $this->proyekId ? $this->riwayat() : collect(),
        ];
    }

    protected function riwayat()
    {
        return SertifikatSerahTerima::query()
            ->where('proyek_id', $this->proyekId)
            ->with('sertifikat.rumah:id,blok,nomor_unit')
            ->withCount('sertifikat')
            ->orderByDesc('tanggal')->orderByDesc('id')
            ->limit(50)
            ->get();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-5 flex items-start gap-3">
            <a href="{{ route('perizinan.sertifikat.index') }}" wire:navigate
               class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
               title="{{ __('Kembali ke Data Sertifikat') }}">
                <flux:icon.arrow-left class="size-4" />
            </a>
            <div>
                <flux:heading size="xl">{{ __('Serah Terima Bank') }}</flux:heading>
                <flux:subheading>{{ __('Catat penyerahan fisik sertifikat beserta BAST-nya.') }}</flux:subheading>
            </div>
        </div>

        @if (! $proyekId)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                {{ __('Pilih proyek dulu di picker "Proyek Aktif" pada sidebar kiri.') }}
            </div>
        @else
            <div class="mb-6 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="grid grid-cols-1 gap-4 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-2">
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('Tanggal sertifikat ke bank') }}</label>
                            <flux:input type="date" wire:model="tanggal" />
                        </div>
                        <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass"
                                    :label="__('Cari sertifikat')" :placeholder="__('blok, nomor sertifikat, atau NIB')" />
                    </div>
                    <flux:field>
                        <flux:label>{{ __('Lampiran dokumen serah terima') }}</flux:label>
                        <input type="file" wire:model="berkas" accept=".pdf,.jpg,.jpeg,.png"
                               class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />

                        {{-- Lihat alasannya di layar Input Data Sertifikat: unggahan
                             yang belum selesai saat Simpan ditekan hilang diam-diam. --}}
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
                        @else
                            <flux:description>{{ __('PDF/JPG/PNG, maksimal 5 MB.') }}</flux:description>
                        @endif

                        <flux:error name="berkas" />
                    </flux:field>
                </div>

                <div class="max-h-96 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                <th class="w-10 px-3 py-2"></th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Unit') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('No. Sertifikat') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('NIB') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($siap as $s)
                                <tr wire:key="sp-{{ $s->id }}">
                                    <td class="px-3 py-2">
                                        <input type="checkbox" wire:model.live="pilih.{{ $s->id }}"
                                               class="size-4 rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500 dark:border-zinc-600" />
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold">{{ $s->rumah?->kode_unit }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $s->nomor_sertifikat }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-zinc-500">{{ $s->nib ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-12 text-center text-sm italic text-zinc-400">
                                        {{ __('Belum ada sertifikat terbit yang menunggu diserahkan.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('pilih', [])">{{ __('Reset') }}</flux:button>
                    <flux:button size="sm" variant="primary" icon="check" wire:click="simpan"
                                 wire:loading.attr="disabled" wire:target="berkas">
                        {{ __('Simpan') }} ({{ count(array_filter($pilih)) }})
                    </flux:button>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <h2 class="text-sm font-bold">{{ __('Data Serah Terima Sertifikat ke Bank') }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Tgl ke Bank') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Dokumen') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Jumlah Unit') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Blok / Unit') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Aksi') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($riwayat as $t)
                                <tr wire:key="rw-{{ $t->id }}">
                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $t->tanggal?->format('d/m/Y') }}</td>
                                    <td class="px-3 py-2 text-xs">
                                        @if ($t->file_path)
                                            <button type="button" wire:click="unduhBast({{ $t->id }})"
                                                    class="text-blue-600 hover:underline dark:text-blue-400">
                                                {{ $t->file_original_name ?? 'BAST.pdf' }}
                                            </button>
                                        @else
                                            <span class="text-zinc-400">{{ __('tanpa lampiran') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $t->sertifikat_count }}</td>
                                    <td class="px-3 py-2 text-xs text-zinc-600 dark:text-zinc-400">
                                        {{ $t->sertifikat->map(fn ($s) => $s->rumah?->kode_unit.($s->nib ? ' NIB: '.$s->nib : ''))->implode(', ') }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="konfirmasiHapus({{ $t->id }})" />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-12 text-center text-sm italic text-zinc-400">
                                        {{ __('Belum ada penyerahan tercatat.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <flux:modal name="hapus-serah" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Batalkan penyerahan?') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Sertifikatnya kembali berstatus Terbit dan bisa diserahkan ulang. Lampiran BAST-nya ikut terlepas.') }}
                        </flux:subheading>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="button" icon="trash" wire:click="hapus">
                        {{ __('Batalkan penyerahan') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

    </div>
</section>
