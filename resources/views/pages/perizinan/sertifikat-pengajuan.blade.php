<?php

use App\Models\Master\Proyek;
use App\Services\SertifikatService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Input Pengajuan Sertifikat — ajukan pecah sertifikat induk ke BPN.
 *
 * Per kavling (pilih unit satu-satu) atau kolektif se-blok. Luas diusulkan
 * dari luas baku tipenya, tapi wajib dicocokkan dengan berkas ukur: sistem
 * tidak menyimpan luas per unit, jadi usulan itu bukan kebenaran.
 */
new #[Title('Input Pengajuan Sertifikat')] class extends Component
{
    #[Url(as: 'proyek')]
    public ?int $proyekId = null;

    public string $metode = 'kavling';

    public string $blok = '';

    public string $catatan = '';

    /** @var array<int, bool> */
    public array $pilih = [];

    /** @var array<int, string> */
    public array $luas = [];

    /**
     * Luas untuk seluruh unit di blok, dipakai hanya pada metode kolektif.
     *
     * Satu blok biasanya berisi puluhan unit berluas sama. Mengetiknya satu per
     * satu bukan ketelitian, melainkan pekerjaan sia-sia yang justru mengundang
     * salah ketik.
     */
    public string $luasBlok = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('legal.pengajuan.kelola'), 403);

        $this->proyekId = \App\Support\ProyekAktif::sinkron($this->proyekId);
    }

    #[On('active-proyek-changed')]
    public function syncProyek(int $proyekId): void
    {
        $this->proyekId = $proyekId;
        $this->reset(['pilih', 'luas', 'blok']);
    }

    public function updated(string $prop): void
    {
        if (in_array($prop, ['metode', 'blok'], true)) {
            $this->reset(['pilih', 'luas', 'luasBlok']);
        }
    }

    protected function svc(): SertifikatService
    {
        return app(SertifikatService::class);
    }

    public function kandidat()
    {
        if (! $this->proyekId) {
            return collect();
        }

        return $this->svc()
            ->unitBelumDiajukan($this->proyekId, $this->metode === 'blok' ? $this->blok : '')
            ->get();
    }

    public function pilihSemua(): void
    {
        foreach ($this->kandidat() as $r) {
            $this->pilih[$r->id] = true;
            $this->luas[$r->id] ??= (string) ($this->svc()->luasUsulan($r) ?? '');
        }
    }

    public function bersihkan(): void
    {
        $this->reset(['pilih', 'luas']);
    }

    public function simpan(): void
    {
        abort_unless(Auth::user()?->can('legal.pengajuan.kelola'), 403);

        $this->validate([
            'catatan' => ['nullable', 'string', 'max:500'],
        ]);

        $proyek = $this->proyekId ? Proyek::find($this->proyekId) : null;

        if (! $proyek) {
            Flux::toast(variant: 'warning', text: 'Pilih proyek dulu di sidebar.');

            return;
        }

        $luas = $this->metode === 'blok' ? $this->luasKolektif() : $this->luasPerKavling();

        if ($luas === null) {
            return;
        }

        try {
            // Tanggalnya saat menekan Ajukan, bukan diketik. Nomor pengajuan
            // diurutkan per bulan dari tanggal ini — tanggal yang bisa
            // dimundurkan membuat penomorannya melompat ke belakang.
            $pengajuan = $this->svc()->ajukan(
                $proyek, now()->toDateString(), $this->metode, $luas, $this->blok, $this->catatan, Auth::id()
            );
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: $e->validator->errors()->first());

            return;
        }

        $this->reset(['pilih', 'luas', 'luasBlok', 'catatan']);

        Flux::toast(
            variant: 'success',
            text: $pengajuan->nomor.' dibuat — '.$pengajuan->sertifikat()->count().' unit diajukan.',
        );
    }

    /**
     * Satu luas untuk seluruh unit blok yang belum diajukan.
     *
     * @return array<int, float>|null  null berarti ada yang kurang, pesannya sudah disampaikan
     */
    private function luasKolektif(): ?array
    {
        if ($this->blok === '') {
            Flux::toast(variant: 'warning', text: 'Pilih bloknya dulu.');

            return null;
        }

        $this->validate([
            'luasBlok' => ['required', 'numeric', 'min:1'],
        ], [], ['luasBlok' => 'luas tanah']);

        $unit = $this->kandidat();

        if ($unit->isEmpty()) {
            Flux::toast(variant: 'warning', text: 'Semua unit di blok ini sudah pernah diajukan.');

            return null;
        }

        return $unit->mapWithKeys(fn ($r) => [$r->id => (float) $this->luasBlok])->all();
    }

    /**
     * Luas yang diketik sendiri per unit yang dicentang.
     *
     * @return array<int, float|null>|null
     */
    private function luasPerKavling(): ?array
    {
        $dipilih = array_keys(array_filter($this->pilih));

        if ($dipilih === []) {
            Flux::toast(variant: 'warning', text: 'Belum ada unit yang dicentang.');

            return null;
        }

        $luas = [];

        foreach ($dipilih as $id) {
            $isi = preg_replace('/[^\d.]/', '', (string) ($this->luas[$id] ?? ''));
            $luas[(int) $id] = $isi !== '' ? (float) $isi : null;
        }

        return $luas;
    }

    public function with(): array
    {
        return [
            'daftar' => $this->kandidat(),
            'blokList' => $this->proyekId ? $this->svc()->blokTersedia($this->proyekId) : collect(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-5 flex items-start gap-3">
            <a href="{{ route('perizinan.sertifikat.index') }}" wire:navigate
               class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
               title="{{ __('Kembali ke Data Sertifikat') }}">
                <flux:icon.arrow-left class="size-4" />
            </a>
            <div>
                <flux:heading size="xl">{{ __('Input Pengajuan Sertifikat') }}</flux:heading>
                <flux:subheading>{{ __('Ajukan pecah sertifikat induk — per kavling atau kolektif se-blok.') }}</flux:subheading>
            </div>
        </div>

        @if (! $proyekId)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                {{ __('Pilih proyek dulu di picker "Proyek Aktif" pada sidebar kiri.') }}
            </div>
        @else
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="grid grid-cols-1 gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-4">
                    <flux:select wire:model.live="metode" :label="__('Metode pengajuan')">
                        <flux:select.option value="kavling">{{ __('Per kavling') }}</flux:select.option>
                        <flux:select.option value="blok">{{ __('Kolektif se-blok') }}</flux:select.option>
                    </flux:select>

                    @if ($metode === 'blok')
                        <flux:select wire:model.live="blok" :label="__('Blok')">
                            <flux:select.option value="">{{ __('Pilih blok') }}</flux:select.option>
                            @foreach ($blokList as $kode => $jml)
                                <flux:select.option value="{{ $kode }}">{{ $kode }} — {{ $jml }} unit</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('Tanggal pengajuan') }}</label>
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ now()->translatedFormat('d M Y') }}
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-500">{{ __('Mengikuti tanggal pengajuan dibuat.') }}</p>
                    </div>
                    <flux:input wire:model="catatan" :label="__('Catatan')" :placeholder="__('opsional')" />
                </div>

                @if ($metode === 'blok')
                    {{-- Satu luas untuk seluruh blok. Daftar unitnya tetap
                         ditampilkan tapi hanya untuk dibaca — supaya jelas apa
                         yang akan ikut terajukan, tanpa mengembalikan pekerjaan
                         mengetik satu per satu. --}}
                    <div class="flex flex-wrap items-end gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="w-44">
                            <label class="mb-1 block text-sm font-medium">{{ __('Luas tanah (m²)') }}</label>
                            <flux:input wire:model="luasBlok" inputmode="decimal" placeholder="60" />
                            <flux:error name="luasBlok" />
                        </div>

                        <p class="mb-2 flex-1 text-xs text-zinc-500">
                            {{ __('Berlaku untuk seluruh unit blok ini yang belum diajukan.') }}
                            @if ($blok !== '')
                                <strong class="text-zinc-700 dark:text-zinc-300">{{ $daftar->count() }} unit.</strong>
                            @endif
                        </p>

                        <flux:button size="sm" variant="primary" icon="paper-airplane" wire:click="simpan"
                                     :disabled="$blok === '' || $daftar->isEmpty()">
                            {{ __('Ajukan') }} {{ $blok !== '' ? '('.$daftar->count().')' : '' }}
                        </flux:button>
                    </div>
                @else
                    <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <flux:button size="sm" variant="ghost" wire:click="pilihSemua">{{ __('Centang semua') }}</flux:button>
                        @if (array_filter($pilih))
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="bersihkan">{{ __('Batalkan') }}</flux:button>
                        @endif
                        <div class="ms-auto">
                            <flux:button size="sm" variant="primary" icon="paper-airplane" wire:click="simpan">
                                {{ __('Ajukan') }} ({{ count(array_filter($pilih)) }})
                            </flux:button>
                        </div>
                    </div>
                @endif

                <div class="max-h-128 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                @if ($metode !== 'blok')
                                    <th class="w-10 px-3 py-2"></th>
                                @endif
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Unit') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Tipe') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Status unit') }}</th>
                                <th class="w-44 px-3 py-2 text-left font-semibold">{{ __('Luas diajukan (m²)') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($daftar as $r)
                                <tr wire:key="k-{{ $r->id }}">
                                    @if ($metode !== 'blok')
                                        <td class="px-3 py-2">
                                            <input type="checkbox" wire:model.live="pilih.{{ $r->id }}"
                                                   class="size-4 rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500 dark:border-zinc-600" />
                                        </td>
                                    @endif
                                    <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold">{{ $r->kode_unit }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-zinc-500">{{ $r->tipeRumah?->tipe }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-xs text-zinc-500">{{ ucfirst($r->status) }}</td>
                                    <td class="px-3 py-2">
                                        @if ($metode === 'blok')
                                            <span class="font-mono text-zinc-500">{{ $luasBlok !== '' ? $luasBlok : '—' }}</span>
                                        @else
                                        <input type="text" inputmode="decimal" wire:model="luas.{{ $r->id }}"
                                               placeholder="{{ $this->svc()->luasUsulan($r) ?? '—' }}"
                                               class="w-32 rounded-md border-zinc-300 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $metode === 'blok' ? 4 : 5 }}" class="px-3 py-12 text-center text-sm italic text-zinc-400">
                                        @if ($metode === 'blok' && $blok === '')
                                            {{ __('Pilih bloknya dulu.') }}
                                        @else
                                            {{ __('Semua unit di sini sudah pernah diajukan.') }}
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="mt-3 max-w-2xl text-xs text-zinc-500">
                {{ __('Luas terisi dari luas baku tipenya sebagai usulan — cocokkan dengan berkas ukur sebelum diajukan.') }}
            </p>
        @endif

    </div>
</section>
