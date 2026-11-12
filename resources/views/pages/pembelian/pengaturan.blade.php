<?php

use App\Models\Master\Perusahaan;
use App\Services\PembelianService;
use App\Support\BusinessActivityLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pengaturan Pembelian (setara "Setting PO" di DDM). Batas persetujuan adalah
 * batas wewenang FAT, jadi hanya direktur yang mengubahnya.
 */
new #[Title('Pengaturan Pembelian')] class extends Component
{
    public string $batas_persetujuan_direktur = '';

    /** Kosong = batal otomatis tidak aktif. */
    public string $batas_po_gantung_hari = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('pembelian.anggaran'), 403);

        $perusahaan = Perusahaan::firstOrFail();
        $this->batas_persetujuan_direktur = (string) (int) $perusahaan->batas_persetujuan_direktur;
        $this->batas_po_gantung_hari = $perusahaan->batas_po_gantung_hari ? (string) $perusahaan->batas_po_gantung_hari : '';
    }

    public function simpanBatasPersetujuan(): void
    {
        abort_unless(Auth::user()->can('pembelian.anggaran'), 403);

        $data = $this->validate([
            'batas_persetujuan_direktur' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
        ], [], ['batas_persetujuan_direktur' => 'batas persetujuan direktur']);

        $perusahaan = Perusahaan::firstOrFail();
        $lama = (float) $perusahaan->batas_persetujuan_direktur;
        $baru = (float) $data['batas_persetujuan_direktur'];
        $perusahaan->update(['batas_persetujuan_direktur' => $baru, 'updated_by_user_id' => Auth::id()]);

        if ($lama !== $baru) {
            BusinessActivityLogger::pembelian(
                'pengaturan_pembelian.diubah',
                'Batas persetujuan direktur diubah: Rp '.number_format($lama, 0, ',', '.').' → Rp '.number_format($baru, 0, ',', '.'),
                ['pengaturan' => 'batas_persetujuan_direktur', 'lama' => $lama, 'baru' => $baru],
            );
        }

        Flux::toast(variant: 'success', text: 'Batas persetujuan direktur disimpan: Rp '.number_format((float) $data['batas_persetujuan_direktur'], 0, ',', '.').'.');
    }

    public function simpanPoGantung(): void
    {
        abort_unless(Auth::user()->can('pembelian.anggaran'), 403);

        $this->batas_po_gantung_hari = trim($this->batas_po_gantung_hari);
        $this->validate([
            'batas_po_gantung_hari' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ], [], ['batas_po_gantung_hari' => 'batas hari']);

        $hari = $this->batas_po_gantung_hari === '' ? null : (int) $this->batas_po_gantung_hari;
        $perusahaan = Perusahaan::firstOrFail();
        $lama = $perusahaan->batas_po_gantung_hari;
        $perusahaan->update(['batas_po_gantung_hari' => $hari, 'updated_by_user_id' => Auth::id()]);

        if ($lama !== $hari) {
            $teks = fn (?int $n) => $n ? "{$n} hari" : 'tidak aktif';
            BusinessActivityLogger::pembelian(
                'pengaturan_pembelian.diubah',
                'Batal otomatis PO gantung diubah: '.$teks($lama).' → '.$teks($hari),
                ['pengaturan' => 'batas_po_gantung_hari', 'lama' => $lama, 'baru' => $hari],
            );
        }

        Flux::toast(variant: 'success', text: $hari
            ? "PO yang lewat {$hari} hari tanpa pembayaran akan dibatalkan otomatis."
            : 'Batal otomatis PO gantung dimatikan.');
    }

    public function with(): array
    {
        $pembelian = app(PembelianService::class);
        $hari = $pembelian->batasPoGantungHari();

        return [
            'hariTersimpan' => $hari,
            'poGantung' => $hari
                ? $pembelian->poGantung($hari)->with('supplier:id,nama')->orderBy('tanggal')->get(['id', 'nomor', 'tanggal', 'supplier_id', 'total'])
                : collect(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-orange-500 to-orange-700 text-white shadow-sm">
                <flux:icon.cog-6-tooth class="size-6" />
            </div>
            <div>
                <flux:heading size="xl">{{ __('Pengaturan Pembelian') }}</flux:heading>
                <flux:subheading>{{ __('Berlaku untuk semua PR dan PO.') }}</flux:subheading>
            </div>
            <x-tombol-panduan modul="pembelian" class="ml-auto self-start" />
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">{{ __('Batas persetujuan direktur') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('PR sampai nilai ini cukup disetujui FAT; di atasnya wajib disetujui direktur juga. Total PO dari PR yang hanya disetujui FAT juga tidak boleh melewati batas ini.') }}
                </flux:text>
                <form wire:submit="simpanBatasPersetujuan" class="mt-4 flex items-start gap-2">
                    <div class="min-w-0 grow">
                        <x-money-input wire="batas_persetujuan_direktur" prefix="Rp" required />
                        <flux:error name="batas_persetujuan_direktur" />
                    </div>
                    <flux:button type="submit" variant="primary">{{ __('Simpan') }}</flux:button>
                </form>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">{{ __('Batal otomatis PO gantung') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('PO yang sudah terbit tapi tidak dibayar sama sekali lebih lama dari batas ini dibatalkan otomatis, dicek tiap hari pukul 01.00. PO yang sudah dibayar sebagian atau sudah ada barang datang tidak ikut. Kosongkan untuk mematikan.') }}
                </flux:text>
                <form wire:submit="simpanPoGantung" class="mt-4 flex items-start gap-2">
                    <div class="min-w-0 grow">
                        <flux:input type="number" min="1" max="3650" wire:model="batas_po_gantung_hari" :placeholder="__('Kosong = tidak aktif')" />
                        <flux:error name="batas_po_gantung_hari" />
                    </div>
                    <span class="py-2 text-sm text-zinc-500">{{ __('hari') }}</span>
                    <flux:button type="submit" variant="primary">{{ __('Simpan') }}</flux:button>
                </form>

                <div class="mt-4 border-t border-zinc-100 pt-3 text-sm dark:border-zinc-800" data-status="po-gantung">
                    @if (! $hariTersimpan)
                        <p class="text-zinc-500">{{ __('Tidak aktif — tidak ada PO yang dibatalkan otomatis.') }}</p>
                    @elseif ($poGantung->isEmpty())
                        <p class="text-zinc-600 dark:text-zinc-400">{{ __('Aktif. Saat ini tidak ada PO yang lewat :n hari tanpa pembayaran.', ['n' => $hariTersimpan]) }}</p>
                    @else
                        <p class="font-medium text-amber-700 dark:text-amber-400">
                            {{ __(':jumlah PO sudah lewat :n hari tanpa pembayaran dan akan dibatalkan pada pengecekan berikutnya:', ['jumlah' => $poGantung->count(), 'n' => $hariTersimpan]) }}
                        </p>
                        <ul class="mt-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($poGantung as $po)
                                <li wire:key="gantung-{{ $po->id }}" class="flex flex-wrap items-baseline gap-x-3 py-1.5">
                                    <a href="{{ route('pembelian.po.show', $po->id) }}" wire:navigate class="font-mono font-semibold text-orange-700 hover:underline dark:text-orange-300">{{ $po->nomor }}</a>
                                    <span class="text-zinc-600 dark:text-zinc-400">{{ $po->supplier?->nama }}</span>
                                    <span class="text-xs text-zinc-500">{{ $po->tanggal?->translatedFormat('d M Y') }}</span>
                                    <span class="ml-auto font-mono tabular-nums">Rp {{ number_format((float) $po->total, 0, ',', '.') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
