{{--
    Pagination bar with per-page selector.
    Usage: @include('partials.per-page-pagination', ['paginator' => $rows])
    The Livewire component must have a public `$perPage` property bound by `wire:model.live="perPage"`.
--}}
<div class="mt-3 flex flex-wrap items-center justify-between gap-3 overflow-hidden">
    <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
        <span>{{ __('Tampilkan') }}</span>
        <flux:select wire:model.live="perPage" size="sm" class="w-auto">
            <flux:select.option value="10">10</flux:select.option>
            <flux:select.option value="20">20</flux:select.option>
            <flux:select.option value="50">50</flux:select.option>
            <flux:select.option value="all">{{ __('Semua') }}</flux:select.option>
        </flux:select>
        <span>{{ __('per halaman') }}</span>
        @if ($paginator->total() > 0)
            <span class="ms-2">
                {{ __('Total :total data', ['total' => number_format($paginator->total(), 0, ',', '.')]) }}
            </span>
        @endif
    </div>
    <div class="min-w-0 max-w-full overflow-hidden">
        <flux:pagination :paginator="$paginator" />
    </div>
</div>
