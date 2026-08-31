<?php

namespace App\Livewire\Concerns;

use App\Models\Akunting\Jurnal;
use App\Models\Akunting\JurnalLampiran;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kelola berkas pendukung jurnal (invoice, bukti transfer, kwitansi).
 *
 * Pakai:
 *   1. `use App\Livewire\Concerns\MengelolaLampiranJurnal;` — sertakan juga
 *      `Livewire\WithFileUploads` di component yang sama.
 *   2. Buka lewat `bukaLampiran($jurnalId)`.
 *   3. Gabungkan `dataLampiran()` ke array `with()`.
 *   4. Render `<x-lampiran-modal ... />` sejajar modal lain.
 *
 * Berkas tetap boleh ditambah setelah jurnal diposting: posting mengunci angkanya,
 * bukan dokumennya — dan bukti bayar memang sering menyusul belakangan.
 */
trait MengelolaLampiranJurnal
{
    public ?int $lampiranJurnalId = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $lampiranBaru = [];

    public string $lampiranKeterangan = '';

    public ?int $hapusLampiranId = null;

    /** Berkas yang sedang ditampilkan di panel pratinjau. */
    public ?int $lampiranPreviewId = null;

    /** Izin menambah & menghapus berkas. */
    protected function bolehKelolaLampiran(): bool
    {
        return (bool) Auth::user()?->can('jurnal.umum.kelola');
    }

    /** Izin melihat & mengunduh berkas — direktur ikut, supaya bisa memeriksa. */
    protected function bolehLihatLampiran(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->can('jurnal.umum.kelola') || $user?->can('bukubesar.lihat'));
    }

    public function bukaLampiran(int $jurnalId): void
    {
        abort_unless($this->bolehLihatLampiran(), 403);

        $this->lampiranJurnalId = Jurnal::whereKey($jurnalId)->value('id');
        $this->reset(['lampiranBaru', 'lampiranKeterangan', 'hapusLampiranId', 'lampiranPreviewId']);
        $this->resetErrorBag();

        // Langsung tampilkan berkas pertama — modal yang terbuka kosong itu sia-sia.
        $this->lampiranPreviewId = JurnalLampiran::where('jurnal_id', $this->lampiranJurnalId)
            ->orderByDesc('id')->value('id');

        Flux::modal('lampiran-jurnal')->show();
    }

    public function pratinjauLampiran(int $lampiranId): void
    {
        abort_unless($this->bolehLihatLampiran(), 403);

        $this->lampiranPreviewId = JurnalLampiran::where('jurnal_id', $this->lampiranJurnalId)
            ->whereKey($lampiranId)
            ->value('id');
    }

    public function simpanLampiran(): void
    {
        abort_unless($this->bolehKelolaLampiran(), 403);

        $this->validate([
            'lampiranBaru' => ['required', 'array', 'min:1', 'max:10'],
            'lampiranBaru.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
            'lampiranKeterangan' => ['nullable', 'string', 'max:200'],
        ], [], [
            'lampiranBaru' => 'berkas',
            'lampiranBaru.*' => 'berkas',
            'lampiranKeterangan' => 'keterangan',
        ]);

        $jurnal = Jurnal::findOrFail($this->lampiranJurnalId);

        foreach ($this->lampiranBaru as $berkas) {
            JurnalLampiran::create([
                'jurnal_id' => $jurnal->id,
                'file_path' => $berkas->store("jurnal/{$jurnal->id}", 'private'),
                'file_original_name' => $berkas->getClientOriginalName(),
                'ukuran' => $berkas->getSize(),
                'mime' => $berkas->getMimeType(),
                'keterangan' => $this->lampiranKeterangan ?: null,
                'uploaded_by_user_id' => Auth::id(),
            ]);
        }

        $jumlah = count($this->lampiranBaru);
        $this->reset(['lampiranBaru', 'lampiranKeterangan']);

        // Tampilkan berkas yang barusan diunggah.
        $this->lampiranPreviewId = JurnalLampiran::where('jurnal_id', $jurnal->id)
            ->orderByDesc('id')->value('id');

        Flux::toast(variant: 'success', text: $jumlah.' berkas ditambahkan.');
    }

    public function unduhLampiran(int $lampiranId): ?StreamedResponse
    {
        abort_unless($this->bolehLihatLampiran(), 403);

        $lampiran = JurnalLampiran::findOrFail($lampiranId);

        if (! $lampiran->adaDiPenyimpanan()) {
            Flux::toast(variant: 'warning', text: 'Berkas tidak ditemukan di penyimpanan.');

            return null;
        }

        return Storage::disk('private')->download($lampiran->file_path, $lampiran->file_original_name);
    }

    public function konfirmasiHapusLampiran(int $lampiranId): void
    {
        abort_unless($this->bolehKelolaLampiran(), 403);

        $this->hapusLampiranId = JurnalLampiran::whereKey($lampiranId)->value('id');
    }

    public function batalHapusLampiran(): void
    {
        $this->hapusLampiranId = null;
    }

    public function hapusLampiran(): void
    {
        abort_unless($this->bolehKelolaLampiran(), 403);

        $lampiran = JurnalLampiran::findOrFail($this->hapusLampiranId);
        $nama = $lampiran->file_original_name;

        // Berkas fisik ikut dibuang supaya tidak jadi sampah yang tak terlacak.
        Storage::disk('private')->delete($lampiran->file_path);
        $lampiran->delete();

        $this->hapusLampiranId = null;

        // Panel pratinjau pindah ke berkas lain, jangan menunjuk yang sudah tiada.
        if ($this->lampiranPreviewId === $lampiran->id) {
            $this->lampiranPreviewId = JurnalLampiran::where('jurnal_id', $lampiran->jurnal_id)
                ->orderByDesc('id')->value('id');
        }

        Flux::toast(variant: 'success', text: 'Berkas '.$nama.' dihapus.');
    }

    /**
     * Data untuk <x-lampiran-modal>. Gabungkan ke array with().
     *
     * @return array<string, mixed>
     */
    protected function dataLampiran(): array
    {
        $jurnal = $this->lampiranJurnalId
            ? Jurnal::with(['lampiran.uploadedBy:id,name'])->find($this->lampiranJurnalId)
            : null;

        $daftar = $jurnal?->lampiran ?? collect();

        return [
            'lampiranJurnal' => $jurnal,
            'lampiranDaftar' => $daftar,
            'lampiranPreview' => $daftar->firstWhere('id', $this->lampiranPreviewId),
            'bolehKelolaLampiran' => $this->bolehKelolaLampiran(),
        ];
    }
}
