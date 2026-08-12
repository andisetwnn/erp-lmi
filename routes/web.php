<?php

use App\Http\Controllers\BukuBesarPdfController;
use App\Http\Controllers\LaporanAkuntingPdfController;
use App\Models\Master\Perusahaan;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

// ============ FITUR #6: Public TTD Konsumen (no auth, akses via hash unik) ============
Route::livewire('spr/sign/{token}', 'pages::public.spr-sign')->name('spr.sign');
Route::livewire('spr/download/{token}', 'pages::public.spr-download')->name('spr.download.page');
Route::get('spr/preview/{token}', function (string $token) {
    // Preview dokumen SPR untuk konsumen sebelum tanda tangan.
    // Diakses via link sign yang sama (belum kadaluwarsa & belum di-sign).
    $spr = Spr::with([
        'prospectCustomer.tempatKerja',
        'rumah.tipeRumah',
        'rumah.proyek',
        'rumah.virtualAccount.bank',
        'sales',
        'utjConfirmedBy',
        'pmApprovedBy',
        'terminPembayaran',
    ])->where('konsumen_signing_link_hash', $token)->firstOrFail();

    abort_if(! $spr->konsumen_signing_link_expires_at || $spr->konsumen_signing_link_expires_at->isPast(), 410, 'Link kedaluwarsa.');

    return view('exports.spr-print', ['spr' => $spr, 'isPreview' => true]);
})->name('spr.preview');
Route::get('spr/download/{token}/file', function (string $token) {
    $spr = Spr::where('konsumen_download_link_hash', $token)->firstOrFail();
    abort_if(! $spr->konsumen_download_link_expires_at || $spr->konsumen_download_link_expires_at->isPast(), 410, 'Link kedaluwarsa.');
    abort_if(! $spr->materai_file_path, 404, 'File materai belum tersedia.');

    // Gate: NIK harus sudah diverifikasi di halaman download sebelumnya
    abort_unless(session()->get('spr-dl-nik-ok:'.$token) === true, 403, 'Verifikasi NIK dulu di halaman download.');

    $path = storage_path('app/public/'.$spr->materai_file_path);
    abort_unless(is_file($path), 404, 'File tidak ditemukan.');

    return response()->download($path, 'SPR-'.$spr->nomor_display.'-final.pdf', [
        'Content-Type' => 'application/pdf',
    ]);
})->name('spr.download.file');

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard dispatcher — redirect ke dashboard per role
    Route::get('dashboard', function () {
        $user = Auth::user();

        return redirect(match (true) {
            $user->hasRole('finance') => route('dashboard.finance'),
            $user->hasRole('project-manager') => route('dashboard.pm'),
            default => route('dashboard.executive'), // super-admin, direktur, admin-kpr
        });
    })->name('dashboard');

    Route::livewire('dashboard/executive', 'pages::dashboard.executive')->name('dashboard.executive');
    Route::livewire('dashboard/pm', 'pages::dashboard.pm')->name('dashboard.pm');
    Route::livewire('dashboard/finance', 'pages::dashboard.finance')->name('dashboard.finance');

    // USER AKSES — kelola user & role sistem pusat (super-admin)
    Route::middleware('permission:user.kelola')->group(function () {
        Route::livewire('user-akses', 'pages::master.user')->name('user-akses.index');
    });

    // MASTER — permission granular per entitas (spatie OR syntax: "master.kelola|master.<x>.kelola")
    Route::prefix('master')->name('master.')->group(function () {
        Route::middleware('permission:master.kelola|master.perusahaan.kelola')->group(function () {
            Route::livewire('perusahaan', 'pages::master.perusahaan')->name('perusahaan.index');
            Route::livewire('bank', 'pages::master.bank')->name('bank.index');
        });
        Route::middleware('permission:master.kelola|master.proyek.kelola')->group(function () {
            Route::livewire('proyek', 'pages::master.proyek')->name('proyek.index');
            Route::livewire('proyek/{id}/siteplan-mapping', 'pages::master.proyek-siteplan-mapping')->name('proyek.siteplan-mapping');
        });
        Route::middleware('permission:master.kelola|master.tipe.kelola')->group(function () {
            Route::livewire('tipe-rumah', 'pages::master.tipe-rumah')->name('tipe-rumah.index');
        });
        Route::middleware('permission:master.kelola|master.rumah.kelola')->group(function () {
            Route::livewire('rumah', 'pages::master.rumah')->name('rumah.index');
        });
        Route::middleware('permission:master.kelola|master.va.kelola')->group(function () {
            Route::livewire('virtual-account', 'pages::master.virtual-account')->name('virtual-account.index');
        });
        Route::middleware('permission:master.kelola|master.customer.kelola')->group(function () {
            Route::livewire('customer', 'pages::master.customer')->name('customer.index');
            Route::livewire('prospect-customer', 'pages::master.prospect-customer')->name('prospect-customer.index');
        });
        Route::middleware('permission:master.kelola|master.sales.kelola')->group(function () {
            Route::livewire('sales', 'pages::master.sales')->name('sales.index');
        });
        Route::middleware('permission:master.kelola|master.notaris.kelola')->group(function () {
            Route::livewire('notaris', 'pages::master.notaris')->name('notaris.index');
        });
        Route::middleware('permission:master.kelola|master.coa.kelola')->group(function () {
            Route::livewire('coa', 'pages::master.coa')->name('coa.index');
        });
        Route::middleware('permission:master.kelola')->group(function () {
            Route::livewire('alasan-pembatalan', 'pages::master.alasan-pembatalan')->name('alasan-pembatalan.index');
        });
    });

    // APPROVAL — Project Manager approve SPR
    Route::middleware('permission:spr.approve')->prefix('approval')->name('approval.')->group(function () {
        Route::livewire('spr', 'pages::approval.spr-list')->name('spr.index');
        Route::livewire('spr/{id}', 'pages::approval.spr-show')->name('spr.show')->where('id', '[0-9]+');
    });

    // FINANCE — proses approval, jurnal, dll
    Route::middleware('permission:pembayaran.kelola')->prefix('finance')->name('finance.')->group(function () {
        Route::livewire('penerimaan-konsumen', 'pages::finance.penerimaan-konsumen')->name('penerimaan-konsumen.index');
        Route::livewire('tempel-materai', 'pages::finance.tempel-materai')->name('tempel-materai.index');
    });

    // AKUNTING — Input Jurnal (Umum/Bank/Kas Kecil) + Buku Besar
    Route::prefix('akunting')->name('akunting.')->group(function () {
        // Landing page 3 card: butuh minimal salah satu permission jurnal
        Route::livewire('input-jurnal', 'pages::akunting.input-jurnal')
            ->middleware('permission:jurnal.umum.kelola|jurnal.bank.kelola|jurnal.kaskecil.kelola')
            ->name('input-jurnal.index');

        // Jurnal Umum
        Route::middleware('permission:jurnal.umum.kelola')->group(function () {
            Route::livewire('jurnal-umum', 'pages::akunting.jurnal-umum')->name('jurnal-umum.index');
        });

        // Buku Besar
        Route::middleware('permission:bukubesar.lihat')->group(function () {
            Route::livewire('buku-besar', 'pages::akunting.buku-besar')->name('buku-besar.index');
            Route::get('buku-besar/print', function () {
                return app(BukuBesarPdfController::class)->__invoke(request());
            })->name('buku-besar.print');
        });

        // Laba Rugi
        Route::middleware('permission:labarugi.lihat')->group(function () {
            Route::livewire('laba-rugi', 'pages::akunting.laba-rugi')->name('laba-rugi.index');
            Route::get('laba-rugi/print', [LaporanAkuntingPdfController::class, 'labaRugi'])
                ->name('laba-rugi.print');
            Route::get('laba-rugi/excel', [LaporanAkuntingPdfController::class, 'labaRugiExcel'])
                ->name('laba-rugi.excel');
        });

        // Neraca
        Route::middleware('permission:neraca.lihat')->group(function () {
            Route::livewire('neraca', 'pages::akunting.neraca')->name('neraca.index');
            Route::get('neraca/print', [LaporanAkuntingPdfController::class, 'neraca'])
                ->name('neraca.print');
            Route::get('neraca/excel', [LaporanAkuntingPdfController::class, 'neracaExcel'])
                ->name('neraca.excel');
        });

        // Neraca Saldo (Trial Balance)
        Route::middleware('permission:neracasaldo.lihat')->group(function () {
            Route::livewire('neraca-saldo', 'pages::akunting.neraca-saldo')->name('neraca-saldo.index');
            Route::get('neraca-saldo/print', [LaporanAkuntingPdfController::class, 'neracaSaldo'])
                ->name('neraca-saldo.print');
            Route::get('neraca-saldo/excel', [LaporanAkuntingPdfController::class, 'neracaSaldoExcel'])
                ->name('neraca-saldo.excel');
        });

        // Neraca Lajur (Worksheet 10 kolom)
        Route::middleware('permission:neracalajur.lihat')->group(function () {
            Route::livewire('neraca-lajur', 'pages::akunting.neraca-lajur')->name('neraca-lajur.index');
            Route::get('neraca-lajur/print', [LaporanAkuntingPdfController::class, 'neracaLajur'])
                ->name('neraca-lajur.print');
            Route::get('neraca-lajur/excel', [LaporanAkuntingPdfController::class, 'neracaLajurExcel'])
                ->name('neraca-lajur.excel');
        });

        // Arus Kas (Cash Flow Statement)
        Route::middleware('permission:aruskas.lihat')->group(function () {
            Route::livewire('arus-kas', 'pages::akunting.arus-kas')->name('arus-kas.index');
            Route::get('arus-kas/print', [LaporanAkuntingPdfController::class, 'arusKas'])
                ->name('arus-kas.print');
            Route::get('arus-kas/excel', [LaporanAkuntingPdfController::class, 'arusKasExcel'])
                ->name('arus-kas.excel');
        });

        // Kas & Bank (dashboard saldo per akun kas/bank)
        Route::middleware('permission:bukubesar.lihat')->group(function () {
            Route::livewire('kas-bank', 'pages::akunting.kas-bank')->name('kas-bank.index');
        });

        // Aktiva Tetap
        Route::middleware('permission:aktivatetap.lihat')->group(function () {
            Route::livewire('aktiva-tetap', 'pages::akunting.aktiva-tetap')->name('aktiva-tetap.index');
            Route::get('aktiva-tetap/print', [LaporanAkuntingPdfController::class, 'aktivaTetap'])
                ->name('aktiva-tetap.print');
            Route::get('aktiva-tetap/excel', [LaporanAkuntingPdfController::class, 'aktivaTetapExcel'])
                ->name('aktiva-tetap.excel');
        });

    });

    // LAPORAN — laporan penjualan, stock, realisasi, outstanding, pembatalan, sales performance
    Route::middleware('permission:laporan.lihat')->prefix('laporan')->name('laporan.')->group(function () {
        Route::livewire('/', 'pages::laporan.index')->name('index');
    });

    // MONITORING — timeline aktivitas bisnis realtime (khusus PM)
    Route::middleware('permission:monitoring.lihat')->prefix('monitoring')->name('monitoring.')->group(function () {
        Route::livewire('/', 'pages::monitoring.index')->name('index');
    });

    // LOG USER — audit trail (super admin, direktur, project-manager)
    Route::middleware('permission:log.lihat')->prefix('log-user')->name('log-user.')->group(function () {
        Route::livewire('/', 'pages::log-user.index')->name('index');
    });

    // MARKETING — hub SPR + sub-page (list, detail, pembatalan)
    Route::middleware('permission:spr.lihat')->prefix('marketing')->name('marketing.')->group(function () {
        Route::livewire('spr', 'pages::marketing.spr')->name('spr.index');          // hub
        Route::livewire('spr/data', 'pages::marketing.spr-list')->name('spr.list'); // list
        Route::livewire('spr/{id}', 'pages::marketing.spr-show')->name('spr.show')->where('id', '[0-9]+'); // detail
        Route::get('spr/{id}/print', function (int $id) {
            $spr = Spr::with([
                'prospectCustomer.tempatKerja',
                'rumah.tipeRumah',
                'rumah.proyek',
                'rumah.virtualAccount.bank',
                'sales',
                'utjConfirmedBy',
                'approvedBy',
                'pmApprovedBy',
                'terminPembayaran',
            ])->findOrFail($id);

            abort_if($spr->status !== 'approved', 403, 'Hanya SPR yang UTJ-nya sudah dikonfirmasi Keuangan yang bisa dicetak.');
            // Note: guard pm_approved_at dihapus. Alur baru (fitur #6):
            // Keuangan perlu download PDF SPR SEBELUM bubuhkan e-Meterai — jadi harus bisa print
            // meskipun PM belum approve. Tampilan draft/final dibedakan lewat watermark di dokumen.

            return view('exports.spr-print', ['spr' => $spr]);
        })->where('id', '[0-9]+')->name('spr.print');
        Route::get('spr/{id}/kuitansi/{realisasiId}', function (int $id, int $realisasiId) {
            $spr = Spr::with([
                'prospectCustomer',
                'rumah.proyek',
                'rumah.tipeRumah',
                'rumah.virtualAccount.bank',
                'sales',
            ])->findOrFail($id);
            $realisasi = SprRealisasiPembayaran::where('spr_id', $spr->id)
                ->with('inputBy')
                ->findOrFail($realisasiId);
            $perusahaan = Perusahaan::first();

            return view('exports.kuitansi-print', compact('spr', 'realisasi', 'perusahaan'));
        })->where(['id' => '[0-9]+', 'realisasiId' => '[0-9]+'])->name('spr.kuitansi');
        // Lihat PDF materai final (untuk admin/sales — tampilkan file yang di-upload Keuangan)
        Route::get('spr/{id}/materai-pdf', function (int $id) {
            $spr = Spr::findOrFail($id);
            abort_if(! $spr->materai_file_path, 404, 'File materai belum tersedia.');
            $path = storage_path('app/public/'.$spr->materai_file_path);
            abort_unless(is_file($path), 404, 'File tidak ditemukan.');

            return response()->file($path, ['Content-Type' => 'application/pdf']);
        })->where('id', '[0-9]+')->name('spr.materai-pdf');
        // Pembatalan SPR
        Route::livewire('spr-batal', 'pages::marketing.spr-pembatalan-list')->name('spr-batal.list');
        Route::livewire('spr-batal/input', 'pages::marketing.spr-pembatalan-input')->name('spr-batal.input');

        // Pindah Kavling (Switching)
        Route::middleware('permission:spr.pindah-unit')->group(function () {
            Route::livewire('spr-pindah', 'pages::marketing.spr-pindah-list')->name('spr-pindah.list');
            Route::livewire('spr-pindah/{id}', 'pages::marketing.spr-pindah-show')
                ->where('id', '[0-9]+')->name('spr-pindah.show');
        });
    });
});

// ============== DBOS (Sales lapangan, guard 'sales') ==============
Route::prefix('dbos')->name('dbos.')->group(function () {
    Route::livewire('login', 'pages::dbos.login')->name('login');

    Route::post('logout', function () {
        Auth::guard('sales')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('dbos.login');
    })->name('logout');

    Route::middleware('auth:sales')->group(function () {
        // HOME dispatcher: pimpinan → pimpinan.home, sales lapangan → sales-home
        Route::get('/', function () {
            /** @var Sales $sales */
            $sales = Auth::guard('sales')->user();

            return $sales->isPimpinan()
                ? redirect()->route('dbos.pimpinan.home')
                : redirect()->route('dbos.sales-home');
        })->name('home');

        // ========== SALES LAPANGAN ZONE (pimpinan diblok) ==========
        Route::middleware('sales.lapangan')->group(function () {
            Route::livewire('home', 'pages::dbos.home')->name('sales-home');
            Route::livewire('profil', 'pages::dbos.profil')->name('profil');
            Route::livewire('cara-kerja', 'pages::dbos.cara-kerja')->name('cara-kerja');

            // DATABASE prospect customer
            Route::livewire('database', 'pages::dbos.database.index')->name('database.index');
            Route::livewire('database/create', 'pages::dbos.database.form')->name('database.create');
            Route::livewire('database/{id}/edit', 'pages::dbos.database.form')->name('database.edit');

            // BOOKING — list & create flow
            Route::livewire('booking', 'pages::dbos.booking.index')->name('booking.index');
            Route::livewire('booking/baru', 'pages::dbos.booking.picker')->name('booking.create');
            Route::livewire('booking/proyek/{id}', 'pages::dbos.booking.blok')->name('booking.blok');
            Route::livewire('booking/proyek/{id}/blok/{blok}', 'pages::dbos.booking.unit')->name('booking.unit');
            Route::livewire('booking/unit/{id}', 'pages::dbos.booking.form')->name('booking.form');

            // SPR — list, form 4-step, view detail
            Route::livewire('spr', 'pages::dbos.spr.index')->name('spr.index');
            Route::livewire('spr/buat/{bookingId}', 'pages::dbos.spr.form')->name('spr.create');
            Route::livewire('spr/{id}', 'pages::dbos.spr.show')->name('spr.show');
        });

        // ========== PIMPINAN ZONE (sales lapangan diblok) ==========
        Route::middleware('pimpinan')->prefix('pimpinan')->name('pimpinan.')->group(function () {
            Route::livewire('/', 'pages::dbos.pimpinan.home')->name('home');

            Route::livewire('anggota', 'pages::dbos.pimpinan.anggota.index')->name('anggota.index');
            Route::livewire('anggota/compare', 'pages::dbos.pimpinan.anggota.compare')->name('anggota.compare');
            Route::livewire('anggota/{id}', 'pages::dbos.pimpinan.anggota.show')->name('anggota.show');

            Route::livewire('prospect', 'pages::dbos.pimpinan.prospect.index')->name('prospect.index');
            Route::livewire('prospect/{id}', 'pages::dbos.pimpinan.prospect.show')->name('prospect.show');

            Route::livewire('booking', 'pages::dbos.pimpinan.booking.index')->name('booking.index');
            Route::livewire('booking/{id}', 'pages::dbos.pimpinan.booking.show')->name('booking.show');

            Route::livewire('spr', 'pages::dbos.pimpinan.spr.index')->name('spr.index');

            Route::livewire('activity', 'pages::dbos.pimpinan.activity')->name('activity');

            Route::livewire('profil', 'pages::dbos.pimpinan.profil')->name('profil');
        });
    });
});

require __DIR__.'/settings.php';
