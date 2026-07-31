<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Spr;
use App\Support\BusinessActivityLogger;
use App\Support\FileOptimizer;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('e-Materai')] class extends Component
{
    use Sortable, WithFileUploads, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'pp', except: 15)]
    public int $perPage = 15;

    public ?int $materaiSprId = null;

    public $materaiFile = null;

    protected function defaultSortBy(): ?string
    {
        return 'konsumen_signed_at';
    }

    protected function defaultSortDir(): string
    {
        return 'desc';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    protected function effectivePerPage(): int
    {
        return in_array($this->perPage, [10, 15, 25, 50, 100], true) ? $this->perPage : 15;
    }

    public function openMateraiUpload(int $sprId): void
    {
        $spr = Spr::findOrFail($sprId);
        if ($spr->status !== 'approved') {
            Flux::toast(variant: 'danger', text: 'SPR harus sudah verified UTJ (status approved) sebelum e-Materai.');
            return;
        }
        if (! $spr->pm_approved_at) {
            Flux::toast(variant: 'danger', text: 'SPR belum di-approve Project Manager.');
            return;
        }
        if (! $spr->konsumen_signed_at) {
            Flux::toast(
                variant: 'danger',
                heading: 'Konsumen belum tanda tangan',
                text: 'Materai baru boleh ditempel setelah konsumen tanda tangan. Kalau materai dulu, hash e-Materai Peruri akan invalid saat file dimodifikasi.',
            );
            return;
        }
        $this->materaiSprId = $spr->id;
        $this->materaiFile = null;
        $this->resetErrorBag();
        Flux::modal('upload-materai')->show();
    }

    public function submitMateraiUpload(): void
    {
        $this->validate([
            'materaiFile' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ], [
            'materaiFile.required' => 'Pilih file PDF ber-materai dulu.',
            'materaiFile.mimes' => 'Format harus PDF.',
            'materaiFile.max' => 'Ukuran maksimum 5 MB.',
        ]);

        $spr = Spr::findOrFail($this->materaiSprId);
        if ($spr->materai_stamped_at) {
            Flux::toast(variant: 'warning', text: 'Materai sudah pernah di-upload untuk SPR ini.');
            return;
        }

        $path = FileOptimizer::storeOptimized($this->materaiFile, 'spr-materai');

        $now = now();
        $spr->update([
            'materai_stamped_at' => $now,
            'materai_by_user_id' => Auth::id(),
            'materai_file_path' => $path,
            'spr_finalized_at' => $now,
        ]);

        $spr->load('prospectCustomer');
        BusinessActivityLogger::materaiStamped($spr);

        Flux::modal('upload-materai')->close();
        Flux::toast(variant: 'success', text: "Materai untuk SPR {$spr->nomor_display} berhasil di-upload. SPR final.");

        $this->reset(['materaiSprId', 'materaiFile']);
    }

    public function with(): array
    {
        $query = Spr::with([
            'prospectCustomer:id,nama_lengkap,hp,nik',
            'rumah:id,blok,nomor_unit,tipe_rumah_id,proyek_id',
            'rumah.proyek:id,nama_proyek',
            'sales:id,kode,nama',
            'pmApprovedBy:id,name',
        ])
            ->where('status', 'approved')
            ->whereNotNull('pm_approved_at')
            ->whereNotNull('konsumen_signed_at')
            ->whereNull('materai_stamped_at');

        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('nomor_spr', 'like', "%{$s}%")
                    ->orWhereHas('prospectCustomer', fn ($qq) => $qq->where('nama_lengkap', 'like', "%{$s}%")->orWhere('hp', 'like', "%{$s}%"));
            });
        }

        $this->applySort($query, [
            'nomor_spr',
            'tanggal_spr',
            'konsumen_signed_at',
            'pm_approved_at',
            'created_at',
            'nama_lengkap' => fn ($q, $dir) => $q
                ->leftJoin('prospect_customer as pc_sort', 'pc_sort.id', '=', 'spr.prospect_customer_id')
                ->orderBy('pc_sort.nama_lengkap', $dir)
                ->select('spr.*'),
        ]);

        $sprs = $query->paginate($this->effectivePerPage());

        return compact('sprs');
    }
}; ?>

@php
    $arrow = fn ($col, $active, $dir) => $active === $col ? ($dir === 'asc' ? '↑' : '↓') : '';
    $thBtn = function ($col, $label, $align = 'left') use ($sortBy, $sortDir, $arrow) {
        $active = $sortBy === $col;
        $alignClass = $align === 'right' ? 'justify-end text-right' : 'justify-start text-left';
        $colorClass = $active ? 'text-purple-600 dark:text-purple-400' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200';
        return '<button type="button" wire:click="sort(\''.$col.'\')" class="inline-flex items-center gap-1 w-full '.$alignClass.' '.$colorClass.' transition">'.
            e($label).' <span class="text-[9px]">'.$arrow($col, $sortBy, $sortDir).'</span></button>';
    };
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex items-start justify-between gap-3">
            <div>
                <flux:heading size="xl">{{ __('e-Materai') }}</flux:heading>
                <flux:subheading>{{ __('Finalisasi SPR — pembubuhan e-Materai pada dokumen yang sudah lengkap semua tanda tangan.') }}</flux:subheading>
            </div>
        </div>

        {{-- Info alur --}}
        <div class="mb-5 rounded-xl border border-purple-200 bg-purple-50/50 p-3 text-xs text-purple-900 dark:border-purple-900/50 dark:bg-purple-950/20 dark:text-purple-200">
            <flux:icon.information-circle class="-mt-0.5 mr-1 inline size-3.5" />
            <strong>{{ __('Alur:') }}</strong>
            {{ __('SPR di halaman ini sudah lengkap TTD Sales, Keuangan, Project Manager, dan Konsumen. Bubuhkan e-Materai di aplikasi Peruri, upload PDF ber-materai kembali → SPR selesai.') }}
        </div>

        {{-- SEARCH + PER PAGE --}}
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="relative min-w-75 flex-1">
                <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                <input type="search" wire:model.live.debounce.400ms="search"
                       placeholder="{{ __('Cari nomor SPR, nama customer, atau HP...') }}"
                       class="block h-10 w-full rounded-lg border border-zinc-200 bg-white pl-10 pr-3 text-sm shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
            </div>

            <div class="flex items-center gap-2">
                <label for="pp" class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Tampilkan') }}</label>
                <select id="pp" wire:model.live="perPage"
                        class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    @foreach ([10, 15, 25, 50, 100] as $pp)
                        <option value="{{ $pp }}">{{ $pp }} baris</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- TABLE --}}
        <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-left dark:border-zinc-700 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider">{!! $thBtn('nomor_spr', 'SPR') !!}</th>
                            <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider">{!! $thBtn('tanggal_spr', 'Tgl SPR') !!}</th>
                            <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider">Sales</th>
                            <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider">{!! $thBtn('nama_lengkap', 'Customer') !!}</th>
                            <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider">Proyek</th>
                            <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider">Blok</th>
                            <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider">{!! $thBtn('pm_approved_at', 'PM Approve') !!}</th>
                            <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider">{!! $thBtn('konsumen_signed_at', 'Konsumen TTD') !!}</th>
                            <th class="px-3 py-2 text-right text-[10px] font-bold uppercase tracking-wider">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($sprs as $s)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                                <td class="whitespace-nowrap px-3 py-3 font-mono text-xs font-bold">{{ $s->nomor_display }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $s->tanggal_spr?->format('d/m/Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $s->sales?->nama ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs font-semibold">{{ $s->prospectCustomer?->nama_lengkap ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $s->rumah?->proyek?->nama_proyek ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 font-mono text-xs">{{ $s->rumah?->blok }}-{{ $s->rumah?->nomor_unit }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $s->pm_approved_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">
                                    <span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $s->konsumen_signed_at?->format('d/m/Y H:i') ?? '—' }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right">
                                    <button type="button" wire:click="openMateraiUpload({{ $s->id }})"
                                            class="inline-flex items-center gap-1.5 rounded-md bg-purple-600 px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-white shadow-sm hover:bg-purple-700"
                                            title="{{ __('Buka form upload PDF ber-e-Materai') }}">
                                        <flux:icon.document-arrow-up class="size-3.5" />
                                        Proses
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-16 text-center">
                                    <flux:icon.inbox class="mx-auto size-10 text-zinc-300" />
                                    <p class="mt-3 text-sm font-semibold text-zinc-500">
                                        {{ __('Tidak ada SPR menunggu e-Materai') }}
                                    </p>
                                    <p class="mt-1 text-xs text-zinc-400">
                                        {{ __('SPR akan muncul di sini setelah full-approved dan konsumen sudah tanda tangan.') }}
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-200 px-3 py-3 dark:border-zinc-700">{{ $sprs->links() }}</div>
        </div>
    </div>

    {{-- ============ MODAL: Upload Materai ============ --}}
    <flux:modal name="upload-materai" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon.document-text class="size-5 text-purple-600" />
                    <flux:heading size="lg">{{ __('Upload PDF Ber-e-Materai') }}</flux:heading>
                </div>
                <flux:subheading>
                    {{ __('Upload PDF SPR yang sudah ditempel e-Materai (dari aplikasi Peruri / e-Meterai OJK).') }}
                </flux:subheading>
            </div>

            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200">
                <flux:icon.information-circle class="-mt-0.5 mr-1 inline size-3.5" />
                <strong>{{ __('Alur:') }}</strong>
                <ol class="mt-1 list-inside list-decimal space-y-0.5">
                    <li>{{ __('Download PDF SPR final (sudah lengkap TTD Sales, Keuangan, Project Manager, dan Konsumen).') }}</li>
                    <li>{{ __('Bubuhkan e-Materai di aplikasi Peruri / e-Meterai OJK.') }}</li>
                    <li>{{ __('Simpan hasilnya sebagai PDF.') }}</li>
                    <li>{{ __('Upload PDF bermaterai di form ini — SPR selesai.') }}</li>
                </ol>
                <p class="mt-2 italic">
                    {{ __('Catatan: e-Materai wajib ditempel setelah semua tanda tangan lengkap. Jika dibubuhkan lebih awal, hash Peruri akan invalid saat dokumen dimodifikasi.') }}
                </p>
            </div>

            @if ($materaiSprId)
                <a href="{{ route('marketing.spr.print', $materaiSprId) }}" target="_blank"
                   class="flex w-full items-center justify-center gap-2 rounded-lg border-2 border-blue-500 bg-white py-2.5 text-sm font-bold text-blue-700 shadow-sm transition hover:bg-blue-50 dark:border-blue-600 dark:bg-zinc-900 dark:text-blue-300 dark:hover:bg-blue-950/30">
                    <flux:icon.arrow-down-tray class="size-4" />
                    {{ __('Download PDF SPR Final untuk di-Materai') }}
                </a>
            @endif

            <flux:field>
                <flux:label>{{ __('File PDF Ber-Materai') }} <span class="ms-1 text-red-500">*</span></flux:label>
                <input type="file" wire:model="materaiFile" accept="application/pdf"
                       class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-purple-600 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-purple-700 dark:text-zinc-300" />
                <flux:description>{{ __('Format PDF, ukuran maksimum 5 MB.') }}</flux:description>
                <flux:error name="materaiFile" />
                <div wire:loading wire:target="materaiFile" class="mt-2 text-[11px] text-purple-700 dark:text-purple-400">
                    <flux:icon.arrow-path class="-mt-0.5 mr-1 inline size-3.5 animate-spin" />
                    {{ __('Mengunggah...') }}
                </div>
            </flux:field>

            @if ($materaiFile)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">
                    <flux:icon.check class="-mt-0.5 mr-1 inline size-3.5" />
                    <strong>{{ $materaiFile->getClientOriginalName() }}</strong>
                    · {{ round($materaiFile->getSize() / 1024, 1) }} KB
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="button" wire:click="submitMateraiUpload"
                             wire:loading.attr="disabled" wire:target="submitMateraiUpload,materaiFile"
                             icon="document-check"
                             class="bg-purple-600! hover:bg-purple-700!">
                    <span wire:loading.remove wire:target="submitMateraiUpload">{{ __('Simpan Materai') }}</span>
                    <span wire:loading wire:target="submitMateraiUpload">{{ __('Menyimpan...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
