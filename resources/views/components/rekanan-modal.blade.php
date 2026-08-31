@props([
    'kategoriList',                  // \Illuminate\Support\Collection — daftar nama kategori
    'kategoriAktif' => '',           // string — kategori yang sedang disaring
    'halamanIni',                    // \Illuminate\Support\Collection — baris halaman ini
    'jumlah' => 0,                   // int — total setelah disaring
    'dariNomor' => 0,                // int — offset nomor urut halaman ini
    'halamanAktif' => 1,             // int
    'totalHalaman' => 1,             // int
    'subheading' => 'Pihak yang bertransaksi. Boleh dilewati kalau transaksinya internal.',
])

{{--
    Modal pemilih rekanan. WAJIB dirender sejajar dengan modal lain, jangan di dalam
    modal/tabel ber-scroll — kalau bersarang, dropdown-nya ikut terpotong wadahnya.

    Terikat ke trait App\Livewire\Concerns\MemilihRekanan lewat nama method &
    property: pilihRekanan(), gantiHalamanRekanan(), rekananCari, rekananKategori.
--}}
<flux:modal name="pilih-rekanan" @class(['max-w-2xl'])>
    <div class="space-y-4">
        <div>
            <flux:heading size="lg">Pilih Rekanan</flux:heading>
            <flux:subheading>{{ $subheading }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-500">Kategori</span>
            <button type="button" wire:click="$set('rekananKategori', '')"
                    @class([
                        'rounded-full px-3 py-1 text-xs font-medium transition',
                        'bg-blue-600 text-white' => $kategoriAktif === '',
                        'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-300' => $kategoriAktif !== '',
                    ])>
                Semua
            </button>
            @foreach ($kategoriList as $kat)
                <button type="button" wire:click="$set('rekananKategori', @js($kat))"
                        @class([
                            'rounded-full px-3 py-1 text-xs font-medium transition',
                            'bg-blue-600 text-white' => $kategoriAktif === $kat,
                            'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-300' => $kategoriAktif !== $kat,
                        ])>
                    {{ $kat }}
                </button>
            @endforeach
        </div>

        <flux:input wire:model.live.debounce.300ms="rekananCari"
                    icon="magnifying-glass"
                    placeholder="Cari nama atau kode..." />

        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                        <th class="w-12 px-3 py-2 font-semibold">No</th>
                        <th class="px-3 py-2 font-semibold">Nama</th>
                        <th class="w-28 px-3 py-2 font-semibold">Kategori</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($halamanIni as $idx => $r)
                        <tr wire:key="rekanan-{{ $r['nilai'] }}"
                            wire:click="pilihRekanan(@js($r['nilai']))"
                            class="cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/30">
                            <td class="px-3 py-2 text-xs text-zinc-400">{{ $dariNomor + $idx + 1 }}</td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-blue-600 dark:text-blue-400">{{ $r['nama'] }}</div>
                                {{-- Bank & notaris tidak punya kode, jadi barisnya cuma muncul kalau ada isinya --}}
                                @if ($r['kode'])
                                    <div class="font-mono text-[11px] text-zinc-400">{{ $r['kode'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-zinc-500">{{ $r['kategori'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-3 py-8 text-center text-sm italic text-zinc-400">
                                Tidak ada rekanan yang cocok.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between">
            <span class="text-xs text-zinc-500">
                @if ($jumlah > 0)
                    {{ $dariNomor + 1 }}&ndash;{{ min($dariNomor + $halamanIni->count(), $jumlah) }}
                    dari {{ number_format($jumlah, 0, ',', '.') }} data
                @else
                    0 data
                @endif
            </span>
            <div class="flex items-center gap-1">
                <flux:button size="xs" variant="ghost" icon="chevron-left"
                             :disabled="$halamanAktif <= 1"
                             wire:click="gantiHalamanRekanan({{ $halamanAktif - 1 }})" />
                <span class="px-2 text-xs text-zinc-500">{{ $halamanAktif }} / {{ $totalHalaman }}</span>
                <flux:button size="xs" variant="ghost" icon="chevron-right"
                             :disabled="$halamanAktif >= $totalHalaman"
                             wire:click="gantiHalamanRekanan({{ $halamanAktif + 1 }})" />
            </div>
        </div>

        <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
            <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
        </div>
    </div>
</flux:modal>
