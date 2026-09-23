<?php

use App\Models\Matrix\MatrixImport;
use App\Models\Matrix\MatrixUnit;
use App\Services\MatrixImportService;
use App\Services\MatrixSinkronPemberkasan;
use App\Services\MatrixSinkronTeknik;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Unggah Matrix')] class extends Component {
    use WithFileUploads;

    public $berkas = null;

    /**
     * Sengaja mati secara bawaan: unggah yang diam-diam mengubah master data
     * susah dilacak kalau ada yang meleset. Menyalakannya harus jadi pilihan sadar.
     */
    public bool $sinkronTeknik = false;

    /**
     * Terpisah dari saklar teknik: pemberkasan sudah dipakai Admin KPR
     * sehari-hari, jadi keputusannya berbeda dan harus bisa dipilih sendiri.
     */
    public bool $sinkronPemberkasan = false;

    public ?int $hapusId = null;

    public function unggah(): void
    {
        $this->validate([
            'berkas' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
        ], [], ['berkas' => 'berkas Matrix']);

        try {
            $import = app(MatrixImportService::class)->impor(
                $this->berkas->getRealPath(),
                $this->berkas->getClientOriginalName(),
                Auth::id(),
            );
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', heading: __('Gagal dibaca'), text: $e->getMessage());

            return;
        }

        $this->reset('berkas');

        $pesan = __(':jumlah unit terbaca dari :file.', [
            'jumlah' => number_format($import->jumlah_baris, 0, ',', '.'),
            'file' => $import->nama_file,
        ]);

        if ($this->sinkronTeknik) {
            $hasil = app(MatrixSinkronTeknik::class)->jalankan($import, Auth::id());

            $pesan .= ' '.__('Data teknik: :unit unit diperbarui (:progres progres, :lot LOT, :subcon subcon).', [
                'unit' => $hasil['diperbarui'],
                'progres' => $hasil['progres'],
                'lot' => $hasil['lot'],
                'subcon' => $hasil['subcon'],
            ]);

            if ($hasil['subcon_baru'] > 0) {
                $pesan .= ' '.__(':jumlah subcon baru didaftarkan dari berkas.', ['jumlah' => $hasil['subcon_baru']]);
            }

            if ($hasil['tanpa_unit'] > 0) {
                $pesan .= ' '.__(':jumlah unit di berkas belum ada di master rumah.', [
                    'jumlah' => $hasil['tanpa_unit'],
                ]);
            }
        }

        if ($this->sinkronPemberkasan) {
            $berkas = app(MatrixSinkronPemberkasan::class)->jalankan($import, Auth::id());

            $pesan .= ' '.__('Pemberkasan: :unit SPR terisi (:kolom kolom, :baru berkas baru).', [
                'unit' => $berkas['diperbarui'],
                'kolom' => $berkas['kolom'],
                'baru' => $berkas['baru'],
            ]);
        }

        Flux::toast(variant: 'success', heading: __('Matrix tersimpan'), text: $pesan);
    }

    public function konfirmasiHapus(int $id): void
    {
        $this->hapusId = $id;
        Flux::modal('hapus-matrix')->show();
    }

    public function hapus(): void
    {
        $import = MatrixImport::find($this->hapusId);

        if (! $import) {
            return;
        }

        $import->delete();
        $this->hapusId = null;

        Flux::modal('hapus-matrix')->close();
        Flux::toast(variant: 'success', heading: __('Terhapus'), text: __('Unggahan Matrix dihapus.'));
    }

    public function with(): array
    {
        $terakhir = MatrixImport::with('uploadedBy')->latest('id')->first();

        return [
            'terakhir' => $terakhir,
            'rincian' => $terakhir
                ? MatrixUnit::where('matrix_import_id', $terakhir->id)
                    ->selectRaw('kategori, COUNT(*) as jumlah')
                    ->groupBy('kategori')
                    ->pluck('jumlah', 'kategori')
                    ->all()
                : [],
            'riwayat' => MatrixImport::with('uploadedBy')->latest('id')->take(10)->get(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-violet-500 to-violet-700 text-white shadow-sm">
                <flux:icon.arrow-up-tray class="size-6" />
            </div>
            <div>
                <flux:heading size="xl">{{ __('Unggah Matrix') }}</flux:heading>
                <flux:subheading>{{ __('Sumber data Laporan Mikro, Makro, dan Non Lot') }}</flux:subheading>
            </div>
        </div>

        <div class="flex flex-col gap-4">

            {{-- ===== KEADAAN SEKARANG ===== --}}
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Yang sedang dipakai laporan') }}</h3>
                </div>

                @if ($terakhir)
                    <div class="grid grid-cols-2 gap-x-6 gap-y-4 px-5 py-4 sm:grid-cols-4">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Proyek') }}</div>
                            <div class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">{{ $terakhir->proyek ?: '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Minggu') }}</div>
                            <div class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">{{ $terakhir->minggu_ke ?: '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Periode') }}</div>
                            <div class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">{{ $terakhir->periode ?: '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Total unit') }}</div>
                            <div class="mt-0.5 font-mono text-sm font-bold tabular-nums text-zinc-900 dark:text-white">
                                {{ number_format($terakhir->jumlah_baris, 0, ',', '.') }}
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-px border-t border-zinc-200 bg-zinc-200 sm:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-700">
                        @foreach (App\Models\Matrix\MatrixUnit::LABEL_KATEGORI as $kode => $label)
                            <div class="bg-white px-5 py-3 dark:bg-zinc-900">
                                <div class="font-mono text-xl font-bold tabular-nums text-violet-700 dark:text-violet-400">
                                    {{ number_format($rincian[$kode] ?? 0, 0, ',', '.') }}
                                </div>
                                <div class="text-xs text-zinc-600 dark:text-zinc-400">{{ $label }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="border-t border-zinc-200 px-5 py-2.5 text-xs text-zinc-500 dark:border-zinc-700">
                        {{ $terakhir->nama_file }} · {{ __('diunggah') }}
                        {{ $terakhir->created_at->translatedFormat('d M Y, H:i') }}
                        @if ($terakhir->uploadedBy)
                            {{ __('oleh') }} {{ $terakhir->uploadedBy->name }}
                        @endif
                    </div>
                @else
                    <p class="px-5 py-8 text-center text-sm italic text-zinc-400">
                        {{ __('Belum ada berkas Matrix yang diunggah.') }}
                    </p>
                @endif
            </div>

            {{-- ===== FORM UNGGAH ===== --}}
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Unggah berkas baru') }}</h3>
                </div>

                <form wire:submit="unggah" class="flex flex-col gap-4 px-5 py-4">
                    <div>
                        <flux:input type="file" wire:model="berkas" accept=".xlsx,.xls"
                                    :label="__('Berkas Matrix (XLSX, maksimal 20 MB)')" />
                        <div wire:loading wire:target="berkas" class="mt-1.5 text-xs text-zinc-500">
                            {{ __('Mengunggah berkas…') }}
                        </div>
                    </div>

                    <div class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2.5 text-xs leading-relaxed text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300">
                        {{ __('Yang dibaca hanya lima sheet:') }}
                        <b>MIKRO</b>, <b>MAKRO A</b>, <b>MAKRO B</b>, <b>NON LOT</b>, {{ __('dan') }} <b>SUDAH AKAD</b>.
                        {{ __('Sheet lain seperti Rekap, Realisasi Akad, dan FU UM diabaikan, jadi berkas asli dari Admin KPR bisa diunggah apa adanya tanpa dirapikan dulu.') }}
                        {{ __('Laporan selalu membaca unggahan paling akhir, jadi berkas ini akan langsung menggantikan yang sekarang dipakai — unggahan lama tetap tersimpan di riwayat.') }}
                    </div>

                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <flux:switch wire:model.live="sinkronTeknik"
                                     :label="__('Sekalian perbarui data teknik')" align="left" />
                        <p class="mt-1.5 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                            {{ __('Progres bangunan dan LOT di Data Rumah ikut disesuaikan dengan isi berkas ini. Unit yang kolomnya kosong di berkas tidak disentuh — kosong berarti belum diisi, bukan nol persen.') }}
                            <span class="block mt-1 text-zinc-500">
                                {{ __('Subcon ikut disesuaikan. Nama lengkapnya dibaca dari daftar singkatan di kepala berkas, dan yang belum terdaftar akan ditambahkan ke master subcon.') }}
                            </span>
                        </p>
                    </div>

                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <flux:switch wire:model.live="sinkronPemberkasan"
                                     :label="__('Sekalian perbarui pemberkasan')" align="left" />
                        <p class="mt-1.5 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                            {{ __('Tanggal BM, WCR, SP3K, LPA, Rencana Akad, dan kode bank diisikan ke modul Pemberkasan. Hanya kolom yang masih kosong — tanggal yang sudah diinput Admin KPR tidak tergeser.') }}
                            <span class="block mt-1 text-zinc-500">
                                {{ __('Masa berlaku SP3K tetap dihitung sistem (SP3K + 90 hari), tidak diambil dari berkas.') }}
                            </span>
                        </p>
                    </div>

                    <div>
                        <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled">
                            {{ __('Unggah') }}
                        </flux:button>
                    </div>
                </form>
            </div>

            {{-- ===== RIWAYAT ===== --}}
            @if ($riwayat->isNotEmpty())
                <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Riwayat unggahan') }}</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-zinc-50 text-[10px] uppercase tracking-wider text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-5 py-2.5 text-left font-bold">{{ __('Berkas') }}</th>
                                    <th class="px-3 py-2.5 text-left font-bold">{{ __('Minggu') }}</th>
                                    <th class="px-3 py-2.5 text-right font-bold">{{ __('Unit') }}</th>
                                    <th class="px-3 py-2.5 text-left font-bold">{{ __('Diunggah') }}</th>
                                    <th class="px-5 py-2.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($riwayat as $i => $r)
                                    <tr class="{{ $i === 0 ? 'bg-violet-50/50 dark:bg-violet-950/20' : '' }}">
                                        <td class="px-5 py-2.5">
                                            <div class="font-medium text-zinc-900 dark:text-white">{{ $r->nama_file }}</div>
                                            @if ($i === 0)
                                                <flux:badge size="sm" color="violet">{{ __('Dipakai laporan') }}</flux:badge>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-zinc-600 dark:text-zinc-400">{{ $r->minggu_ke ?: '—' }}</td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums">
                                            {{ number_format($r->jumlah_baris, 0, ',', '.') }}
                                        </td>
                                        <td class="px-3 py-2.5 text-xs text-zinc-500">
                                            {{ $r->created_at->translatedFormat('d M Y, H:i') }}
                                            @if ($r->uploadedBy)
                                                <div>{{ $r->uploadedBy->name }}</div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-2.5 text-right">
                                            @php $riwayatId = $r->id; @endphp
                                            <flux:button size="xs" variant="subtle" icon="trash"
                                                         wire:click="konfirmasiHapus({{ $riwayatId }})">
                                                {{ __('Hapus') }}
                                            </flux:button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- MODAL HAPUS --}}
    <flux:modal name="hapus-matrix" class="max-w-md">
        <div class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Hapus unggahan ini?') }}</flux:heading>
                <flux:subheading>
                    {{ __('Seluruh baris unit dari unggahan tersebut ikut terhapus. Kalau ini unggahan terakhir, laporan akan kembali memakai unggahan sebelumnya.') }}
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="hapus">{{ __('Ya, hapus') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
