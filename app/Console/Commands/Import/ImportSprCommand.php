<?php

namespace App\Console\Commands\Import;

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Models\User;
use App\Services\Import\ExcelSourceLoader;
use App\Services\Import\NameMatcher;
use App\Services\Import\SalesResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Import SPR (216 aktif) dari DATA_PENJUALAN JUAL + skema pembayaran dari MASTER_DATA MASTER DATA 1.
 *
 * Rules:
 * - Nomor SPR as-is dari kolom H (00001..00235), wrap ke format SPR/YYYY/MM/XXXX pakai tanggal_spr
 * - Kategori: 30/60 = subsidi, 30+/78 atau 36/78 = komersial
 * - Status = 'approved' + spr_finalized_at (SELESAI). Kalau ada TGL AKAD di REKAP_KWITANSI → 'akad' + tgl_akad
 * - Skema pembayaran: dari MASTER DATA 1 kalau ada, else derive dari tipe rumah default
 * - Booking baru per SPR (spr.booking_id UNIQUE)
 * - UTJ default per kategori (500rb subsidi, 2jt komersial)
 * - Prospect customer harus sudah ada (jalankan import:customer dulu)
 *
 * Usage:
 *   php artisan import:spr --dry-run
 *   php artisan import:spr
 */
class ImportSprCommand extends Command
{
    protected $signature = 'import:spr
        {--dry-run : Preview tanpa write ke DB}
        {--force : Skip konfirmasi}';

    protected $description = 'Import SPR (216 aktif) dari Excel legacy Grha Aryana';

    protected ExcelSourceLoader $loader;

    protected NameMatcher $matcher;

    protected SalesResolver $salesResolver;

    protected bool $dryRun = false;

    protected array $masterDataMap = []; // normalized nama → skema pembayaran

    protected array $tglAkadMap = []; // normalized nama → tgl akad

    protected int $userFinanceId; // Bu Uli (finance) untuk utj_confirmed, approved, materai

    protected int $userPmId; // Pak Febri (PM) untuk pm_approved

    /**
     * Hardcoded list customer CASH (per file "CASH" tab dari operasional).
     * Sudah termasuk yg di-detect dari suffix "(CASH)" di MASTER DATA 1
     * (CHRISTIANA, SANDRY) + tambahan dari sheet CASH terpisah.
     * Dinormalisasi (uppercase, tanpa gelar) supaya match dgn NameMatcher.
     */
    protected const CASH_CUSTOMERS = [
        'CHRISTIANA SUGONDO RAHMAN',
        'SANDRY FIRNANDO',
        'GRESIA ADRIEL ENDRASTI',
        'UJANG EDI',
        'FAUZI MALIK',
    ];

    /**
     * Customer dgn UTJ non-standard (bukan 500rb subsidi / 2jt komersial).
     * Effective per 1 Aug 2026 UTJ subsidi naik jadi 1jt (belum di-generalize
     * jadi rule tanggal, sementara per-nama).
     */
    protected const UTJ_OVERRIDE = [
        'MUHAMAD FAJAR AL FATHIR' => 1_000_000,
    ];

    public function handle(
        ExcelSourceLoader $loader,
        NameMatcher $matcher,
        SalesResolver $salesResolver,
    ): int {
        $this->loader = $loader;
        $this->matcher = $matcher;
        $this->salesResolver = $salesResolver;
        $this->dryRun = (bool) $this->option('dry-run');

        $this->info('== IMPORT SPR — Grha Aryana ==');
        if ($this->dryRun) {
            $this->warn('MODE: DRY-RUN — tidak akan write ke DB');
        }

        if (! $this->dryRun && ! $this->option('force') && ! $this->confirm('Lanjut import SPR ke DB?', true)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        $proyek = Proyek::where('nama_proyek', 'Grha Aryana')->first() ?? Proyek::first();
        if (! $proyek) {
            $this->error('Master proyek kosong.');

            return self::FAILURE;
        }

        if (ProspectCustomer::count() === 0) {
            $this->error('Master prospect_customer kosong. Jalankan import:customer dulu.');

            return self::FAILURE;
        }

        // Resolve user default TTD (Bu Uli finance & Pak Febri PM)
        $this->userFinanceId = User::whereHas('roles', fn ($q) => $q->where('name', 'finance'))
            ->orderBy('id')->value('id') ?? 1;
        $this->userPmId = User::whereHas('roles', fn ($q) => $q->where('name', 'project-manager'))
            ->orderBy('id')->value('id') ?? 1;

        $userFinance = User::find($this->userFinanceId);
        $userPm = User::find($this->userPmId);
        $this->comment("User TTD default: FIN={$userFinance?->name}, PM={$userPm?->name}");

        $this->loadMasterDataPembayaran();
        $this->loadTglAkad();

        DB::beginTransaction();
        try {
            $stats = $this->importSpr($proyek);

            if ($this->dryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('[DRY-RUN] Semua perubahan di-rollback.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('✓ Import SPR selesai (committed).');
            }

            $this->printStats($stats);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Import gagal: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Load skema pembayaran + info tambahan per customer dari DATA_PUSAT > UANG MUKA. */
    protected function loadMasterDataPembayaran(): void
    {
        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'UANG MUKA');
        // Header UANG MUKA: A NO, B NAMA, C BLOK, D NO, E SALES, F TANGGAL, G/H TYPE LB/LT, I TYPE nama,
        //   J LOT, K AJB NOTARIS, L TOTAL HARGA JUAL, M KPR, N BBA, O SBUM, P UANG MUKA,
        //   Q TGL BAYAR TERAKHIR, R AKUMULASI UM MASUK, S %,
        //   T TARGET MASUK UM, U TGL AKAD, V TGL BAST, W PBB, X PK, Y SERTIFIKAT,
        //   Z LISTRIK SEMENTARA, AA NOMOR SPR, AB NOMOR VA, AC BIAYA LAIN LAIN
        $rows = $this->loader->rows($sheet, headerRow: 2, columns: [
            'nama' => 'B',
            'ajb_notaris' => 'K',
            'total_harga_jual' => 'L',
            'kpr' => 'M',
            'bba' => 'N',
            'sbum' => 'O',
            'uang_muka' => 'P',
            'tgl_bayar_terakhir' => 'Q',
            'akumulasi_um' => 'R',
            'tgl_akad' => 'U',
            'nomor_spr' => 'AA',
            'nomor_va' => 'AB',
            'biaya_lain' => 'AC',
        ]);
        foreach ($rows as $r => $d) {
            if ($d['nama'] === '') {
                continue;
            }
            $key = $this->matcher->normalize($d['nama']);

            $totalHarga = $this->loader->parseNumber($d['total_harga_jual']);
            $kpr = $this->loader->parseNumber($d['kpr']);
            $bba = $this->loader->parseNumber($d['bba']);
            $sbum = $this->loader->parseNumber($d['sbum']);
            $ajb = $this->loader->parseNumber($d['ajb_notaris']);
            $um = $this->loader->parseNumber($d['uang_muka']);
            $biayaLain = $this->loader->parseNumber($d['biaya_lain']);

            // biaya_tambahan = (total - ajb) + biaya_lain_lain
            $biayaTambahan = max(0, $totalHarga - $ajb) + $biayaLain;

            $this->masterDataMap[$key] = [
                'harga_jual' => $ajb ?: $totalHarga,
                'biaya_tambahan' => $biayaTambahan,
                'diskon' => 0,
                'total_harga' => $totalHarga,
                'nilai_kpr' => $kpr,
                'dp_nominal' => $um ?: max(0, $totalHarga - $kpr),
                'sbum' => $sbum,
                'tgl_cair_bum' => null,
                'um_net' => $um ?: max(0, $totalHarga - $kpr - $bba - $sbum),
                'jenis_pembayaran' => 'kpr',
                'nomor_spr' => $d['nomor_spr'] ?: null,
                'nomor_va' => $d['nomor_va'] ?: null,
                'tgl_akad' => $this->loader->parseDate($d['tgl_akad']) ?: null,
            ];

            // Populate tglAkadMap dari UANG MUKA kolom U
            $tglAkad = $this->loader->parseDate($d['tgl_akad']);
            if ($tglAkad) {
                $this->tglAkadMap[$key] = $tglAkad;
            }
        }
        $this->comment('UANG MUKA skema pembayaran: '.count($this->masterDataMap).' customer, '.count($this->tglAkadMap).' punya TGL AKAD');
    }

    /** TGL AKAD di-load di loadMasterDataPembayaran() (dari sheet UANG MUKA kolom U). */
    protected function loadTglAkad(): void
    {
        // No-op — TGL AKAD sudah di-load di loadMasterDataPembayaran (kolom U UANG MUKA)
    }

    protected function importSpr(Proyek $proyek): array
    {
        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'JUAL');
        $rowsRaw = iterator_to_array($this->loader->rows($sheet, headerRow: 3, columns: [
            'tgl_jual' => 'B',
            'type' => 'D',
            'blok' => 'E',
            'no_unit' => 'F',
            'nama' => 'G',
            'nomor_spr' => 'H',
            'sales' => 'K',
            'harga_jual' => 'N',
        ]));

        // Deteksi duplicate nomor SPR (kasus: customer lama batal, unit di-replace customer baru
        // dgn nomor SPR sama). Row pertama (tgl paling awal) = batal, dapat suffix "-A". Row
        // terakhir (tgl paling baru) = aktif, pakai nomor SPR asli.
        $sprGroups = [];
        foreach ($rowsRaw as $r => $d) {
            if ($d['nomor_spr'] !== '') {
                $sprGroups[$d['nomor_spr']][] = ['r' => $r, 'tgl' => $this->loader->parseDate($d['tgl_jual']) ?? '9999-12-31'];
            }
        }
        $rowCancelSuffix = []; // r => 'A'/'B' suffix untuk row yg lebih tua
        foreach ($sprGroups as $sprNo => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            // Sort ascending tgl → yg pertama = tertua (batal)
            usort($rows, fn ($a, $b) => strcmp($a['tgl'], $b['tgl']));
            $suffixes = ['A', 'B', 'C', 'D']; // support up to 4 duplicates
            for ($i = 0; $i < count($rows) - 1; $i++) {
                $rowCancelSuffix[$rows[$i]['r']] = $suffixes[$i] ?? 'X';
            }
        }

        $rows = $rowsRaw;

        $stats = [
            'total_baris' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_no_spr' => 0,
            'skipped_customer_not_found' => 0,
            'skipped_rumah_not_found' => 0,
            'status_akad' => 0,
            'status_approved' => 0,
            'skema_from_master' => 0,
            'skema_default' => 0,
        ];

        $unmatchedCustomer = [];
        $unmatchedRumah = [];

        foreach ($rows as $r => $d) {
            $stats['total_baris']++;
            if ($d['nama'] === '') {
                $stats['skipped_no_spr']++;

                continue;
            }

            // Fallback: kalau nomor SPR di JUAL kolom H kosong, coba lookup di UANG MUKA (kolom AA)
            if ($d['nomor_spr'] === '') {
                $normLookup = $this->matcher->normalize($d['nama']);
                $d['nomor_spr'] = $this->masterDataMap[$normLookup]['nomor_spr'] ?? '';
                if ($d['nomor_spr'] === '') {
                    foreach ($this->masterDataMap as $mKey => $mData) {
                        if ($mKey !== '' && (str_contains($mKey, $normLookup) || str_contains($normLookup, $mKey))) {
                            $d['nomor_spr'] = $mData['nomor_spr'] ?? '';
                            if ($d['nomor_spr'] !== '') {
                                break;
                            }
                        }
                    }
                }
                if ($d['nomor_spr'] === '') {
                    $stats['skipped_no_spr']++;

                    continue;
                }
            }

            // Handle format "X/Y" (customer pindah, 2 nomor SPR) — ambil terakhir
            $sprParts = array_map('trim', explode('/', $d['nomor_spr']));
            $d['nomor_spr'] = end($sprParts);

            // Lookup prospect_customer by nama
            $normNama = $this->matcher->normalize($d['nama']);
            $customer = $this->findCustomer($normNama, $proyek->id);
            if (! $customer) {
                $stats['skipped_customer_not_found']++;
                $unmatchedCustomer[] = $d['nama'];

                continue;
            }

            // Lookup rumah by BLOK+NO
            $blok = strtoupper($d['blok']);
            $noUnit = $d['no_unit'];
            $rumah = Rumah::where('proyek_id', $proyek->id)
                ->where('blok', $blok)
                ->where('nomor_unit', $noUnit)
                ->with('tipeRumah')
                ->first();
            if (! $rumah) {
                $stats['skipped_rumah_not_found']++;
                $unmatchedRumah[] = "$blok-$noUnit";

                continue;
            }

            $kategori = $rumah->tipeRumah?->kategori ?? (str_contains($d['type'], '30/60') ? 'subsidi' : 'komersial');
            $tanggalSpr = $this->loader->parseDate($d['tgl_jual']) ?: now()->toDateString();

            // Skema pembayaran: dari MASTER DATA 1 kalau ada.
            // Fallback fuzzy: SPR nama contains MASTER nama, atau sebaliknya (untuk kasus
            // MASTER "Christiana Sugondo" vs SPR "Christiana Sugondo Rahman").
            $skema = $this->masterDataMap[$normNama] ?? null;
            if (! $skema) {
                foreach ($this->masterDataMap as $masterKey => $masterData) {
                    if ($masterKey !== '' && (str_contains($normNama, $masterKey) || str_contains($masterKey, $normNama))) {
                        $skema = $masterData;
                        break;
                    }
                }
            }
            if ($skema) {
                $stats['skema_from_master']++;
            } else {
                $skema = $this->deriveDefaultSkema($rumah, $kategori, $this->loader->parseNumber($d['harga_jual']));
                $stats['skema_default']++;
            }

            // UTJ default per kategori, dgn per-nama override
            $utjNominal = self::UTJ_OVERRIDE[strtoupper($d['nama'])]
                ?? ($kategori === 'subsidi' ? 500_000 : 2_000_000);

            // Komersial: selisih Total-AJB adalah UTJ, bukan biaya di luar. Reset biaya_tambahan
            // supaya tidak double-count (UTJ sudah tercatat di kolom utj_nominal terpisah).
            if ($kategori === 'komersial') {
                $selisih = max(0, (float) $skema['total_harga'] - (float) $skema['harga_jual']);
                $skema['biaya_tambahan'] = max(0, (float) $skema['biaya_tambahan'] - $selisih);
            }

            // Jenis pembayaran: cek dari (1) hardcoded CASH list, (2) suffix (CASH) di MASTER DATA 1
            $jenisPembayaran = 'kpr';
            $isCash = ($skema['jenis_pembayaran'] ?? null) === 'cash'
                || in_array(strtoupper($d['nama']), self::CASH_CUSTOMERS, true);
            if ($isCash) {
                $jenisPembayaran = 'cash';
                $skema['nilai_kpr'] = 0;
                $skema['dp_nominal'] = $skema['total_harga'];
                $skema['um_net'] = max(0, $skema['total_harga'] - $skema['sbum']);
            }

            // Status: akad kalau ada TGL AKAD, else approved+finalized
            $tglAkad = $this->tglAkadMap[$normNama] ?? null;
            $isAkad = $tglAkad !== null;
            if ($isAkad) {
                $stats['status_akad']++;
            } else {
                $stats['status_approved']++;
            }

            $sales = $this->salesResolver->resolve($d['sales']);

            // Duplicate SPR handling: row lebih tua dapat suffix "-A" + status cancelled
            $duplicateSuffix = $rowCancelSuffix[$r] ?? null;
            $isDuplicateBatal = $duplicateSuffix !== null;

            $nomorSprFull = 'SPR/'.substr($tanggalSpr, 0, 4).'/'.substr($tanggalSpr, 5, 2).'/'.str_pad($d['nomor_spr'], 5, '0', STR_PAD_LEFT);
            if ($isDuplicateBatal) {
                $nomorSprFull .= '-'.$duplicateSuffix;
            }
            $existing = Spr::where('nomor_spr', $nomorSprFull)->first();

            if ($this->dryRun) {
                $existing ? $stats['updated']++ : $stats['created']++;

                continue;
            }

            // Booking baru per SPR
            $booking = Booking::create([
                'sales_id' => $sales?->id,
                'proyek_id' => $proyek->id,
                'rumah_id' => $rumah->id,
                'prospect_customer_id' => $customer->id,
                'tanggal_booking' => $tanggalSpr,
                'tanggal_expired' => date('Y-m-d', strtotime($tanggalSpr.' +7 days')),
                'status' => 'sukses',
            ]);

            $dpPersen = $skema['total_harga'] > 0
                ? round(($skema['dp_nominal'] / $skema['total_harga']) * 100, 2)
                : 0;

            $sprData = [
                'booking_id' => $booking->id,
                'sales_id' => $sales?->id,
                'prospect_customer_id' => $customer->id,
                'rumah_id' => $rumah->id,
                'kategori' => $kategori,
                'nomor_spr' => $nomorSprFull,
                'tanggal_spr' => $tanggalSpr,
                'harga_jual' => $skema['harga_jual'],
                'biaya_tambahan' => $skema['biaya_tambahan'],
                'ppn' => 0,
                'diskon' => $skema['diskon'],
                'kelebihan_tanah_m2' => 0,
                'harga_per_m2' => 0,
                'total_harga' => $skema['total_harga'],
                'jenis_pembayaran' => $jenisPembayaran,
                'dp_persen' => $dpPersen,
                'dp_nominal' => $skema['dp_nominal'],
                'sbum' => $skema['sbum'],
                'tgl_cair_bum' => $skema['tgl_cair_bum'],
                'um_net' => $skema['um_net'],
                'nilai_kpr' => $skema['nilai_kpr'],
                'tgl_akad' => $tglAkad,
                'utj_nominal' => $utjNominal,
                'utj_tanggal_bayar' => $tanggalSpr,
                'utj_tanggal_transaksi' => $tanggalSpr,
                'utj_metode' => 'transfer',
                'utj_nominal_aktual' => $utjNominal,
                'utj_bukti_path' => 'legacy/dummy-utj.jpg',
                'utj_confirmed_at' => $tanggalSpr,
                'utj_confirmed_by_user_id' => $this->userFinanceId,
                'status' => $isDuplicateBatal ? 'cancelled' : ($isAkad ? 'akad' : 'approved'),
                'approved_at' => $tanggalSpr,
                'approved_by_user_id' => $this->userFinanceId,
                'pm_approved_at' => $tanggalSpr,
                'pm_approved_by_user_id' => $this->userPmId,
                'materai_stamped_at' => $tanggalSpr,
                'materai_by_user_id' => $this->userFinanceId,
                'spr_finalized_at' => $tanggalSpr,
                'cancelled_at' => $isDuplicateBatal ? $tanggalSpr : null,
                'cancelled_by_user_id' => $isDuplicateBatal ? $this->userFinanceId : null,
                'cancel_keterangan' => $isDuplicateBatal ? 'Import legacy: customer batal, unit di-replace SPR dgn nomor sama' : null,
                'catatan' => 'Import legacy Grha Aryana 31 Juli 2026'.($isAkad ? " · AKAD {$tglAkad}" : '').($isDuplicateBatal ? ' · BATAL (duplicate SPR)' : ''),
            ];

            if ($existing) {
                $existing->update($sprData);
                $stats['updated']++;
            } else {
                Spr::create($sprData);
                $stats['created']++;
            }
        }

        // Post-process: SPR di UANG MUKA yg belum ada di JUAL (customer minimal dari UANG MUKA)
        $stats['created_from_uang_muka'] = $this->importSprFromUangMuka($proyek);

        // Post-process pindah kavling: kalau customer punya SPR di unit ≠ UANG MUKA current unit,
        // mark yg unit lama sbg cancelled.
        $stats['cancelled_pindah_kavling'] = $this->cancelPindahKavlingSpr($proyek);

        $this->saveUnmatchedReport($unmatchedCustomer, $unmatchedRumah);

        return $stats;
    }

    /**
     * Post-process: untuk tiap customer di UANG MUKA, tentukan CURRENT unit-nya.
     * SPR lain untuk customer yg sama yg BUKAN di current unit = SPR unit lama
     * (pindah kavling) → mark cancelled dgn alasan "Pindah Kavling".
     *
     * Ini menangani kasus SITI KHODIJAH CD-5→AF-4, EJI CD-6→AF-3, dll — di mana
     * PINDAH sheet tidak menyebut unit lama dgn benar tapi UANG MUKA jelas
     * mencatat current unit.
     */
    protected function cancelPindahKavlingSpr(Proyek $proyek): int
    {
        $alasanPindah = AlasanPembatalan::firstOrCreate(
            ['nama' => 'Pindah Kavling'],
            ['dapat_meneruskan_angsuran' => true, 'is_aktif' => true],
        );

        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'UANG MUKA');
        $rows = $this->loader->rows($sheet, headerRow: 2, columns: [
            'nama' => 'B',
            'blok' => 'C',
            'no_unit' => 'D',
        ]);

        // Pass 1: build map customer → current unit. Kalau customer punya 2+ UM row
        // (pindah kavling: old + new), row TERAKHIR = current active (Excel row order
        // = chronological). Contoh ADE KURNIAWAN row 107 DC-7 (lama) + row 162 AC-21 (baru)
        // → current = AC-21.
        $customerCurrentUnit = [];
        foreach ($rows as $r => $d) {
            if ($d['nama'] === '' || $d['blok'] === '' || $d['no_unit'] === '') {
                continue;
            }
            if (strtoupper($d['nama']) === 'NAMA' || strlen($d['nama']) < 3) {
                continue;
            }
            $normNama = $this->matcher->normalize($d['nama']);
            $customerCurrentUnit[$normNama] = [
                'blok' => strtoupper($d['blok']),
                'no_unit' => $d['no_unit'],
            ];
        }

        // Pass 2: untuk tiap customer, cancel SPR yg unit-nya BUKAN current unit.
        // whereIn approved/akad → SPR yg sudah cancelled (dari import:batal) tidak
        // di-touch, jadi alasan_pembatalan asli tetap terpelihara.
        $cancelled = 0;
        foreach ($customerCurrentUnit as $normNama => $unit) {
            $customer = $this->findCustomer($normNama, $proyek->id);
            if (! $customer) {
                continue;
            }
            $currentRumah = Rumah::where('proyek_id', $proyek->id)
                ->where('blok', $unit['blok'])
                ->where('nomor_unit', $unit['no_unit'])
                ->first();
            if (! $currentRumah) {
                continue;
            }
            $oldSprs = Spr::where('prospect_customer_id', $customer->id)
                ->where('rumah_id', '!=', $currentRumah->id)
                ->whereIn('status', ['approved', 'akad'])
                ->get();
            foreach ($oldSprs as $oldSpr) {
                if ($this->dryRun) {
                    $cancelled++;

                    continue;
                }
                $oldSpr->update([
                    'status' => 'cancelled',
                    'alasan_pembatalan_id' => $alasanPindah->id,
                    'cancel_keterangan' => "Pindah Kavling (auto): customer sekarang di {$unit['blok']}-{$unit['no_unit']}",
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => $this->userFinanceId,
                    'refund_status' => 'tidak_ada_refund',
                ]);
                $cancelled++;
            }
        }

        if ($cancelled > 0) {
            $this->comment("Pindah kavling auto-cancel: $cancelled SPR di unit lama");
        }

        return $cancelled;
    }

    /**
     * Iterate UANG MUKA. Kalau nomor SPR di kolom AA belum ada di DB (tidak muncul di JUAL),
     * bikin SPR dari data UANG MUKA (harga, KPR, UM, SBUM dari skema di sheet).
     */
    protected function importSprFromUangMuka(Proyek $proyek): int
    {
        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'UANG MUKA');
        $rows = $this->loader->rows($sheet, headerRow: 2, columns: [
            'nama' => 'B',
            'blok' => 'C',
            'no_unit' => 'D',
            'sales' => 'E',
            'tanggal' => 'F',
            'nomor_spr' => 'AA',
        ]);

        $created = 0;
        foreach ($rows as $r => $d) {
            if ($d['nama'] === '' || $d['nomor_spr'] === '') {
                continue;
            }
            // Skip header/junk
            if (strtoupper($d['nama']) === 'NAMA' || strlen($d['nama']) < 3) {
                continue;
            }
            // Kalau nomor SPR format "X/Y" (customer pindah kavling, ada 2 SPR), ambil paling akhir (aktif)
            $sprNumbers = array_map('trim', explode('/', $d['nomor_spr']));
            $sprNumber = end($sprNumbers);

            $tanggalSpr = $this->loader->parseDate($d['tanggal']) ?: now()->toDateString();
            $nomorSprFull = 'SPR/'.substr($tanggalSpr, 0, 4).'/'.substr($tanggalSpr, 5, 2).'/'.str_pad($sprNumber, 5, '0', STR_PAD_LEFT);
            if (Spr::where('nomor_spr', $nomorSprFull)->exists()) {
                continue;
            }

            // Lookup customer + rumah
            $normNama = $this->matcher->normalize($d['nama']);
            $customer = $this->findCustomer($normNama, $proyek->id);
            if (! $customer) {
                continue;
            }
            $blok = strtoupper($d['blok']);
            $noUnit = $d['no_unit'];
            $rumah = Rumah::where('proyek_id', $proyek->id)
                ->where('blok', $blok)->where('nomor_unit', $noUnit)
                ->with('tipeRumah')->first();
            if (! $rumah) {
                continue;
            }

            // Reissue case: customer sudah punya SPR di unit ini (dari JUAL). Skip supaya
            // tidak duplicate. UANG MUKA X/Y format = SPR di-reissue nomor baru untuk
            // customer & unit sama; JUAL sudah menangkap yg lama, cukup pakai itu.
            if (Spr::where('prospect_customer_id', $customer->id)->where('rumah_id', $rumah->id)->exists()) {
                continue;
            }

            $kategori = $rumah->tipeRumah?->kategori ?? 'subsidi';
            $skema = $this->masterDataMap[$normNama] ?? $this->deriveDefaultSkema($rumah, $kategori, 0);
            $utjNominal = self::UTJ_OVERRIDE[strtoupper($d['nama'])]
                ?? ($kategori === 'subsidi' ? 500_000 : 2_000_000);

            // Komersial: selisih Total-AJB = UTJ, bukan biaya di luar
            if ($kategori === 'komersial') {
                $selisih = max(0, (float) $skema['total_harga'] - (float) $skema['harga_jual']);
                $skema['biaya_tambahan'] = max(0, (float) $skema['biaya_tambahan'] - $selisih);
            }

            $isCash = in_array(strtoupper($d['nama']), self::CASH_CUSTOMERS, true);
            $jenisPembayaran = 'kpr';
            if ($isCash) {
                $jenisPembayaran = 'cash';
                $skema['nilai_kpr'] = 0;
                $skema['dp_nominal'] = $skema['total_harga'];
                $skema['um_net'] = max(0, $skema['total_harga'] - $skema['sbum']);
            }

            $tglAkad = $this->tglAkadMap[$normNama] ?? null;
            $isAkad = $tglAkad !== null;
            $sales = $this->salesResolver->resolve($d['sales']);

            if ($this->dryRun) {
                $created++;

                continue;
            }

            $booking = Booking::create([
                'sales_id' => $sales?->id,
                'proyek_id' => $proyek->id,
                'rumah_id' => $rumah->id,
                'prospect_customer_id' => $customer->id,
                'tanggal_booking' => $tanggalSpr,
                'tanggal_expired' => date('Y-m-d', strtotime($tanggalSpr.' +7 days')),
                'status' => 'sukses',
            ]);

            $dpPersen = $skema['total_harga'] > 0 ? round(($skema['dp_nominal'] / $skema['total_harga']) * 100, 2) : 0;

            Spr::create([
                'booking_id' => $booking->id,
                'sales_id' => $sales?->id,
                'prospect_customer_id' => $customer->id,
                'rumah_id' => $rumah->id,
                'kategori' => $kategori,
                'nomor_spr' => $nomorSprFull,
                'tanggal_spr' => $tanggalSpr,
                'harga_jual' => $skema['harga_jual'],
                'biaya_tambahan' => $skema['biaya_tambahan'],
                'ppn' => 0,
                'diskon' => $skema['diskon'],
                'kelebihan_tanah_m2' => 0,
                'harga_per_m2' => 0,
                'total_harga' => $skema['total_harga'],
                'jenis_pembayaran' => $jenisPembayaran,
                'dp_persen' => $dpPersen,
                'dp_nominal' => $skema['dp_nominal'],
                'sbum' => $skema['sbum'],
                'tgl_cair_bum' => $skema['tgl_cair_bum'],
                'um_net' => $skema['um_net'],
                'nilai_kpr' => $skema['nilai_kpr'],
                'tgl_akad' => $tglAkad,
                'utj_nominal' => $utjNominal,
                'utj_tanggal_bayar' => $tanggalSpr,
                'utj_tanggal_transaksi' => $tanggalSpr,
                'utj_metode' => 'transfer',
                'utj_nominal_aktual' => $utjNominal,
                'utj_bukti_path' => 'legacy/dummy-utj.jpg',
                'utj_confirmed_at' => $tanggalSpr,
                'utj_confirmed_by_user_id' => $this->userFinanceId,
                'status' => $isAkad ? 'akad' : 'approved',
                'approved_at' => $tanggalSpr,
                'approved_by_user_id' => $this->userFinanceId,
                'pm_approved_at' => $tanggalSpr,
                'pm_approved_by_user_id' => $this->userPmId,
                'materai_stamped_at' => $tanggalSpr,
                'materai_by_user_id' => $this->userFinanceId,
                'spr_finalized_at' => $tanggalSpr,
                'catatan' => 'Import legacy Grha Aryana 31 Juli 2026 — dari UANG MUKA (tidak ada di JUAL)',
            ]);
            $created++;
        }
        if ($created > 0) {
            $this->comment("SPR supplementary dari UANG MUKA: $created");
        }

        return $created;
    }

    /** Cari prospect_customer by normalized nama. */
    protected function findCustomer(string $normNama, int $proyekId): ?ProspectCustomer
    {
        static $cache = [];
        if (isset($cache[$normNama])) {
            return $cache[$normNama];
        }

        // Try match by normalized name across all customers of this proyek
        $candidates = ProspectCustomer::where('proyek_id', $proyekId)->get(['id', 'nama_lengkap']);
        foreach ($candidates as $c) {
            if ($this->matcher->normalize($c->nama_lengkap) === $normNama) {
                $cache[$normNama] = ProspectCustomer::find($c->id);

                return $cache[$normNama];
            }
        }

        $cache[$normNama] = null;

        return null;
    }

    /** Derive skema pembayaran default kalau tidak ada di MASTER DATA 1. */
    /**
     * AJB Notaris standar untuk subsidi (30/60 Arjuna) = 185jt.
     * Semua subsidi Grha Aryana punya AJB fixed 185jt; selisih ke total harga
     * (13jt-27jt tergantung blok) di-treat sbg biaya_tambahan (BBA+notaris+admin).
     */
    protected const AJB_STANDARD_SUBSIDI = 185_000_000;

    protected function deriveDefaultSkema(Rumah $rumah, string $kategori, float $hargaJualLegacy): array
    {
        $tipe = $rumah->tipeRumah;
        // hargaJualLegacy dari JUAL kolom N sebenarnya total_harga (bukan AJB net)
        $totalHarga = $hargaJualLegacy ?: (float) ($tipe?->harga_jual ?? 0);

        if ($kategori === 'komersial') {
            // Komersial: AJB net = KPR, tidak ada biaya tambahan
            return [
                'harga_jual' => $totalHarga,
                'biaya_tambahan' => 0,
                'diskon' => 0,
                'total_harga' => $totalHarga,
                'nilai_kpr' => $totalHarga,
                'dp_nominal' => 0,
                'sbum' => 0,
                'tgl_cair_bum' => null,
                'um_net' => 0,
            ];
        }

        // Subsidi: AJB fixed 185jt, sisanya masuk biaya_tambahan
        $hargaJual = min(self::AJB_STANDARD_SUBSIDI, $totalHarga);
        $biayaTambahan = max(0, $totalHarga - $hargaJual);
        $plafonKpr = (float) ($tipe?->plafon_kpr ?? 179_000_000);
        $sbum = (float) ($tipe?->sbum ?? 4_000_000);
        $dpNominal = max(0, $totalHarga - $plafonKpr);
        $umNet = max(0, $dpNominal - $sbum);

        return [
            'harga_jual' => $hargaJual,
            'biaya_tambahan' => $biayaTambahan,
            'diskon' => 0,
            'total_harga' => $totalHarga,
            'nilai_kpr' => $plafonKpr,
            'dp_nominal' => $dpNominal,
            'sbum' => $sbum,
            'tgl_cair_bum' => null,
            'um_net' => $umNet,
        ];
    }

    protected function saveUnmatchedReport(array $unmatchedCustomer, array $unmatchedRumah): void
    {
        if ($this->dryRun) {
            return;
        }
        Storage::disk('local')->makeDirectory('import-log');
        $ts = now()->format('Ymd_His');

        if (count($unmatchedCustomer) > 0) {
            $csv = "NAMA_CUSTOMER\n".implode("\n", array_map(fn ($n) => "\"$n\"", $unmatchedCustomer));
            Storage::disk('local')->put("import-log/spr-{$ts}-customer-not-found.csv", $csv);
        }
        if (count($unmatchedRumah) > 0) {
            $csv = "BLOK_UNIT\n".implode("\n", array_unique($unmatchedRumah));
            Storage::disk('local')->put("import-log/spr-{$ts}-rumah-not-found.csv", $csv);
        }
    }

    protected function printStats(array $stats): void
    {
        $this->newLine();
        $this->info('== STATISTIK IMPORT SPR ==');
        foreach ($stats as $key => $val) {
            $this->line(sprintf('  %-30s %d', $key, $val));
        }
    }
}
