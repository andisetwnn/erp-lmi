<?php

use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Kas & Bank')] class extends Component
{
    #[Url(as: 'tgl')]
    public string $tanggal = '';

    public function mount(): void
    {
        if ($this->tanggal === '') {
            $this->tanggal = now()->toDateString();
        }
    }

    public function with(): array
    {
        $perusahaan = Perusahaan::first();

        // Ambil semua saldo kas & bank per akun
        $saldos = app(LaporanAkuntingService::class)->bukuBankSaldo($perusahaan->id, $this->tanggal);

        // Split kas (1001.*) vs bank (1002.*)
        $kas = $saldos->filter(fn ($row) => str_starts_with($row['coa']->kode, '1001.'))->values();
        $bank = $saldos->filter(fn ($row) => str_starts_with($row['coa']->kode, '1002.'))->values();

        $totalKas = $kas->sum('saldo');
        $totalBank = $bank->sum('saldo');

        // Parent id untuk preset "Buat Rekening"
        $parentKasId = Coa::where('perusahaan_id', $perusahaan->id)->where('kode', '1001')->value('id');
        $parentBankId = Coa::where('perusahaan_id', $perusahaan->id)->where('kode', '1002')->value('id');

        return [
            'perusahaan' => $perusahaan,
            'kas' => $kas,
            'bank' => $bank,
            'totalKas' => $totalKas,
            'totalBank' => $totalBank,
            'total' => $totalKas + $totalBank,
            'parentKasId' => $parentKasId,
            'parentBankId' => $parentBankId,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-emerald-500 to-emerald-700 text-white shadow-sm">
                    <flux:icon.banknotes class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ __('Kas & Bank') }}</flux:heading>
                        <x-info-button title="Kas & Bank">
                            <p>Dashboard posisi kas &amp; bank per tanggal cutoff — nampak saldo semua akun kas (1001.*) &amp; bank (1002.*) dalam sekali lihat, plus total per section &amp; grand total.</p>
                            <p class="mt-2">Cara pakai:</p>
                            <ul class="ml-4 mt-1 list-disc space-y-1">
                                <li>Klik <strong>Lihat</strong> di sebelah akun → langsung ke Buku Besar akun tsb (lihat detail mutasi).</li>
                                <li>Ubah <strong>Per Tanggal</strong> untuk cek saldo historis (mis. per akhir bulan lalu).</li>
                                <li>Kalau punya permission COA, tombol <strong>Buat Rekening Kas/Bank</strong> untuk tambah akun baru.</li>
                            </ul>
                            <p class="mt-2">Saldo di sini = catatan sistem berdasarkan jurnal yg sudah posted. Kalau beda dgn rekening bank fisik → ada jurnal yg belum diinput atau dobel input.</p>
                            <p class="mt-2 text-xs text-zinc-500">Berguna sebelum bayar SPK/gaji: cek dulu bank mana saldonya cukup.</p>
                        </x-info-button>
                    </div>
                    <flux:subheading>{{ __('Posisi likuiditas — saldo per akun kas & bank per tanggal cutoff.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('master.coa.kelola')
                    <flux:button variant="ghost" icon="plus"
                                 :href="route('master.coa.index', array_filter(['tipe' => 'aset', 'parent' => $parentKasId, 'prefill_kode' => '1001']))"
                                 wire:navigate>
                        {{ __('Buat Rekening Kas') }}
                    </flux:button>
                    <flux:button variant="ghost" icon="plus"
                                 :href="route('master.coa.index', array_filter(['tipe' => 'aset', 'parent' => $parentBankId, 'prefill_kode' => '1002']))"
                                 wire:navigate>
                        {{ __('Buat Rekening Bank') }}
                    </flux:button>
                @endcan
            </div>
        </div>

        {{-- FILTER + SUMMARY --}}
        <div class="mb-4 grid grid-cols-1 gap-3 lg:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:input type="date" wire:model.live="tanggal" label="Per Tanggal" size="sm" />
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-950/30">
                <div class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-400">Total Kas</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-amber-900 dark:text-amber-200">
                    Rp {{ number_format($totalKas, 0, ',', '.') }}
                </div>
                <div class="mt-0.5 text-[10px] text-amber-700 dark:text-amber-400">{{ $kas->count() }} akun</div>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/40 dark:bg-blue-950/30">
                <div class="text-xs uppercase tracking-wide text-blue-700 dark:text-blue-400">Total Bank</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-blue-900 dark:text-blue-200">
                    Rp {{ number_format($totalBank, 0, ',', '.') }}
                </div>
                <div class="mt-0.5 text-[10px] text-blue-700 dark:text-blue-400">{{ $bank->count() }} akun</div>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/30">
                <div class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Total Kas + Bank</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-200">
                    Rp {{ number_format($total, 0, ',', '.') }}
                </div>
                <div class="mt-0.5 text-[10px] text-emerald-700 dark:text-emerald-400">Posisi likuiditas</div>
            </div>
        </div>

        {{-- SECTION KAS --}}
        <div class="mb-4 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-amber-100 bg-amber-50 px-4 py-2.5 dark:border-amber-900/40 dark:bg-amber-950/30">
                <div class="flex items-center justify-between">
                    <div class="text-sm font-bold uppercase text-amber-800 dark:text-amber-300">💵 Kas</div>
                    <div class="text-xs text-amber-700 dark:text-amber-400">{{ $kas->count() }} akun · Rp {{ number_format($totalKas, 0, ',', '.') }}</div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr class="text-left text-xs uppercase text-zinc-500">
                            <th class="px-4 py-2 font-semibold">Kode Rekening</th>
                            <th class="px-4 py-2 font-semibold">Nama Akun</th>
                            <th class="px-4 py-2 text-right font-semibold">Saldo</th>
                            <th class="px-4 py-2 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($kas as $row)
                            <tr class="hover:bg-amber-50/40 dark:hover:bg-amber-950/10">
                                <td class="px-4 py-2 font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $row['coa']->kode }}</td>
                                <td class="px-4 py-2 font-medium">{{ $row['coa']->nama }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-semibold {{ $row['saldo'] < 0 ? 'text-rose-600' : '' }}">
                                    {{ number_format($row['saldo'], 0, ',', '.') }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <flux:button size="xs" variant="ghost" icon="eye"
                                                 :href="route('akunting.buku-besar.index', ['coa' => $row['coa']->id])"
                                                 wire:navigate>
                                        {{ __('Lihat') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-zinc-400">
                                    Belum ada akun kas.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($kas->isNotEmpty())
                        <tfoot class="border-t-2 border-amber-200 bg-amber-50/60 dark:border-amber-800/60 dark:bg-amber-950/20">
                            <tr class="text-sm font-bold">
                                <td colspan="2" class="px-4 py-2 text-right">TOTAL KAS</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-amber-900 dark:text-amber-200">
                                    Rp {{ number_format($totalKas, 0, ',', '.') }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- SECTION BANK --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-blue-100 bg-blue-50 px-4 py-2.5 dark:border-blue-900/40 dark:bg-blue-950/30">
                <div class="flex items-center justify-between">
                    <div class="text-sm font-bold uppercase text-blue-800 dark:text-blue-300">🏦 Bank</div>
                    <div class="text-xs text-blue-700 dark:text-blue-400">{{ $bank->count() }} akun · Rp {{ number_format($totalBank, 0, ',', '.') }}</div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr class="text-left text-xs uppercase text-zinc-500">
                            <th class="px-4 py-2 font-semibold">Kode Rekening</th>
                            <th class="px-4 py-2 font-semibold">Nama Akun</th>
                            <th class="px-4 py-2 text-right font-semibold">Saldo</th>
                            <th class="px-4 py-2 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($bank as $row)
                            <tr class="hover:bg-blue-50/40 dark:hover:bg-blue-950/10">
                                <td class="px-4 py-2 font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $row['coa']->kode }}</td>
                                <td class="px-4 py-2 font-medium">{{ $row['coa']->nama }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-semibold {{ $row['saldo'] < 0 ? 'text-rose-600' : '' }}">
                                    {{ number_format($row['saldo'], 0, ',', '.') }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <flux:button size="xs" variant="ghost" icon="eye"
                                                 :href="route('akunting.buku-besar.index', ['coa' => $row['coa']->id])"
                                                 wire:navigate>
                                        {{ __('Lihat') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-zinc-400">
                                    Belum ada akun bank.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($bank->isNotEmpty())
                        <tfoot class="border-t-2 border-blue-200 bg-blue-50/60 dark:border-blue-800/60 dark:bg-blue-950/20">
                            <tr class="text-sm font-bold">
                                <td colspan="2" class="px-4 py-2 text-right">TOTAL BANK</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-blue-900 dark:text-blue-200">
                                    Rp {{ number_format($totalBank, 0, ',', '.') }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- GRAND TOTAL --}}
        <div class="mt-4 rounded-lg border-2 border-emerald-300 bg-emerald-50 px-4 py-3 dark:border-emerald-700/60 dark:bg-emerald-950/30">
            <div class="flex items-center justify-between">
                <div class="text-sm font-bold uppercase text-emerald-800 dark:text-emerald-300">TOTAL KAS + BANK</div>
                <div class="font-mono text-xl font-bold tabular-nums text-emerald-900 dark:text-emerald-200">
                    Rp {{ number_format($total, 0, ',', '.') }}
                </div>
            </div>
        </div>

    </div>
</section>
