<?php

use App\Models\Master\PesananPembelian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Purchase Order')] class extends Component
{
    use WithPagination;

    /** berjalan / belum-bayar / menunggu-barang / selesai / batal / semua */
    #[Url(as: 'tab')]
    public string $tab = 'berjalan';

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingTab(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    private function dasar(): Builder
    {
        $dibayar = DB::table('pembayaran_pembelian')->selectRaw('coalesce(sum(jumlah), 0)')->whereColumn('pesanan_pembelian_id', 'pesanan_pembelian.id');

        return PesananPembelian::query()
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($qq) => $qq->where('nomor', 'like', $term)
                    ->orWhereHas('supplier', fn ($s) => $s->where('nama', 'like', $term))
                    ->orWhereHas('permintaan', fn ($p) => $p->where('nomor', 'like', $term)));
            })
            ->addSelect(['pesanan_pembelian.*', 'total_dibayar' => $dibayar]);
    }

    private function saring(Builder $q, string $tab): Builder
    {
        $dibayar = '(select coalesce(sum(jumlah), 0) from pembayaran_pembelian where pesanan_pembelian_id = pesanan_pembelian.id)';

        return match ($tab) {
            'berjalan' => $q->where('status', 'terbit'),
            'belum-bayar' => $q->where('status', '!=', 'batal')->whereRaw("$dibayar < total"),
            'menunggu-barang' => $q->where('status', 'terbit')->whereRaw("$dibayar > 0"),
            'selesai' => $q->where('status', 'selesai'),
            'batal' => $q->where('status', 'batal'),
            default => $q,
        };
    }

    public function with(): array
    {
        $tabs = [
            'berjalan' => 'Berjalan',
            'belum-bayar' => 'Belum lunas',
            'menunggu-barang' => 'Menunggu barang',
            'selesai' => 'Selesai',
            'batal' => 'Batal',
            'semua' => 'Semua',
        ];

        return [
            'tabs' => $tabs,
            'jumlahPerTab' => collect($tabs)->mapWithKeys(fn ($l, $k) => [$k => $this->saring($this->dasar(), $k)->count()]),
            'daftar' => $this->saring($this->dasar(), $this->tab)
                ->with(['supplier:id,nama', 'permintaan:id,nomor,proyek_id', 'permintaan.proyek:id,nama_proyek'])
                ->withSum('item as total_dipesan', 'jumlah')
                ->withSum('item as total_diterima', 'jumlah_diterima')
                ->orderByDesc('tanggal')->orderByDesc('id')
                ->paginate(20),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-orange-500 to-orange-700 text-white shadow-sm">
                <flux:icon.shopping-cart class="size-6" />
            </div>
            <div>
                <flux:heading size="xl">{{ __('Purchase Order') }}</flux:heading>
                <flux:subheading>{{ __('Supplier dibayar dulu, baru barang dikirim. PO selesai otomatis saat semua barang diterima.') }}</flux:subheading>
            </div>
            <x-tombol-panduan modul="pembelian" class="ml-auto self-start" />
        </div>

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
                        <span class="ml-1 rounded-full bg-zinc-100 px-1.5 text-xs dark:bg-zinc-700">{{ $jumlahPerTab[$kunci] ?? 0 }}</span>
                    </button>
                @endforeach
            </nav>
        </div>

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Cari nomor PO, nomor PR, atau supplier...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                            <th class="px-4 py-3">{{ __('Nomor') }}</th>
                            <th class="px-4 py-3">{{ __('Supplier') }}</th>
                            <th class="px-4 py-3">{{ __('Dari PR') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                            <th class="px-4 py-3">{{ __('Pembayaran') }}</th>
                            <th class="px-4 py-3">{{ __('Barang') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($daftar as $po)
                            @php
                                $dibayar = (float) $po->total_dibayar;
                                [$labelBayar, $warnaBayar] = match (true) {
                                    $dibayar <= 0 => ['Belum dibayar', 'rose'],
                                    $dibayar + 0.005 < (float) $po->total => ['Sebagian', 'amber'],
                                    default => ['Lunas', 'emerald'],
                                };
                                $diterima = (float) $po->total_diterima;
                                [$labelBarang, $warnaBarang] = match (true) {
                                    $diterima <= 0 => ['Belum datang', 'zinc'],
                                    $diterima + 0.005 < (float) $po->total_dipesan => ['Sebagian', 'amber'],
                                    default => ['Lengkap', 'emerald'],
                                };
                            @endphp
                            <tr wire:key="po-{{ $po->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <a href="{{ route('pembelian.po.show', $po->id) }}" wire:navigate class="font-mono font-semibold text-orange-700 hover:underline dark:text-orange-300">{{ $po->nomor }}</a>
                                    <div class="text-xs text-zinc-500">{{ $po->tanggal?->translatedFormat('d M Y') }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $po->supplier?->nama }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="font-mono text-xs">{{ $po->permintaan?->nomor }}</div>
                                    <div class="text-xs text-zinc-500">{{ $po->permintaan?->namaLokasi() }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-mono tabular-nums">Rp {{ number_format((float) $po->total, 0, ',', '.') }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if ($po->status === 'batal')
                                        <flux:badge color="zinc" size="sm">{{ __('Batal') }}</flux:badge>
                                    @else
                                        <flux:badge :color="$warnaBayar" size="sm">{{ $labelBayar }}</flux:badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if ($po->ditutup())
                                        <flux:badge color="zinc" size="sm">{{ __('Ditutup') }}</flux:badge>
                                    @elseif ($po->status !== 'batal')
                                        <flux:badge :color="$warnaBarang" size="sm">{{ $labelBarang }}</flux:badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-zinc-500">{{ $search ? __('Tidak ada PO yang cocok.') : __('Tidak ada PO di tab ini.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    </div>
</section>
