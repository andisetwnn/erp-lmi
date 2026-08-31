<?php

namespace App\Livewire\Concerns;

use App\Support\RekananPilihan;
use Flux\Flux;

/**
 * Pemilih rekanan berbentuk modal, siap pakai di Livewire/Volt component.
 *
 * Pakai:
 *   1. `use App\Livewire\Concerns\MemilihRekanan;` di kelas component.
 *   2. Implementasi `terapkanRekanan()` — menentukan nilai terpilih disimpan ke mana.
 *   3. Buka lewat `bukaRekanan()`; kalau satu layar punya beberapa tujuan
 *      (mis. tiap baris tabel), kirim penandanya: `bukaRekanan((string) $index)`.
 *   4. Gabungkan `dataRekanan()` ke array `with()`.
 *   5. Render `<x-rekanan-modal ... />` sejajar modal lain, bukan di dalamnya —
 *      kalau bersarang di wadah ber-scroll, modalnya ikut terpotong.
 *
 * Penyaringan dikerjakan di memori: daftarnya ratusan baris, tidak sebanding
 * dengan query ulang tiap ketikan.
 */
trait MemilihRekanan
{
    /** Penanda tujuan pilihan — diisi pemanggil, dibaca terapkanRekanan(). */
    public ?string $rekananTujuan = null;

    public string $rekananCari = '';

    public string $rekananKategori = '';

    public int $rekananHalaman = 1;

    /** Simpan nilai terpilih. Wajib diisi component pemakai. */
    abstract protected function terapkanRekanan(string $nilai, ?string $tujuan): void;

    /** Override kalau butuh jumlah baris per halaman yang lain. */
    protected function jumlahRekananPerHalaman(): int
    {
        return 8;
    }

    public function bukaRekanan(?string $tujuan = null): void
    {
        $this->rekananTujuan = $tujuan;
        $this->reset(['rekananCari', 'rekananKategori']);
        $this->rekananHalaman = 1;
        Flux::modal('pilih-rekanan')->show();
    }

    public function pilihRekanan(string $nilai): void
    {
        $this->terapkanRekanan($nilai, $this->rekananTujuan);
        Flux::modal('pilih-rekanan')->close();
        $this->rekananTujuan = null;
    }

    public function gantiHalamanRekanan(int $halaman): void
    {
        $this->rekananHalaman = max(1, $halaman);
    }

    public function updatedRekananCari(): void
    {
        $this->rekananHalaman = 1;
    }

    public function updatedRekananKategori(): void
    {
        $this->rekananHalaman = 1;
    }

    /**
     * Data untuk <x-rekanan-modal>. Gabungkan ke array with().
     *
     * @return array<string, mixed>
     */
    protected function dataRekanan(): array
    {
        $semua = RekananPilihan::daftar();
        $perHalaman = $this->jumlahRekananPerHalaman();

        $tersaring = $semua
            ->when($this->rekananKategori !== '',
                fn ($c) => $c->where('kategori', $this->rekananKategori))
            ->when($this->rekananCari !== '', function ($c) {
                $cari = mb_strtolower(trim($this->rekananCari));

                return $c->filter(fn ($r) => str_contains(mb_strtolower($r['kode'].' '.$r['nama']), $cari));
            })
            ->values();

        $totalHalaman = max(1, (int) ceil($tersaring->count() / $perHalaman));

        // Halaman di luar jangkauan dijepit ke halaman terakhir yang valid — tabel kosong
        // bikin orang mengira datanya hilang.
        $halaman = min(max(1, $this->rekananHalaman), $totalHalaman);

        return [
            'rekananLabel' => $semua->keyBy('nilai'),
            'rekananKategoriList' => $semua->pluck('kategori')->unique()->values(),
            'rekananHalamanIni' => $tersaring
                ->slice(($halaman - 1) * $perHalaman, $perHalaman)
                ->values(),
            'rekananJumlah' => $tersaring->count(),
            'rekananDariNomor' => ($halaman - 1) * $perHalaman,
            'rekananHalamanAktif' => $halaman,
            'rekananTotalHalaman' => $totalHalaman,
        ];
    }
}
