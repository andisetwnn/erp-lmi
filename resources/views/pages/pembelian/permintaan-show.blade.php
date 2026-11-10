<?php

use App\Models\Master\PermintaanPembelian;
use App\Services\PembelianService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detail Permintaan Pembelian')] class extends Component
{
    public int $permintaanId;

    /** Aksi yang sedang dikonfirmasi di modal keputusan. */
    public ?string $aksi = null;

    public string $alasan = '';

    /** aksi => [izin, status asal, judul, butuh alasan?] */
    private const AKSI = [
        'setujui-fat' => ['pembelian.review', 'diajukan', 'Setujui PR ini?', false],
        'kembalikan' => ['pembelian.review', 'diajukan', 'Kembalikan PR ke peminta untuk direvisi?', true],
        'tolak-fat' => ['pembelian.review', 'diajukan', 'Tolak PR ini?', true],
        'setujui-anggaran' => ['pembelian.anggaran', 'ditinjau', 'Setujui PR ini sebagai direktur?', false],
        'tolak-anggaran' => ['pembelian.anggaran', 'ditinjau', 'Tolak PR ini sebagai direktur?', true],
        'teruskan-direktur' => ['pembelian.po', 'disetujui', 'Teruskan PR ini ke direktur untuk disetujui?', false],
    ];

    public function mount(int $id): void
    {
        $pr = PermintaanPembelian::findOrFail($id);
        abort_unless($this->bolehMelihat($pr), 403);
        $this->permintaanId = $pr->id;
    }

    #[Computed]
    public function pr(): PermintaanPembelian
    {
        return PermintaanPembelian::with([
            'item', 'proyek:id,nama_proyek', 'createdBy:id,name', 'diajukanBy:id,name', 'ditinjauBy:id,name',
            'disetujuiBy:id,name', 'ditolakBy:id,name', 'pesanan.supplier:id,nama', 'item.itemPesanan.pesanan:id,status',
        ])->findOrFail($this->permintaanId);
    }

    private function bolehMelihat(PermintaanPembelian $pr): bool
    {
        $user = Auth::user();

        return $pr->created_by_user_id === $user->id
            || $user->canAny(['pembelian.review', 'pembelian.anggaran', 'pembelian.po', 'pembelian.lihat']);
    }

    private function milikSaya(): bool
    {
        return $this->pr->created_by_user_id === Auth::id() && Auth::user()->can('pembelian.minta');
    }

    public function ajukan(PembelianService $layanan): void
    {
        abort_unless($this->milikSaya(), 403);

        try {
            $layanan->ajukan($this->pr, Auth::id());
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());

            return;
        }

        unset($this->pr);
        Flux::toast(variant: 'success', text: 'PR diajukan ke Keuangan (FAT) untuk direview.');
    }

    public function openAksi(string $aksi): void
    {
        abort_unless(isset(self::AKSI[$aksi]) && Auth::user()->can(self::AKSI[$aksi][0]), 403);

        $this->aksi = $aksi;
        $this->alasan = '';
        $this->resetErrorBag();
        Flux::modal('keputusan-pr')->show();
    }

    public function jalankanAksi(PembelianService $layanan): void
    {
        $aksi = $this->aksi;
        abort_unless($aksi && isset(self::AKSI[$aksi]) && Auth::user()->can(self::AKSI[$aksi][0]), 403);

        if (self::AKSI[$aksi][3]) {
            $this->validate(['alasan' => ['required', 'string', 'max:1000']], [], ['alasan' => 'alasan']);
        }

        try {
            $hasil = match ($aksi) {
                'setujui-fat' => $layanan->tinjau($this->pr, 'setujui', null, Auth::id()),
                'kembalikan' => $layanan->tinjau($this->pr, 'kembalikan', $this->alasan, Auth::id()),
                'tolak-fat' => $layanan->tinjau($this->pr, 'tolak', $this->alasan, Auth::id()),
                'setujui-anggaran' => $layanan->putuskanAnggaran($this->pr, 'setujui', null, Auth::id()),
                'tolak-anggaran' => $layanan->putuskanAnggaran($this->pr, 'tolak', $this->alasan, Auth::id()),
                'teruskan-direktur' => $layanan->teruskanKeDirektur($this->pr),
            };
        } catch (ValidationException $e) {
            Flux::modal('keputusan-pr')->close();
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());

            return;
        }

        Flux::modal('keputusan-pr')->close();
        unset($this->pr);
        Flux::toast(variant: 'success', text: match (true) {
            $aksi === 'setujui-fat' && $hasil->status === 'ditinjau' => 'PR disetujui FAT. Nilainya di atas Rp '.number_format($layanan->batasPersetujuanDirektur(), 0, ',', '.').', jadi diteruskan ke direktur.',
            $aksi === 'setujui-fat', $aksi === 'setujui-anggaran' => 'PR disetujui. PO sekarang bisa diterbitkan Keuangan.',
            $aksi === 'kembalikan' => 'PR dikembalikan ke peminta untuk direvisi.',
            $aksi === 'teruskan-direktur' => 'PR diteruskan ke direktur untuk disetujui.',
            default => 'PR ditolak.',
        });
        $this->aksi = null;
    }

    public function unduhLampiran()
    {
        $pr = $this->pr;
        abort_unless($this->bolehMelihat($pr) && $pr->lampiran_path && Storage::disk('private')->exists($pr->lampiran_path), 404);

        return Storage::disk('private')->download($pr->lampiran_path, $pr->lampiran_nama ?: basename($pr->lampiran_path));
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'milikSaya' => $this->milikSaya(),
            'bolehReview' => $user->can('pembelian.review'),
            'bolehAnggaran' => $user->can('pembelian.anggaran'),
            'bolehPo' => $user->can('pembelian.po'),
            'batas' => app(PembelianService::class)->batasPersetujuanDirektur(),
            'infoAksi' => $this->aksi ? self::AKSI[$this->aksi] : null,
        ];
    }
}; ?>

<section class="w-full">
    @php $pr = $this->pr; @endphp
    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('pembelian.permintaan.index')" wire:navigate />
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="xl" class="font-mono">{{ $pr->nomor }}</flux:heading>
                        <flux:badge :color="$pr->warnaStatus()">{{ $pr->labelStatus() }}</flux:badge>
                    </div>
                    <flux:subheading>{{ __('Permintaan pembelian untuk') }} {{ $pr->namaLokasi() }} · {{ $pr->departemen }}</flux:subheading>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($pr->status === 'draft' && $milikSaya)
                    <flux:button icon="pencil-square" :href="route('pembelian.permintaan.edit', $pr->id)" wire:navigate>{{ __('Ubah') }}</flux:button>
                    <flux:button variant="primary" icon="paper-airplane" wire:click="ajukan">{{ __('Ajukan ke FAT') }}</flux:button>
                @endif
                @if ($pr->status === 'diajukan' && $bolehReview)
                    <flux:button variant="danger" wire:click="openAksi('tolak-fat')">{{ __('Tolak') }}</flux:button>
                    <flux:button wire:click="openAksi('kembalikan')">{{ __('Kembalikan') }}</flux:button>
                    <flux:button variant="primary" icon="check" wire:click="openAksi('setujui-fat')">{{ __('Setujui') }}</flux:button>
                @endif
                @if ($pr->status === 'ditinjau' && $bolehAnggaran)
                    <flux:button variant="danger" wire:click="openAksi('tolak-anggaran')">{{ __('Tolak') }}</flux:button>
                    <flux:button variant="primary" icon="check" wire:click="openAksi('setujui-anggaran')">{{ __('Setujui') }}</flux:button>
                @endif
                @if ($pr->status === 'disetujui' && $bolehPo && ! $pr->masihBisaDipesan())
                    <span class="self-center text-sm text-zinc-500">{{ __('Semua barang sudah dipesan') }}</span>
                @elseif ($pr->status === 'disetujui' && $bolehPo)
                    @unless ($pr->disetujuiDirektur())
                        <flux:button icon="arrow-up-right" wire:click="openAksi('teruskan-direktur')">{{ __('Teruskan ke direktur') }}</flux:button>
                    @endunless
                    <flux:button variant="primary" icon="document-plus" :href="route('pembelian.po.create', $pr->id)" wire:navigate>{{ __('Terbitkan PO') }}</flux:button>
                @endif
            </div>
        </div>

        @if ($pr->alasan)
            <div @class([
                'mb-4 rounded-lg border p-4 text-sm',
                'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200' => $pr->status === 'ditolak',
                'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200' => $pr->status !== 'ditolak',
            ])>
                <div class="font-semibold">{{ $pr->status === 'ditolak' ? __('Alasan ditolak') : __('Dikembalikan untuk revisi') }}</div>
                <div class="mt-1" style="white-space: pre-line">{{ $pr->alasan }}</div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Tanggal') }}</dt><dd class="mt-1">{{ $pr->tanggal->translatedFormat('d F Y') }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Dibutuhkan') }}</dt><dd class="mt-1">{{ $pr->tanggal_dibutuhkan?->translatedFormat('d F Y') ?? '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Keperluan') }}</dt><dd class="mt-1" style="white-space: pre-line">{{ $pr->keperluan }}</dd></div>
                        @if ($pr->lampiran_path)
                            <div class="sm:col-span-2">
                                <dt class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Lampiran') }}</dt>
                                <dd class="mt-1"><flux:button size="sm" icon="paper-clip" wire:click="unduhLampiran">{{ $pr->lampiran_nama ?: __('Unduh') }}</flux:button></dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                                <th class="px-4 py-3">{{ __('Barang') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Jumlah') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Harga perkiraan') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Subtotal') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($pr->item as $i)
                                <tr wire:key="item-{{ $i->id }}">
                                    <td class="px-4 py-3">
                                        <div class="font-medium">{{ $i->nama_barang }}</div>
                                        @if ($i->spesifikasi)<div class="text-xs text-zinc-500">{{ $i->spesifikasi }}</div>@endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $i->jumlah, 2, ',', '.'), '0'), ',') }} {{ $i->satuan }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono tabular-nums">{{ number_format((float) $i->harga_perkiraan, 0, ',', '.') }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono tabular-nums">{{ number_format($i->subtotal(), 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <td colspan="3" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ __('Total perkiraan') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-semibold tabular-nums">Rp {{ number_format($pr->totalPerkiraan(), 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if ($pr->pesanan->isNotEmpty())
                    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700"><flux:heading size="lg">{{ __('Purchase Order') }}</flux:heading></div>
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($pr->pesanan as $po)
                                <a href="{{ route('pembelian.po.show', $po->id) }}" wire:navigate wire:key="po-{{ $po->id }}" class="flex items-center justify-between px-5 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <div>
                                        <div class="font-mono font-semibold text-orange-700 dark:text-orange-300">{{ $po->nomor }}</div>
                                        <div class="text-xs text-zinc-500">{{ $po->supplier?->nama }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-mono tabular-nums">Rp {{ number_format((float) $po->total, 0, ',', '.') }}</div>
                                        <div class="text-xs text-zinc-500">{{ $po->labelStatus() }}</div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" class="mb-4">{{ __('Riwayat') }}</flux:heading>
                @php
                    $riwayat = array_filter([
                        ['Dibuat', $pr->created_at, $pr->createdBy?->name],
                        $pr->diajukan_at ? ['Diajukan ke FAT', $pr->diajukan_at, $pr->diajukanBy?->name] : null,
                        $pr->ditinjau_at ? ['Disetujui FAT', $pr->ditinjau_at, $pr->ditinjauBy?->name] : null,
                        $pr->disetujui_at ? ['Disetujui direktur', $pr->disetujui_at, $pr->disetujuiBy?->name] : null,
                        $pr->ditolak_at ? ['Ditolak', $pr->ditolak_at, $pr->ditolakBy?->name] : null,
                    ]);
                @endphp
                <ol class="space-y-4">
                    @foreach ($riwayat as [$label, $waktu, $oleh])
                        <li class="flex gap-3 text-sm">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-orange-500"></span>
                            <div>
                                <div class="font-medium">{{ $label }}</div>
                                <div class="text-xs text-zinc-500">{{ $waktu?->translatedFormat('d M Y H:i') }}{{ $oleh ? ' · '.$oleh : '' }}</div>
                            </div>
                        </li>
                    @endforeach
                </ol>
                @php $batasRp = 'Rp '.number_format($batas, 0, ',', '.'); @endphp
                @if (in_array($pr->status, ['draft', 'diajukan', 'ditinjau'], true))
                    <p class="mt-5 border-t border-zinc-200 pt-4 text-xs text-zinc-500 dark:border-zinc-700">
                        {{ match ($pr->status) {
                            'draft' => __('Langkah berikutnya: peminta mengajukan PR ke FAT.'),
                            'diajukan' => __('Langkah berikutnya: Keuangan (FAT) mereview PR. Sampai :batas cukup disetujui FAT; di atasnya diteruskan ke direktur.', ['batas' => $batasRp]),
                            default => __('Langkah berikutnya: direktur menyetujui, karena nilainya di atas :batas.', ['batas' => $batasRp]),
                        } }}
                    </p>
                @elseif ($pr->status === 'disetujui' && ! $pr->disetujuiDirektur())
                    <p class="mt-5 border-t border-zinc-200 pt-4 text-xs text-zinc-500 dark:border-zinc-700">
                        {{ __('Cukup disetujui FAT. Total PO dari PR ini tidak boleh melewati :batas tanpa persetujuan direktur.', ['batas' => $batasRp]) }}
                    </p>
                @endif
            </div>
        </div>

        <flux:modal name="keputusan-pr" class="md:w-md">
            @if ($infoAksi)
                <form wire:submit="jalankanAksi" class="space-y-5">
                    <flux:heading size="lg">{{ $infoAksi[2] }}</flux:heading>
                    @if ($infoAksi[3])
                        <flux:field>
                            <flux:label>{{ __('Alasan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:textarea wire:model="alasan" rows="3" :placeholder="__('Akan terlihat oleh peminta.')" />
                            <flux:error name="alasan" />
                        </flux:field>
                    @endif
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button></flux:modal.close>
                        <flux:button :variant="str_starts_with($aksi ?? '', 'tolak') ? 'danger' : 'primary'" type="submit">{{ __('Ya, lanjutkan') }}</flux:button>
                    </div>
                </form>
            @endif
        </flux:modal>
    </div>
</section>
