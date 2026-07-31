@props([
    'wire',                  // nama property Livewire yang di-bind (string)
    'placeholder' => '0',
    'required' => false,
    'disabled' => false,
    'prefix' => null,        // opsional, mis. 'Rp'. Default tanpa prefix.
])

{{-- Reusable money input.
     Stored: integer rupiah (tanpa desimal).
     Display: "185.000.000" (Intl id-ID).
     Pakai: <x-money-input wire="harga_jual" required />
     Dynamic key: <x-money-input :wire="'ajbEdits.'.$id.'.nominal'" />
     Tanpa prefix Rp: <x-money-input wire="x" prefix="" />
--}}
<div
    x-data="{
        model: @js($wire),
        raw: 0,
        display: '',
        fmt(v) {
            if (v === null || v === undefined || v === '') return '';
            const n = Math.round(Number(v));
            if (isNaN(n)) return '';
            return new Intl.NumberFormat('id-ID').format(n);
        },
        sync() {
            const v = this.$wire.get(this.model);
            this.raw = v;
            this.display = this.fmt(v);
        },
        init() {
            this.sync();
            this.$wire.$watch(this.model, v => {
                if (Number(v) !== Number(this.raw)) {
                    this.raw = v;
                    this.display = this.fmt(v);
                }
            });
        },
        onInput(e) {
            const digits = e.target.value.replace(/[^0-9]/g, '');
            const num = digits === '' ? 0 : Number(digits);
            this.raw = num;
            this.$wire.set(this.model, num, false);
            this.display = this.fmt(num);
        }
    }"
    wire:ignore.self
>
    <flux:input
        x-model="display"
        @input="onInput"
        type="text"
        inputmode="numeric"
        placeholder="{{ $placeholder }}"
        :required="$required"
        :disabled="$disabled"
        class:input="{{ $prefix ? 'ps-12!' : '' }}"
        {{ $attributes->except(['wire', 'placeholder', 'required', 'disabled', 'prefix']) }}
    >
        @if ($prefix)
            <x-slot name="iconLeading">
                <span class="ps-3 text-sm font-medium text-zinc-500">{{ $prefix }}</span>
            </x-slot>
        @endif
    </flux:input>
</div>
