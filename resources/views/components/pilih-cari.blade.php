@props([
    'wireProperty',                   // string — nama property livewire, mis. "pindahSprId"
    'items' => [],                    // iterable of ['id' =>, 'judul' =>, 'keterangan' => ?]
    'placeholder' => 'Pilih...',      // string
    'cariPlaceholder' => 'Ketik untuk mencari...',
    'kosong' => 'Tidak ada yang cocok.',
    'live' => true,                   // bool — true: langsung sync ke server saat dipilih
    'allowClear' => true,             // bool
    'disabled' => false,              // bool
])

@php
    // Diseragamkan di sini supaya pemanggil boleh mengirim Eloquent collection,
    // array asosiatif, atau apa pun yang penting punya id + judul.
    $itemsJs = collect($items)
        ->map(fn ($i) => [
            'id' => (string) ($i['id'] ?? ''),
            'judul' => (string) ($i['judul'] ?? ''),
            'keterangan' => (string) ($i['keterangan'] ?? ''),
        ])
        ->filter(fn ($i) => $i['id'] !== '')
        ->values()
        ->all();
@endphp

<div x-data="pilihCariCmp({
        wireProp: @js($wireProperty),
        items: @js($itemsJs),
        placeholder: @js($placeholder),
        live: @js($live),
    })"
     class="relative">
    <button type="button" @click="toggle()" @disabled($disabled)
            class="flex w-full items-center justify-between rounded-lg border border-zinc-300 bg-white px-3 py-2 text-left text-sm hover:bg-zinc-50 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:bg-zinc-700">
        <span class="min-w-0 flex-1 truncate"
              :class="{ 'text-zinc-400 dark:text-zinc-500': !nilaiSekarang }"
              x-text="labelSekarang"></span>
        <span class="ml-1 flex shrink-0 items-center gap-1">
            @if ($allowClear)
                <span role="button" tabindex="-1" x-show="nilaiSekarang" @click.stop="bersihkan()"
                      class="rounded p-0.5 text-zinc-400 hover:bg-zinc-100 hover:text-rose-500 dark:hover:bg-zinc-700">
                    <svg class="size-3.5" fill="none" viewBox="0 0 20 20" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l8 8m0-8l-8 8" /></svg>
                </span>
            @endif
            <svg class="size-4 text-zinc-400" fill="none" viewBox="0 0 20 20" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 8l4 4 4-4" /></svg>
        </span>
    </button>

    <div x-show="terbuka" x-cloak
         @click.outside="tutup()"
         @keydown.escape.window="tutup()"
         x-transition.opacity.duration.100ms
         class="absolute left-0 z-50 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-600 dark:bg-zinc-800">
        <div class="border-b border-zinc-200 p-1.5 dark:border-zinc-700">
            <input x-model="cari" x-ref="kotakCari" type="text"
                   @keydown.arrow-down.prevent="berikutnya()"
                   @keydown.arrow-up.prevent="sebelumnya()"
                   @keydown.enter.prevent="pilihYangDisorot()"
                   placeholder="{{ $cariPlaceholder }}"
                   class="w-full rounded border-0 bg-zinc-50 px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400 dark:bg-zinc-900" />
        </div>

        <ul class="max-h-64 overflow-y-auto text-sm" x-ref="daftar">
            <template x-for="(it, idx) in tersaring" :key="it.id">
                <li @click="pilih(it.id)"
                    @mouseenter="disorot = idx"
                    class="cursor-pointer px-3 py-2 hover:bg-blue-50 dark:hover:bg-blue-900/30"
                    :class="{
                        'bg-blue-100 dark:bg-blue-900/50 font-semibold': it.id == nilaiSekarang,
                        'bg-blue-50 dark:bg-blue-900/30': disorot === idx && it.id != nilaiSekarang,
                    }">
                    <div class="truncate text-zinc-800 dark:text-zinc-200" x-text="it.judul"></div>
                    <div x-show="it.keterangan" class="truncate text-xs text-zinc-500" x-text="it.keterangan"></div>
                </li>
            </template>
            <li x-show="tersaring.length === 0" class="px-3 py-4 text-center italic text-zinc-400">
                {{ $kosong }}
            </li>
        </ul>

        <div class="border-t border-zinc-200 px-3 py-1.5 text-[11px] text-zinc-400 dark:border-zinc-700">
            <span x-text="tersaring.length"></span> dari <span x-text="_items.length"></span> pilihan
        </div>
    </div>
</div>

@once
    <script>
        (function () {
            if (window._pilihCariRegistered) return;
            window._pilihCariRegistered = true;

            const register = () => {
                if (! window.Alpine) return false;

                Alpine.data('pilihCariCmp', ({ wireProp, items, placeholder, live }) => ({
                    terbuka: false,
                    cari: '',
                    disorot: 0,
                    _items: items,
                    _wireProp: wireProp,
                    _placeholder: placeholder,
                    _live: !! live,

                    init() {
                        this.$watch('terbuka', v => {
                            if (v) {
                                this.$nextTick(() => this.$refs.kotakCari?.focus());
                                this.disorot = Math.max(0, this.tersaring.findIndex(i => i.id == this.nilaiSekarang));
                            }
                        });
                        this.$watch('cari', () => { this.disorot = 0; });
                    },

                    get nilaiSekarang() {
                        const v = this.$wire.get(this._wireProp);
                        return v === null || v === undefined ? '' : String(v);
                    },

                    get labelSekarang() {
                        const v = this.nilaiSekarang;
                        if (v === '') return this._placeholder;
                        const it = this._items.find(i => i.id == v);
                        if (! it) return this._placeholder;
                        return it.keterangan ? it.judul + ' — ' + it.keterangan : it.judul;
                    },

                    get tersaring() {
                        if (! this.cari) return this._items;
                        const s = this.cari.toLowerCase();
                        return this._items.filter(i =>
                            (i.judul + ' ' + i.keterangan).toLowerCase().includes(s)
                        );
                    },

                    toggle() {
                        this.terbuka = ! this.terbuka;
                        if (! this.terbuka) this.reset();
                    },

                    tutup() {
                        this.terbuka = false;
                        this.reset();
                    },

                    reset() {
                        this.cari = '';
                        this.disorot = 0;
                    },

                    pilih(id) {
                        this.$wire.set(this._wireProp, id, this._live);
                        this.tutup();
                    },

                    bersihkan() {
                        this.$wire.set(this._wireProp, '', this._live);
                    },

                    berikutnya() {
                        if (this.disorot < this.tersaring.length - 1) {
                            this.disorot++;
                            this.gulirKeSorotan();
                        }
                    },

                    sebelumnya() {
                        if (this.disorot > 0) {
                            this.disorot--;
                            this.gulirKeSorotan();
                        }
                    },

                    pilihYangDisorot() {
                        const it = this.tersaring[this.disorot];
                        if (it) this.pilih(it.id);
                    },

                    gulirKeSorotan() {
                        this.$nextTick(() => {
                            const daftar = this.$refs.daftar;
                            if (! daftar) return;
                            const li = daftar.children[this.disorot];
                            if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
                        });
                    },
                }));

                return true;
            };

            if (! register()) {
                document.addEventListener('alpine:init', register);
            }
        })();
    </script>
@endonce
