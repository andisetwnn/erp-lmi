<?php

use App\Models\Master\PermintaanPembelian;
use App\Models\Master\Proyek;
use App\Services\PembelianService;
use App\Support\ProyekAktif;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Form Permintaan Pembelian')] class extends Component
{
    use WithFileUploads;

    public ?int $permintaanId = null;

    public string $tanggal = '';

    /** '' = kantor pusat */
    public string $proyekId = '';

    public string $departemen = '';

    public string $keperluan = '';

    public ?string $tanggalDibutuhkan = null;

    /** @var list<array{nama_barang: string, spesifikasi: ?string, jumlah: string, satuan: string, harga_perkiraan: string|int}> */
    public array $item = [];

    public $lampiran;

    public ?string $lampiranLama = null;

    public function mount(?int $id = null): void
    {
        if ($id) {
            $pr = PermintaanPembelian::with('item')->findOrFail($id);
            abort_unless($pr->created_by_user_id === Auth::id(), 403);

            if (! $pr->bolehDiubah()) {
                Flux::toast(variant: 'warning', text: 'PR ini sudah diajukan, jadi tidak bisa diubah lagi.');
                $this->redirectRoute('pembelian.permintaan.show', $pr->id, navigate: true);

                return;
            }

            $this->permintaanId = $pr->id;
            $this->tanggal = $pr->tanggal->format('Y-m-d');
            $this->proyekId = $pr->proyek_id ? (string) $pr->proyek_id : '';
            $this->departemen = $pr->departemen;
            $this->keperluan = $pr->keperluan;
            $this->tanggalDibutuhkan = $pr->tanggal_dibutuhkan?->format('Y-m-d');
            $this->lampiranLama = $pr->lampiran_nama;
            $this->item = $pr->item->map(fn ($i) => [
                'nama_barang' => $i->nama_barang,
                'spesifikasi' => $i->spesifikasi,
                'jumlah' => rtrim(rtrim((string) $i->jumlah, '0'), '.'),
                'satuan' => $i->satuan,
                'harga_perkiraan' => (int) $i->harga_perkiraan,
            ])->all();

            return;
        }

        $this->tanggal = now()->format('Y-m-d');
        $this->proyekId = (string) (ProyekAktif::id() ?? '');
        $this->tambahBarang();
    }

    public function tambahBarang(): void
    {
        $this->item[] = ['nama_barang' => '', 'spesifikasi' => null, 'jumlah' => '1', 'satuan' => '', 'harga_perkiraan' => 0];
    }

    public function hapusBarang(int $index): void
    {
        unset($this->item[$index]);
        $this->item = array_values($this->item);

        if ($this->item === []) {
            $this->tambahBarang();
        }
    }

    public function simpan(PembelianService $layanan): void
    {
        $this->validate([
            'tanggal' => ['required', 'date'],
            'proyekId' => ['nullable', 'exists:proyek,id'],
            'departemen' => ['required', Rule::in(PermintaanPembelian::DEPARTEMEN)],
            'keperluan' => ['required', 'string', 'max:1000'],
            'tanggalDibutuhkan' => ['nullable', 'date'],
            'item' => ['required', 'array', 'min:1'],
            'item.*.nama_barang' => ['required', 'string', 'max:255'],
            'item.*.spesifikasi' => ['nullable', 'string', 'max:255'],
            'item.*.jumlah' => ['required', 'numeric', 'gt:0'],
            'item.*.satuan' => ['required', 'string', 'max:20'],
            'item.*.harga_perkiraan' => ['nullable', 'numeric', 'min:0'],
            'lampiran' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ], [], [
            'tanggalDibutuhkan' => 'tanggal dibutuhkan',
            'item.*.nama_barang' => 'nama barang',
            'item.*.jumlah' => 'jumlah',
            'item.*.satuan' => 'satuan',
            'item.*.harga_perkiraan' => 'harga perkiraan',
        ]);

        $data = [
            'tanggal' => $this->tanggal,
            'proyek_id' => $this->proyekId !== '' ? (int) $this->proyekId : null,
            'departemen' => $this->departemen,
            'keperluan' => trim($this->keperluan),
            'tanggal_dibutuhkan' => $this->tanggalDibutuhkan ?: null,
        ];
        $item = array_map(fn ($i) => [
            'nama_barang' => trim($i['nama_barang']),
            'spesifikasi' => trim((string) ($i['spesifikasi'] ?? '')) ?: null,
            'jumlah' => (float) $i['jumlah'],
            'satuan' => trim($i['satuan']),
            'harga_perkiraan' => (float) ($i['harga_perkiraan'] ?: 0),
        ], $this->item);

        try {
            $pr = $this->permintaanId
                ? $layanan->perbaruiPermintaan(PermintaanPembelian::findOrFail($this->permintaanId), $data, $item)
                : $layanan->buatPermintaan($data, $item, Auth::id());
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());

            return;
        }

        if ($this->lampiran) {
            $pr->update([
                'lampiran_path' => $this->lampiran->store("pembelian/pr/{$pr->id}", 'private'),
                'lampiran_nama' => $this->lampiran->getClientOriginalName(),
            ]);
        }

        Flux::toast(variant: 'success', text: $this->permintaanId ? 'PR diperbarui.' : "PR {$pr->nomor} tersimpan sebagai draft. Ajukan kalau sudah lengkap.");
        $this->redirectRoute('pembelian.permintaan.show', $pr->id, navigate: true);
    }

    public function with(): array
    {
        return [
            'proyekOptions' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek']),
            'totalPerkiraan' => collect($this->item)->sum(fn ($i) => (float) ($i['jumlah'] ?: 0) * (float) ($i['harga_perkiraan'] ?: 0)),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center gap-3">
            <flux:button variant="ghost" icon="arrow-left" size="sm"
                         :href="$permintaanId ? route('pembelian.permintaan.show', $permintaanId) : route('pembelian.permintaan.index')" wire:navigate />
            <div>
                <flux:heading size="xl">{{ $permintaanId ? __('Ubah Permintaan Pembelian') : __('Buat Permintaan Pembelian') }}</flux:heading>
                <flux:subheading>{{ __('PR disimpan sebagai draft dulu. Ajukan ke Keuangan dari halaman detailnya.') }}</flux:subheading>
            </div>
        </div>

        <form wire:submit="simpan" class="space-y-6">
            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Tanggal') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input type="date" wire:model="tanggal" required />
                        <flux:error name="tanggal" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Dibutuhkan Tanggal') }}</flux:label>
                        <flux:input type="date" wire:model="tanggalDibutuhkan" />
                        <flux:error name="tanggalDibutuhkan" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Untuk') }}</flux:label>
                        <flux:select wire:model="proyekId">
                            <flux:select.option value="">{{ __('Kantor pusat') }}</flux:select.option>
                            @foreach ($proyekOptions as $p)
                                <flux:select.option value="{{ $p->id }}">{{ __('Proyek') }} {{ $p->nama_proyek }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="proyekId" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Departemen') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:select wire:model="departemen" required>
                            <flux:select.option value="">{{ __('-- Pilih departemen --') }}</flux:select.option>
                            @foreach (\App\Models\Master\PermintaanPembelian::DEPARTEMEN as $d)
                                <flux:select.option value="{{ $d }}">{{ $d }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="departemen" />
                    </flux:field>
                </div>
                <div class="mt-4">
                    <flux:field>
                        <flux:label>{{ __('Keperluan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:textarea wire:model="keperluan" rows="2" :placeholder="__('Contoh: material pondasi Blok AD unit 01–10')" />
                        <flux:error name="keperluan" />
                    </flux:field>
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                    <flux:heading size="lg">{{ __('Barang') }}</flux:heading>
                    <flux:button size="sm" icon="plus" type="button" wire:click="tambahBarang">{{ __('Tambah barang') }}</flux:button>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($item as $i => $baris)
                        <div wire:key="barang-{{ $i }}" class="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-12">
                            <div class="md:col-span-4">
                                <flux:input wire:model="item.{{ $i }}.nama_barang" :label="$i === 0 ? __('Nama barang') : null" :placeholder="__('Semen')" />
                                <flux:error name="item.{{ $i }}.nama_barang" />
                            </div>
                            <div class="md:col-span-3">
                                <flux:input wire:model="item.{{ $i }}.spesifikasi" :label="$i === 0 ? __('Merek / spesifikasi') : null" :placeholder="__('50 kg')" />
                            </div>
                            <div class="md:col-span-1">
                                <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="item.{{ $i }}.jumlah" :label="$i === 0 ? __('Jumlah') : null" />
                                <flux:error name="item.{{ $i }}.jumlah" />
                            </div>
                            <div class="md:col-span-1">
                                <flux:input wire:model="item.{{ $i }}.satuan" :label="$i === 0 ? __('Satuan') : null" :placeholder="__('sak')" />
                                <flux:error name="item.{{ $i }}.satuan" />
                            </div>
                            <div class="md:col-span-2">
                                @if ($i === 0)
                                    <flux:label class="mb-2 block">{{ __('Harga perkiraan') }}</flux:label>
                                @endif
                                <x-money-input :wire="'item.'.$i.'.harga_perkiraan'" />
                            </div>
                            <div class="flex items-end md:col-span-1">
                                <flux:button variant="ghost" size="sm" icon="trash" type="button" wire:click="hapusBarang({{ $i }})" />
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-end border-t border-zinc-200 px-5 py-3 text-sm dark:border-zinc-700">
                    <span class="text-zinc-500">{{ __('Total perkiraan') }}</span>
                    <span class="ms-3 font-mono font-semibold tabular-nums">Rp {{ number_format($totalPerkiraan, 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:field>
                    <flux:label>{{ __('Lampiran') }}</flux:label>
                    <flux:description>{{ __('Opsional: penawaran, foto, atau daftar kebutuhan. PDF/JPG/PNG, maksimal 5 MB.') }}</flux:description>
                    <input type="file" wire:model="lampiran" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mt-2 block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />
                    @if ($lampiranLama && ! $lampiran)
                        <p class="mt-1 text-xs text-zinc-500">{{ __('Lampiran sekarang:') }} {{ $lampiranLama }}</p>
                    @endif
                    <flux:error name="lampiran" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" :href="$permintaanId ? route('pembelian.permintaan.show', $permintaanId) : route('pembelian.permintaan.index')" wire:navigate>{{ __('Batal') }}</flux:button>
                <flux:button variant="primary" type="submit">{{ __('Simpan draft') }}</flux:button>
            </div>
        </form>
    </div>
</section>
