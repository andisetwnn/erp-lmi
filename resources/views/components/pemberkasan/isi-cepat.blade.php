@props([
    'jenis' => 'tanggal',   // tanggal | bank
    'detail' => null,       // judul tombol "isian lengkap" (unggah berkas, catatan); null = tanpa tombol
    'peringatan' => [],
])

{{-- Isi satu tanggal (atau bank) langsung di sel tabel Pemberkasan, tanpa membuka jendela.
     Enter menyimpan, Esc membatalkan. Isian tambahan seperti unggah berkas atau catatan
     tetap lewat jendela lengkap. --}}
<div class="min-w-44 space-y-1.5 whitespace-normal" x-data x-init="$nextTick(() => $refs.isi?.focus())">
    <div class="flex items-center gap-1">
        @if ($jenis === 'bank')
            <select x-ref="isi" wire:model="inlineValue" wire:keydown.enter="simpanInline" wire:keydown.escape="batalInline"
                    class="w-36 rounded-md border border-zinc-300 bg-white px-1.5 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-800">
                <option value="">Pilih bank</option>
                @foreach (\App\Models\Master\SprPemberkasan::pilihanBank() as $kode => $label)
                    <option value="{{ $kode }}">{{ $kode }} — {{ $label }}</option>
                @endforeach
            </select>
        @else
            <input x-ref="isi" type="date" wire:model="inlineValue" wire:keydown.enter="simpanInline" wire:keydown.escape="batalInline"
                   class="w-32 rounded-md border border-zinc-300 bg-white px-1.5 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-800" />
        @endif
        <button type="button" wire:click="simpanInline" title="Simpan (Enter)"
                class="rounded-md bg-zinc-900 p-1 text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900">
            <flux:icon.check class="size-3.5" />
        </button>
        <button type="button" wire:click="batalInline" title="Batal (Esc)"
                class="rounded-md p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800">
            <flux:icon.x-mark class="size-3.5" />
        </button>
        @if ($detail)
            <button type="button" wire:click="bukaDetailInline" title="{{ $detail }}"
                    class="rounded-md p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                <flux:icon.ellipsis-horizontal class="size-3.5" />
            </button>
        @endif
    </div>

    @error('inlineValue') <p class="text-[10px] text-rose-600">{{ $message }}</p> @enderror

    @if ($peringatan)
        <div class="rounded-md border border-amber-300 bg-amber-50 p-2 text-[10px] leading-snug text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
            @foreach ($peringatan as $pesan)
                <p>{{ $pesan }}</p>
            @endforeach
            <button type="button" wire:click="simpanInline(true)"
                    class="mt-1 font-semibold underline underline-offset-2 hover:text-amber-950 dark:hover:text-amber-100">
                Tetap simpan
            </button>
        </div>
    @endif
</div>
