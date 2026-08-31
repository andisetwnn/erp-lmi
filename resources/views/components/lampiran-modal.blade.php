@props([
    'jurnal',                    // \App\Models\Akunting\Jurnal|null
    'daftar',                    // \Illuminate\Support\Collection — lampiran
    'preview' => null,           // \App\Models\Akunting\JurnalLampiran|null — yang ditampilkan
    'bolehKelola' => false,      // bool — izin tambah & hapus
    'hapusId' => null,           // int|null — lampiran yang sedang dikonfirmasi hapus
])

{{--
    Berkas pendukung jurnal. Dirender sejajar modal lain, bukan bersarang.
    Terikat ke trait App\Livewire\Concerns\MengelolaLampiranJurnal lewat nama method
    & property: pratinjauLampiran(), simpanLampiran(), unduhLampiran(), lampiranBaru.
--}}
<flux:modal name="lampiran-jurnal" @class(['max-w-5xl'])>
    <div class="space-y-4">
        <div>
            <flux:heading size="lg">Berkas Pendukung</flux:heading>
            <flux:subheading>
                @if ($jurnal)
                    {{ $jurnal->no_bukti }} &middot; {{ $jurnal->tanggal?->translatedFormat('d F Y') }}
                @else
                    Invoice, bukti transfer, atau kwitansi.
                @endif
            </flux:subheading>
        </div>

        @if ($jurnal?->isPosted())
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800 dark:border-blue-900/40 dark:bg-blue-950/30 dark:text-blue-300">
                Jurnal ini sudah diposting. Angkanya terkunci, tapi berkas pendukung masih
                boleh ditambahkan &mdash; bukti bayar memang sering menyusul.
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-5">

            {{-- Daftar berkas --}}
            <div class="lg:col-span-2">
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="border-b border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-semibold uppercase text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                        {{ $daftar->count() }} Berkas
                    </div>
                    <ul class="max-h-80 divide-y divide-zinc-200 overflow-y-auto dark:divide-zinc-700">
                        @forelse ($daftar as $l)
                            <li wire:key="lampiran-{{ $l->id }}"
                                @class([
                                    'group flex items-start gap-2 px-3 py-2',
                                    'bg-blue-50 dark:bg-blue-900/30' => $preview?->id === $l->id,
                                ])>
                                <button type="button" wire:click="pratinjauLampiran({{ $l->id }})"
                                        class="min-w-0 flex-1 text-left">
                                    <div class="truncate text-sm font-medium text-blue-600 dark:text-blue-400">
                                        {{ $l->file_original_name }}
                                    </div>
                                    @if ($l->keterangan)
                                        <div class="truncate text-xs text-zinc-500">{{ $l->keterangan }}</div>
                                    @endif
                                    <div class="text-[11px] text-zinc-400">
                                        {{ $l->ukuranTerbaca() }} &middot;
                                        {{ $l->uploadedBy?->name ?? '—' }} &middot;
                                        {{ $l->created_at?->translatedFormat('d M y') }}
                                    </div>
                                </button>

                                <div class="flex shrink-0 items-center gap-0.5">
                                    <button type="button" wire:click="unduhLampiran({{ $l->id }})"
                                            title="Unduh berkas"
                                            class="rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-blue-600 dark:hover:bg-zinc-700">
                                        <flux:icon.arrow-down-tray class="size-4" />
                                    </button>
                                    @if ($bolehKelola)
                                        @if ($hapusId === $l->id)
                                            <flux:button size="xs" variant="danger" wire:click="hapusLampiran">Hapus</flux:button>
                                            <flux:button size="xs" variant="ghost" wire:click="batalHapusLampiran">Batal</flux:button>
                                        @else
                                            <button type="button" wire:click="konfirmasiHapusLampiran({{ $l->id }})"
                                                    title="Hapus berkas"
                                                    class="rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-rose-500 dark:hover:bg-zinc-700">
                                                <flux:icon.trash class="size-4" />
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li class="px-3 py-8 text-center text-sm italic text-zinc-400">
                                Belum ada berkas pendukung.
                            </li>
                        @endforelse
                    </ul>
                </div>
            </div>

            {{-- Pratinjau --}}
            <div class="lg:col-span-3">
                <div class="flex h-80 items-center justify-center overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50">
                    @if (! $preview)
                        <div class="px-4 text-center text-sm text-zinc-400">
                            <flux:icon.document-magnifying-glass class="mx-auto mb-2 size-8" />
                            Pilih berkas di sebelah kiri untuk melihat isinya.
                        </div>
                    @elseif (! $preview->adaDiPenyimpanan())
                        <div class="px-4 text-center text-sm text-amber-600 dark:text-amber-400">
                            <flux:icon.exclamation-triangle class="mx-auto mb-2 size-8" />
                            Berkas tidak ditemukan di penyimpanan.
                        </div>
                    @else
                        @php
                            $urlPratinjau = route('akunting.jurnal-lampiran.pratinjau', $preview);
                            $mime = (string) $preview->mime;
                        @endphp

                        @if (str_starts_with($mime, 'image/'))
                            <img src="{{ $urlPratinjau }}" alt="{{ $preview->file_original_name }}"
                                 class="max-h-full max-w-full object-contain" />
                        @elseif ($mime === 'application/pdf')
                            {{-- Sebagian browser menolak PDF di iframe; tautan di bawah jadi jalan cadangan --}}
                            <iframe src="{{ $urlPratinjau }}#toolbar=0"
                                    title="{{ $preview->file_original_name }}"
                                    class="h-full w-full border-0"></iframe>
                        @else
                            <div class="px-4 text-center text-sm text-zinc-500">
                                <flux:icon.document class="mx-auto mb-2 size-8 text-zinc-400" />
                                Jenis berkas ini tidak bisa dipratinjau.
                                <div class="mt-2">
                                    <flux:button size="xs" variant="filled" icon="arrow-down-tray"
                                                 wire:click="unduhLampiran({{ $preview->id }})">
                                        Unduh
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                @if ($preview?->adaDiPenyimpanan())
                    <div class="mt-1 flex items-center justify-between text-xs text-zinc-500">
                        <span class="truncate">{{ $preview->file_original_name }}</span>
                        <a href="{{ route('akunting.jurnal-lampiran.pratinjau', $preview) }}" target="_blank"
                           class="shrink-0 font-medium text-blue-600 hover:underline dark:text-blue-400">
                            Buka di tab baru
                        </a>
                    </div>
                @endif
            </div>
        </div>

        {{-- Unggah baru --}}
        @if ($bolehKelola)
            <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <flux:heading size="sm">Tambah Berkas</flux:heading>

                <input type="file" multiple wire:model="lampiranBaru"
                       accept=".pdf,.jpg,.jpeg,.png,.webp"
                       class="block w-full text-xs text-zinc-600 file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs file:font-medium hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700" />

                <div wire:loading wire:target="lampiranBaru" class="text-xs text-zinc-500">
                    Mengunggah&hellip;
                </div>

                @error('lampiranBaru')
                    <div class="text-xs text-rose-600">{{ $message }}</div>
                @enderror
                @error('lampiranBaru.*')
                    <div class="text-xs text-rose-600">{{ $message }}</div>
                @enderror

                <flux:input wire:model="lampiranKeterangan" size="sm"
                            placeholder="Keterangan (opsional) — mis. Invoice #123" />

                <div class="flex items-center justify-between">
                    <span class="text-[11px] text-zinc-400">
                        PDF atau gambar, maksimal 5 MB per berkas.
                    </span>
                    <flux:button size="sm" variant="primary" icon="arrow-up-tray"
                                 wire:click="simpanLampiran"
                                 wire:loading.attr="disabled" wire:target="lampiranBaru,simpanLampiran">
                        Unggah
                    </flux:button>
                </div>
            </div>
        @endif

        <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
            <flux:modal.close><flux:button variant="ghost">Tutup</flux:button></flux:modal.close>
        </div>
    </div>
</flux:modal>
