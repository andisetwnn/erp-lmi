@props(['modul'])

{{-- Buka panduan PDF modul ini di tab baru; tidak tampil kalau panduannya belum ada atau orang ini tidak boleh membukanya. --}}
@if (\App\Support\Panduan::bolehLihat(auth()->user(), $modul))
    <flux:button size="sm" variant="outline" icon="book-open" :href="route('panduan.buka', $modul)" target="_blank" {{ $attributes }}>
        {{ __('Panduan') }}
    </flux:button>
@endif
