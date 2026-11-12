<?php

use App\Models\Master\Sales;
use App\Models\PanduanBaca;
use App\Models\User;
use App\Support\Panduan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Rekap siapa sudah membuka dan mengonfirmasi membaca panduan modulnya. Setiap
 * orang hanya dihitung untuk panduan yang wajib ia baca (perannya menjalankan modul
 * itu; direktur dan super-admin tidak dilacak), dan hanya versi terbaru.
 */
new #[Title('Rekap Pembaca Panduan')] class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $cari = '';

    #[Url(as: 'peran', except: '')]
    public string $peran = '';

    #[Url(as: 'panduan', except: '')]
    public string $panduan = '';

    /** '' semua, 'belum' = masih ada yang belum dikonfirmasi, 'selesai' = semua sudah dibaca. */
    #[Url(as: 'status', except: '')]
    public string $status = '';

    /** Nilai filter peran untuk sales DBOS (bukan peran ERP). */
    public const PERAN_SALES = 'sales-dbos';

    public function with(): array
    {
        $kolom = array_filter(Panduan::DAFTAR, fn (string $kunci) => Panduan::tersedia($kunci), ARRAY_FILTER_USE_KEY);
        if ($this->panduan !== '' && isset($kolom[$this->panduan])) {
            $kolom = [$this->panduan => $kolom[$this->panduan]];
        }

        // Pembaca: user ERP (per peran) dan sales DBOS (login terpisah). Filter peran
        // "sales" hanya menampilkan sales; peran ERP hanya menampilkan user ERP.
        $users = $this->peran === self::PERAN_SALES ? collect() : User::query()
            ->where('is_aktif', true)
            ->with('roles.permissions', 'permissions')
            ->when($this->cari !== '', fn ($q) => $q->where('name', 'like', "%{$this->cari}%"))
            ->when($this->peran !== '', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $this->peran)))
            ->orderBy('name')
            ->get();
        $sales = ($this->peran !== '' && $this->peran !== self::PERAN_SALES) ? collect() : Sales::query()
            ->where('is_aktif', true)
            ->when($this->cari !== '', fn ($q) => $q->where('nama', 'like', "%{$this->cari}%"))
            ->orderBy('nama')
            ->get();

        $jejakUser = $this->jejakPer(User::class, $users->pluck('id'));
        $jejakSales = $this->jejakPer(Sales::class, $sales->pluck('id'));
        $sel = function (bool $wajib, ?PanduanBaca $j) {
            return $wajib ? ['status' => Panduan::status($j), 'jejak' => $j] : null;
        };

        $baris = $users
            ->map(function (User $user) use ($kolom, $jejakUser, $sel) {
                $wajib = Panduan::wajibUntuk($user);

                return [
                    'kunci' => 'user-'.$user->id,
                    'nama' => $user->name,
                    'peran' => $user->roles->pluck('name')->all(),
                    'sel' => collect($kolom)->map(fn ($p, $kunci) => $sel(isset($wajib[$kunci]), $jejakUser->get($user->id)?->get($kunci)))->all(),
                ];
            })
            ->concat($sales->map(fn (Sales $s) => [
                'kunci' => 'sales-'.$s->id,
                'nama' => $s->nama,
                'peran' => [$s->isPimpinan() ? 'pimpinan-sales' : self::PERAN_SALES],
                'sel' => collect($kolom)->map(fn ($p, $kunci) => $sel(Panduan::wajibSales($kunci), $jejakSales->get($s->id)?->get($kunci)))->all(),
            ]))
            ->filter(fn (array $b) => array_filter($b['sel']) !== [])
            ->filter(function (array $b) {
                $belum = collect($b['sel'])->filter()->contains(fn ($s) => $s['status'] !== Panduan::DIBACA);

                return match ($this->status) {
                    'belum' => $belum,
                    'selesai' => ! $belum,
                    default => true,
                };
            })
            ->values();

        $ringkasan = [];
        foreach (array_keys($kolom) as $kunci) {
            $sel = $baris->pluck("sel.{$kunci}")->filter();
            $ringkasan[$kunci] = [
                'total' => $sel->count(),
                'dibaca' => $sel->where('status', Panduan::DIBACA)->count(),
                'dibuka' => $sel->where('status', Panduan::DIBUKA)->count(),
            ];
        }

        return [
            'kolom' => $kolom,
            'baris' => $baris,
            'ringkasan' => $ringkasan,
            'semuaPanduan' => array_filter(Panduan::DAFTAR, fn (string $kunci) => Panduan::tersedia($kunci), ARRAY_FILTER_USE_KEY),
            'daftarPeran' => Role::query()->orderBy('name')->pluck('name'),
        ];
    }

    /**
     * Jejak baca versi terbaru, per pembaca lalu per kunci panduan.
     *
     * @param  class-string<Model>  $jenis
     * @param  Collection<int, int>  $ids
     * @return Collection<int, Collection<string, PanduanBaca>>
     */
    protected function jejakPer(string $jenis, Collection $ids): Collection
    {
        return PanduanBaca::query()
            ->where('pembaca_type', (new $jenis)->getMorphClass())
            ->whereIn('pembaca_id', $ids)
            ->get()
            ->filter(fn (PanduanBaca $b) => (Panduan::DAFTAR[$b->kunci]['versi'] ?? null) === $b->versi)
            ->groupBy('pembaca_id')
            ->map(fn (Collection $per) => $per->keyBy('kunci'));
    }
}; ?>

@php
    $labelPeran = fn (string $nama) => [
        'super-admin' => 'Super Admin', 'direktur' => 'Direktur', 'project-manager' => 'Project Manager',
        'finance' => 'Keuangan', 'staf-keuangan' => 'Staf Keuangan', 'admin-kpr' => 'Admin KPR',
        'admin-sales' => 'Admin Sales', 'admin-teknik' => 'Admin Teknik', 'admin-legal' => 'Admin Legal',
        'sales-dbos' => 'Sales lapangan (DBOS)', 'pimpinan-sales' => 'Pimpinan grup (DBOS)',
    ][$nama] ?? ucwords(str_replace('-', ' ', $nama));
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-sm dark:bg-zinc-700">
                <flux:icon.users class="size-6" />
            </div>
            <div class="grow">
                <flux:heading size="xl">{{ __('Rekap Pembaca Panduan') }}</flux:heading>
                <flux:subheading>{{ __('Apakah orang yang menjalankan tiap modul sudah membaca panduannya. Panduan yang diperbarui harus dibaca ulang.') }}</flux:subheading>
            </div>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('panduan.index')" wire:navigate class="self-center">
                {{ __('Panduan') }}
            </flux:button>
        </div>

        {{-- RINGKASAN PER PANDUAN --}}
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($kolom as $kunci => $p)
                @php $r = $ringkasan[$kunci]; @endphp
                <div wire:key="ringkas-{{ $kunci }}" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $p['judul'] }}</div>
                    <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ $r['dibaca'] }}<span class="text-base font-normal text-zinc-400"> / {{ $r['total'] }}</span></div>
                    <div class="mt-0.5 text-xs text-zinc-500">
                        {{ __('sudah dibaca') }}@if ($r['dibuka'] > 0) · {{ __(':n dibuka, belum dikonfirmasi', ['n' => $r['dibuka']]) }}@endif
                    </div>
                    <div class="mt-1 text-xs text-zinc-400">{{ __('Wajib:') }} {{ collect($p['wajib'])->map($labelPeran)->when(! empty($p['sales']), fn ($c) => $c->push(__('semua sales DBOS')))->join(', ') }}</div>
                </div>
            @endforeach
        </div>

        {{-- SARINGAN --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="min-w-56 flex-1">
                <flux:input wire:model.live.debounce.300ms="cari" placeholder="{{ __('Cari nama...') }}" icon="magnifying-glass" />
            </div>
            <div>
                <flux:select wire:model.live="peran">
                    <flux:select.option value="">{{ __('Semua peran') }}</flux:select.option>
                    <flux:select.option value="sales-dbos">{{ $labelPeran('sales-dbos') }}</flux:select.option>
                    @foreach ($daftarPeran as $nama)
                        <flux:select.option value="{{ $nama }}">{{ $labelPeran($nama) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <flux:select wire:model.live="panduan">
                    <flux:select.option value="">{{ __('Semua panduan') }}</flux:select.option>
                    @foreach ($semuaPanduan as $kunci => $p)
                        <flux:select.option value="{{ $kunci }}">{{ $p['judul'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <flux:select wire:model.live="status">
                    <flux:select.option value="">{{ __('Semua status') }}</flux:select.option>
                    <flux:select.option value="belum">{{ __('Masih ada yang belum dibaca') }}</flux:select.option>
                    <flux:select.option value="selesai">{{ __('Semua sudah dibaca') }}</flux:select.option>
                </flux:select>
            </div>
        </div>

        {{-- TABEL --}}
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                    <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                        <th class="px-4 py-3 font-semibold">{{ __('Nama') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('Peran') }}</th>
                        @foreach ($kolom as $p)
                            <th class="px-4 py-3 font-semibold">{{ $p['judul'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($baris as $b)
                        <tr wire:key="pembaca-{{ $b['kunci'] }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-2.5 font-medium whitespace-nowrap">{{ $b['nama'] }}</td>
                            <td class="px-4 py-2.5 text-xs text-zinc-600 dark:text-zinc-400">{{ collect($b['peran'])->map($labelPeran)->join(', ') ?: '-' }}</td>
                            @foreach ($kolom as $kunci => $p)
                                @php $s = $b['sel'][$kunci]; @endphp
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    @if ($s === null)
                                        <span class="text-zinc-300 dark:text-zinc-600" title="{{ __('Bukan modulnya') }}">—</span>
                                    @elseif ($s['status'] === \App\Support\Panduan::DIBACA)
                                        <flux:badge size="sm" color="emerald" icon="check">{{ $s['jejak']->dibaca_at->translatedFormat('j M Y') }}</flux:badge>
                                    @elseif ($s['status'] === \App\Support\Panduan::DIBUKA)
                                        <flux:badge size="sm" color="amber" title="{{ __('Dibuka :n kali, terakhir :tgl', ['n' => $s['jejak']->jumlah_dibuka, 'tgl' => $s['jejak']->dibuka_terakhir_at->translatedFormat('j M Y H:i')]) }}">{{ __('Dibuka') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('Belum') }}</flux:badge>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 2 + count($kolom) }}" class="px-4 py-12 text-center text-zinc-400 dark:text-zinc-500">
                                {{ __('Tidak ada yang cocok dengan saringan ini.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-zinc-500">
            {{ __('Hijau: sudah dikonfirmasi dibaca (tanggalnya). Kuning: sudah dibuka tapi belum dikonfirmasi. "—": ia tidak menjalankan modul itu.') }}
        </p>
    </div>
</section>
