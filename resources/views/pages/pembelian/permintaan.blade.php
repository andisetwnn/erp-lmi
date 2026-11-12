<?php

use App\Models\Master\PermintaanPembelian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Permintaan Pembelian')] class extends Component
{
    use WithPagination;

    #[Url(as: 'tab')]
    public string $tab = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        // Tab pertama yang dibuka = antrean pekerjaan orang itu sendiri.
        if ($this->tab === '') {
            $user = Auth::user();
            $this->tab = match (true) {
                $user->can('pembelian.review') => 'diajukan',
                $user->can('pembelian.anggaran') => 'ditinjau',
                $user->can('pembelian.po') => 'disetujui',
                default => 'semua',
            };
        }
    }

    public function updatingTab(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /** Peminta biasa hanya melihat PR buatannya; FAT, atasan, dan pemegang izin lihat melihat semua. */
    private function lingkup(): Builder
    {
        $user = Auth::user();
        $lihatSemua = $user->canAny(['pembelian.review', 'pembelian.anggaran', 'pembelian.po', 'pembelian.lihat']);

        return PermintaanPembelian::query()
            ->when(! $lihatSemua, fn ($q) => $q->where('created_by_user_id', $user->id))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($qq) => $qq->where('nomor', 'like', $term)->orWhere('keperluan', 'like', $term));
            });
    }

    public function with(): array
    {
        $jumlahPerStatus = $this->lingkup()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        $daftar = $this->lingkup()
            ->when($this->tab !== 'semua', fn ($q) => $q->where('status', $this->tab))
            ->with([
                'proyek:id,nama_proyek',
                'createdBy:id,name',
                'pesanan' => fn ($q) => $q->where('status', '!=', 'batal')->select('id', 'nomor', 'permintaan_pembelian_id'),
            ])
            ->addSelect(['total_perkiraan' => DB::table('permintaan_pembelian_item')
                ->selectRaw('coalesce(sum(jumlah * harga_perkiraan), 0)')
                ->whereColumn('permintaan_pembelian_id', 'permintaan_pembelian.id')])
            ->orderByDesc('tanggal')->orderByDesc('id')
            ->paginate(20);

        return [
            'daftar' => $daftar,
            'jumlahPerStatus' => $jumlahPerStatus,
            'jumlahSemua' => $jumlahPerStatus->sum(),
            'bolehMinta' => Auth::user()->can('pembelian.minta'),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-orange-500 to-orange-700 text-white shadow-sm">
                    <flux:icon.clipboard-document-list class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ __('Permintaan Pembelian') }}</flux:heading>
                        <x-info-button title="Permintaan Pembelian (PR)">
                            <p>Alur pembelian barang:</p>
                            <ol class="ml-4 mt-1 list-decimal space-y-1">
                                <li><strong>Draft</strong> — disusun peminta di proyek atau kantor pusat</li>
                                <li><strong>Menunggu review FAT</strong> — Keuangan memeriksa barang dan harga</li>
                                <li><strong>Menunggu persetujuan direktur</strong> — hanya PR di atas Rp {{ number_format(app(\App\Services\PembelianService::class)->batasPersetujuanDirektur(), 0, ',', '.') }}; sampai nilai itu cukup disetujui FAT</li>
                                <li><strong>Disetujui</strong> — Keuangan menerbitkan PO, membayar supplier, lalu barang dikirim</li>
                            </ol>
                            <p class="mt-2">PR yang dikembalikan FAT kembali ke Draft beserta alasannya, untuk diperbaiki lalu diajukan lagi.</p>
                        </x-info-button>
                    </div>
                    <flux:subheading>{{ __('PR dari proyek dan kantor pusat, sampai disetujui untuk dibuatkan PO.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-tombol-panduan modul="pembelian" />
                @if ($bolehMinta)
                    <flux:button variant="primary" icon="plus" :href="route('pembelian.permintaan.create')" wire:navigate>
                        {{ __('Buat PR') }}
                    </flux:button>
                @endif
            </div>
        </div>

        @php
            $tabs = ['semua' => 'Semua'] + \App\Models\Master\PermintaanPembelian::LABEL_STATUS;
        @endphp
        <div class="mb-4 border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-1 overflow-x-auto">
                @foreach ($tabs as $kunci => $label)
                    <button type="button" wire:click="$set('tab', '{{ $kunci }}')"
                            @class([
                                'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition',
                                'border-orange-600 text-orange-700 dark:border-orange-400 dark:text-orange-300' => $tab === $kunci,
                                'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400' => $tab !== $kunci,
                            ])>
                        {{ $label }}
                        <span class="ml-1 rounded-full bg-zinc-100 px-1.5 text-xs dark:bg-zinc-700">
                            {{ $kunci === 'semua' ? $jumlahSemua : ($jumlahPerStatus[$kunci] ?? 0) }}
                        </span>
                    </button>
                @endforeach
            </nav>
        </div>

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Cari nomor PR atau keperluan...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                            <th class="px-4 py-3">{{ __('Nomor') }}</th>
                            <th class="px-4 py-3">{{ __('Tanggal') }}</th>
                            <th class="px-4 py-3">{{ __('Untuk') }}</th>
                            <th class="px-4 py-3">{{ __('Keperluan') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Perkiraan') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3">{{ __('No PO') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($daftar as $pr)
                            <tr wire:key="pr-{{ $pr->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <a href="{{ route('pembelian.permintaan.show', $pr->id) }}" wire:navigate class="font-mono font-semibold text-orange-700 hover:underline dark:text-orange-300">{{ $pr->nomor }}</a>
                                    <div class="text-xs text-zinc-500">{{ $pr->createdBy?->name }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $pr->tanggal?->translatedFormat('d M Y') }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div>{{ $pr->namaLokasi() }}</div>
                                    <div class="text-xs text-zinc-500">{{ $pr->departemen }}</div>
                                </td>
                                <td class="max-w-md px-4 py-3"><div class="line-clamp-2">{{ $pr->keperluan }}</div></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-mono tabular-nums">Rp {{ number_format((float) $pr->total_perkiraan, 0, ',', '.') }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <flux:badge :color="$pr->warnaStatus()" size="sm">{{ $pr->labelStatus() }}</flux:badge>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @forelse ($pr->pesanan as $po)
                                        <a href="{{ route('pembelian.po.show', $po->id) }}" wire:navigate class="block font-mono text-xs text-orange-700 hover:underline dark:text-orange-300">{{ $po->nomor }}</a>
                                    @empty
                                        @if ($pr->status === 'disetujui')
                                            <span class="text-xs font-medium text-amber-600">{{ __('Belum dibuat PO') }}</span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    @endforelse
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-zinc-500">
                                    @if ($search)
                                        {{ __('Tidak ada PR yang cocok dengan ":q".', ['q' => $search]) }}
                                    @elseif ($bolehMinta && $tab === 'semua')
                                        {{ __('Belum ada PR. Klik "Buat PR" untuk mengajukan pembelian barang.') }}
                                    @else
                                        {{ __('Tidak ada PR di tab ini.') }}
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    </div>
</section>
