<?php

use App\Models\Master\Booking;
use App\Models\Master\Spr;
use App\Support\BusinessActivityLogger;
use App\Support\FileOptimizer;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Buat SPR'), Layout('layouts.dbos')] class extends Component
{
    use WithFileUploads;

    public ?Booking $booking = null;

    public int $currentStep = 1;

    // Step 1 — Cek data customer
    public string $tanggalSpr = '';

    public ?string $catatanUmum = null;

    // Step 2 — Harga
    public string $hargaJual = '0';

    public string $diskon = '0';

    public string $biayaTambahan = '0';

    public string $ppn = '0';

    public string $sbum = '0';

    // Step 3 — Angsuran
    public string $jenisPembayaran = 'kpr';

    public int $jumlahTerminUm = 1;

    public ?string $catatanAngsuran = null;

    // Step 4 — UTJ
    public string $utjNominal = '0';

    public string $utjTanggalBayar = '';

    public string $utjMetode = 'transfer';

    public $utjBukti = null;

    public ?string $utjKeterangan = null;

    public function mount(int $bookingId): void
    {
        $salesId = Auth::guard('sales')->id();
        $this->booking = Booking::with([
            'rumah.tipeRumah',
            'rumah.proyek',
            'rumah.virtualAccount.bank',
            'prospectCustomer.tempatKerja',
            'prospectCustomer.bank',
            'prospectCustomer.kontakDarurat',
            'spr',
        ])
            ->where('sales_id', $salesId)
            ->findOrFail($bookingId);

        // Guard: booking harus aktif & belum punya SPR
        if ($this->booking->spr) {
            session()->flash('toast', 'Booking ini sudah punya SPR. Akan diarahkan ke detail.');
            $this->redirect(route('dbos.spr.show', $this->booking->spr->id), navigate: true);
            return;
        }

        if ($this->booking->status !== 'aktif') {
            session()->flash('toast', 'Booking tidak aktif, tidak bisa buat SPR.');
            $this->redirect(route('dbos.booking.index'), navigate: true);
            return;
        }

        // Default values dari relasi
        $tipe = $this->booking->rumah?->tipeRumah;
        $rumah = $this->booking->rumah;

        $this->tanggalSpr = now()->format('Y-m-d');
        $this->utjTanggalBayar = now()->format('Y-m-d');

        $this->hargaJual = $tipe?->harga_jual ? (string) $tipe->harga_jual : '0';
        // Biaya administrasi dari tipe (notaris, umum, pajak — sudah bagian harga all-in).
        // rumah.biaya_tambahan (hook/view/dll) TIDAK masuk sini — diproses TERPISAH via
        // tabel biaya_tambahan_realisasi (halaman detail SPR internal).
        $this->biayaTambahan = (string) ($tipe?->biaya_administrasi ?? 0);
        // Diskon per unit (dari master rumah, negosiasi/promo)
        $this->diskon = $rumah?->discount ? (string) $rumah->discount : '0';
        // PPN per unit (dari master rumah, ada beberapa unit yang kena)
        $this->ppn = $rumah?->ppn ? (string) $rumah->ppn : '0';
        $this->sbum = $tipe?->sbum ? (string) $tipe->sbum : '0';

        // Prefill dari template tipe rumah
        $this->utjNominal = $tipe?->utj ? (string) $tipe->utj : '5000000';

        // Komersial: force KPR + kosongkan UM/SBUM/termin (UM langsung ke bank, bukan ke developer)
        if (($tipe?->kategori ?? '') === 'komersial') {
            $this->jenisPembayaran = 'kpr';
            $this->jumlahTerminUm = 0;
            $this->sbum = '0';
        }
    }

    // ============ COMPUTED HELPERS ============

    /** Cek apakah unit ini kategori komersial. Kalau iya, UM+DP+SBUM=0, semua ke KPR bank. */
    public function getIsKomersialProperty(): bool
    {
        return ($this->booking?->rumah?->tipeRumah?->kategori ?? '') === 'komersial';
    }

    public function getTotalHargaProperty(): float
    {
        $jual = (float) $this->hargaJual;
        $tambahan = (float) $this->biayaTambahan;
        $ppn = (float) $this->ppn;
        $diskon = (float) $this->diskon;

        return max(0, $jual + $tambahan + $ppn - $diskon);
    }

    /** Plafon KPR dari master tipe rumah. Untuk komersial: 100% dari total harga. */
    public function getPlafonKprProperty(): float
    {
        if ($this->isKomersial) {
            return $this->totalHarga;
        }

        return (float) ($this->booking?->rumah?->tipeRumah?->plafon_kpr ?? 0);
    }

    /** Nilai KPR yang ditalangi bank (hanya untuk jenis 'kpr'). */
    public function getNilaiKprProperty(): float
    {
        return $this->jenisPembayaran === 'kpr' ? $this->plafonKpr : 0;
    }

    /**
     * Total UM (yang bukan KPR / bukan pinjaman bank).
     * - Komersial: 0 (semua ke KPR bank)
     * - KPR subsidi: total_harga − plafon_kpr
     * - Cash Bertahap & Cash: total_harga (semua ke developer)
     */
    public function getDpNominalProperty(): float
    {
        if ($this->isKomersial) {
            return 0;
        }

        return match ($this->jenisPembayaran) {
            'kpr' => max(0, $this->totalHarga - $this->plafonKpr),
            default => $this->totalHarga, // cash & cash_bertahap
        };
    }

    /** UM Sendiri customer (setelah dikurangi SBUM subsidi, kalau ada). */
    public function getUmNetProperty(): float
    {
        if ($this->isKomersial) {
            return 0;
        }

        return max(0, $this->dpNominal - (float) $this->sbum);
    }

    /** UM yang perlu dicicil ke developer setelah UTJ (booking fee) masuk.
     *  UTJ dianggap bagian dari UM Sendiri (bukan tambahan), jadi mengurangi
     *  jumlah yang perlu dicicil. Contoh: UM Sendiri 10jt, UTJ 1jt →
     *  sisa cicilan = 9jt, dibagi 4 termin = 2.25jt per termin. */
    public function getSisaCicilProperty(): float
    {
        return max(0, $this->umNet - (float) $this->utjNominal);
    }

    /** Max termin sesuai jenis pembayaran. Komersial: 0 (tidak dicicil). */
    public function getMaxTerminProperty(): int
    {
        if ($this->isKomersial) {
            return 0;
        }

        return match ($this->jenisPembayaran) {
            'cash' => 0,
            'cash_bertahap' => 6,
            'kpr' => 4,
            default => 0,
        };
    }

    /** Min termin sesuai jenis pembayaran. Komersial: 0. */
    public function getMinTerminProperty(): int
    {
        if ($this->isKomersial) {
            return 0;
        }

        return match ($this->jenisPembayaran) {
            'cash' => 0,
            'cash_bertahap' => 2,
            'kpr' => 1,
            default => 0,
        };
    }

    /**
     * Jadwal termin yang akan di-generate saat submit.
     * Sumber cicilan = sisa setelah UTJ dibagi jumlah_termin.
     * Tanggal = UTJ + n bulan.
     */
    public function getJadwalTerminProperty(): array
    {
        if ($this->jenisPembayaran === 'cash') {
            return [];
        }

        $jumlah = max($this->minTermin, min($this->maxTermin, (int) $this->jumlahTerminUm));
        // Anchor jadwal termin pakai tanggal SPR (sementara — akan di-regenerate saat Finance
        // konfirmasi UTJ, anchor final = utj_tanggal_transaksi). Aturan: termin 1 = anchor+15 hari,
        // sisanya +1 bulan dari termin sebelumnya.
        $anchor = \App\Support\SprJadwalTermin::toAnchor($this->tanggalSpr);
        $sisa = $this->sisaCicil;
        $perTermin = $jumlah > 0 ? round($sisa / $jumlah, 0) : 0;

        return $anchor
            ? \App\Support\SprJadwalTermin::generate($anchor, $jumlah, $perTermin)
            : array_map(fn ($n) => ['urutan' => $n, 'tanggal' => null, 'jumlah' => $perTermin], range(1, $jumlah));
    }

    // Auto-adjust jumlah termin & SBUM saat jenis pembayaran berubah
    public function updatedJenisPembayaran(): void
    {
        // Komersial: force back to KPR (Cash/Cash Bertahap tidak berlaku)
        if ($this->isKomersial) {
            $this->jenisPembayaran = 'kpr';
            $this->jumlahTerminUm = 0;
            $this->sbum = '0';

            return;
        }

        $this->jumlahTerminUm = match ($this->jenisPembayaran) {
            'cash' => 0,
            'cash_bertahap' => 2,
            'kpr' => 1,
            default => 1,
        };

        // SBUM hanya relevan untuk KPR subsidi.
        // Cash/Cash Bertahap → 0. KPR → restore dari master tipe rumah.
        $this->sbum = $this->jenisPembayaran === 'kpr'
            ? (string) ($this->booking?->rumah?->tipeRumah?->sbum ?? 0)
            : '0';
    }

    // ============ STEP NAVIGATION ============

    public function nextStep(): void
    {
        $this->validateStep($this->currentStep);
        if ($this->currentStep < 3) {
            $this->currentStep++;
        }
    }

    public function prevStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step < $this->currentStep) {
            $this->currentStep = $step;
        }
    }

    protected function validateStep(int $step): void
    {
        match ($step) {
            1 => (function () {
                // Tanggal SPR selalu hari ini (server-side), sales tidak boleh manipulasi
                $this->tanggalSpr = now()->format('Y-m-d');
                return $this->validate([
                    'catatanUmum' => ['nullable', 'string', 'max:1000'],
                ]);
            })(),
            // Step 2: Harga (read-only dari master) + Angsuran (jenis + jumlah termin)
            2 => $this->validate([
                'hargaJual' => ['required', 'numeric', 'min:0'],
                'diskon' => ['nullable', 'numeric', 'min:0'],
                'biayaTambahan' => ['nullable', 'numeric', 'min:0'],
                'ppn' => ['nullable', 'numeric', 'min:0'],
                'jenisPembayaran' => ['required', 'in:cash,cash_bertahap,kpr'],
                'sbum' => ['nullable', 'numeric', 'min:0'],
                'jumlahTerminUm' => [
                    $this->jenisPembayaran === 'cash' ? 'nullable' : 'required',
                    'integer',
                    'min:'.$this->minTermin,
                    'max:'.max(1, $this->maxTermin),
                ],
                'catatanAngsuran' => ['nullable', 'string', 'max:1000'],
            ], [], ['jumlahTerminUm' => 'jumlah termin']),
            // Step 3: UTJ + upload bukti transfer
            3 => $this->validate([
                'utjNominal' => ['required', 'numeric', 'min:0'],
                'utjBukti' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
                'utjKeterangan' => ['nullable', 'string', 'max:500'],
            ], [
                'utjBukti.required' => 'Bukti transfer UTJ wajib di-upload.',
                'utjBukti.mimes' => 'Format bukti harus JPG, PNG, atau PDF.',
                'utjBukti.max' => 'Ukuran file maksimal 5MB.',
            ]),
        };
    }

    public function submit(): void
    {
        // Validate semua step (defensive)
        $this->validateStep(1);
        $this->validateStep(2);
        $this->validateStep(3);

        // Guard: sales wajib register TTD sebelum bisa submit SPR
        // (biar SPR yang dicetak selalu punya TTD sales)
        $sales = $this->booking->sales;
        if (! $sales?->tanda_tangan_path) {
            Flux::toast(
                variant: 'danger',
                heading: 'TTD belum terdaftar',
                text: 'Anda belum mendaftarkan tanda tangan digital. Buka menu Profil → Tanda Tangan untuk mendaftar dulu.',
            );
            return;
        }

        // Server-side override: paksa nominal UTJ dari master tipe rumah.
        // Sales tidak boleh manipulasi via Livewire payload.
        $tipeMaster = $this->booking->rumah?->tipeRumah;
        $this->utjNominal = (string) ($tipeMaster?->utj ?? 0);

        $totalHarga = $this->totalHarga;
        $nilaiKpr = $this->nilaiKpr;
        $dpNominal = $this->dpNominal;
        $umNet = $this->umNet;
        $jadwalTermin = $this->jadwalTermin;
        $kategori = $tipeMaster?->kategori ?? 'komersial';
        $dpPersen = $totalHarga > 0 ? round(($dpNominal / $totalHarga) * 100, 2) : 0;

        // Komersial: UM tidak dicicil ke developer (langsung ke bank via KPR).
        // Force jenis pembayaran = KPR, kosongkan termin UM & data DP/UM/SBUM.
        $jenisPembayaran = $this->jenisPembayaran;
        if ($kategori === 'komersial') {
            $jenisPembayaran = 'kpr';
            $jadwalTermin = [];
            $umNet = 0;
            $dpNominal = 0;
            $dpPersen = 0;
        }

        $sprId = DB::transaction(function () use ($totalHarga, $nilaiKpr, $dpNominal, $dpPersen, $umNet, $jadwalTermin, $kategori, $jenisPembayaran) {
            $spr = Spr::create([
                'booking_id' => $this->booking->id,
                'sales_id' => $this->booking->sales_id,
                'prospect_customer_id' => $this->booking->prospect_customer_id,
                'rumah_id' => $this->booking->rumah_id,
                'kategori' => $kategori,
                'nomor_spr' => Spr::generateNextNomor(Carbon::parse($this->tanggalSpr)),
                'tanggal_spr' => $this->tanggalSpr,
                'harga_jual' => (float) $this->hargaJual,
                'diskon' => (float) $this->diskon,
                'biaya_tambahan' => (float) $this->biayaTambahan,
                'ppn' => (float) $this->ppn,
                'kelebihan_tanah_m2' => 0,
                'harga_per_m2' => 0,
                'total_harga' => $totalHarga,
                'jenis_pembayaran' => $jenisPembayaran,
                'bank_kpr_id' => null, // Bank KPR nanti diisi di modul Admin KPR
                'dp_persen' => $dpPersen,
                'dp_nominal' => $dpNominal,
                'sbum' => $kategori === 'komersial' ? 0 : (float) $this->sbum,
                'um_net' => $umNet,
                'nilai_kpr' => $nilaiKpr,
                'catatan_angsuran' => $this->catatanAngsuran ?: null,
                'utj_nominal' => (float) $this->utjNominal,
                'utj_tanggal_bayar' => null,   // Diisi Finance saat konfirmasi.
                'utj_metode' => 'transfer',
                'utj_bukti_path' => $this->utjBukti ? FileOptimizer::storeOptimized($this->utjBukti, 'utj-bukti') : null,
                'utj_keterangan' => $this->utjKeterangan ?: null,
                'status' => 'submitted',
                'catatan' => $this->catatanUmum ?: null,
                // Snapshot TTD Sales — dari master supaya dokumen historis tetap stabil.
                'ttd_sales_path' => $this->booking->sales?->tanda_tangan_path,
            ]);

            // BF (UTJ) — selalu ada, terpisah dari termin cicilan.
            // Tanggal jadwal null; realisasi tanggal diset saat Finance konfirmasi.
            $spr->terminPembayaran()->create([
                'jenis' => 'bf',
                'urutan' => 0,
                'tanggal_jadwal' => null,
                'jumlah_jadwal' => (float) $this->utjNominal,
                'input_by_user_id' => null,
            ]);

            // Termin cicilan (kosong untuk Cash, banyak untuk Cash Bertahap / KPR)
            foreach ($jadwalTermin as $row) {
                $spr->terminPembayaran()->create([
                    'jenis' => 'um',
                    'urutan' => $row['urutan'],
                    'tanggal_jadwal' => $row['tanggal'],
                    'jumlah_jadwal' => $row['jumlah'],
                    'input_by_user_id' => null,
                ]);
            }

            // SBUM (hanya untuk KPR subsidi — komersial tidak ada SBUM)
            if ($kategori !== 'komersial' && $jenisPembayaran === 'kpr' && (float) $this->sbum > 0) {
                $spr->terminPembayaran()->create([
                    'jenis' => 'sbum',
                    'urutan' => 0,
                    'jumlah_jadwal' => (float) $this->sbum,
                    'input_by_user_id' => null,
                ]);
            }

            $this->booking->update(['status' => 'sukses']);

            return $spr->id;
        });

        BusinessActivityLogger::sprSubmitted(
            Spr::with('rumah', 'prospectCustomer', 'sales')->find($sprId)
        );

        session()->flash('toast', 'SPR berhasil dibuat dan menunggu verifikasi Keuangan.');
        $this->redirect(route('dbos.spr.show', $sprId), navigate: true);
    }

    public function with(): array
    {
        return [
            'jenisPembayaranOptions' => Spr::JENIS_PEMBAYARAN,
            'metodeUtjOptions' => Spr::METODE_UTJ,
            'hubunganOptions' => \App\Models\Master\ProspectCustomerKontakDarurat::HUBUNGAN_OPTIONS,
        ];
    }
}; ?>

<section class="px-4 pb-24 pt-4">

    {{-- HEADER --}}
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('dbos.booking.index') }}" wire:navigate
           class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
            <flux:icon.arrow-left class="size-5" />
        </a>
        <div class="min-w-0 flex-1">
            <h1 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Buat SPR') }}</h1>
            <p class="text-xs text-zinc-500">{{ __('Surat Pemesanan Rumah · Step ') }}{{ $currentStep }}/3</p>
        </div>
    </div>

    {{-- Guard warning: sales belum register TTD --}}
    @if (! $booking?->sales?->tanda_tangan_path)
        <div class="mb-4 flex items-start gap-3 rounded-2xl border-2 border-rose-300 bg-rose-50 p-4 dark:border-rose-900/50 dark:bg-rose-950/30">
            <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-rose-600 dark:text-rose-400" />
            <div class="min-w-0 flex-1">
                <div class="text-sm font-bold text-rose-900 dark:text-rose-200">{{ __('Tanda Tangan Digital Belum Terdaftar') }}</div>
                <p class="mt-1 text-xs leading-relaxed text-rose-800 dark:text-rose-300">
                    {{ __('SPR yang Anda buat tidak dapat disimpan sampai tanda tangan digital didaftarkan. Klik tombol di bawah untuk mendaftar sekarang.') }}
                </p>
                <a href="{{ route('dbos.profil') }}" wire:navigate
                   class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-bold text-white shadow active:scale-95 hover:bg-rose-700">
                    <flux:icon.pencil-square class="size-3.5" />
                    {{ __('Daftar Tanda Tangan Sekarang') }}
                </a>
            </div>
        </div>
    @endif

    {{-- STEPPER --}}
    @php
        $steps = [
            1 => ['Cek Data', 'user'],
            2 => ['Harga & Angsuran', 'banknotes'],
            3 => ['Bukti UTJ', 'document-arrow-up'],
        ];
    @endphp
    <div class="mb-4 flex items-center gap-1">
        @foreach ($steps as $n => [$label, $icon])
            @php
                $isCurrent = $currentStep === $n;
                $isDone = $currentStep > $n;
            @endphp
            <button type="button" wire:click="goToStep({{ $n }})" @disabled($n > $currentStep)
                    class="flex flex-1 flex-col items-center gap-1">
                <div @class([
                    'flex h-9 w-9 items-center justify-center rounded-full text-xs font-bold transition',
                    'bg-orange-600 text-white shadow-lg ring-4 ring-orange-200 dark:ring-orange-900/50' => $isCurrent,
                    'bg-emerald-500 text-white' => $isDone,
                    'bg-zinc-200 text-zinc-500 dark:bg-zinc-700' => ! $isCurrent && ! $isDone,
                ])>
                    @if ($isDone)
                        <flux:icon.check class="size-4" />
                    @else
                        {{ $n }}
                    @endif
                </div>
                <span @class([
                    'text-[10px] font-semibold',
                    'text-orange-600 dark:text-orange-400' => $isCurrent,
                    'text-emerald-600 dark:text-emerald-400' => $isDone,
                    'text-zinc-400' => ! $isCurrent && ! $isDone,
                ])>{{ $label }}</span>
            </button>
            @if ($n < 3)
                <div @class(['mb-5 h-0.5 w-3', $isDone ? 'bg-emerald-500' : 'bg-zinc-200 dark:bg-zinc-700'])></div>
            @endif
        @endforeach
    </div>

    @php
        $prospect = $booking->prospectCustomer;
        $rumah = $booking->rumah;
        $tipe = $rumah?->tipeRumah;
        $totalHarga = $this->totalHarga;
        $nilaiKpr = $this->nilaiKpr;
        $dpNominal = $this->dpNominal;
    @endphp

    {{-- ============= STEP 1: CEK DATA CUSTOMER ============= --}}
    @if ($currentStep === 1)
        <div class="space-y-3">
            <div class="rounded-2xl border border-orange-200 bg-orange-50 px-4 py-3 text-xs text-orange-900 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-200">
                <flux:icon.information-circle class="-mt-0.5 mr-1 inline size-3.5" />
                {{ __('Periksa data customer & unit. Jika ada yang salah, edit terlebih dahulu di Database Konsumen sebelum lanjut.') }}
            </div>

            {{-- Booking & Unit --}}
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-emerald-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-emerald-950/20">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">Unit Booking</span>
                </div>
                <dl class="space-y-2 px-4 py-3 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Proyek</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">{{ $rumah?->proyek?->nama_proyek ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Unit</dt>
                        <dd class="text-right font-mono font-semibold text-zinc-900 dark:text-white">{{ $rumah?->kode_unit ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Tipe</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">
                            {{ $tipe?->tipe ?? '' }} {{ $tipe?->nama_tipe ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Luas Tanah / Bangunan</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">
                            {{ $rumah?->luas_tanah ?? '—' }} m² / {{ $rumah?->luas_bangunan ?? '—' }} m²
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Tanggal Booking</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">{{ $booking->tanggal_booking?->translatedFormat('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Customer identitas --}}
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-100 bg-blue-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-blue-950/20">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300">Identitas Customer</span>
                    <a href="{{ route('dbos.database.edit', $prospect->id) }}" wire:navigate
                       class="text-[11px] font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">
                        {{ __('Edit ✏') }}
                    </a>
                </div>
                <dl class="space-y-2 px-4 py-3 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Nama</dt>
                        <dd class="text-right font-bold text-zinc-900 dark:text-white">{{ $prospect->nama_lengkap }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">NIK</dt>
                        <dd class="text-right font-mono text-zinc-900 dark:text-white">{{ $prospect->nik ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">NPWP</dt>
                        <dd class="text-right font-mono text-zinc-900 dark:text-white">{{ $prospect->npwp ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Tempat / Tgl Lahir</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">
                            {{ $prospect->tempat_lahir ?? '—' }},
                            {{ $prospect->tanggal_lahir ? $prospect->tanggal_lahir->translatedFormat('d M Y') : '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Jenis Kelamin</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">
                            {{ match ($prospect->jenis_kelamin) { 'L' => 'Laki-laki', 'P' => 'Perempuan', default => '—' } }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Agama</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">{{ $prospect->agama ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Status Perkawinan</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">{{ $prospect->status_perkawinan ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">HP</dt>
                        <dd class="text-right font-mono text-green-700 dark:text-green-400">{{ $prospect->hp }}</dd>
                    </div>
                    @if ($prospect->hp_2)
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">HP 2</dt>
                            <dd class="text-right font-mono text-green-700 dark:text-green-400">{{ $prospect->hp_2 }}</dd>
                        </div>
                    @endif
                    @if ($prospect->alamat)
                        <div class="border-t border-zinc-100 pt-2 dark:border-zinc-800">
                            <dt class="text-zinc-500">Alamat KTP</dt>
                            <dd class="mt-0.5 text-zinc-700 dark:text-zinc-300">
                                {{ collect([$prospect->alamat, $prospect->kelurahan_nama, $prospect->kecamatan_nama, $prospect->kota_nama, $prospect->provinsi_nama])->filter()->implode(', ') }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Foto KTP --}}
            @if ($prospect->foto_ktp)
                <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-cyan-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-cyan-950/20">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-cyan-700 dark:text-cyan-300">Foto KTP</span>
                    </div>
                    <div class="p-3">
                        <a href="{{ asset('storage/'.$prospect->foto_ktp) }}" target="_blank" class="block">
                            <img src="{{ asset('storage/'.$prospect->foto_ktp) }}" alt="Foto KTP"
                                 class="h-40 w-full rounded-lg border border-zinc-200 object-cover dark:border-zinc-700" />
                        </a>
                        <p class="mt-1.5 text-center text-[10px] text-zinc-500">{{ __('Ketuk untuk lihat ukuran penuh') }}</p>
                    </div>
                </div>
            @endif

            {{-- Pekerjaan & Rekening --}}
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-indigo-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-indigo-950/20">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-300">Pekerjaan & Rekening</span>
                </div>
                <dl class="space-y-2 px-4 py-3 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Pekerjaan (KTP)</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">{{ $prospect->pekerjaan_ktp ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Perusahaan</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">{{ $prospect->tempatKerja?->nama ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Penghasilan / bln</dt>
                        <dd class="text-right font-mono font-bold text-emerald-700 dark:text-emerald-400">
                            @if ($prospect->penghasilan_bulanan)
                                Rp {{ number_format((float) $prospect->penghasilan_bulanan, 0, ',', '.') }}
                            @else — @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-t border-zinc-100 pt-2 dark:border-zinc-800">
                        <dt class="text-zinc-500">Bank</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">{{ $prospect->bank?->nama ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">No. Rekening</dt>
                        <dd class="text-right font-mono text-zinc-900 dark:text-white">{{ $prospect->nomor_rekening ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Atas Nama</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">{{ $prospect->rekening_atas_nama ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- BI Checking --}}
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-amber-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-amber-950/20">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-300">BI Checking</span>
                </div>
                <dl class="space-y-2 px-4 py-3 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Kolektibilitas</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">
                            @if ($prospect->bi_kol)
                                KOL {{ $prospect->bi_kol }}
                            @else — @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">DBR</dt>
                        <dd class="text-right font-semibold text-zinc-900 dark:text-white">
                            {{ $prospect->bi_dbr !== null ? number_format((float) $prospect->bi_dbr, 2).' %' : '—' }}
                        </dd>
                    </div>
                    @if ($prospect->bi_keterangan)
                        <div class="border-t border-zinc-100 pt-2 dark:border-zinc-800">
                            <dt class="text-zinc-500">Keterangan</dt>
                            <dd class="mt-0.5 italic text-zinc-700 dark:text-zinc-300">"{{ $prospect->bi_keterangan }}"</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Kontak Darurat --}}
            @if ($prospect->kontakDarurat->isNotEmpty())
                <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-rose-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-rose-950/20">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-rose-700 dark:text-rose-300">
                            Kontak Darurat ({{ $prospect->kontakDarurat->count() }})
                        </span>
                    </div>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($prospect->kontakDarurat as $k)
                            <li class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <div>
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $k->nama }}</div>
                                    <div class="text-[10px] uppercase tracking-wider text-zinc-500">
                                        {{ $hubunganOptions[$k->hubungan] ?? $k->hubungan }}
                                    </div>
                                </div>
                                <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ $k->nomor_telepon }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Catatan --}}
            <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-zinc-900">
                <flux:field>
                    <flux:label>{{ __('Catatan Umum') }} <span class="ms-1 text-xs font-normal text-zinc-500">— opsional</span></flux:label>
                    <flux:textarea wire:model="catatanUmum" rows="2" placeholder="Catatan tambahan untuk SPR ini" />
                    <flux:error name="catatanUmum" />
                </flux:field>
            </div>
        </div>
    @endif

    {{-- ============= STEP 2: HARGA (READ-ONLY, DARI MASTER) ============= --}}
    @if ($currentStep === 2)
        <div class="space-y-3">
            <div class="rounded-2xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-[11px] text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200">
                <flux:icon.information-circle class="-mt-0.5 mr-1 inline size-3.5" />
                {{ __('Harga otomatis dari master Tipe Rumah & Unit. Jika perlu penyesuaian, edit di master.') }}
            </div>

            @php
                $bAdminTipe = (float) ($booking->rumah?->tipeRumah?->biaya_administrasi ?? 0);
                $bTambahUnit = (float) ($booking->rumah?->biaya_tambahan ?? 0);
            @endphp

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                <div class="border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-800">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Detail Harga Unit') }}</span>
                </div>
                <dl class="divide-y divide-zinc-100 px-4 py-2 text-sm dark:divide-zinc-800">
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Harga Jual AJB') }}</dt>
                        <dd class="font-mono font-semibold text-zinc-900 dark:text-white">Rp {{ number_format((float) $hargaJual, 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Biaya Administrasi') }}</dt>
                        <dd class="font-mono font-semibold text-zinc-900 dark:text-white">Rp {{ number_format($bAdminTipe, 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Biaya Tambahan') }}</dt>
                        <dd class="font-mono font-semibold text-zinc-900 dark:text-white">Rp {{ number_format($bTambahUnit, 0, ',', '.') }}</dd>
                    </div>
                    @if ((float) $ppn > 0)
                        <div class="flex items-center justify-between py-2">
                            <dt class="text-zinc-600 dark:text-zinc-400">
                                {{ __('PPN') }}
                                <span class="ms-1 text-[10px] font-normal text-zinc-500">(+)</span>
                            </dt>
                            <dd class="font-mono font-semibold text-amber-600">+ Rp {{ number_format((float) $ppn, 0, ',', '.') }}</dd>
                        </div>
                    @endif
                    @if ((float) $diskon > 0)
                        <div class="flex items-center justify-between py-2">
                            <dt class="text-zinc-600 dark:text-zinc-400">
                                {{ __('Diskon') }}
                                <span class="ms-1 text-[10px] font-normal text-zinc-500">(−)</span>
                            </dt>
                            <dd class="font-mono font-semibold text-rose-600">− Rp {{ number_format((float) $diskon, 0, ',', '.') }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Live total --}}
            <div class="rounded-2xl bg-linear-to-br from-emerald-600 to-emerald-500 p-4 text-white shadow-lg">
                <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">{{ __('Harga All In') }}</div>
                <div class="mt-1 text-3xl font-extrabold tabular-nums">
                    Rp {{ number_format($totalHarga, 0, ',', '.') }}
                </div>
            </div>
        </div>
    @endif

    {{-- ============= STEP 2 (LANJUTAN): ANGSURAN — merged from old Step 3 ============= --}}
    @if ($currentStep === 2)
        <div class="space-y-3 mt-3 border-t-2 border-dashed border-zinc-200 pt-3 dark:border-zinc-700">
            <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-zinc-900">
                <div class="mb-3 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Jenis Pembayaran') }}</div>

                <div class="space-y-2">
                    @foreach ($jenisPembayaranOptions as $key => $label)
                        {{-- Komersial: hanya KPR yang tampil (Cash & Cash Bertahap belum dipakai) --}}
                        @if ($this->isKomersial && $key !== 'kpr')
                            @continue
                        @endif
                        <label @class([
                            'flex cursor-pointer items-center gap-3 rounded-xl border-2 p-3 transition',
                            'border-orange-500 bg-orange-50 dark:bg-orange-950/30' => $jenisPembayaran === $key,
                            'border-zinc-200 dark:border-zinc-700' => $jenisPembayaran !== $key,
                        ])>
                            <input type="radio" wire:model.live="jenisPembayaran" value="{{ $key }}" class="accent-orange-600" />
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            @php
                $plafonKpr = $this->plafonKpr;
                $dpNominalStep3 = $this->dpNominal;
                $umNetStep3 = $this->umNet;
                $sisaCicilStep3 = $this->sisaCicil;
                $perTerminStep3 = $jumlahTerminUm > 0 ? round($sisaCicilStep3 / max(1, $jumlahTerminUm), 0) : 0;
            @endphp

            {{-- Komersial: tidak ada section termin UM & tidak ada info Cash --}}
            @if (! $this->isKomersial)
                @if ($jenisPembayaran !== 'cash')
                    <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-zinc-900">
                        <flux:field>
                            <flux:label>
                                @if ($jenisPembayaran === 'kpr')
                                    {{ __('Jumlah Termin UM') }}
                                @else
                                    {{ __('Jumlah Termin Cicilan') }}
                                @endif
                            </flux:label>
                            <flux:select wire:model.live="jumlahTerminUm">
                                @for ($i = $this->minTermin; $i <= $this->maxTermin; $i++)
                                    <flux:select.option value="{{ $i }}">{{ $i }} kali</flux:select.option>
                                @endfor
                            </flux:select>
                            <flux:error name="jumlahTerminUm" />
                        </flux:field>

                        @if ($sisaCicilStep3 > 0 && $jumlahTerminUm > 0)
                            <div class="mt-3 rounded-lg bg-orange-50 px-3 py-2 text-xs dark:bg-orange-950/30">
                                <div class="text-orange-700 dark:text-orange-400">
                                    <span class="font-semibold">{{ __('Nominal per termin:') }}</span>
                                    <span class="ms-1 font-mono font-bold">Rp {{ number_format($perTerminStep3, 0, ',', '.') }}</span>
                                    <span class="text-[10px] text-zinc-500">/ termin (auto-hitung)</span>
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-4 text-sm dark:border-emerald-900/50 dark:bg-emerald-950/30">
                        <div class="flex items-center gap-2 font-semibold text-emerald-800 dark:text-emerald-300">
                            <flux:icon.check-circle class="size-4" />
                            {{ __('Cash: bayar UTJ + Cash lunas sekaligus') }}
                        </div>
                        <div class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">
                            {{ __('Tidak ada termin cicilan. Sisa (Total Harga − UTJ) dibayar lunas cash saat akad.') }}
                        </div>
                    </div>
                @endif
            @endif

            <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-zinc-900">
                <flux:field>
                    <flux:label>{{ __('Catatan Angsuran') }} <span class="ms-1 text-xs font-normal text-zinc-500">— opsional</span></flux:label>
                    <flux:textarea wire:model="catatanAngsuran" rows="2" placeholder="Catatan terkait skema pembayaran" />
                    <flux:error name="catatanAngsuran" />
                </flux:field>
            </div>

            {{-- Summary — beda per jenis pembayaran --}}
            @php
                $utjFloat = (float) $utjNominal;
                $sisaCash = max(0, $totalHarga - $utjFloat);
            @endphp
            <div class="rounded-2xl bg-linear-to-br from-orange-600 to-amber-500 p-4 text-white shadow-lg">
                @if ($jenisPembayaran === 'cash')
                    {{-- CASH: Total Harga + UTJ + Sisa Lunas Cash --}}
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Total Harga</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($totalHarga, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">UTJ</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($utjFloat, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Sisa Lunas Cash</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($sisaCash, 0, ',', '.') }}</div>
                        </div>
                    </div>
                @elseif ($jenisPembayaran === 'cash_bertahap')
                    {{-- CASH BERTAHAP: Total Harga + UTJ + Total Cicilan + Per Termin --}}
                    @php
                        $perTerminBertahap = $jumlahTerminUm > 0 ? round($sisaCash / max(1, $jumlahTerminUm), 0) : 0;
                    @endphp
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Total Harga</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($totalHarga, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">UTJ</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($utjFloat, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Total Cicilan</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($sisaCash, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Per Termin ({{ $jumlahTerminUm }}×)</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($perTerminBertahap, 0, ',', '.') }}</div>
                        </div>
                    </div>
                @elseif ($this->isKomersial)
                    {{-- KOMERSIAL: hanya Total Harga + UTJ + Nilai KPR (UM & DP tidak berlaku) --}}
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Total Harga</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($totalHarga, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">UTJ</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($utjFloat, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Nilai KPR</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($plafonKpr, 0, ',', '.') }}</div>
                        </div>
                    </div>
                @else
                    {{-- KPR SUBSIDI: Total Harga + Nilai KPR + Total UM + UM Sendiri --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Total Harga</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($totalHarga, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Nilai KPR</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($plafonKpr, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">Total UM</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($dpNominalStep3, 0, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80">UM Sendiri</div>
                            <div class="mt-1 text-lg font-bold tabular-nums">Rp {{ number_format($umNetStep3, 0, ',', '.') }}</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ============= STEP 3: BUKTI UTJ ============= --}}
    @if ($currentStep === 3)
        <div class="space-y-3">
            <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-zinc-900">
                <div class="mb-3 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Uang Tanda Jadi (UTJ)') }}</div>

                <div class="space-y-4">
                    <flux:field>
                        <flux:label class="flex items-center gap-1">
                            {{ __('Nominal UTJ') }}
                            <flux:icon.lock-closed class="size-3 text-zinc-400" />
                        </flux:label>
                        <div class="flex h-10 items-center rounded-lg border border-zinc-200 bg-zinc-100 px-3 dark:border-zinc-700 dark:bg-zinc-800/60">
                            <span class="font-mono text-sm font-bold text-zinc-800 dark:text-zinc-100">
                                Rp {{ number_format((float) $utjNominal, 0, ',', '.') }}
                            </span>
                        </div>
                    </flux:field>

                    {{-- ============ UPLOAD BUKTI TRANSFER UTJ ============ --}}
                    <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                        <div class="mb-2 flex items-center gap-2">
                            <flux:icon.arrow-up-tray class="size-4 text-amber-700 dark:text-amber-400" />
                            <span class="text-[10px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">
                                {{ __('Upload Bukti Transfer UTJ') }} <span class="ms-1 text-rose-600">*</span>
                            </span>
                        </div>
                        <input type="file" wire:model="utjBukti" accept="image/*,application/pdf"
                               class="block w-full text-xs text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-amber-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:bg-amber-700 dark:text-zinc-300" />

                        <div wire:loading wire:target="utjBukti" class="mt-2 text-[10px] text-amber-700">
                            <flux:icon.arrow-path class="mr-1 inline size-3 animate-spin" />
                            {{ __('Uploading...') }}
                        </div>

                        @if ($utjBukti)
                            <div class="mt-2 flex items-center gap-2 rounded-lg bg-white px-2 py-1.5 dark:bg-zinc-900">
                                <flux:icon.document-check class="size-4 text-emerald-600" />
                                <span class="flex-1 truncate text-[10px] text-zinc-700 dark:text-zinc-300">
                                    {{ $utjBukti->getClientOriginalName() }}
                                </span>
                                <span class="text-[10px] text-zinc-500">{{ round($utjBukti->getSize() / 1024) }} KB</span>
                            </div>
                        @endif

                        <flux:error name="utjBukti" />

                        <p class="mt-2 text-[10px] italic text-amber-700 dark:text-amber-400">
                            {{ __('Wajib upload foto/PDF bukti transfer UTJ dari customer. Max 5MB, format JPG/PNG/PDF.') }}
                        </p>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Keterangan') }} <span class="ms-1 text-xs font-normal text-zinc-500">— opsional</span></flux:label>
                        <flux:textarea wire:model="utjKeterangan" rows="2" placeholder="Catatan tambahan terkait pembayaran (rekening tujuan, dll)" />
                        <flux:error name="utjKeterangan" />
                    </flux:field>
                </div>
            </div>

            {{-- Preview Jadwal Termin UM --}}
            @php $jadwal = $this->jadwalTermin; @endphp
            <div class="rounded-2xl border border-purple-200 bg-white shadow-sm dark:border-purple-900/50 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-2 border-b border-purple-100 bg-purple-50/60 px-4 py-2.5 dark:border-purple-900/50 dark:bg-purple-950/20">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-purple-700 dark:text-purple-300">{{ __('Jadwal Termin') }}</span>
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-purple-50/40 text-purple-700 dark:bg-purple-950/20 dark:text-purple-300">
                        <tr>
                            <th class="px-3 py-1.5 text-left font-semibold">{{ __('Termin') }}</th>
                            <th class="px-3 py-1.5 text-left font-semibold">{{ __('Tanggal') }}</th>
                            <th class="px-3 py-1.5 text-right font-semibold">{{ __('Nominal') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-t border-purple-100 dark:border-purple-900/30">
                            <td class="px-3 py-1.5 font-mono font-bold">BF (UTJ)</td>
                            <td class="px-3 py-1.5 text-zinc-400 italic">{{ __('Menunggu bayar') }}</td>
                            <td class="px-3 py-1.5 text-right font-mono">Rp {{ number_format((float) $utjNominal, 0, ',', '.') }}</td>
                        </tr>
                        @foreach ($jadwal as $row)
                            <tr class="border-t border-purple-100 dark:border-purple-900/30">
                                <td class="px-3 py-1.5 font-mono font-bold">UM-{{ $row['urutan'] }}</td>
                                <td class="px-3 py-1.5">{{ $row['tanggal']?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-3 py-1.5 text-right font-mono">Rp {{ number_format((float) $row['jumlah'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Ringkasan SPR (recap lengkap — detail sync dengan banner orange di step 2) --}}
            @php
                $plafonKprSum = $this->plafonKpr;
                $dpNominalSum = $this->dpNominal;
                $umNetSum = $this->umNet;
                $totalHargaSum = $this->totalHarga;
                $utjFloatSum = (float) $utjNominal;
                $sisaCashSum = max(0, $totalHargaSum - $utjFloatSum);
                $perTerminBertahapSum = ($jenisPembayaran === 'cash_bertahap' && $jumlahTerminUm > 0)
                    ? round($sisaCashSum / max(1, $jumlahTerminUm), 0)
                    : 0;
            @endphp
            <div class="rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-300">
                    {{ __('Ringkasan SPR') }}
                </div>
                <dl class="space-y-1.5 text-xs">
                    <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">Customer</dt><dd class="font-semibold text-zinc-900 dark:text-white">{{ $prospect->nama_lengkap }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">Unit</dt><dd class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $rumah?->kode_unit }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">Jenis Pembayaran</dt><dd class="font-semibold text-zinc-900 dark:text-white">{{ $jenisPembayaranOptions[$jenisPembayaran] }}</dd></div>

                    <div class="flex justify-between border-t border-emerald-200 pt-1.5 dark:border-emerald-900/50">
                        <dt class="text-zinc-600 dark:text-zinc-400">Total Harga</dt>
                        <dd class="font-bold text-emerald-700 dark:text-emerald-300">Rp {{ number_format($totalHargaSum, 0, ',', '.') }}</dd>
                    </div>

                    @if ($jenisPembayaran === 'kpr' && $this->isKomersial)
                        {{-- KOMERSIAL: cukup Nilai KPR saja (UM & DP tidak berlaku, langsung ke bank) --}}
                        <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">Nilai KPR</dt><dd class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format($plafonKprSum, 0, ',', '.') }}</dd></div>
                    @elseif ($jenisPembayaran === 'kpr')
                        {{-- KPR SUBSIDI: Nilai KPR + Total UM + UM Sendiri --}}
                        <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">Nilai KPR</dt><dd class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format($plafonKprSum, 0, ',', '.') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">Total UM</dt><dd class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format($dpNominalSum, 0, ',', '.') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">UM Sendiri</dt><dd class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format($umNetSum, 0, ',', '.') }}</dd></div>
                    @elseif ($jenisPembayaran === 'cash_bertahap')
                        <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">Total Cicilan</dt><dd class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format($sisaCashSum, 0, ',', '.') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">Per Termin ({{ $jumlahTerminUm }}×)</dt><dd class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format($perTerminBertahapSum, 0, ',', '.') }}</dd></div>
                    @else
                        <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-400">Sisa Lunas Cash</dt><dd class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format($sisaCashSum, 0, ',', '.') }}</dd></div>
                    @endif

                    <div class="flex justify-between border-t border-emerald-200 pt-1.5 dark:border-emerald-900/50">
                        <dt class="text-zinc-600 dark:text-zinc-400">UTJ (BF)</dt>
                        <dd class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format($utjFloatSum, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </div>

        </div>
    @endif

    {{-- ============= NAV BUTTONS ============= --}}
    <div class="mt-4 grid grid-cols-2 gap-3">
        @if ($currentStep > 1)
            <button type="button" wire:click="prevStep"
                    wire:loading.attr="disabled" wire:target="prevStep,nextStep,submit,utjBukti"
                    class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white text-sm font-semibold text-zinc-700 active:scale-95 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                <flux:icon.arrow-left class="size-4" />
                {{ __('Sebelumnya') }}
            </button>
        @else
            <a href="{{ route('dbos.booking.index') }}" wire:navigate
               class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white text-sm font-semibold text-zinc-700 active:scale-95 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                {{ __('Batal') }}
            </a>
        @endif

        @if ($currentStep < 3)
            <button type="button" wire:click="nextStep"
                    wire:loading.attr="disabled" wire:target="nextStep,prevStep"
                    class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-orange-600 text-sm font-semibold text-white shadow active:scale-95 disabled:opacity-70">
                <span wire:loading.remove wire:target="nextStep">{{ __('Lanjut') }}</span>
                <span wire:loading wire:target="nextStep">{{ __('Memvalidasi...') }}</span>
                <svg wire:loading wire:target="nextStep" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="50.27" stroke-dashoffset="20"/>
                </svg>
                <flux:icon.arrow-right wire:loading.remove wire:target="nextStep" class="size-4" />
            </button>
        @else
            <button type="button" wire:click="submit"
                    class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white shadow active:scale-95">
                <span wire:loading.remove wire:target="submit">
                    <flux:icon.check-circle class="size-4" />
                </span>
                <svg wire:loading wire:target="submit" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="50.27" stroke-dashoffset="20"/>
                </svg>
                <span wire:loading.remove wire:target="submit">{{ __('Submit SPR') }}</span>
                <span wire:loading wire:target="submit">{{ __('Menyimpan...') }}</span>
            </button>
        @endif
    </div>

    {{-- ====== FULL-SCREEN LOADING OVERLAY untuk semua action penting ====== --}}
    {{-- Termasuk submit final, upload bukti UTJ, dan step navigation (nextStep/prevStep). --}}
    <div wire:loading.flex wire:target="submit,utjBukti,nextStep,prevStep"
         class="fixed inset-0 z-9999 items-center justify-center bg-zinc-900/70 backdrop-blur-sm"
         style="display: none;">
        <div class="mx-4 flex max-w-xs flex-col items-center gap-4 rounded-2xl bg-white p-6 shadow-2xl dark:bg-zinc-900">
            <div class="relative">
                <div class="h-14 w-14 rounded-full border-4 border-emerald-100 dark:border-emerald-950/50"></div>
                <div class="absolute inset-0 h-14 w-14 animate-spin rounded-full border-4 border-transparent border-t-emerald-600"></div>
                <div class="absolute inset-0 flex items-center justify-center">
                    <flux:icon.document-check class="size-5 text-emerald-600" />
                </div>
            </div>
            <div class="text-center">
                <div class="text-base font-bold text-zinc-900 dark:text-white">
                    <span wire:loading wire:target="utjBukti">{{ __('Mengunggah bukti UTJ...') }}</span>
                    <span wire:loading wire:target="submit">{{ __('Memproses SPR...') }}</span>
                    <span wire:loading wire:target="nextStep">{{ __('Memvalidasi step...') }}</span>
                    <span wire:loading wire:target="prevStep">{{ __('Kembali ke step sebelumnya...') }}</span>
                </div>
                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Mohon tunggu, jangan tutup atau kembali') }}
                </div>
            </div>
        </div>
    </div>

</section>
