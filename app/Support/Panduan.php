<?php

namespace App\Support;

use App\Models\PanduanBaca;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Panduan pemakaian ERP per modul, berupa PDF yang disimpan bersama kode
 * (resources/panduan/{kunci}.pdf). Tiap orang hanya melihat panduan modul yang
 * bisa ia buka: cukup punya salah satu izin modul itu.
 *
 * Saat PDF dicetak ulang, perbarui juga 'versi' supaya tanggalnya ikut berganti —
 * semua orang lalu diminta membaca versi barunya (jejak baca dicatat per versi).
 */
final class Panduan
{
    public const BELUM = 'belum';

    public const DIBUKA = 'dibuka';

    public const DIBACA = 'dibaca';

    /** @var array<string, array{judul: string, ringkasan: string, izin: list<string>, wajib: list<string>, sales?: bool, versi: string}> */
    public const DAFTAR = [
        'pembelian' => [
            'judul' => 'Pembelian',
            'ringkasan' => 'Permintaan pembelian (PR), persetujuan FAT dan direktur, PO, pembayaran ke supplier, barang datang, tutup PO dan pengembalian kelebihan bayar, laporan, serta pengaturan.',
            'izin' => [
                'pembelian.minta', 'pembelian.review', 'pembelian.anggaran', 'pembelian.po',
                'pembelian.bayar', 'pembelian.terima', 'pembelian.lihat', 'master.supplier.kelola',
            ],
            'wajib' => ['finance', 'staf-keuangan', 'admin-teknik', 'project-manager', 'admin-kpr', 'admin-sales', 'admin-legal'],
            'versi' => '2026-11-11',
        ],
        'keuangan' => [
            'judul' => 'Keuangan',
            'ringkasan' => 'Verifikasi UTJ, mencatat cicilan uang muka dan biaya tambahan, kuitansi, tempel e-Meterai, dan pengembalian uang SPR yang batal.',
            'izin' => ['pembayaran.kelola', 'utj.verifikasi', 'biayatambahan.kelola'],
            'wajib' => ['finance', 'staf-keuangan'],
            'versi' => '2026-11-11',
        ],
        'akunting' => [
            'judul' => 'Akunting',
            'ringkasan' => 'Mencatat dan memposting jurnal, koreksi lewat reverse, Buku Besar, Kas & Bank, Laba Rugi, Neraca, Arus Kas, Aktiva Tetap, dan COA.',
            'izin' => ['jurnal.umum.kelola', 'bukubesar.lihat', 'kasbank.lihat', 'labarugi.lihat', 'neraca.lihat', 'neracasaldo.lihat', 'neracalajur.lihat', 'aruskas.lihat', 'aktivatetap.lihat', 'master.coa.kelola'],
            'wajib' => ['finance', 'staf-keuangan'],
            'versi' => '2026-11-11',
        ],
        'marketing' => [
            'judul' => 'Marketing',
            'ringkasan' => 'Sisi kantor dari penjualan: data SPR dan prospek, promo penjualan, tandai akad, pembatalan SPR, pindah kavling, dan target penjualan.',
            'izin' => ['spr.lihat', 'master.customer.kelola', 'promo.kelola', 'target.kelola', 'spr.batal', 'spr.pindah-unit', 'spr.akad'],
            'wajib' => ['admin-sales', 'admin-kpr', 'project-manager'],
            'versi' => '2026-11-11',
        ],
        'pemberkasan' => [
            'judul' => 'Pemberkasan KPR',
            'ringkasan' => 'Mencatat berkas KPR di bank dari berkas masuk sampai SP3K, menyusun Rencana Akad, serta persetujuan Project Manager dan Direksi.',
            'izin' => ['pemberkasan.kelola', 'pemberkasan.lihat', 'rencanaakad.kelola', 'rencanaakad.lihat', 'rencanaakad.mengetahui', 'rencanaakad.approve', 'master.bankkpr.kelola'],
            'wajib' => ['admin-kpr', 'project-manager'],
            'versi' => '2026-11-12',
        ],
        'approval' => [
            'judul' => 'Approval',
            'ringkasan' => 'Untuk Project Manager: tanda tangan digital, dashboard, menyetujui atau membatalkan persetujuan SPR, dan mengetahui Rencana Akad.',
            'izin' => ['spr.approve', 'rencanaakad.mengetahui'],
            'wajib' => ['project-manager'],
            'versi' => '2026-11-12',
        ],
        'perizinan' => [
            'judul' => 'Perizinan',
            'ringkasan' => 'Sertifikat per unit: pengajuan pecah sertifikat ke BPN, mencatat sertifikat yang terbit, dan serah terima sertifikat ke bank.',
            'izin' => ['legal.lihat', 'legal.pengajuan.kelola', 'legal.sertifikat.kelola', 'legal.serahterima.kelola'],
            'wajib' => ['admin-legal'],
            'versi' => '2026-11-12',
        ],
        'teknik' => [
            'judul' => 'Teknik & Matrix',
            'ringkasan' => 'Mencatat progres pembangunan, LOT, dan subcon setiap unit, lalu membaca laporan Matrix mingguan: Mikro, Makro, Non Lot, dan Akad.',
            'izin' => ['teknik.rumah.lihat', 'teknik.rumah.update', 'matrix.lihat', 'matrix.kelola'],
            'wajib' => ['admin-teknik'],
            'versi' => '2026-11-12',
        ],
        'dashboard' => [
            'judul' => 'Dashboard & Laporan',
            'ringkasan' => 'Membaca dashboard Direksi, Admin Sales, dan Executive, lalu menyaring dan mengunduh laporan penjualan dan keuangan ke Excel.',
            'izin' => ['laporan.lihat'],
            'wajib' => ['admin-sales', 'finance', 'staf-keuangan'],
            'versi' => '2026-11-12',
        ],
        'monitoring' => [
            'judul' => 'Monitoring',
            'ringkasan' => 'Memantau aktivitas semua divisi secara langsung, menyaring kejadian yang perlu dilihat, dan membaca lonceng notifikasi.',
            'izin' => ['monitoring.lihat', 'log.lihat', 'notifikasi.keuangan'],
            'wajib' => ['project-manager'],
            'versi' => '2026-11-12',
        ],
        // Pemakai utamanya super-admin, yang memang tidak dilacak.
        'admin' => [
            'judul' => 'Admin Sistem',
            'ringkasan' => 'Akun pengguna, role dan izin, data master perusahaan, proyek, tipe rumah, dan unit, serta rekap pembaca panduan.',
            'izin' => ['user.kelola', 'master.kelola', 'master.perusahaan.kelola', 'master.proyek.kelola', 'master.tipe.kelola', 'master.rumah.kelola', 'master.va.kelola', 'master.notaris.kelola'],
            'wajib' => [],
            'versi' => '2026-11-12',
        ],
        // Dibaca sales di DBOS (login terpisah, lihat rute dbos.panduan); di ERP hanya untuk
        // admin yang mengurus sales. Semua sales aktif wajib membacanya.
        'dbos' => [
            'judul' => 'DBOS',
            'ringkasan' => 'Data Booking Online System untuk sales lapangan dan pimpinan grup: prospek, status FINISH, booking unit, SPR, link tanda tangan konsumen, dan pemantauan tim.',
            'izin' => ['dbos.kelola', 'master.sales.kelola'],
            'wajib' => [],
            'sales' => true,
            'versi' => '2026-11-11',
        ],
    ];

    public static function berkas(string $kunci): string
    {
        return rtrim(config('panduan.folder') ?? resource_path('panduan'), '/\\').DIRECTORY_SEPARATOR.$kunci.'.pdf';
    }

    public static function tersedia(string $kunci): bool
    {
        return isset(self::DAFTAR[$kunci]) && is_file(self::berkas($kunci));
    }

    public static function bolehLihat(?User $user, string $kunci): bool
    {
        return $user !== null
            && self::tersedia($kunci)
            && ($user->hasRole('super-admin') || $user->canAny(self::DAFTAR[$kunci]['izin']));
    }

    /**
     * Panduan yang bisa dibuka orang ini, berurutan seperti di DAFTAR.
     *
     * @return array<string, array{judul: string, ringkasan: string, izin: list<string>, wajib: list<string>, sales?: bool, versi: string}>
     */
    public static function untuk(?User $user): array
    {
        return array_filter(self::DAFTAR, fn (string $kunci) => self::bolehLihat($user, $kunci), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Orang ini wajib membaca panduan itu: perannya termasuk yang menjalankan modulnya
     * ('wajib'). Direktur dan super-admin boleh membuka, tapi tidak dilacak.
     */
    public static function wajibBaca(?User $user, string $kunci): bool
    {
        return self::bolehLihat($user, $kunci) && $user->hasAnyRole(self::DAFTAR[$kunci]['wajib']);
    }

    /**
     * Panduan yang wajib dibaca orang ini.
     *
     * @return array<string, array{judul: string, ringkasan: string, izin: list<string>, wajib: list<string>, sales?: bool, versi: string}>
     */
    public static function wajibUntuk(?User $user): array
    {
        return array_filter(self::DAFTAR, fn (string $kunci) => self::wajibBaca($user, $kunci), ARRAY_FILTER_USE_KEY);
    }

    /** Panduan yang wajib dibaca semua sales aktif di DBOS. */
    public static function wajibSales(string $kunci): bool
    {
        return (self::DAFTAR[$kunci]['sales'] ?? false) && self::tersedia($kunci);
    }

    /**
     * Catat bahwa orang ini membuka atau mengunduh versi panduan yang sekarang.
     * Satu upsert supaya dua pembukaan bersamaan tidak saling menimpa hitungan.
     */
    public static function catatDibuka(Model $pembaca, string $kunci): void
    {
        $sekarang = now();

        PanduanBaca::upsert([[
            'pembaca_type' => $pembaca->getMorphClass(),
            'pembaca_id' => $pembaca->getKey(),
            'kunci' => $kunci,
            'versi' => self::DAFTAR[$kunci]['versi'],
            'jumlah_dibuka' => 1,
            'dibuka_pertama_at' => $sekarang,
            'dibuka_terakhir_at' => $sekarang,
        ]], ['pembaca_type', 'pembaca_id', 'kunci', 'versi'], [
            'jumlah_dibuka' => DB::raw('jumlah_dibuka + 1'),
            'dibuka_terakhir_at' => $sekarang,
        ]);
    }

    /**
     * Konfirmasi sudah membaca versi panduan yang sekarang. Hanya berlaku kalau
     * panduannya sudah pernah dibuka; konfirmasi pertama yang dipakai.
     */
    public static function konfirmasiBaca(Model $pembaca, string $kunci): bool
    {
        $baris = self::jejakSatu($pembaca, $kunci);
        if ($baris === null) {
            return false;
        }
        if ($baris->dibaca_at === null) {
            $baris->update(['dibaca_at' => now()]);
        }

        return true;
    }

    /**
     * Jejak membaca versi terkini tiap panduan milik orang ini, per kunci.
     *
     * @return Collection<string, PanduanBaca>
     */
    public static function jejak(Model $pembaca): Collection
    {
        return PanduanBaca::query()
            ->where('pembaca_type', $pembaca->getMorphClass())
            ->where('pembaca_id', $pembaca->getKey())
            ->get()
            ->filter(fn (PanduanBaca $b) => (self::DAFTAR[$b->kunci]['versi'] ?? null) === $b->versi)
            ->keyBy('kunci');
    }

    public static function jejakSatu(Model $pembaca, string $kunci): ?PanduanBaca
    {
        return PanduanBaca::query()
            ->where('pembaca_type', $pembaca->getMorphClass())
            ->where('pembaca_id', $pembaca->getKey())
            ->where('kunci', $kunci)
            ->where('versi', self::DAFTAR[$kunci]['versi'] ?? '')
            ->first();
    }

    /** 'belum' (belum pernah dibuka), 'dibuka', atau 'dibaca' (sudah dikonfirmasi). */
    public static function status(?PanduanBaca $jejak): string
    {
        return match (true) {
            $jejak?->dibaca_at !== null => self::DIBACA,
            $jejak !== null => self::DIBUKA,
            default => self::BELUM,
        };
    }

    /** Jumlah panduan wajib orang ini yang belum ia konfirmasi baca — untuk pengingat di sidebar. */
    public static function jumlahBelumDibaca(?User $user): int
    {
        $punya = array_keys(self::wajibUntuk($user));
        if ($punya === []) {
            return 0;
        }
        $jejak = self::jejak($user);

        return count(array_filter($punya, fn (string $kunci) => self::status($jejak->get($kunci)) !== self::DIBACA));
    }

    /** Nama berkas saat diunduh, mis. "Panduan Pembelian - ERP LMI (11 Nov 2026).pdf". */
    public static function namaUnduhan(string $kunci): string
    {
        $info = self::DAFTAR[$kunci];

        return 'Panduan '.$info['judul'].' - ERP LMI ('.Carbon::parse($info['versi'])->translatedFormat('j M Y').').pdf';
    }

    /**
     * PDF dikirim utuh dalam satu permintaan. Kalau server mengizinkan, penampil PDF Chrome
     * mengambil PDF "fast web view" sepotong-sepotong, dan tiap potongan melewati sesi dan
     * pengecekan login — di hosting prod itu jauh lebih lambat daripada mengunduh sekaligus.
     * Browser boleh menyimpannya sejam supaya membuka ulang tidak mengunduh lagi.
     */
    public static function respons(string $kunci, bool $unduh = false): BinaryFileResponse
    {
        $respons = response()->download(self::berkas($kunci), self::namaUnduhan($kunci), [
            'Content-Type' => 'application/pdf',
            'Accept-Ranges' => 'none',
        ], $unduh ? 'attachment' : 'inline');

        return $respons->setPrivate()->setMaxAge(3600);
    }
}
