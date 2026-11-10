<?php

use App\Models\Master\PermintaanPembelian;
use App\Models\Master\Supplier;
use App\Services\PembelianService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Terbitkan PO')] class extends Component
{
    public int $permintaanId;

    public string $supplierId = '';

    public string $tanggal = '';

    public ?string $tanggalKirim = null;

    public string $syaratBayar = 'Dibayar di muka sebelum barang dikirim';

    public bool $ppnTerpisah = false;

    public string $ppnPersen = '11';

    public ?string $catatan = null;

    /** @var list<array{permintaan_pembelian_item_id: int, nama_barang: string, spesifikasi: ?string, satuan: string, sisa: float, jumlah: string, harga: int}> */
    public array $baris = [];

    public function mount(int $id): void
    {
        abort_unless(Auth::user()->can('pembelian.po'), 403);

        $pr = PermintaanPembelian::with(['item.itemPesanan.pesanan'])->findOrFail($id);
        if ($pr->status !== 'disetujui') {
            Flux::toast(variant: 'warning', text: 'PO hanya bisa diterbitkan dari PR yang sudah disetujui.');
            $this->redirectRoute('pembelian.permintaan.show', $pr->id, navigate: true);

            return;
        }
        if (! $pr->masihBisaDipesan()) {
            Flux::toast(variant: 'warning', text: 'Semua barang di PR ini sudah dipesan.');
            $this->redirectRoute('pembelian.permintaan.show', $pr->id, navigate: true);

            return;
        }

        $this->permintaanId = $pr->id;
        $this->tanggal = now()->format('Y-m-d');
        $this->baris = $pr->item->map(function ($i) {
            $sisa = $i->sisaBelumDipesan();

            return [
                'permintaan_pembelian_item_id' => $i->id,
                'nama_barang' => $i->nama_barang,
                'spesifikasi' => $i->spesifikasi,
                'satuan' => $i->satuan,
                'sisa' => $sisa,
                'jumlah' => rtrim(rtrim(number_format($sisa, 2, '.', ''), '0'), '.'),
                'harga' => (int) $i->harga_perkiraan,
            ];
        })->all();
    }

    /** Supplier PKP biasanya mencantumkan PPN terpisah — jadikan pilihan awal, tetap bisa diubah. */
    public function updatedSupplierId(string $id): void
    {
        $this->ppnTerpisah = (bool) Supplier::whereKey($id)->value('is_pkp');
    }

    public function terbitkan(PembelianService $layanan): void
    {
        abort_unless(Auth::user()->can('pembelian.po'), 403);

        $this->validate([
            'supplierId' => ['required', 'exists:supplier,id'],
            'tanggal' => ['required', 'date'],
            'tanggalKirim' => ['nullable', 'date'],
            'syaratBayar' => ['nullable', 'string', 'max:255'],
            'ppnPersen' => ['required_if:ppnTerpisah,true', 'nullable', 'numeric', 'min:0', 'max:100'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'baris.*.jumlah' => ['nullable', 'numeric', 'min:0'],
            'baris.*.harga' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'supplierId' => 'supplier',
            'tanggalKirim' => 'tanggal kirim',
            'ppnPersen' => 'persen PPN',
            'baris.*.jumlah' => 'jumlah',
            'baris.*.harga' => 'harga',
        ]);

        foreach ($this->baris as $i => $b) {
            if ((float) $b['jumlah'] > $b['sisa'] + 0.005) {
                $this->addError("baris.$i.jumlah", "Melebihi sisa di PR ({$b['sisa']} {$b['satuan']}).");
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $po = $layanan->terbitkanPesanan(PermintaanPembelian::findOrFail($this->permintaanId), [
                'supplier_id' => (int) $this->supplierId,
                'tanggal' => $this->tanggal,
                'tanggal_kirim' => $this->tanggalKirim ?: null,
                'syarat_bayar' => $this->syaratBayar ?: null,
                'ppn_terpisah' => $this->ppnTerpisah,
                'ppn_persen' => (float) ($this->ppnPersen ?: 0),
                'catatan' => $this->catatan ?: null,
            ], array_map(fn ($b) => [
                'permintaan_pembelian_item_id' => $b['permintaan_pembelian_item_id'],
                'nama_barang' => $b['nama_barang'],
                'spesifikasi' => $b['spesifikasi'],
                'jumlah' => (float) ($b['jumlah'] ?: 0),
                'satuan' => $b['satuan'],
                'harga' => (float) ($b['harga'] ?: 0),
            ], $this->baris), Auth::id());
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());

            return;
        }

        Flux::toast(variant: 'success', text: "PO {$po->nomor} terbit. Langkah berikutnya: bayar supplier.");
        $this->redirectRoute('pembelian.po.show', $po->id, navigate: true);
    }

    public function with(): array
    {
        $subtotal = collect($this->baris)->sum(fn ($b) => (float) ($b['jumlah'] ?: 0) * (float) ($b['harga'] ?: 0));
        $ppn = $this->ppnTerpisah ? round($subtotal * (float) ($this->ppnPersen ?: 0) / 100) : 0;
        $pr = PermintaanPembelian::with('proyek:id,nama_proyek')->find($this->permintaanId);

        // PR yang cukup disetujui FAT: beri tahu sebelum disimpan bila total PO-nya melewati batas.
        $batas = app(PembelianService::class)->batasPersetujuanDirektur();
        $melewatiBatas = $pr && ! $pr->disetujuiDirektur()
            && (float) $pr->pesanan()->where('status', '!=', 'batal')->sum('total') + $subtotal + $ppn > $batas;

        return [
            'pr' => $pr,
            'supplierOptions' => Supplier::where('is_aktif', true)->orderBy('nama')->get(['id', 'nama', 'is_pkp']),
            'bolehKelolaSupplier' => Auth::user()->canAny(['master.supplier.kelola', 'master.kelola']),
            'subtotal' => $subtotal,
            'ppn' => $ppn,
            'total' => $subtotal + $ppn,
            'batas' => $batas,
            'melewatiBatas' => $melewatiBatas,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center gap-3">
            <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('pembelian.permintaan.show', $permintaanId)" wire:navigate />
            <div>
                <flux:heading size="xl">{{ __('Terbitkan PO') }}</flux:heading>
                <flux:subheading>{{ __('Dari') }} <span class="font-mono">{{ $pr?->nomor }}</span> · {{ $pr?->namaLokasi() }}</flux:subheading>
            </div>
        </div>

        <form wire:submit="terbitkan" class="space-y-6">
            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:field class="md:col-span-2">
                        <flux:label>{{ __('Supplier') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:select wire:model.live="supplierId">
                            <flux:select.option value="">{{ __('-- Pilih supplier --') }}</flux:select.option>
                            @foreach ($supplierOptions as $s)
                                <flux:select.option value="{{ $s->id }}">{{ $s->nama }}{{ $s->is_pkp ? ' (PKP)' : '' }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="supplierId" />
                        @if ($supplierOptions->isEmpty())
                            <flux:description>
                                {{ __('Belum ada supplier aktif.') }}
                                @if ($bolehKelolaSupplier)
                                    <a href="{{ route('master.supplier.index') }}" wire:navigate class="font-medium text-orange-700 underline">{{ __('Tambah di Master Supplier') }}</a>
                                @endif
                            </flux:description>
                        @endif
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Tanggal PO') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input type="date" wire:model="tanggal" required />
                        <flux:error name="tanggal" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Tanggal kirim') }}</flux:label>
                        <flux:input type="date" wire:model="tanggalKirim" />
                        <flux:error name="tanggalKirim" />
                    </flux:field>
                    <flux:field class="md:col-span-2">
                        <flux:label>{{ __('Syarat bayar') }}</flux:label>
                        <flux:input wire:model="syaratBayar" />
                        <flux:error name="syaratBayar" />
                    </flux:field>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                    <flux:heading size="lg">{{ __('Barang') }}</flux:heading>
                    <flux:subheading>{{ __('Isi 0 untuk barang yang tidak dipesan ke supplier ini.') }}</flux:subheading>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                                <th class="px-4 py-3">{{ __('Barang') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Sisa di PR') }}</th>
                                <th class="px-4 py-3">{{ __('Jumlah') }}</th>
                                <th class="px-4 py-3">{{ __('Harga final') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Subtotal') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($baris as $i => $b)
                                <tr wire:key="baris-{{ $b['permintaan_pembelian_item_id'] }}">
                                    <td class="px-4 py-3">
                                        <div class="font-medium">{{ $b['nama_barang'] }}</div>
                                        @if ($b['spesifikasi'])<div class="text-xs text-zinc-500">{{ $b['spesifikasi'] }}</div>@endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-zinc-500">{{ rtrim(rtrim(number_format($b['sisa'], 2, ',', '.'), '0'), ',') }} {{ $b['satuan'] }}</td>
                                    <td class="w-32 px-4 py-3">
                                        <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="baris.{{ $i }}.jumlah" />
                                        <flux:error name="baris.{{ $i }}.jumlah" />
                                    </td>
                                    <td class="w-44 px-4 py-3"><x-money-input :wire="'baris.'.$i.'.harga'" /></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono tabular-nums">{{ number_format((float) ($b['jumlah'] ?: 0) * (float) ($b['harga'] ?: 0), 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="space-y-2 border-t border-zinc-200 px-5 py-4 text-sm dark:border-zinc-700">
                    <div class="flex justify-end gap-6"><span class="text-zinc-500">{{ __('Subtotal') }}</span><span class="w-36 text-right font-mono tabular-nums">{{ number_format($subtotal, 0, ',', '.') }}</span></div>
                    <div class="flex items-center justify-end gap-3">
                        <flux:checkbox wire:model.live="ppnTerpisah" :label="__('PPN dicantumkan terpisah')" />
                        @if ($ppnTerpisah)
                            <div class="w-20"><flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="ppnPersen" size="sm" /></div><span class="text-zinc-500">%</span>
                        @endif
                        <span class="w-36 text-right font-mono tabular-nums">{{ number_format($ppn, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-end gap-6 border-t border-zinc-200 pt-2 dark:border-zinc-700"><span class="font-semibold">{{ __('Total PO') }}</span><span class="w-36 text-right font-mono font-semibold tabular-nums">Rp {{ number_format($total, 0, ',', '.') }}</span></div>
                    @if ($melewatiBatas)
                        <p class="text-right text-xs text-amber-700 dark:text-amber-300">
                            {{ __('Total PO dari PR ini melewati Rp :batas yang cukup disetujui FAT. Teruskan PR ke direktur dulu dari halaman PR.', ['batas' => number_format($batas, 0, ',', '.')]) }}
                        </p>
                    @endif
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:textarea wire:model="catatan" :label="__('Catatan untuk supplier')" rows="2" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" :href="route('pembelian.permintaan.show', $permintaanId)" wire:navigate>{{ __('Batal') }}</flux:button>
                <flux:button variant="primary" type="submit" icon="document-check">{{ __('Terbitkan PO') }}</flux:button>
            </div>
        </form>
    </div>
</section>
