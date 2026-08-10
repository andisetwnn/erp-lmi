@props([
    'wireProperty',                       // string — nama property livewire (mis. "coaId" atau "detail.0.coa_id")
    'label' => null,                      // string|null — label di atas dropdown
    'placeholder' => 'Pilih akun...',     // string
    'perusahaanId' => null,               // int|null — filter by perusahaan (default: semua)
    'onlyLeaf' => true,                   // bool — hanya akun leaf (bukan header). Default true.
    'onlyAktif' => true,                  // bool — hanya akun aktif. Default true.
    'allowClear' => false,                // bool — tampilkan tombol "kosongkan"
    'required' => false,                  // bool
    'live' => false,                      // bool — kalau true: langsung sync ke server saat pilih
    'compact' => false,                   // bool — versi lebih kecil untuk table cell
    'options' => null,                    // \Illuminate\Support\Collection|null — override list (kalau caller sudah query sendiri)
    'id' => null,                         // string|null — custom id (auto-generate kalau null)
])

@php
    // Auto-fetch options kalau tidak diberikan
    if ($options === null) {
        $q = \App\Models\Master\Coa::query();
        if ($perusahaanId) {
            $q->where('perusahaan_id', $perusahaanId);
        }
        if ($onlyLeaf) {
            $q->where('is_header', false);
        }
        if ($onlyAktif) {
            $q->where('is_aktif', true);
        }
        $options = $q->orderBy('kode')->get(['id', 'kode', 'nama']);
    }

    $optionsJs = $options->map(fn ($c) => [
        'id' => $c->id,
        'kode' => $c->kode,
        'nama' => $c->nama,
    ])->values()->toArray();

    $componentId = $id ?? 'coa-picker-'.uniqid();
    $sizeCls = $compact
        ? 'text-xs px-2 py-1.5'
        : 'text-sm px-3 py-1.5';
@endphp

<div>
    @if ($label)
        <label class="mb-1 block text-sm font-medium">
            {{ $label }}
            @if ($required)<span class="text-rose-500">*</span>@endif
        </label>
    @endif

    <div x-data="coaPickerCmp({
            wireProp: @js($wireProperty),
            options: @js($optionsJs),
            placeholder: @js($placeholder),
            live: @js($live),
        })"
         class="relative">
        <button type="button" @click="toggle()"
                class="flex w-full items-center justify-between rounded border border-zinc-300 bg-white {{ $sizeCls }} text-left hover:bg-zinc-50 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:bg-zinc-700">
            <span class="truncate" :class="{ 'text-zinc-400 dark:text-zinc-500': !currentValue }" x-text="currentLabel"></span>
            <span class="ml-1 flex shrink-0 items-center gap-1">
                @if ($allowClear)
                    <span role="button" tabindex="-1"
                            x-show="currentValue"
                            @click.stop="clear()"
                            class="rounded p-0.5 text-zinc-400 hover:bg-zinc-100 hover:text-rose-500 dark:hover:bg-zinc-700">
                        <svg class="size-3.5" fill="none" viewBox="0 0 20 20" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l8 8m0-8l-8 8" /></svg>
                    </span>
                @endif
                <svg class="size-3.5 text-zinc-400" fill="none" viewBox="0 0 20 20" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 8l4 4 4-4" /></svg>
            </span>
        </button>

        <div x-show="open" x-cloak
             @click.outside="open = false; search = ''; highlightedIdx = 0"
             @keydown.escape.window="open = false; search = ''; highlightedIdx = 0"
             x-transition.opacity.duration.100ms
             class="absolute left-0 z-50 mt-1 max-h-72 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-md border border-zinc-200 bg-white shadow-lg dark:border-zinc-600 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 p-1.5 dark:border-zinc-700">
                <input x-model="search" x-ref="searchInput" type="text"
                       @keydown.arrow-down.prevent="highlightNext()"
                       @keydown.arrow-up.prevent="highlightPrev()"
                       @keydown.enter.prevent="selectHighlighted()"
                       placeholder="Cari kode atau nama..."
                       class="w-full rounded border-0 bg-zinc-50 px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400 dark:bg-zinc-900" />
            </div>
            <ul class="max-h-56 overflow-y-auto text-xs" x-ref="listbox">
                <template x-for="(c, idx) in filtered" :key="c.id">
                    <li @click="select(c.id)"
                        @mouseenter="highlightedIdx = idx"
                        class="cursor-pointer px-2 py-1.5 hover:bg-blue-50 dark:hover:bg-blue-900/30"
                        :class="{
                            'bg-blue-100 dark:bg-blue-900/50 font-semibold': c.id == currentValue,
                            'bg-blue-50 dark:bg-blue-900/30': highlightedIdx === idx && c.id != currentValue,
                        }">
                        <span class="font-mono text-zinc-500 dark:text-zinc-400" x-text="c.kode"></span>
                        <span class="text-zinc-800 dark:text-zinc-200" x-text="' — ' + c.nama"></span>
                    </li>
                </template>
                <li x-show="filtered.length === 0" class="px-2 py-3 text-center italic text-zinc-400">
                    Tidak ada akun cocok.
                </li>
            </ul>
        </div>
    </div>
</div>

@once
    <script>
        (function () {
            if (window._coaPickerRegistered) return;
            window._coaPickerRegistered = true;
            const register = () => {
                if (! window.Alpine) return false;
                Alpine.data('coaPickerCmp', ({ wireProp, options, placeholder, live }) => ({
                    open: false,
                    search: '',
                    highlightedIdx: 0,
                    _options: options,
                    _wireProp: wireProp,
                    _placeholder: placeholder,
                    _live: !! live,

                    init() {
                        this.$watch('open', v => {
                            if (v) {
                                this.$nextTick(() => this.$refs.searchInput?.focus());
                                this.highlightedIdx = Math.max(0, this.filtered.findIndex(c => c.id == this.currentValue));
                            }
                        });
                        this.$watch('search', () => {
                            this.highlightedIdx = 0;
                        });
                    },

                    get currentValue() {
                        return this.$wire.get(this._wireProp);
                    },

                    get currentLabel() {
                        const v = this.currentValue;
                        if (v === null || v === undefined || v === '') return this._placeholder;
                        const c = this._options.find(o => o.id == v);
                        return c ? c.kode + ' — ' + c.nama : this._placeholder;
                    },

                    get filtered() {
                        if (! this.search) return this._options;
                        const s = this.search.toLowerCase();
                        return this._options.filter(c => (c.kode + ' ' + c.nama).toLowerCase().includes(s));
                    },

                    toggle() {
                        this.open = ! this.open;
                        if (! this.open) { this.search = ''; this.highlightedIdx = 0; }
                    },

                    select(id) {
                        if (this._live) {
                            this.$wire.set(this._wireProp, id);
                        } else {
                            this.$wire.set(this._wireProp, id, false); // no server round-trip
                        }
                        this.open = false;
                        this.search = '';
                        this.highlightedIdx = 0;
                    },

                    clear() {
                        if (this._live) {
                            this.$wire.set(this._wireProp, null);
                        } else {
                            this.$wire.set(this._wireProp, null, false);
                        }
                    },

                    highlightNext() {
                        if (this.highlightedIdx < this.filtered.length - 1) {
                            this.highlightedIdx++;
                            this.scrollToHighlight();
                        }
                    },

                    highlightPrev() {
                        if (this.highlightedIdx > 0) {
                            this.highlightedIdx--;
                            this.scrollToHighlight();
                        }
                    },

                    selectHighlighted() {
                        const c = this.filtered[this.highlightedIdx];
                        if (c) this.select(c.id);
                    },

                    scrollToHighlight() {
                        this.$nextTick(() => {
                            const list = this.$refs.listbox;
                            if (! list) return;
                            const li = list.children[this.highlightedIdx];
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
