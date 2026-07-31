<?php

use App\Models\Master\Proyek;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public ?int $activeProyekId = null;

    public function mount(): void
    {
        $this->activeProyekId = session('active_proyek_id');
    }

    public function with(): array
    {
        return [
            'proyekList' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek', 'nama_perumahan', 'kota_kabupaten']),
            'activeProyek' => $this->activeProyekId ? Proyek::find($this->activeProyekId) : null,
        ];
    }

    public function openPicker(): void
    {
        Flux::modal('global-proyek-picker')->show();
    }

    public function selectProyek(int $id): void
    {
        session(['active_proyek_id' => $id]);
        $this->activeProyekId = $id;

        Flux::modal('global-proyek-picker')->close();
        Flux::toast(variant: 'success', text: 'Proyek aktif diganti.');

        // Broadcast ke semua Livewire component yang listen di page yang sama
        $this->dispatch('active-proyek-changed', proyekId: $id);
    }
}; ?>

<div class="px-3 pb-2 in-data-flux-sidebar-collapsed-desktop:hidden">
    <button type="button" wire:click="openPicker"
            class="group flex w-full items-center gap-2 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-left transition hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-500 dark:hover:bg-zinc-700">
        <flux:icon.home-modern class="size-4 shrink-0 text-zinc-500" />
        <div class="min-w-0 flex-1">
            <div class="truncate text-xs font-semibold text-zinc-900 dark:text-white">
                {{ $activeProyek?->nama_proyek ?? __('Pilih Proyek') }}
            </div>
            @if ($activeProyek)
                <div class="truncate text-[10px] text-zinc-500">
                    {{ $activeProyek->nama_perumahan }}@if ($activeProyek->kota_kabupaten) <span class="text-zinc-400">· {{ $activeProyek->kota_kabupaten }}</span>@endif
                </div>
            @endif
        </div>
        <flux:icon.arrows-right-left class="size-3 shrink-0 text-zinc-400 group-hover:text-zinc-700 dark:group-hover:text-zinc-200" />
    </button>

    <flux:modal name="global-proyek-picker" class="md:w-lg">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Pilih Proyek Aktif') }}</flux:heading>

            @if ($proyekList->isEmpty())
                <div class="py-8 text-center">
                    <flux:icon.home-modern class="mx-auto size-12 text-zinc-400" />
                    <flux:heading class="mt-3">{{ __('Belum ada proyek') }}</flux:heading>
                    <flux:subheading>{{ __('Buat proyek dulu.') }}</flux:subheading>
                    <flux:button :href="route('master.proyek.index')" wire:navigate
                                 variant="primary" icon="plus" class="mt-4">
                        {{ __('Buat Proyek') }}
                    </flux:button>
                </div>
            @else
                <div class="-mx-2 max-h-96 space-y-1 overflow-y-auto px-2">
                    @foreach ($proyekList as $p)
                        <button type="button" wire:click="selectProyek({{ $p->id }})"
                                @class([
                                    'flex w-full items-center justify-between rounded-lg border px-4 py-3 text-start transition',
                                    'border-zinc-200 hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-500 dark:hover:bg-zinc-800' => $activeProyekId != $p->id,
                                    'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-950/30' => $activeProyekId == $p->id,
                                ])>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium text-zinc-900 dark:text-white">{{ $p->nama_proyek }}</div>
                                <div class="truncate text-xs text-zinc-500">
                                    {{ $p->nama_perumahan }}@if ($p->kota_kabupaten) <span class="text-zinc-400">· {{ $p->kota_kabupaten }}</span>@endif
                                </div>
                            </div>
                            @if ($activeProyekId == $p->id)
                                <flux:icon.check-circle class="ms-3 size-5 shrink-0 text-blue-600 dark:text-blue-400" />
                            @else
                                <flux:icon.chevron-right class="ms-3 size-4 shrink-0 text-zinc-400" />
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </flux:modal>
</div>
