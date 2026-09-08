<?php

use App\Models\Master\Subcon;
use App\Services\SubconTarifPph;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Subcon')] class extends Component
{
    use WithPagination;

    #[Url(as: 'tab')]
    public string $tab = 'aktif';

    #[Url(as: 'q')]
    public string $cari = '';

    #[Url(as: 'per')]
    public string $perPage = '25';

    public function updated($property): void
    {
        if (in_array($property, ['tab', 'cari', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    private function bolehKelola(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->can('master.subcon.kelola') || $user?->can('master.kelola'));
    }

    public function with(): array
    {
        $q = Subcon::query()
            ->with(['dokumen', 'rekeningUtama'])
            ->when($this->cari !== '', function ($x) {
                $s = "%{$this->cari}%";
                $x->where(function ($w) use ($s) {
                    $w->where('nama', 'like', $s)
                        ->orWhere('badan_usaha', 'like', $s)
                        ->orWhere('telepon', 'like', $s)
                        ->orWhereHas('dokumen', fn ($d) => $d->where('nomor', 'like', $s));
                });
            });

        match ($this->tab) {
            'aktif' => $q->where('is_aktif', true),
            'nonaktif' => $q->where('is_aktif', false),
            'perorangan' => $q->whereNull('badan_usaha')->orWhere('badan_usaha', ''),
            'badan' => $q->whereNotNull('badan_usaha')->where('badan_usaha', '!=', ''),
            default => null,
        };

        return [
            'daftar' => $q->orderBy('nama')->paginate((int) $this->perPage),
            'jumlahPerTab' => $this->jumlahPerTab(),
            'tarif' => app(SubconTarifPph::class),
            'bolehKelola' => $this->bolehKelola(),
        ];
    }

    /** @return array<string, int> */
    private function jumlahPerTab(): array
    {
        return [
            'aktif' => Subcon::where('is_aktif', true)->count(),
            'perorangan' => Subcon::whereNull('badan_usaha')->orWhere('badan_usaha', '')->count(),
            'badan' => Subcon::whereNotNull('badan_usaha')->where('badan_usaha', '!=', '')->count(),
            'nonaktif' => Subcon::where('is_aktif', false)->count(),
            'all' => Subcon::count(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-slate-500 to-slate-700 text-white shadow-sm">
                    <flux:icon.wrench class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ __('Master Subcon') }}</flux:heading>
                        <x-info-button title="Master Subcon">
                            <p>Daftar subkontraktor pembangun unit. Yang dicatat <strong>orangnya</strong>; badan usaha menyusul kalau punya &mdash; sebagian subcon memang perorangan.</p>
                            <p class="mt-2">Tiap subcon bisa punya enam dokumen: NPWP, SIUP, SBU, SIUJK, PKP, dan SKA. Masing-masing dengan nomor, masa berlaku, dan berkasnya.</p>
                            <p class="mt-2"><strong>Tarif PPh disarankan sistem</strong> dari jenis jasa, kualifikasi, dan ada-tidaknya sertifikat yang masih berlaku &mdash; tapi tetap bisa diubah kalau kasusnya khusus.</p>
                            <p class="mt-2 text-xs text-zinc-500">SBU atau SKA yang lewat masa berlakunya dianggap tidak ada, sehingga tarif PPh yang disarankan ikut berubah.</p>
                        </x-info-button>
                    </div>
                    <flux:subheading>{{ __('Subkontraktor, legalitas, dan tarif pajaknya.') }}</flux:subheading>
                </div>
            </div>
            @if ($bolehKelola)
                <flux:button variant="primary" icon="plus"
                             :href="route('master.subcon.create')" wire:navigate>
                    {{ __('Tambah Subcon') }}
                </flux:button>
            @endif
        </div>

        {{-- TAB --}}
        @php
            $tabs = [
                'aktif' => 'Aktif',
                'perorangan' => 'Perorangan',
                'badan' => 'Badan Usaha',
                'nonaktif' => 'Non Aktif',
                'all' => 'Semua',
            ];
        @endphp
        <div class="mb-4 border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-1 overflow-x-auto">
                @foreach ($tabs as $kunci => $label)
                    <button type="button" wire:click="$set('tab', '{{ $kunci }}')"
                            @class([
                                'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition',
                                'border-slate-700 text-slate-800 dark:border-slate-300 dark:text-slate-200' => $tab === $kunci,
                                'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400' => $tab !== $kunci,
                            ])>
                        {{ $label }}
                        <span class="ml-1 rounded-full bg-zinc-100 px-1.5 text-xs dark:bg-zinc-700">
                            {{ $jumlahPerTab[$kunci] ?? 0 }}
                        </span>
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="text-sm text-zinc-600 dark:text-zinc-400">Tampilkan</span>
                <flux:select wire:model.live="perPage" class="w-28">
                    <option value="25">25 baris</option>
                    <option value="50">50 baris</option>
                    <option value="100">100 baris</option>
                </flux:select>
            </div>
            <div class="min-w-64 max-w-sm flex-1">
                <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass"
                            placeholder="Cari nama / telp / dokumen" />
            </div>
        </div>

        {{-- TABEL --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                            <th class="w-10 px-4 py-3 font-semibold">#</th>
                            <th class="px-4 py-3 font-semibold">Nama</th>
                            <th class="px-4 py-3 font-semibold">Jasa / Kualifikasi</th>
                            <th class="px-4 py-3 font-semibold">Telp/HP</th>
                            <th class="px-4 py-3 font-semibold">SIUP</th>
                            <th class="px-4 py-3 font-semibold">SBU</th>
                            <th class="px-4 py-3 font-semibold">PKP</th>
                            <th class="px-4 py-3 font-semibold">SKA</th>
                            <th class="px-4 py-3 text-right font-semibold">PPh</th>
                            <th class="px-4 py-3 text-right font-semibold">PPN</th>
                            <th class="px-4 py-3 text-center font-semibold">Rek Bank</th>
                            <th class="px-4 py-3 text-right font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($daftar as $i => $s)
                            @php
                                $kadaluarsa = $s->dokumenKadaluarsa();
                                $menyimpang = $tarif->menyimpang($s);
                            @endphp
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="px-4 py-2.5 text-xs text-zinc-400">{{ $daftar->firstItem() + $i }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-blue-600 dark:text-blue-400">{{ $s->nama }}</div>
                                    @if ($s->badan_usaha)
                                        <div class="text-xs italic text-zinc-500">{{ $s->badan_usaha }}</div>
                                    @endif
                                    @unless ($s->is_aktif)
                                        <flux:badge color="zinc" size="sm">Non aktif</flux:badge>
                                    @endunless
                                </td>
                                <td class="px-4 py-2.5 text-xs">
                                    {{ $s->labelJenisJasa() ?? '—' }}
                                    @if ($s->kualifikasi)
                                        <div class="text-zinc-500">{{ $s->labelKualifikasi() }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-xs">{{ $s->telepon ?: '—' }}</td>
                                @foreach (['siup', 'sbu', 'pkp', 'ska'] as $jenisKolom)
                                    @php($dok = $s->dokumenJenis($jenisKolom))
                                    <td class="px-4 py-2.5 text-xs">
                                        @if (! $dok)
                                            <span class="text-zinc-400">-</span>
                                        @else
                                            <div class="font-mono">{{ $dok->nomor }}</div>
                                            @if ($dok->berlaku_sampai)
                                                {{-- Merah kalau lewat tanggal, seperti penanda di sistem lama --}}
                                                <div @class([
                                                    'text-[11px]',
                                                    'font-semibold text-rose-600 dark:text-rose-400' => $dok->kadaluarsa(),
                                                    'text-amber-600 dark:text-amber-400' => $dok->segeraKadaluarsa(),
                                                    'text-zinc-400' => ! $dok->kadaluarsa() && ! $dok->segeraKadaluarsa(),
                                                ])>
                                                    {{ $dok->berlaku_sampai->translatedFormat('d/m/Y') }}
                                                </div>
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-4 py-2.5 text-right font-mono tabular-nums text-xs">
                                    {{ $s->pph_persen !== null ? rtrim(rtrim(number_format((float) $s->pph_persen, 2, ',', '.'), '0'), ',').'%' : '—' }}
                                    @if ($menyimpang)
                                        {{-- Tarif diketik berbeda dari saran sistem --}}
                                        <div class="text-[11px] text-amber-600 dark:text-amber-400">di luar saran</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right font-mono tabular-nums text-xs">
                                    {{ $s->ppn_persen > 0 ? rtrim(rtrim(number_format((float) $s->ppn_persen, 2, ',', '.'), '0'), ',').'%' : '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    @php($idSubcon = $s->id)
                                    @if ($s->rekeningUtama)
                                        <flux:button size="xs" variant="filled" icon="eye"
                                                     :href="route('master.subcon.show', $idSubcon)" wire:navigate>
                                            Lihat
                                        </flux:button>
                                    @else
                                        <flux:button size="xs" variant="ghost" icon="plus"
                                                     :href="route('master.subcon.show', $idSubcon)" wire:navigate>
                                            Tambah
                                        </flux:button>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <flux:button size="xs" variant="filled"
                                                 :href="route('master.subcon.show', $s->id)" wire:navigate>
                                        Detail
                                    </flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="px-4 py-12 text-center text-zinc-400">
                                    Belum ada subcon di saringan ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($daftar->hasPages())
                <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    {{ $daftar->links() }}
                </div>
            @endif
        </div>
    </div>

</section>
