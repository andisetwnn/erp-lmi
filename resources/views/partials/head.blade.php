<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/images/logo.png" type="image/png">
<link rel="apple-touch-icon" href="/images/logo.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

{{-- Flatpickr — date picker dengan locale Indonesia --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/id.js"></script>

<script>
    // Force light mode — abaikan preference OS / localStorage.
    (function () {
        try { localStorage.setItem('flux.appearance', 'light'); } catch (e) {}
        document.documentElement.classList.remove('dark');
        document.documentElement.classList.add('light');
        document.documentElement.style.colorScheme = 'light';
    })();
</script>

<script>
/**
 * Auto-toast + auto-scroll saat validasi Livewire gagal setelah user klik tombol action.
 * Trigger: setelah setiap Livewire commit yang memicu method call (bukan property update biasa).
 * Deteksi: scan `[data-flux-error]:not(.hidden)` yang punya isi teks.
 */
document.addEventListener('livewire:init', () => {
    if (! window.Livewire) return;

    Livewire.hook('commit', ({ commit, succeed }) => {
        // Hanya proses untuk action user (klik tombol yang panggil method).
        // Property update biasa (wire:model.live) skip supaya tidak spam toast tiap ketik.
        const isMethodCall = Array.isArray(commit.calls) && commit.calls.length > 0;
        if (! isMethodCall) return;

        succeed(() => {
            // Tunggu sebentar sampai DOM ter-morph dengan error state
            setTimeout(() => {
                const errorNodes = document.querySelectorAll('[data-flux-error]:not(.hidden)');
                const messages = [];
                errorNodes.forEach(node => {
                    const txt = node.textContent?.replace(/\s+/g, ' ').trim();
                    if (txt && txt.length > 2) messages.push(txt);
                });

                if (messages.length === 0) return;

                // Show Flux toast
                if (window.Flux && typeof Flux.toast === 'function') {
                    const uniq = [...new Set(messages)];
                    const preview = uniq.slice(0, 3).join(' · ') + (uniq.length > 3 ? ` +${uniq.length - 3} lagi` : '');
                    Flux.toast({
                        variant: 'danger',
                        heading: uniq.length === 1 ? 'Data belum lengkap' : `${uniq.length} data belum lengkap`,
                        text: preview,
                        duration: 6000,
                    });
                }

                // Scroll ke field error pertama (target: parent field container, bukan div error saja)
                const first = errorNodes[0];
                if (first) {
                    const container = first.closest('[data-flux-field]') || first.closest('.flux-field') || first;
                    container.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 100);
        });
    });
});
</script>
