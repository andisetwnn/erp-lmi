<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Exports\AktivitasExport;
use App\Models\Master\Sales;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Monitoring')] class extends Component
{
    use WithPagination;

    /** '' = semua kategori; selain itu salah satu kunci CATEGORIES. */
    #[Url(as: 'c')]
    public string $category = '';

    #[Url(as: 'range')]
    public string $range = 'today';

    #[Url(as: 'event')]
    public string $eventFilter = '';

    /** Pencarian bebas: isi deskripsi aktivitas atau nama orang yang melakukannya. */
    #[Url(as: 'q')]
    public string $cari = '';

    /** ID activity terbaru saat terakhir user lihat (untuk deteksi new events). */
    public int $lastSeenId = 0;

    /** Jumlah aktivitas baru sejak lastSeenId (di-update tiap poll). */
    public int $newCount = 0;

    /** Kunci => [label, ikon, warna saluran]. */
    public const CATEGORIES = [
        '' => ['Semua', 'squares-2x2', '#18181b'],
        'penjualan' => ['Penjualan', 'chart-bar', '#ea580c'],
        'keuangan' => ['Keuangan', 'banknotes', '#9333ea'],
        'kpr' => ['Pemberkasan', 'folder-open', '#0d9488'],
        'akunting' => ['Akunting', 'calculator', '#4f46e5'],
        'unit' => ['Unit', 'home-modern', '#0284c7'],
        'legal' => ['Legal', 'document-text', '#d97706'],
        'akses' => ['Akses', 'shield-check', '#e11d48'],
    ];

    /** Warna label event, sesuai nama warna di EVENT_LABELS. */
    public const NADA = [
        'orange' => '#c2410c', 'emerald' => '#047857', 'green' => '#15803d', 'teal' => '#0f766e',
        'cyan' => '#0e7490', 'blue' => '#1d4ed8', 'indigo' => '#4338ca', 'violet' => '#6d28d9',
        'purple' => '#7e22ce', 'amber' => '#b45309', 'rose' => '#be123c', 'zinc' => '#52525b',
    ];

    /**
     * Event yang membatalkan, mengoreksi, atau menghapus sesuatu. Dikumpulkan di
     * panel tersendiri supaya tidak tenggelam di antara aktivitas rutin.
     */
    private const PERLU_DILIHAT = [
        'spr.rejected', 'spr.cancelled', 'realisasi.updated', 'realisasi.deleted', 'refund.processed',
        'biaya_tambahan.dikoreksi', 'biaya_tambahan.dihapus', 'biaya_tambahan.direfund',
        'tipe.deleted', 'sertifikat.bast_dibatalkan', 'rencana_akad.dikembalikan', 'rencana_akad.batal',
        'jurnal.dibalik', 'jurnal.dihapus', 'user.dinonaktifkan', 'user.sandi_direset', 'peran.dihapus', 'izin.dicabut',
    ];

    /** Jam kerja kantor, ditandai di grafik denyut supaya aktivitas di luar jam kerja mudah terlihat. */
    private const JAM_KERJA = [8, 17];

    /** Nama log lain yang ikut terbaca di sebuah kategori (perubahan tipe rumah tercatat sebagai 'tipe_rumah'). */
    private const LOG_TAMBAHAN = ['unit' => ['tipe_rumah']];

    public const RANGES = [
        'today' => 'Hari Ini',
        '7d' => '7 Hari',
        '30d' => '30 Hari',
        'all' => 'Semua',
    ];

    public const EVENT_LABELS = [
        // Penjualan
        'booking.created'   => ['Booking Baru', 'home-modern', 'orange', 'penjualan'],
        'spr.submitted'     => ['SPR Diajukan', 'document-plus', 'emerald', 'penjualan'],
        'spr.approved'      => ['SPR Disetujui', 'check-circle', 'emerald', 'penjualan'],
        'spr.rejected'      => ['SPR Ditolak', 'x-circle', 'rose', 'penjualan'],
        'spr.cancelled'     => ['SPR Dibatalkan', 'x-mark', 'rose', 'penjualan'],
        'spr.switched'      => ['SPR Pindah Unit', 'arrows-right-left', 'blue', 'penjualan'],
        'spr.swapped'       => ['SPR Tukar Unit', 'arrow-path-rounded-square', 'indigo', 'penjualan'],
        'spr.akad'          => ['Akad Kredit', 'trophy', 'violet', 'penjualan'],
        'konsumen.signed'   => ['Konsumen Tanda Tangan', 'pencil-square', 'teal', 'penjualan'],
        // Keuangan
        'utj.verified'      => ['UTJ Diverifikasi', 'banknotes', 'purple', 'keuangan'],
        'realisasi.created' => ['Realisasi Pembayaran', 'currency-dollar', 'purple', 'keuangan'],
        'realisasi.updated' => ['Realisasi Dikoreksi', 'pencil-square', 'amber', 'keuangan'],
        'realisasi.deleted' => ['Realisasi Dihapus', 'trash', 'rose', 'keuangan'],
        'refund.processed'  => ['Refund Diproses', 'arrow-uturn-left', 'amber', 'keuangan'],
        'materai.stamped'   => ['e-Materai Ditempel', 'document-check', 'purple', 'keuangan'],
        // Unit
        'unit.created' => ['Unit Baru', 'plus-circle', 'blue', 'unit'],
        'unit.updated' => ['Unit Diubah', 'pencil-square', 'blue', 'unit'],
        'unit.status_changed' => ['Status Unit Berubah', 'arrow-path', 'cyan', 'unit'],
        'tipe.created' => ['Tipe Rumah Baru', 'cube', 'blue', 'unit'],
        'tipe.updated' => ['Tipe Rumah Diubah', 'pencil-square', 'blue', 'unit'],
        'tipe.deleted' => ['Tipe Rumah Dihapus', 'trash', 'rose', 'unit'],
        // Legal — pecah sertifikat
        'sertifikat.diajukan' => ['Pengajuan Pecah Sertifikat', 'document-plus', 'amber', 'legal'],
        'sertifikat.terbit' => ['Sertifikat Terbit', 'document-check', 'emerald', 'legal'],
        'sertifikat.diperbarui' => ['Data Sertifikat Diperbarui', 'pencil-square', 'blue', 'legal'],
        'sertifikat.ke_bank' => ['Sertifikat Diserahkan ke Bank', 'building-library', 'violet', 'legal'],
        'sertifikat.bast_dibatalkan' => ['Serah Terima Bank Dibatalkan', 'arrow-uturn-left', 'rose', 'legal'],
        // Keuangan — biaya tambahan
        'biaya_tambahan.dibayar' => ['Biaya Tambahan Dibayar', 'currency-dollar', 'purple', 'keuangan'],
        'biaya_tambahan.dikoreksi' => ['Biaya Tambahan Dikoreksi', 'pencil-square', 'amber', 'keuangan'],
        'biaya_tambahan.dihapus' => ['Biaya Tambahan Dihapus', 'trash', 'rose', 'keuangan'],
        'biaya_tambahan.direfund' => ['Biaya Tambahan Direfund', 'arrow-uturn-left', 'amber', 'keuangan'],
        // Pemberkasan KPR
        'pemberkasan.bank' => ['Bank KPR Dipilih', 'building-library', 'green', 'kpr'],
        'pemberkasan.berkas' => ['Berkas Lengkap', 'folder', 'green', 'kpr'],
        'pemberkasan.wawancara' => ['Wawancara Bank', 'chat-bubble-left-right', 'green', 'kpr'],
        'pemberkasan.sp3k' => ['SP3K', 'document-check', 'emerald', 'kpr'],
        'pemberkasan.lpa' => ['LPA', 'clipboard-document-check', 'green', 'kpr'],
        'pemberkasan.npwp' => ['NPWP Konsumen Diperbarui', 'identification', 'blue', 'kpr'],
        'rencana_akad.dibuat' => ['Rencana Akad Dibuat', 'calendar', 'green', 'kpr'],
        'rencana_akad.diajukan' => ['Rencana Akad Diajukan', 'paper-airplane', 'green', 'kpr'],
        'rencana_akad.diketahui' => ['Rencana Akad Diketahui PM', 'check-circle', 'emerald', 'kpr'],
        'rencana_akad.fix' => ['Rencana Akad Selesai', 'check-badge', 'violet', 'kpr'],
        'rencana_akad.dikembalikan' => ['Rencana Akad Dikembalikan', 'arrow-uturn-left', 'amber', 'kpr'],
        'rencana_akad.batal' => ['Rencana Akad Dibatalkan', 'x-circle', 'rose', 'kpr'],
        // Akunting
        'jurnal.dibuat' => ['Jurnal Dibuat', 'document-plus', 'indigo', 'akunting'],
        'jurnal.diubah' => ['Jurnal Diubah', 'pencil-square', 'amber', 'akunting'],
        'jurnal.diposting' => ['Jurnal Diposting', 'check-circle', 'indigo', 'akunting'],
        'jurnal.dibalik' => ['Jurnal Dibalik', 'arrow-uturn-left', 'amber', 'akunting'],
        'jurnal.dihapus' => ['Jurnal Dihapus', 'trash', 'rose', 'akunting'],
        'jurnal.diimpor' => ['Jurnal Diimpor', 'arrow-down-tray', 'indigo', 'akunting'],
        'jurnal.diposting_massal' => ['Jurnal Diposting Sekaligus', 'check-badge', 'indigo', 'akunting'],
        // Akses
        'user.dibuat' => ['User Baru', 'user-plus', 'blue', 'akses'],
        'user.diubah' => ['User Diubah', 'pencil-square', 'blue', 'akses'],
        'user.diaktifkan' => ['User Diaktifkan', 'check-circle', 'emerald', 'akses'],
        'user.dinonaktifkan' => ['User Dinonaktifkan', 'no-symbol', 'rose', 'akses'],
        'user.sandi_direset' => ['Password Direset', 'key', 'amber', 'akses'],
        'peran.dibuat' => ['Role Baru', 'plus-circle', 'blue', 'akses'],
        'peran.diganti_nama' => ['Role Diganti Nama', 'pencil-square', 'blue', 'akses'],
        'peran.dihapus' => ['Role Dihapus', 'trash', 'rose', 'akses'],
        'izin.diberikan' => ['Izin Diberikan', 'lock-open', 'emerald', 'akses'],
        'izin.dicabut' => ['Izin Dicabut', 'lock-closed', 'rose', 'akses'],
    ];

    /** Deskripsi tooltip per event — dijelaskan supaya user paham. */
    public const EVENT_DESC = [
        'booking.created'   => 'Booking unit baru dibuat oleh sales. Customer siap masuk ke tahap SPR.',
        'spr.submitted'     => 'Sales mengajukan SPR baru. Menunggu Keuangan verifikasi bukti transfer UTJ.',
        'spr.approved'      => 'Project Manager menyetujui isi kontrak SPR. Selanjutnya menunggu TTD konsumen.',
        'spr.rejected'      => 'SPR ditolak Keuangan (bukti UTJ tidak sesuai) atau Project Manager.',
        'spr.cancelled'     => 'SPR dibatalkan (customer mengundurkan diri, tolak bank, dll).',
        'spr.switched'      => 'Customer pindah ke unit lain. SPR lama dibatalkan, SPR baru diterbitkan (nomor transaksi PK/YYYY/MM/XXXX).',
        'spr.swapped'       => 'Dua customer saling menukar unit. Kedua SPR lama dibatalkan, 2 SPR baru diterbitkan bersamaan.',
        'spr.akad'          => 'Akad kredit terlaksana di notaris. Transaksi jual-beli sah — customer resmi jadi pemilik.',
        'konsumen.signed'   => 'Konsumen tanda tangan digital SPR melalui link WhatsApp (verifikasi NIK berhasil).',
        'utj.verified'      => 'Keuangan verifikasi bukti transfer UTJ cocok dengan mutasi bank. Status SPR jadi Diproses.',
        'realisasi.created' => 'Pembayaran termin UM dari customer diterima & dicatat Keuangan.',
        'realisasi.updated' => 'Data realisasi pembayaran dikoreksi (tanggal / jumlah / metode / keterangan). Nomor kwitansi tetap.',
        'realisasi.deleted' => 'Pembayaran yang salah catat dihapus Keuangan. Sisa uang muka customer kembali seperti sebelum dicatat.',
        'refund.processed'  => 'Refund dana ke customer diproses (setelah SPR dibatalkan).',
        'materai.stamped'   => 'e-Materai ditempel di dokumen SPR final (langkah terakhir). SPR sekarang sah bermaterai.',
        'unit.created'         => 'Unit rumah baru ditambahkan ke master data.',
        'unit.updated'         => 'Data unit rumah diubah (blok, tipe, harga, dll).',
        'unit.status_changed'  => 'Status unit berubah antara Tersedia / Booking / Terjual, beserta sebabnya. Perubahan tangan di Master Rumah tercatat sebagai manual.',
        'tipe.created'         => 'Tipe rumah baru ditambahkan (luas, harga, skema uang muka).',
        'tipe.updated'         => 'Data tipe rumah diubah — harga dan skema uang muka unit baru mengikuti perubahan ini.',
        'tipe.deleted'         => 'Tipe rumah dihapus dari master data.',
        'sertifikat.diajukan'  => 'Legal mengajukan pemecahan sertifikat induk untuk sejumlah unit.',
        'sertifikat.terbit'    => 'Sertifikat satu unit sudah terbit dan nomornya dicatat.',
        'sertifikat.diperbarui' => 'Data sertifikat yang sudah tercatat dikoreksi — isi lama dan barunya tersimpan di riwayat unitnya.',
        'sertifikat.ke_bank'   => 'Sertifikat diserahkan ke bank sebagai jaminan kredit, disertai dokumen serah terima.',
        'sertifikat.bast_dibatalkan' => 'Penyerahan ke bank dibatalkan — sertifikatnya kembali berstatus Terbit.',
        'biaya_tambahan.dibayar' => 'Pembayaran biaya tambahan unit dicatat dengan nomor kuitansinya.',
        'biaya_tambahan.dikoreksi' => 'Pembayaran biaya tambahan yang sudah dicatat dikoreksi; isi lama dan barunya tersimpan.',
        'biaya_tambahan.dihapus' => 'Pembayaran biaya tambahan yang salah catat dihapus.',
        'biaya_tambahan.direfund' => 'Biaya tambahan SPR yang batal dikembalikan ke konsumen.',
        'pemberkasan.bank' => 'Admin KPR memilih atau mengganti bank KPR konsumen.',
        'pemberkasan.berkas' => 'Berkas KPR konsumen dinyatakan lengkap dan diserahkan ke bank.',
        'pemberkasan.wawancara' => 'Tanggal wawancara bank diisi atau dikoreksi.',
        'pemberkasan.sp3k' => 'SP3K dari bank dicatat: tanggal, nomor, masa berlaku, dan nominalnya.',
        'pemberkasan.lpa' => 'Tanggal LPA (khusus BTN) diisi atau dikoreksi.',
        'pemberkasan.npwp' => 'NPWP konsumen diperbarui dari halaman pemberkasan.',
        'rencana_akad.dibuat' => 'Admin KPR membuat rencana akad baru.',
        'rencana_akad.diajukan' => 'Rencana akad diajukan ke Project Manager.',
        'rencana_akad.diketahui' => 'Project Manager menyetujui rencana akad.',
        'rencana_akad.fix' => 'Rencana akad selesai; tanggal akad unit-unitnya terkunci.',
        'rencana_akad.dikembalikan' => 'Rencana akad dikembalikan ke Draft untuk diperbaiki.',
        'rencana_akad.batal' => 'Rencana akad dibatalkan.',
        'jurnal.dibuat' => 'Jurnal baru dibuat sebagai draft.',
        'jurnal.diubah' => 'Jurnal draft diubah.',
        'jurnal.diposting' => 'Jurnal diposting dan tidak bisa diubah lagi.',
        'jurnal.dibalik' => 'Jurnal yang sudah diposting dibalik dengan jurnal reversal.',
        'jurnal.dihapus' => 'Jurnal draft dihapus.',
        'jurnal.diimpor' => 'Jurnal diimpor massal dari berkas Excel; dicatat sekali per impor.',
        'jurnal.diposting_massal' => 'Beberapa jurnal draft diposting sekaligus; dicatat sekali, daftar nomor buktinya ada di detail.',
        'user.dibuat' => 'Akun user baru dibuat beserta role-nya.',
        'user.diubah' => 'Data atau role user diubah.',
        'user.diaktifkan' => 'Akun user diaktifkan kembali.',
        'user.dinonaktifkan' => 'Akun user dinonaktifkan dan tidak bisa masuk lagi.',
        'user.sandi_direset' => 'Password user direset oleh admin.',
        'peran.dibuat' => 'Role baru dibuat.',
        'peran.diganti_nama' => 'Nama role diganti.',
        'peran.dihapus' => 'Role dihapus.',
        'izin.diberikan' => 'Izin akses diberikan ke sebuah role.',
        'izin.dicabut' => 'Izin akses dicabut dari sebuah role.',
    ];

    /**
     * Kategori yang ikut terbaca saat tidak ada kategori yang dipilih.
     *
     * Diturunkan dari CATEGORIES, bukan ditulis ulang: daftarnya dipakai di tiga
     * kueri berbeda, dan kategori baru yang lupa ditambahkan ke salah satunya
     * akan hilang diam-diam dari feed tanpa pesan galat apa pun.
     *
     * @return list<string>
     */
    private function logTerpantau(): array
    {
        return collect(array_keys(self::CATEGORIES))
            ->filter()
            ->flatMap(fn ($kategori) => $this->logNames($kategori))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function logNames(string $kategori): array
    {
        return [$kategori, ...(self::LOG_TAMBAHAN[$kategori] ?? [])];
    }

    public function kategoriLabel(?string $logName): string
    {
        return self::CATEGORIES[$this->kategoriKunci($logName)][0] ?? ucfirst((string) $logName);
    }

    public function kategoriKunci(?string $logName): string
    {
        foreach (self::LOG_TAMBAHAN as $kategori => $nama) {
            if (in_array($logName, $nama, true)) {
                return $kategori;
            }
        }

        return (string) $logName;
    }

    public function warnaKategori(?string $logName): string
    {
        return self::CATEGORIES[$this->kategoriKunci($logName)][2] ?? self::NADA['zinc'];
    }

    public function pilihEvent(string $event): void
    {
        $this->eventFilter = $this->eventFilter === $event ? '' : $event;
        $this->resetPage();
    }

    public function setCategory(string $c): void
    {
        if (! array_key_exists($c, self::CATEGORIES)) return;
        $this->category = $c;
        $this->eventFilter = '';
        $this->resetPage();
    }

    public function setRange(string $r): void
    {
        if (! array_key_exists($r, self::RANGES)) return;
        $this->range = $r;
        $this->resetPage();
    }

    public function updatingEventFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Mencari biasanya untuk menelusuri riwayat (pergerakan sebuah unit, satu
     * konsumen), jadi begitu mulai mencari periodenya dibuka ke Semua.
     */
    public function updatedCari(string $nilai): void
    {
        $this->cari = trim($nilai);
        if ($this->cari !== '' && $this->range !== 'all') {
            $this->range = 'all';
        }
        $this->resetPage();
    }

    /** Unduh aktivitas sesuai saringan yang sedang tampil, tanpa batas halaman. */
    public function unduhExcel(): BinaryFileResponse
    {
        $labelKategori = collect($this->logTerpantau())->mapWithKeys(fn ($log) => [$log => $this->kategoriLabel($log)])->all();

        return Excel::download(
            new AktivitasExport($this->saring(Activity::query()->with('causer'))->orderByDesc('created_at')->orderByDesc('id'), $labelKategori),
            'aktivitas_'.now()->format('Y-m-d_His').'.xlsx',
        );
    }

    /** @param  Builder<Activity>  $query */
    private function cocokkanCari(Builder $query): Builder
    {
        if ($this->cari === '') {
            return $query;
        }

        $pola = '%'.addcslashes($this->cari, '%_\\').'%';

        return $query->where(fn ($q) => $q
            ->where('description', 'like', $pola)
            ->orWhereHasMorph('causer', [User::class, Sales::class], fn ($c, $tipe) => $c->where($tipe === User::class ? 'name' : 'nama', 'like', $pola)));
    }

    public function mount(): void
    {
        // Set baseline: ID activity paling baru saat page loaded
        $this->lastSeenId = (int) Activity::max('id');
    }

    /**
     * Dipanggil tiap 30 detik oleh wire:poll.
     * Cek berapa aktivitas baru sejak lastSeenId (yang match filter aktif).
     * Kalau ada, dispatch event ke browser untuk toast + update banner.
     */
    public function checkNewActivities(): void
    {
        $query = $this->cocokkanCari(Activity::query())
            ->when($this->category !== '',
                fn ($q) => $q->whereIn('log_name', $this->logNames($this->category)),
                fn ($q) => $q->whereIn('log_name', $this->logTerpantau()))
            ->when($this->eventFilter, fn ($q) => $q->where('event', $this->eventFilter))
            ->where('id', '>', $this->lastSeenId);

        $before = $this->newCount;
        $this->newCount = (int) $query->count();

        // Ada event baru yang belum ada sebelumnya → trigger notif browser
        if ($this->newCount > $before && $this->newCount > 0) {
            $latest = $query->orderByDesc('id')->limit(3)->get(['description', 'event', 'log_name']);
            $this->dispatch('new-monitoring-activities',
                count: $this->newCount - $before,
                total: $this->newCount,
                latest: $latest->pluck('description')->toArray(),
            );
        }
    }

    /**
     * User klik banner "X aktivitas baru" → refresh feed & reset baseline.
     */
    public function refreshFeed(): void
    {
        $this->lastSeenId = (int) Activity::max('id');
        $this->newCount = 0;
        $this->resetPage();
    }

    /** Periode dihitung per hari kalender, supaya "7 Hari" sama dengan tujuh baris di grafik denyut. */
    private function rangeStart(): ?CarbonInterface
    {
        return match ($this->range) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(6)->startOfDay(),
            '30d' => now()->subDays(29)->startOfDay(),
            default => null,
        };
    }

    /** Batasi ke kategori yang dipilih, pencarian, dan periode aktif. */
    private function saring(Builder $query, bool $denganEvent = true): Builder
    {
        return $this->cocokkanCari($query)
            ->whereIn('log_name', $this->category !== '' ? $this->logNames($this->category) : $this->logTerpantau())
            ->when($this->rangeStart(), fn ($q, $mulai) => $q->where('created_at', '>=', $mulai))
            ->when($denganEvent && $this->eventFilter, fn ($q) => $q->where('event', $this->eventFilter));
    }

    public function with(): array
    {
        $activities = $this->saring(Activity::query()->with(['causer', 'subject']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(30);

        // Saluran selalu menghitung semua kategori, supaya yang tidak sedang dipilih tetap terbaca angkanya.
        $perLog = $this->cocokkanCari(Activity::query())
            ->whereIn('log_name', $this->logTerpantau())
            ->when($this->rangeStart(), fn ($q, $mulai) => $q->where('created_at', '>=', $mulai))
            ->selectRaw('log_name, COUNT(*) as cnt')->groupBy('log_name')
            ->pluck('cnt', 'log_name');

        $terakhirLog = $this->cocokkanCari(Activity::query())
            ->whereIn('log_name', $this->logTerpantau())
            ->selectRaw('log_name, MAX(created_at) as terakhir')->groupBy('log_name')
            ->pluck('terakhir', 'log_name');

        $saluran = collect(self::CATEGORIES)->except('')->map(function ($kategori, $kunci) use ($perLog, $terakhirLog) {
            $logs = $this->logNames($kunci);
            $terakhir = collect($logs)->map(fn ($log) => $terakhirLog[$log] ?? null)->filter()->max();

            return [
                'jumlah' => (int) collect($logs)->sum(fn ($log) => $perLog[$log] ?? 0),
                'terakhir' => $terakhir ? CarbonImmutable::parse($terakhir) : null,
            ];
        });

        // Rincian event hanya untuk saluran yang dipilih; tidak terpengaruh event yang sedang disaring.
        $perEvent = $this->category === '' ? collect() : $this->saring(Activity::query(), denganEvent: false)
            ->selectRaw('event, COUNT(*) as cnt')->groupBy('event')
            ->orderByDesc('cnt')
            ->pluck('cnt', 'event');

        $perluDilihat = $this->saring(Activity::query()->with('causer'), denganEvent: false)
            ->where(fn ($q) => $q->whereIn('event', self::PERLU_DILIHAT)
                ->orWhere(fn ($q) => $q->where('event', 'unit.status_changed')->where('properties->sumber', 'manual')))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(6)->get();

        return [
            'activities' => $activities,
            'saluran' => $saluran,
            'perEvent' => $perEvent,
            'perluDilihat' => $perluDilihat,
            'denyut' => $this->denyut(),
        ];
    }

    /**
     * Sebaran aktivitas per 15 menit untuk tiap hari di periode aktif, terbaru di atas.
     * Periode "Semua" dibatasi 30 hari terakhir supaya grafiknya tetap terbaca.
     *
     * @return array{hari: list<array{tanggal: CarbonInterface, ketukan: list<array{slot: int, n: int, warna: string, judul: string}>}>, puncak: int, jamKerja: array{0: int, 1: int}}
     */
    private function denyut(): array
    {
        $mulai = $this->rangeStart() ?? now()->subDays(29)->startOfDay();

        $baris = $this->saring(Activity::query())
            ->where('created_at', '>=', $mulai)
            ->toBase()
            ->get(['created_at', 'log_name']);

        /** @var array<string, array<int, array<string, int>>> $ember tanggal => slot => kategori => jumlah */
        $ember = [];
        foreach ($baris as $b) {
            $waktu = (string) $b->created_at;
            $slot = intdiv((int) substr($waktu, 11, 2) * 60 + (int) substr($waktu, 14, 2), 15);
            $kategori = $this->kategoriKunci($b->log_name);
            $ember[substr($waktu, 0, 10)][$slot][$kategori] = ($ember[substr($waktu, 0, 10)][$slot][$kategori] ?? 0) + 1;
        }

        $puncak = 1;
        $hari = [];
        for ($tanggal = now()->startOfDay(); $tanggal->gte($mulai); $tanggal = $tanggal->subDay()) {
            $ketukan = [];
            foreach ($ember[$tanggal->toDateString()] ?? [] as $slot => $perKategori) {
                arsort($perKategori);
                $n = array_sum($perKategori);
                $puncak = max($puncak, $n);
                $dari = $tanggal->addMinutes($slot * 15);
                $rincian = collect($perKategori)->map(fn ($jumlah, $kategori) => self::CATEGORIES[$kategori][0].' '.$jumlah)->implode(', ');

                $ketukan[] = [
                    'slot' => $slot,
                    'n' => $n,
                    'warna' => self::CATEGORIES[array_key_first($perKategori)][2] ?? self::NADA['zinc'],
                    'judul' => $dari->translatedFormat('D j M').' · '.$dari->format('H:i').'–'.$dari->addMinutes(15)->format('H:i')
                        .' · '.$n.' aktivitas ('.$rincian.')',
                ];
            }
            $hari[] = ['tanggal' => $tanggal, 'ketukan' => $ketukan];
        }

        return ['hari' => $hari, 'puncak' => $puncak, 'jamKerja' => self::JAM_KERJA];
    }
}; ?>

<section class="rk w-full"
         wire:poll.30s="checkNewActivities"
         x-data="{
            requestNotifPermission() {
                if ('Notification' in window && Notification.permission === 'default') {
                    Notification.requestPermission();
                }
            },
            showBrowserNotif(title, body) {
                if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
                    new Notification(title, { body, icon: '/favicon.ico', tag: 'monitoring' });
                }
            }
         }"
         x-init="requestNotifPermission()"
         @new-monitoring-activities.window="
            const count = $event.detail.count ?? $event.detail[0]?.count;
            const total = $event.detail.total ?? $event.detail[0]?.total;
            const latest = $event.detail.latest ?? $event.detail[0]?.latest ?? [];
            const preview = latest[0] ?? '';
            // Toast Flux
            if (window.Flux) {
                Flux.toast({
                    variant: 'info',
                    heading: count + ' aktivitas baru',
                    text: preview,
                    duration: 4000,
                });
            }
            // Browser desktop notif (kalau tab tidak aktif)
            showBrowserNotif('ERP-LMI — ' + count + ' aktivitas baru', preview);
            // Update title dengan badge count
            if (document.hidden) {
                document.title = '(' + total + ') Monitoring · ERP-LMI';
            }
         "
         @visibilitychange.window="if (!document.hidden) document.title = 'Monitoring · ERP-LMI'">
    <style>
        /* Monitoring — kartu putih berbingkai seperti halaman lain; warna dipakai hanya untuk membedakan divisi. */
        .rk { --teks: #18181b; --redup: #52525b; --samar: #71717a; --garis: #e4e4e7; --halus: #f4f4f5; --oranye: #f97316; color: var(--teks); font-variant-numeric: tabular-nums; }
        .rk button { cursor: pointer; }
        .rk button:focus-visible { outline: 2px solid #18181b; outline-offset: 2px; border-radius: 8px; }

        /* ---------- kepala ---------- */
        .rk-kepala { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px 32px; margin-bottom: 20px; }
        .rk-langsung { display: inline-flex; align-items: center; gap: 8px; margin-top: 10px; padding: 4px 10px; border-radius: 999px; background: #ecfdf5; color: #047857; font-size: 11px; font-weight: 700; }
        .rk-titik { position: relative; flex: none; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
        .rk-titik::after { content: ''; position: absolute; inset: 0; border-radius: 50%; background: currentColor; animation: rk-pancar 1.8s ease-out infinite; }
        @keyframes rk-pancar { from { transform: scale(1); opacity: .6; } to { transform: scale(2.8); opacity: 0; } }
        .rk-waktu { text-align: right; }
        .rk-jam { font-weight: 600; letter-spacing: -.01em; font-size: clamp(28px, 3vw, 40px); line-height: 1; font-variant-numeric: tabular-nums; }
        .rk-detik { color: #a1a1aa; }
        .rk-tanggal { margin-top: 8px; font-size: 12px; color: var(--samar); }

        /* ---------- panel ---------- */
        .rk-panel { position: relative; border: 1px solid var(--garis); border-radius: 12px; background: #fff; padding: 16px 18px; box-shadow: 0 1px 2px rgba(0, 0, 0, .05); }
        .rk-panel-kepala { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px 20px; margin-bottom: 14px; }
        .rk-h2 { font-size: 15px; font-weight: 600; }
        .rk-ket { margin-top: 3px; font-size: 12px; line-height: 1.45; color: var(--samar); }
        .rk-rentang { display: inline-flex; padding: 3px; border: 1px solid var(--garis); border-radius: 10px; background: #fff; }
        .rk-rentang button { padding: 6px 12px; border-radius: 7px; font-size: 12px; font-weight: 600; color: var(--redup); transition: background .15s, color .15s; }
        .rk-rentang button:hover { background: var(--halus); }
        .rk-rentang button.aktif { background: #059669; color: #fff; }

        /* ---------- denyut: satu lintasan per hari, satu garis per 15 menit ---------- */
        .rk-grafik { --tinggi: 26px; --celah: 6px; position: relative; display: grid; grid-template-columns: 64px minmax(0, 1fr); column-gap: 12px; row-gap: var(--celah); align-items: center; }
        .rk-denyut--tunggal .rk-grafik { --tinggi: 96px; }
        .rk-denyut--bulan .rk-grafik { --tinggi: 9px; --celah: 3px; }
        .rk-hari { font-size: 10.5px; line-height: 1; color: var(--samar); text-align: right; white-space: nowrap; }
        .rk-hari.minggu { color: #e11d48; }
        .rk-hari.ini { color: var(--teks); font-weight: 500; }
        .rk-lintasan { position: relative; height: var(--tinggi); border-radius: 3px 3px 0 0; background: #fafafa; border-bottom: 1px solid #d4d4d8; }
        .rk-pita { position: absolute; top: 0; bottom: 0; background: rgba(24, 24, 27, .045); }
        .rk-ketuk { position: absolute; bottom: 0; width: max(2px, calc(100% / 96 - 1.5px)); border-radius: 2px 2px 0 0; background: var(--w); }
        .rk-ketuk:hover { filter: brightness(1.15) saturate(1.2); }
        .rk-kini { position: absolute; top: -3px; bottom: -1px; width: 2px; margin-left: -1px; background: var(--oranye); }
        .rk-kini::before { content: ''; position: absolute; top: -3px; left: -2.5px; width: 7px; height: 7px; border-radius: 50%; background: var(--oranye); }
        .rk-denyut--bulan .rk-kini::before { display: none; }
        .rk-sumbu { position: relative; height: 14px; margin-top: 4px; font-size: 10px; color: var(--samar); }
        .rk-sumbu span { position: absolute; top: 0; transform: translateX(-50%); }
        .rk-sumbu span:first-child { transform: none; }
        .rk-sumbu span:last-child { transform: translateX(-100%); }
        .rk-kosong-denyut { position: absolute; inset: 0 0 18px 76px; display: grid; place-items: center; font-size: 12.5px; color: var(--samar); pointer-events: none; }
        .rk-legenda { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--halus); font-size: 11.5px; color: var(--redup); }
        .rk-legenda span { display: inline-flex; align-items: center; gap: 7px; }
        .rk-legenda span::before { content: ''; width: 9px; height: 9px; border-radius: 2px; background: var(--w); }
        .rk-legenda .pita::before { width: 16px; background: #ececee; }
        .rk-legenda .kini::before { width: 2px; height: 11px; background: var(--oranye); }

        /* ---------- badan ---------- */
        .rk-badan { display: grid; grid-template-columns: minmax(0, 1fr); gap: 18px; margin-top: 18px; align-items: start; }
        @media (min-width: 1080px) { .rk-badan { grid-template-columns: 300px minmax(0, 1fr); } }
        .rk-samping { display: grid; gap: 18px; }

        .rk-saluran { display: grid; gap: 2px; }
        .rk-kanal { display: grid; grid-template-columns: 4px minmax(0, 1fr) auto; align-items: center; gap: 12px; width: 100%; padding: 9px 10px 9px 0; border-radius: 8px; text-align: left; transition: background .15s; }
        .rk-kanal:hover { background: #fafafa; }
        .rk-kanal.aktif { background: var(--halus); }
        .rk-pip { align-self: stretch; border-radius: 0 3px 3px 0; background: var(--w); opacity: .35; transition: opacity .15s; }
        .rk-kanal:hover .rk-pip, .rk-kanal.aktif .rk-pip { opacity: 1; }
        .rk-nama { display: block; font-size: 13.5px; font-weight: 600; }
        .rk-meter { display: block; height: 4px; margin-top: 7px; border-radius: 4px; background: var(--halus); overflow: hidden; }
        .rk-meter i { display: block; height: 100%; border-radius: inherit; background: var(--w); }
        .rk-terakhir { display: block; margin-top: 6px; font-size: 11px; color: var(--samar); }
        .rk-angka { font-size: 20px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; color: var(--w); }
        .rk-angka.nol { color: #a1a1aa; }

        .rk-rinci { display: grid; gap: 1px; margin: 4px 0 8px 16px; padding-left: 10px; border-left: 1px dashed #d4d4d8; }
        .rk-rinci button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 6px 8px; border-radius: 6px; font-size: 12.5px; color: var(--redup); text-align: left; }
        .rk-rinci button::before { content: ''; flex: none; width: 6px; height: 6px; border-radius: 50%; background: var(--nada); }
        .rk-rinci button:hover { background: #fafafa; color: var(--teks); }
        .rk-rinci button.aktif { background: var(--halus); color: var(--teks); font-weight: 600; }
        .rk-rinci-nama { flex: 1; min-width: 0; }
        .rk-rinci-angka { font-size: 11.5px; color: var(--teks); }

        .rk-waspada-daftar { display: grid; gap: 8px; }
        .rk-waspada-item { padding: 10px 12px; border: 1px solid var(--garis); border-left: 3px solid var(--nada); border-radius: 8px; background: #fafafa; }
        .rk-waspada-atas { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
        .rk-waspada-item p { margin-top: 4px; font-size: 12.5px; line-height: 1.45; overflow-wrap: anywhere; }
        .rk-waspada-item time { font-size: 10.5px; color: var(--samar); white-space: nowrap; }
        .rk-oleh { display: block; margin-top: 4px; font-size: 11px; color: var(--samar); }
        .rk-label { font-size: 10.5px; font-weight: 500; letter-spacing: .06em; text-transform: uppercase; color: var(--nada); }
        .rk-tenang { font-size: 12.5px; line-height: 1.5; color: var(--samar); }
        .rk-cari { margin-bottom: 18px; }
        .rk mark { border-radius: 3px; padding: 0 2px; background: #fef08a; color: inherit; }

        /* ---------- arus aktivitas ---------- */
        .rk-arus { padding: 0; overflow: hidden; }
        .rk-arus > .rk-panel-kepala { padding: 16px 18px 0; margin-bottom: 4px; }
        .rk-aksi { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .rk-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 11px; border: 1px solid #d4d4d8; border-radius: 999px; background: #fff; font-size: 12px; font-weight: 600; }
        .rk-chip::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--nada); }
        .rk-chip:hover { background: #fafafa; }
        .rk-tombol-baru { display: inline-flex; align-items: center; gap: 9px; padding: 6px 12px; border: 1px solid #fcd34d; border-radius: 999px; background: #fffbeb; color: #92400e; font-size: 12px; font-weight: 700; }
        .rk-tombol-baru:hover { background: #fef3c7; }
        .rk-tombol-ikon { display: inline-grid; place-items: center; width: 32px; height: 32px; border: 1px solid var(--garis); border-radius: 8px; background: #fff; color: var(--redup); }
        .rk-tombol-ikon:hover { background: #fafafa; color: var(--teks); }
        .rk-tombol { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border: 1px solid var(--garis); border-radius: 8px; background: #fff; font-size: 12px; font-weight: 600; color: var(--redup); }
        .rk-tombol:hover { background: #fafafa; color: var(--teks); }
        .rk-tombol:disabled { opacity: .5; cursor: wait; }
        .rk-pemisah { display: flex; align-items: center; gap: 12px; padding: 16px 18px 6px; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--samar); }
        .rk-pemisah::after { content: ''; flex: 1; height: 1px; background: var(--garis); }
        .rk-baris { display: grid; grid-template-columns: 44px 3px minmax(0, 1fr) auto; column-gap: 14px; align-items: start; padding: 11px 18px; transition: background .15s; }
        .rk-baris + .rk-baris { border-top: 1px solid var(--halus); }
        .rk-baris:hover { background: #fafafa; }
        .rk-baris.baru { background: linear-gradient(90deg, #fffbeb, rgba(255, 251, 235, 0) 65%); animation: rk-masuk .6s ease-out both; }
        @keyframes rk-masuk { from { opacity: 0; transform: translateY(-4px); } }
        .rk-pukul { padding-top: 2px; font-size: 12px; color: var(--samar); font-variant-numeric: tabular-nums; }
        .rk-garis-kanal { align-self: stretch; border-radius: 3px; background: var(--w); }
        .rk-isi-atas { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; }
        .rk-kat { font-size: 11px; color: #a1a1aa; }
        .rk-tanda-baru { padding: 1px 6px; border-radius: 4px; background: #f59e0b; color: #fff; font-size: 9.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .rk-desk { margin-top: 3px; font-size: 13.5px; font-weight: 500; line-height: 1.45; overflow-wrap: anywhere; }
        .rk-meta { margin-top: 3px; font-size: 11.5px; color: var(--samar); }
        .rk-meta b { font-weight: 600; color: var(--redup); }
        .rk-nilai { padding-top: 2px; font-size: 12.5px; font-weight: 500; color: #047857; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .rk-halaman { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 6px; padding: 12px 18px; border-top: 1px solid var(--garis); font-size: 12px; color: var(--samar); }
        .rk-halaman button { padding: 7px 12px; border: 1px solid var(--garis); border-radius: 8px; background: #fff; font-size: 12px; font-weight: 600; color: var(--teks); }
        .rk-halaman button:hover:not(:disabled) { background: #fafafa; }
        .rk-halaman button:disabled { opacity: .4; cursor: default; }
        .rk-kosong { padding: 56px 18px 64px; text-align: center; font-size: 13px; color: var(--samar); }
        .rk-kosong strong { display: block; margin-bottom: 6px; font-size: 14px; color: var(--teks); }
        .rk-kosong button { margin-top: 16px; padding: 7px 14px; border: 1px solid #d4d4d8; border-radius: 999px; background: #fff; font-size: 12px; font-weight: 600; color: var(--teks); }

        @media (max-width: 640px) {
            .rk-waktu { text-align: left; }
            .rk-panel { padding: 14px; }
            .rk-grafik { grid-template-columns: 50px minmax(0, 1fr); column-gap: 8px; }
            .rk-kosong-denyut { left: 58px; }
            .rk-sumbu .minor { display: none; }
            .rk-arus > .rk-panel-kepala { padding: 14px 14px 0; }
            .rk-pemisah { padding: 14px 14px 6px; }
            .rk-baris { grid-template-columns: 40px 3px minmax(0, 1fr); column-gap: 10px; padding: 10px 14px; }
            .rk-nilai { grid-column: 3; padding-top: 4px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .rk-titik::after, .rk-baris.baru { animation: none; }
        }
    </style>

    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        @php
            $zonaWaktu = config('app.timezone');
            $singkatanZona = ['Asia/Jakarta' => 'WIB', 'Asia/Makassar' => 'WITA', 'Asia/Jayapura' => 'WIT'][$zonaWaktu] ?? $zonaWaktu;
            $totalPeriode = $saluran->sum('jumlah');
            $saluranTerbesar = max(1, $saluran->max('jumlah'));
            $modeDenyut = match ($range) { 'today' => 'tunggal', '7d' => 'pekan', default => 'bulan' };
            [$jamMasuk, $jamPulang] = $denyut['jamKerja'];
            $adaDenyut = collect($denyut['hari'])->contains(fn ($h) => $h['ketukan'] !== []);
            $labelEvent = fn (?string $event) => $this::EVENT_LABELS[$event] ?? [$event ?? 'Aktivitas', 'sparkles', 'zinc', null];
            // Deskripsi di-escape dulu, baru kata yang dicari ditandai.
            $sorot = fn (?string $teks) => $cari === '' ? e($teks) : preg_replace('~'.preg_quote(e($cari), '~').'~iu', '<mark>$0</mark>', e($teks));
        @endphp

        {{-- KEPALA --}}
        <header class="rk-kepala">
            <div>
                <flux:heading size="xl">{{ __('Monitoring') }}</flux:heading>
                <flux:subheading>{{ __('Aktivitas seluruh divisi: penjualan, keuangan, pemberkasan, akunting, unit, legal, dan akses.') }}</flux:subheading>
                <div class="rk-langsung"><span class="rk-titik"></span>{{ __('Langsung · diperbarui tiap 30 detik') }}</div>
            </div>
            <div class="rk-waktu" wire:ignore
                 x-data="{
                    jamMenit: '{{ now()->format('H:i') }}',
                    detik: ':{{ now()->format('s') }}',
                    tanggal: '{{ now()->translatedFormat('l, j F Y') }}',
                    init() {
                        const zona = '{{ $zonaWaktu }}';
                        const jam = new Intl.DateTimeFormat('id-ID', { timeZone: zona, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
                        const tgl = new Intl.DateTimeFormat('id-ID', { timeZone: zona, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                        const tik = () => {
                            const kini = new Date();
                            const b = Object.fromEntries(jam.formatToParts(kini).map(p => [p.type, p.value]));
                            this.jamMenit = b.hour + ':' + b.minute;
                            this.detik = ':' + b.second;
                            this.tanggal = tgl.format(kini);
                        };
                        tik();
                        this.timer = setInterval(tik, 1000);
                    },
                    destroy() { clearInterval(this.timer); }
                 }">
                <div class="rk-jam"><span x-text="jamMenit">{{ now()->format('H:i') }}</span><span class="rk-detik" x-text="detik">:{{ now()->format('s') }}</span></div>
                <div class="rk-tanggal"><span x-text="tanggal">{{ now()->translatedFormat('l, j F Y') }}</span> · {{ $singkatanZona }}</div>
            </div>
        </header>

        <div class="rk-cari">
            <flux:input wire:model.live.debounce.400ms="cari" icon="magnifying-glass" clearable
                        :placeholder="__('Cari aktivitas: kode unit, nama konsumen, nomor SPR, atau nama user')" />
        </div>

        {{-- DENYUT --}}
        <div class="rk-panel rk-denyut rk-denyut--{{ $modeDenyut }}">
            <div class="rk-panel-kepala">
                <div>
                    <h2 class="rk-h2">{{ __('Denyut aktivitas') }}</h2>
                    <p class="rk-ket">
                        {{ __('Setiap garis mewakili 15 menit: makin tinggi makin ramai, warnanya divisi yang paling banyak bergerak.') }}
                        @if ($range === 'all') {{ __('Grafik menampilkan 30 hari terakhir.') }} @endif
                    </p>
                </div>
                <div class="rk-rentang" role="group" aria-label="{{ __('Periode') }}">
                    @foreach ($this::RANGES as $k => $lbl)
                        <button type="button" wire:click="setRange('{{ $k }}')" @class(['aktif' => $range === $k]) aria-pressed="{{ $range === $k ? 'true' : 'false' }}">{{ $lbl }}</button>
                    @endforeach
                </div>
            </div>

            <div class="rk-grafik">
                @foreach ($denyut['hari'] as $h)
                    @php
                        $tgl = $h['tanggal'];
                        $hariIni = $tgl->isToday();
                        $tampilLabel = $modeDenyut !== 'bulan' || $hariIni || $tgl->isMonday();
                    @endphp
                    <div @class(['rk-hari', 'ini' => $hariIni, 'minggu' => $tgl->isSunday()])>
                        @if ($tampilLabel){{ $hariIni ? __('Hari ini') : $tgl->translatedFormat('D j') }}@endif
                    </div>
                    <div class="rk-lintasan" title="{{ $tgl->translatedFormat('l, j F Y') }}">
                        <span class="rk-pita" style="left: {{ round($jamMasuk / 24 * 100, 3) }}%; width: {{ round(($jamPulang - $jamMasuk) / 24 * 100, 3) }}%"></span>
                        @foreach ($h['ketukan'] as $t)
                            <span class="rk-ketuk" title="{{ $t['judul'] }}"
                                  style="left: {{ round($t['slot'] / 96 * 100, 3) }}%; height: {{ round(22 + 78 * sqrt($t['n'] / $denyut['puncak']), 1) }}%; --w: {{ $t['warna'] }}"></span>
                        @endforeach
                        @if ($hariIni)
                            <span class="rk-kini" style="left: {{ round((now()->hour * 60 + now()->minute) / 1440 * 100, 3) }}%" title="{{ __('Sekarang') }}"></span>
                        @endif
                    </div>
                @endforeach
                <div></div>
                <div class="rk-sumbu" aria-hidden="true">
                    @foreach ([0, 3, 6, 9, 12, 15, 18, 21, 24] as $jam)
                        <span @class(['minor' => $jam % 6 !== 0]) style="left: {{ round($jam / 24 * 100, 3) }}%">{{ sprintf('%02d:00', $jam) }}</span>
                    @endforeach
                </div>
                @unless ($adaDenyut)
                    <div class="rk-kosong-denyut">{{ __('Belum ada aktivitas di periode ini.') }}</div>
                @endunless
            </div>

            <div class="rk-legenda">
                @foreach ($saluran as $kunci => $s)
                    <span style="--w: {{ $this::CATEGORIES[$kunci][2] }}">{{ $this::CATEGORIES[$kunci][0] }}</span>
                @endforeach
                <span class="pita">{{ __('Jam kerja') }} {{ sprintf('%02d:00–%02d:00', $jamMasuk, $jamPulang) }}</span>
                <span class="kini">{{ __('Sekarang') }}</span>
            </div>
        </div>

        <div class="rk-badan">
            <aside class="rk-samping">
                {{-- SALURAN --}}
                <div class="rk-panel">
                    <div class="rk-panel-kepala">
                        <div>
                            <h2 class="rk-h2">{{ __('Saluran') }}</h2>
                            <p class="rk-ket">{{ number_format($totalPeriode, 0, ',', '.') }} {{ __('aktivitas') }} · {{ $this::RANGES[$range] }}. {{ __('Pilih saluran untuk melihat rinciannya.') }}</p>
                        </div>
                    </div>
                    <ul class="rk-saluran">
                        <li>
                            <button type="button" wire:click="setCategory('')" @class(['rk-kanal', 'aktif' => $category === '']) style="--w: {{ $this::CATEGORIES[''][2] }}" aria-pressed="{{ $category === '' ? 'true' : 'false' }}">
                                <span class="rk-pip"></span>
                                <span><span class="rk-nama">{{ __('Semua saluran') }}</span></span>
                                <span class="rk-angka">{{ number_format($totalPeriode, 0, ',', '.') }}</span>
                            </button>
                        </li>
                        @foreach ($saluran as $kunci => $s)
                            @php [$namaSaluran, , $warnaSaluran] = $this::CATEGORIES[$kunci]; @endphp
                            <li wire:key="saluran-{{ $kunci }}">
                                <button type="button" wire:click="setCategory('{{ $kunci }}')" @class(['rk-kanal', 'aktif' => $category === $kunci]) style="--w: {{ $warnaSaluran }}" aria-pressed="{{ $category === $kunci ? 'true' : 'false' }}">
                                    <span class="rk-pip"></span>
                                    <span>
                                        <span class="rk-nama">{{ $namaSaluran }}</span>
                                        <span class="rk-meter"><i style="width: {{ round($s['jumlah'] / $saluranTerbesar * 100, 1) }}%"></i></span>
                                        <span class="rk-terakhir">{{ $s['terakhir'] ? __('terakhir').' '.$s['terakhir']->diffForHumans() : __('belum ada catatan') }}</span>
                                    </span>
                                    <span @class(['rk-angka', 'nol' => $s['jumlah'] === 0])>{{ number_format($s['jumlah'], 0, ',', '.') }}</span>
                                </button>

                                @if ($category === $kunci && ($perEvent->isNotEmpty() || $eventFilter))
                                    <div class="rk-rinci">
                                        @foreach ($eventFilter && ! $perEvent->has($eventFilter) ? $perEvent->put($eventFilter, 0) : $perEvent as $ev => $cnt)
                                            @php [$lblEv, , $warnaEv] = $labelEvent($ev); @endphp
                                            <button type="button" wire:click="pilihEvent('{{ $ev }}')" @class(['aktif' => $eventFilter === $ev])
                                                    style="--nada: {{ $this::NADA[$warnaEv] ?? $this::NADA['zinc'] }}"
                                                    title="{{ $this::EVENT_DESC[$ev] ?? '' }}" aria-pressed="{{ $eventFilter === $ev ? 'true' : 'false' }}">
                                                <span class="rk-rinci-nama">{{ $lblEv }}</span>
                                                <span class="rk-rinci-angka">{{ number_format($cnt, 0, ',', '.') }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- PERLU DILIHAT --}}
                <div class="rk-panel">
                    <div class="rk-panel-kepala">
                        <div>
                            <h2 class="rk-h2">{{ __('Perlu dilihat') }}</h2>
                            <p class="rk-ket">{{ __('Pembatalan, koreksi, penghapusan, dan perubahan status unit yang dilakukan manual.') }}</p>
                        </div>
                    </div>
                    @if ($perluDilihat->isEmpty())
                        <p class="rk-tenang">{{ __('Tidak ada pembatalan atau koreksi di periode ini.') }}</p>
                    @else
                        <div class="rk-waspada-daftar">
                            @foreach ($perluDilihat as $act)
                                @php [$lblEv, , $warnaEv] = $labelEvent($act->event); @endphp
                                <div class="rk-waspada-item" style="--nada: {{ $this::NADA[$warnaEv] ?? $this::NADA['zinc'] }}" wire:key="waspada-{{ $act->id }}">
                                    <div class="rk-waspada-atas">
                                        <span class="rk-label">{{ $lblEv }}</span>
                                        <time datetime="{{ $act->created_at?->toIso8601String() }}">{{ $act->created_at?->isToday() ? $act->created_at->format('H:i') : $act->created_at?->translatedFormat('j M H:i') }}</time>
                                    </div>
                                    <p>{{ \App\Support\BusinessActivityLogger::shortenDesc($act->description) }}</p>
                                    <span class="rk-oleh">{{ __('oleh') }} {{ $act->causer?->name ?? $act->causer?->nama ?? __('Sistem') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </aside>

            {{-- ARUS AKTIVITAS --}}
            <div class="rk-panel rk-arus">
                <div class="rk-panel-kepala">
                    <div>
                        <h2 class="rk-h2">{{ __('Arus aktivitas') }}</h2>
                        <p class="rk-ket">
                            {{ number_format($activities->total(), 0, ',', '.') }} {{ __('aktivitas') }}
                            @if ($category !== '') · {{ $this::CATEGORIES[$category][0] }} @endif
                            @if ($cari !== '') · {{ __('cocok dengan') }} “{{ $cari }}” @endif
                            · {{ $this::RANGES[$range] }}
                        </p>
                    </div>
                    <div class="rk-aksi">
                        @if ($eventFilter)
                            @php [$lblEv, , $warnaEv] = $labelEvent($eventFilter); @endphp
                            <button type="button" class="rk-chip" wire:click="pilihEvent('{{ $eventFilter }}')" style="--nada: {{ $this::NADA[$warnaEv] ?? $this::NADA['zinc'] }}" title="{{ __('Hapus saringan') }}">
                                {{ $lblEv }} <span aria-hidden="true">✕</span>
                            </button>
                        @endif
                        @if ($newCount > 0)
                            <button type="button" class="rk-tombol-baru" wire:click="refreshFeed">
                                <span class="rk-titik" style="color: var(--oranye)"></span>
                                {{ $newCount }} {{ __('baru · tandai sudah dilihat') }}
                            </button>
                        @endif
                        @if ($activities->total() > 0)
                            <button type="button" class="rk-tombol" wire:click="unduhExcel" wire:loading.attr="disabled" wire:target="unduhExcel">
                                <flux:icon.arrow-down-tray class="size-4" />
                                <span wire:loading.remove wire:target="unduhExcel">{{ __('Unduh Excel') }}</span>
                                <span wire:loading wire:target="unduhExcel">{{ __('Menyiapkan…') }}</span>
                            </button>
                        @endif
                        <button type="button" class="rk-tombol-ikon" wire:click="$refresh" title="{{ __('Muat ulang') }}" aria-label="{{ __('Muat ulang') }}">
                            <flux:icon.arrow-path class="size-4" wire:loading.class="animate-spin" wire:target="$refresh" />
                        </button>
                    </div>
                </div>

                @if ($activities->isEmpty())
                    <div class="rk-kosong">
                        @if ($cari !== '')
                            <strong>{{ __('Tidak ada aktivitas yang cocok dengan') }} “{{ $cari }}”</strong>
                            {{ __('Periksa ejaannya, atau cari dengan kata yang lebih pendek, misalnya nama depan konsumen.') }}
                        @else
                            <strong>{{ __('Belum ada aktivitas di periode ini') }}</strong>
                            {{ __('Aktivitas baru akan muncul di sini begitu tercatat.') }}
                        @endif
                        @if ($range !== 'all')
                            <div><button type="button" wire:click="setRange('all')">{{ __('Lihat semua periode') }}</button></div>
                        @endif
                    </div>
                @else
                    @php $hariSebelumnya = null; @endphp
                    @foreach ($activities as $act)
                        @php
                            [$lbl, , $col] = $labelEvent($act->event);
                            $props = $act->properties ?? collect();
                            $causerName = $act->causer?->name ?? $act->causer?->nama ?? 'Sistem';
                            $hari = $act->created_at?->toDateString();
                            $baru = $act->id > $lastSeenId;
                        @endphp
                        @if ($hari !== $hariSebelumnya)
                            <div class="rk-pemisah" wire:key="hari-{{ $hari }}">
                                {{ $act->created_at?->isToday() ? __('Hari ini').' · ' : ($act->created_at?->isYesterday() ? __('Kemarin').' · ' : '') }}{{ $act->created_at?->translatedFormat('l, j F Y') }}
                            </div>
                            @php $hariSebelumnya = $hari; @endphp
                        @endif
                        <article @class(['rk-baris', 'baru' => $baru]) wire:key="act-{{ $act->id }}"
                                 style="--w: {{ $this->warnaKategori($act->log_name) }}; --nada: {{ $this::NADA[$col] ?? $this::NADA['zinc'] }}">
                            <time class="rk-pukul" datetime="{{ $act->created_at?->toIso8601String() }}" title="{{ $act->created_at?->diffForHumans() }}">{{ $act->created_at?->format('H:i') }}</time>
                            <span class="rk-garis-kanal" aria-hidden="true"></span>
                            <div>
                                <div class="rk-isi-atas">
                                    <span class="rk-label">{{ $lbl }}</span>
                                    <span class="rk-kat">{{ $this->kategoriLabel($act->log_name) }}</span>
                                    @if ($baru)<span class="rk-tanda-baru">{{ __('Baru') }}</span>@endif
                                </div>
                                <p class="rk-desk">{!! $sorot(\App\Support\BusinessActivityLogger::shortenDesc($act->description)) !!}</p>
                                <div class="rk-meta">
                                    {{ __('oleh') }} <b>{!! $sorot($causerName) !!}</b>
                                    @if (! empty($props['sales']))
                                        · {{ __('Sales') }} <b>{{ $props['sales'] }}</b>
                                    @endif
                                </div>
                            </div>
                            @if (isset($props['nilai']) && $props['nilai'] > 0)
                                <div class="rk-nilai">Rp {{ number_format((float) $props['nilai'], 0, ',', '.') }}</div>
                            @endif
                        </article>
                    @endforeach

                    @if ($activities->hasPages())
                        <nav class="rk-halaman" aria-label="{{ __('Halaman') }}">
                            <button type="button" wire:click="previousPage" @disabled($activities->onFirstPage())>← {{ __('Lebih baru') }}</button>
                            <span>{{ __('Halaman') }} {{ $activities->currentPage() }} {{ __('dari') }} {{ $activities->lastPage() }}</span>
                            <button type="button" wire:click="nextPage" @disabled(! $activities->hasMorePages())>{{ __('Lebih lama') }} →</button>
                        </nav>
                    @endif
                @endif
            </div>
        </div>
    </div>
</section>
