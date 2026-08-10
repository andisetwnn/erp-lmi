<?php

namespace App\Console\Commands\Import;

use App\Models\Akunting\AktivaTetap;
use App\Models\Akunting\Jurnal;
use App\Models\Akunting\JurnalDetail;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Import data akunting dari file ImportJurnal.xlsx (keuangan).
 *
 * Sheet source:
 * - SAA (Master COA) — 184 rows, header row 8, data row 10+
 *     C=kode, D=nama, E=saldo awal (optional)
 * - Daf Aktiva — 70 rows, header row 6, data row 9+
 *     C=kode, D=keterangan, E=tgl beli, F=masa (thn), H=harga beli, J=buku awal, M=akumulasi
 * - JU (Jurnal) — 18.816 rows, header row 9, data row 10+
 *     C=tgl (serial), D=no bukti, E=keterangan, F=akun debet, H=nominal, I=akun kredit, K=(=H)
 * - NR + LR — skip (formula report reference, dipakai untuk reconciliation manual)
 *
 * Prefix no bukti → kategori:
 * - KAS*, KASPROYEK*, KASTEHNIK*, KASPUSAT* → KAS
 * - BANK*, BTN*, BCA*, NOBU*, BSN*, BSI*, BJB*, MANDIRI*, dsb → BANK
 * - RJE* → RJE
 * - PENJ*, SALES* → PENJ
 * - AKM* → AKM
 * - HPP* → HPP
 * - default → AKM (jurnal umum)
 */
class ImportAkuntingCommand extends Command
{
    protected $signature = 'import:akunting
        {--file=ImportJurnal.xlsx : Path file Excel relatif ke base_path}
        {--fresh : Hapus jurnal & aktiva tetap existing sebelum import}
        {--skip-coa : Skip import COA (kalau sudah pernah run)}
        {--skip-aktiva : Skip import aktiva tetap}
        {--skip-jurnal : Skip import jurnal}
        {--chunk=500 : Chunk size untuk import jurnal}
        {--force : Skip konfirmasi}';

    protected $description = 'Import COA + Aktiva Tetap + Jurnal historis dari file Excel keuangan';

    protected Perusahaan $perusahaan;

    protected int $userId;

    protected array $coaMap = []; // kode → id

    protected array $stats = [
        'coa_added' => 0,
        'coa_updated' => 0,
        'coa_skipped' => 0,
        'aktiva_added' => 0,
        'aktiva_skipped' => 0,
        'jurnal_added' => 0,
        'jurnal_skipped' => 0,
        'jurnal_errors' => [],
        'saldo_awal_added' => 0,
        'saldo_awal_total_debet' => 0.0,
        'saldo_awal_total_kredit' => 0.0,
    ];

    public function handle(): int
    {
        ini_set('memory_limit', '2G');

        $file = base_path((string) $this->option('file'));
        if (! file_exists($file)) {
            $this->error("File tidak ditemukan: $file");

            return self::FAILURE;
        }

        $this->perusahaan = Perusahaan::firstWhere('kode_surat', 'LMI') ?? Perusahaan::first();
        if (! $this->perusahaan) {
            $this->error('Master perusahaan belum ada.');

            return self::FAILURE;
        }

        $this->userId = User::firstWhere('email', 'admin@lmi.test')?->id
            ?? User::first()?->id;

        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║  IMPORT AKUNTING — ImportJurnal.xlsx                       ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->info('File   : '.$file);
        $this->info('Company: '.$this->perusahaan->nama);
        $this->newLine();

        if ($this->option('fresh') && ! $this->option('force')) {
            if (! $this->confirm('⚠️  Opsi --fresh akan HAPUS semua jurnal + aktiva tetap existing. Lanjut?', false)) {
                $this->warn('Dibatalkan.');

                return self::FAILURE;
            }
        }

        if ($this->option('fresh')) {
            $this->fresh();
        }

        // ═══ FASE 1: COA ═══
        if (! $this->option('skip-coa')) {
            $this->importCoa($file);
        }
        $this->loadCoaMap();

        // ═══ FASE 2: Aktiva Tetap ═══
        if (! $this->option('skip-aktiva')) {
            $this->importAktiva($file);
        }

        // ═══ FASE 3: Saldo Awal (Opening Balance) dari SAA kolom E ═══
        $this->importSaldoAwal($file);

        // ═══ FASE 4: Jurnal ═══
        if (! $this->option('skip-jurnal')) {
            $this->importJurnal($file);
        }

        $this->summary();

        return self::SUCCESS;
    }

    protected function fresh(): void
    {
        $this->comment('== Membersihkan data lama ==');
        DB::table('jurnal_detail')->truncate();
        DB::table('jurnal')->delete();
        DB::table('aktiva_tetap')->delete();
        $this->info('  ✓ Jurnal + Aktiva Tetap dihapus');
        $this->newLine();
    }

    protected function loadCoaMap(): void
    {
        $this->coaMap = Coa::where('perusahaan_id', $this->perusahaan->id)
            ->pluck('id', 'kode')
            ->toArray();
        $this->comment('COA di master: '.count($this->coaMap).' akun');
        $this->newLine();
    }

    // ═══════════════════════════════════════════════════════════
    // FASE 1: IMPORT COA (SAA)
    // ═══════════════════════════════════════════════════════════
    protected function importCoa(string $file): void
    {
        $this->comment('════ FASE 1: IMPORT COA (sheet SAA) ════');

        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['SAA']);
        $sp = $reader->load($file);
        $sheet = $sp->getActiveSheet();

        $highest = $sheet->getHighestDataRow();
        $bar = $this->output->createProgressBar($highest - 9);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('COA');
        $bar->start();

        $existing = Coa::where('perusahaan_id', $this->perusahaan->id)->pluck('id', 'kode')->toArray();

        for ($r = 10; $r <= $highest; $r++) {
            $kode = trim((string) $sheet->getCell('C'.$r)->getValue());
            $nama = trim((string) $sheet->getCell('D'.$r)->getValue());
            if ($kode === '' || $nama === '') {
                $bar->advance();

                continue;
            }

            // Derive tipe dari kode (leading digit):
            // 1xxx = aset, 2xxx = kewajiban, 3xxx = modal, 4/7xxx = pendapatan, 5/6xxx = beban
            $lead = substr($kode, 0, 1);
            $tipe = match ($lead) {
                '1' => 'aset',
                '2' => 'kewajiban',
                '3' => 'modal',
                '4', '7' => 'pendapatan',
                '5', '6' => 'beban',
                default => 'aset',
            };
            $saldoNormal = in_array($tipe, ['aset', 'beban']) ? 'debit' : 'kredit';
            $isHeader = ! str_contains($kode, '.'); // kode 4-digit = header, kode dgn titik = leaf

            if (isset($existing[$kode])) {
                $this->stats['coa_skipped']++;
            } else {
                // Cari parent kalau leaf (mis. 1001.001 → parent = 1001)
                $parentId = null;
                if (str_contains($kode, '.')) {
                    $parentKode = explode('.', $kode)[0];
                    $parentId = $existing[$parentKode] ?? Coa::where('perusahaan_id', $this->perusahaan->id)
                        ->where('kode', $parentKode)->value('id');
                }

                Coa::create([
                    'perusahaan_id' => $this->perusahaan->id,
                    'kode' => $kode,
                    'nama' => $nama,
                    'tipe' => $tipe,
                    'saldo_normal' => $saldoNormal,
                    'parent_id' => $parentId,
                    'is_header' => $isHeader,
                    'is_aktif' => true,
                ]);
                $this->stats['coa_added']++;
                $existing[$kode] = 1; // add ke local map biar next iterasi child bisa lookup
            }

            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info(sprintf('  ✓ COA: %d added, %d skipped (already exist)',
            $this->stats['coa_added'], $this->stats['coa_skipped']));
        $this->newLine();

        $sp->disconnectWorksheets();
        unset($sp);
    }

    // ═══════════════════════════════════════════════════════════
    // FASE 2: IMPORT AKTIVA TETAP (Daf Aktiva)
    // ═══════════════════════════════════════════════════════════
    protected function importAktiva(string $file): void
    {
        $this->comment('════ FASE 2: IMPORT AKTIVA TETAP (sheet Daf Aktiva) ════');

        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['Daf Aktiva']);
        $sp = $reader->load($file);
        $sheet = $sp->getActiveSheet();

        $highest = $sheet->getHighestDataRow();
        $bar = $this->output->createProgressBar($highest - 8);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        $currentKategori = 'Lainnya';

        for ($r = 8; $r <= $highest; $r++) {
            $b = trim((string) $sheet->getCell('B'.$r)->getValue());
            $c = trim((string) $sheet->getCell('C'.$r)->getValue());
            $d = trim((string) $sheet->getCell('D'.$r)->getValue());
            $e = $sheet->getCell('E'.$r)->getValue(); // tgl serial
            $f = $this->numericCell($sheet->getCell('F'.$r)); // masa tahun
            $h = $this->numericCell($sheet->getCell('H'.$r)); // harga beli
            $m = $this->numericCell($sheet->getCell('M'.$r)); // akumulasi

            // Skip empty
            if ($b === '' && $d === '' && $h == 0) {
                $bar->advance();

                continue;
            }

            // Detect kategori header row (mis. "INVENTARIS KANTOR :", "KENDARAAN :")
            if ($d === '' && $h == 0 && preg_match('/^(.*?)\s*:\s*$/', $b, $mch)) {
                $raw = preg_replace('/\s+/', ' ', trim($mch[1]));
                $currentKategori = ucwords(strtolower($raw));
                $bar->advance();

                continue;
            }

            if ($d === '' || $h == 0) {
                $bar->advance();

                continue;
            }

            // Parse tgl (Excel serial → date)
            $tglPerolehan = null;
            if (is_numeric($e) && $e > 25000) {
                $tglPerolehan = Carbon::createFromTimestamp((int) (($e - 25569) * 86400))->toDateString();
            }

            AktivaTetap::create([
                'perusahaan_id' => $this->perusahaan->id,
                'kode' => $c ?: null,
                'nama' => $d,
                'kategori' => $currentKategori,
                'tgl_perolehan' => $tglPerolehan ?? now()->toDateString(),
                'harga_perolehan' => $h,
                'umur_ekonomis_bulan' => (int) round($f * 12),
                'metode_penyusutan' => $f > 0 ? 'garis_lurus' : 'tidak_disusutkan',
                'nilai_residu' => 0,
                'akumulasi_penyusutan' => $m,
                'status' => 'aktif',
                'created_by_user_id' => $this->userId,
            ]);
            $this->stats['aktiva_added']++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info(sprintf('  ✓ Aktiva Tetap: %d added', $this->stats['aktiva_added']));
        $this->newLine();

        $sp->disconnectWorksheets();
        unset($sp);
    }

    // ═══════════════════════════════════════════════════════════
    // FASE 3: IMPORT SALDO AWAL (OPENING BALANCE) dari SAA kolom E
    // ═══════════════════════════════════════════════════════════
    protected function importSaldoAwal(string $file): void
    {
        $this->comment('════ FASE 3: IMPORT SALDO AWAL (opening balance dari SAA kolom E) ════');

        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['SAA']);
        $sp = $reader->load($file);
        $sheet = $sp->getActiveSheet();

        $highest = $sheet->getHighestDataRow();

        // Kumpulkan semua saldo awal ≠ 0
        $lines = [];
        $totalDebet = 0;
        $totalKredit = 0;

        for ($r = 10; $r <= $highest; $r++) {
            $kode = trim((string) $sheet->getCell('C'.$r)->getValue());
            $saldo = (float) $sheet->getCell('E'.$r)->getValue();

            if ($kode === '' || abs($saldo) < 0.01) {
                continue;
            }
            if (! isset($this->coaMap[$kode])) {
                continue;
            }

            // Cari tipe COA untuk tentukan debet/kredit
            $coa = Coa::find($this->coaMap[$kode]);
            if (! $coa) {
                continue;
            }

            // Convention SAA kolom E berdasarkan tipe kategori neraca:
            //   - Aset/Beban: nilai positif = debet, nilai negatif = kredit (contra asset spt Akumulasi Penyusutan)
            //   - Kewajiban/Modal/Pendapatan: nilai positif = kredit, nilai negatif = debet (rugi ditahan)
            $typeExpectDebit = in_array($coa->tipe, ['aset', 'beban']);
            $sisiDebet = ($saldo > 0) ? $typeExpectDebit : ! $typeExpectDebit;
            $nilai = abs($saldo);

            $lines[] = [
                'coa_id' => $this->coaMap[$kode],
                'debet' => $sisiDebet ? $nilai : 0,
                'kredit' => $sisiDebet ? 0 : $nilai,
            ];
            if ($sisiDebet) {
                $totalDebet += $nilai;
            } else {
                $totalKredit += $nilai;
            }
        }

        if (empty($lines)) {
            $this->warn('  (Tidak ada saldo awal di kolom E SAA — skip)');
            $this->newLine();
            $sp->disconnectWorksheets();
            unset($sp);

            return;
        }

        // Balance check — kalau tidak balance, tambah balancing entry ke akun "Laba Ditahan" atau "Saldo Awal Belum Sesuai"
        $selisih = $totalDebet - $totalKredit;
        if (abs($selisih) > 0.01) {
            $this->warn(sprintf('  ⚠ Saldo awal tidak balance: debet %s vs kredit %s, selisih %s',
                number_format($totalDebet, 0, ',', '.'),
                number_format($totalKredit, 0, ',', '.'),
                number_format($selisih, 0, ',', '.')));

            // Coba pakai akun Modal (3xxx) pertama atau bikin balancing account
            $modalCoa = Coa::where('perusahaan_id', $this->perusahaan->id)
                ->where('kode', 'like', '3%')
                ->where('is_header', false)
                ->first();

            if ($modalCoa) {
                if ($selisih > 0) {
                    // Debet lebih besar → butuh kredit tambahan → tambah ke modal (kredit)
                    $lines[] = ['coa_id' => $modalCoa->id, 'debet' => 0, 'kredit' => $selisih];
                    $totalKredit += $selisih;
                } else {
                    $lines[] = ['coa_id' => $modalCoa->id, 'debet' => abs($selisih), 'kredit' => 0];
                    $totalDebet += abs($selisih);
                }
                $this->line(sprintf('    → Balancing ke %s %s (%s)', $modalCoa->kode, $modalCoa->nama,
                    $selisih > 0 ? 'kredit' : 'debet'));
            }
        }

        // Insert opening jurnal (1 jurnal besar dgn semua detail)
        DB::transaction(function () use ($lines) {
            $tglOpening = '2025-12-31'; // sehari sebelum periode jurnal mulai
            $jurnal = Jurnal::create([
                'perusahaan_id' => $this->perusahaan->id,
                'tanggal' => $tglOpening,
                'no_bukti' => 'OB/12/25/0001',
                'tipe' => 'umum',
                'kategori_bukti' => 'AKM',
                'keterangan' => 'SALDO AWAL — Opening Balance dari SAA sheet (per 31 Des 2025)',
                'status' => 'posted',
                'posted_by_user_id' => $this->userId,
                'posted_at' => now(),
                'created_by_user_id' => $this->userId,
            ]);

            $insertData = [];
            foreach ($lines as $l) {
                $insertData[] = [
                    'jurnal_id' => $jurnal->id,
                    'coa_id' => $l['coa_id'],
                    'debet' => $l['debet'],
                    'kredit' => $l['kredit'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            JurnalDetail::insert($insertData);
        });

        $this->stats['saldo_awal_added'] = count($lines);
        $this->stats['saldo_awal_total_debet'] = $totalDebet;
        $this->stats['saldo_awal_total_kredit'] = $totalKredit;

        $this->info(sprintf('  ✓ Saldo Awal: %d akun, total Rp %s (balance)',
            count($lines), number_format($totalDebet, 0, ',', '.')));
        $this->newLine();

        $sp->disconnectWorksheets();
        unset($sp);
    }

    // ═══════════════════════════════════════════════════════════
    // FASE 4: IMPORT JURNAL (JU) — support multi-line via row extension
    // ═══════════════════════════════════════════════════════════
    /**
     * Mapping akun extension untuk kasus "split ke 2 jurnal" (Kategori A):
     * Kalau row extension I=akun_aset yg listed di sini, generate akun D extension
     * dan bikin 2 jurnal terpisah (bukan 1 multi-line).
     *
     * Format: 'F_utama|I_extension' => 'kode_D_extension_baru'
     */
    protected const SPLIT_MAPPING = [
        // HPP Perolehan Tanah — extension ke tanah extention
        '5001.001|1008.003' => '5001.002', // HPP Perolehan Tanah Extension (baru)
    ];

    /** Ensure COA extension existing sebelum import. */
    protected function ensureExtensionCoa(): void
    {
        $header5001 = Coa::firstWhere(['perusahaan_id' => $this->perusahaan->id, 'kode' => '5001']);
        Coa::firstOrCreate(
            ['perusahaan_id' => $this->perusahaan->id, 'kode' => '5001.002'],
            [
                'nama' => 'HPP Perolehan Tanah Extension',
                'tipe' => 'beban',
                'saldo_normal' => 'debit',
                'parent_id' => $header5001?->id,
                'is_header' => false,
                'is_aktif' => true,
            ],
        );
    }

    protected function importJurnal(string $file): void
    {
        $this->comment('════ FASE 4: IMPORT JURNAL (sheet JU) — bisa lama (18k rows) ════');

        $this->ensureExtensionCoa();
        // Refresh coaMap kalau ada COA baru
        $this->loadCoaMap();

        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['JU']);
        $sp = $reader->load($file);
        $sheet = $sp->getActiveSheet();

        $highest = $sheet->getHighestDataRow();
        $totalRows = $highest - 9;
        $bar = $this->output->createProgressBar($totalRows);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% | added=%added% skipped=%skipped%');
        $bar->setMessage('0', 'added');
        $bar->setMessage('0', 'skipped');
        $bar->start();

        $chunk = (int) $this->option('chunk');
        $buffer = [];

        // Pre-load rows
        $rows = [];
        $byNoBukti = []; // untuk post-scan orphan extension
        for ($r = 10; $r <= $highest; $r++) {
            $noBukti = trim((string) $sheet->getCell('D'.$r)->getValue());
            $rows[$r] = [
                'r' => $r,
                'tglSerial' => $sheet->getCell('C'.$r)->getValue(),
                'noBukti' => $noBukti,
                'keterangan' => trim((string) $sheet->getCell('E'.$r)->getValue()),
                'kodeDebet' => trim((string) $sheet->getCell('F'.$r)->getValue()),
                'nominalH' => $this->numericCell($sheet->getCell('H'.$r)),
                'kodeKredit' => trim((string) $sheet->getCell('I'.$r)->getValue()),
                'nominalK' => $this->numericCell($sheet->getCell('K'.$r)),
            ];
            if ($noBukti !== '') {
                $byNoBukti[$noBukti][] = $r;
            }
        }

        $rowKeys = array_keys($rows);
        $rowCount = count($rowKeys);
        $usedExtRows = []; // track row extension yg sudah dipakai (bisa via adjacent atau via post-scan)
        $jurnalByNoBukti = []; // no_bukti => index di $buffer (buat post-scan add extra line)

        for ($idx = 0; $idx < $rowCount; $idx++) {
            $r = $rowKeys[$idx];
            $row = $rows[$r];

            $noBukti = $row['noBukti'];
            $kodeDebet = $row['kodeDebet'];
            $kodeKredit = $row['kodeKredit'];
            $nominalH = $row['nominalH'];
            $nominalK = $row['nominalK'];

            // Skip row utama tidak valid
            if ($noBukti === '' || $kodeDebet === '' || $kodeKredit === '' || $nominalH <= 0) {
                if (! isset($usedExtRows[$r])) {
                    $this->stats['jurnal_skipped']++;
                }
                $bar->advance();

                continue;
            }

            // Validasi COA
            if (! isset($this->coaMap[$kodeDebet]) || ! isset($this->coaMap[$kodeKredit])) {
                $this->stats['jurnal_skipped']++;
                $this->stats['jurnal_errors'][] = "R$r: COA tidak ada ($kodeDebet / $kodeKredit)";
                $bar->advance();

                continue;
            }

            $tanggal = null;
            if (is_numeric($row['tglSerial']) && $row['tglSerial'] > 25000) {
                $tanggal = Carbon::createFromTimestamp((int) (($row['tglSerial'] - 25569) * 86400))->toDateString();
            }
            if (! $tanggal) {
                $this->stats['jurnal_skipped']++;
                $bar->advance();

                continue;
            }

            $kategori = $this->deriveKategori($noBukti);

            // 1. Lookahead adjacent extension — 2 pattern:
            //    a. Kredit-only: F kosong, I terisi, K > 0 (mis. PPh line)
            //    b. Debet-only:  F terisi, I kosong, H > 0 (mis. debet tambahan ke akun sama)
            $extRows = [];
            while ($idx + 1 < $rowCount) {
                $nextR = $rowKeys[$idx + 1];
                $next = $rows[$nextR];
                if (isset($usedExtRows[$nextR])) {
                    break;
                }
                $isKreditOnly = ($next['kodeDebet'] === '' && $next['kodeKredit'] !== '' && $next['nominalK'] > 0
                    && isset($this->coaMap[$next['kodeKredit']]));
                $isDebetOnly = ($next['kodeDebet'] !== '' && $next['kodeKredit'] === '' && $next['nominalH'] > 0
                    && isset($this->coaMap[$next['kodeDebet']]));
                if ($isKreditOnly || $isDebetOnly) {
                    $extRows[] = $next;
                    $usedExtRows[$nextR] = true;
                    $idx++;
                    $bar->advance();
                } else {
                    break;
                }
            }

            // 2. Kalau H > K di row utama (H≠K), cari extension by no_bukti di pool byNoBukti
            //    (extension bisa terpisah lokasi tapi no_bukti sama)
            if ($nominalH > $nominalK && $nominalK > 0 && isset($byNoBukti[$noBukti])) {
                foreach ($byNoBukti[$noBukti] as $rr) {
                    if ($rr === $r) {
                        continue; // skip row utama sendiri
                    }
                    if (isset($usedExtRows[$rr])) {
                        continue;
                    }
                    $rd = $rows[$rr];
                    if ($rd['kodeDebet'] === '' && $rd['kodeKredit'] !== '' && $rd['nominalK'] > 0
                        && isset($this->coaMap[$rd['kodeKredit']])) {
                        $extRows[] = $rd;
                        $usedExtRows[$rr] = true;
                    }
                }
            }

            if (! empty($extRows)) {
                $firstExt = $extRows[0];
                $mapKey = "$kodeDebet|{$firstExt['kodeKredit']}";

                if (count($extRows) === 1 && isset(self::SPLIT_MAPPING[$mapKey])) {
                    $extDebetKode = self::SPLIT_MAPPING[$mapKey];
                    $extDebetId = $this->coaMap[$extDebetKode] ?? null;
                    if ($extDebetId) {
                        $buffer[] = [
                            'no_bukti' => $noBukti,
                            'tanggal' => $tanggal,
                            'keterangan' => $row['keterangan'] ?: null,
                            'kategori_bukti' => $kategori,
                            'lines' => [
                                ['coa_id' => $this->coaMap[$kodeDebet], 'debet' => $nominalK, 'kredit' => 0],
                                ['coa_id' => $this->coaMap[$kodeKredit], 'debet' => 0, 'kredit' => $nominalK],
                            ],
                        ];
                        $jurnalByNoBukti[$noBukti] = count($buffer) - 1;
                        $buffer[] = [
                            'no_bukti' => $firstExt['noBukti'] ?: $noBukti,
                            'tanggal' => $tanggal,
                            'keterangan' => $firstExt['keterangan'] ?: $row['keterangan'],
                            'kategori_bukti' => $kategori,
                            'lines' => [
                                ['coa_id' => $extDebetId, 'debet' => $firstExt['nominalK'], 'kredit' => 0],
                                ['coa_id' => $this->coaMap[$firstExt['kodeKredit']], 'debet' => 0, 'kredit' => $firstExt['nominalK']],
                            ],
                        ];
                    }
                } else {
                    $lines = [
                        ['coa_id' => $this->coaMap[$kodeDebet], 'debet' => $nominalH, 'kredit' => 0],
                        ['coa_id' => $this->coaMap[$kodeKredit], 'debet' => 0, 'kredit' => $nominalK > 0 ? $nominalK : $nominalH],
                    ];
                    foreach ($extRows as $ext) {
                        // Kredit-only extension
                        if ($ext['kodeKredit'] !== '' && $ext['nominalK'] > 0 && isset($this->coaMap[$ext['kodeKredit']])) {
                            $lines[] = [
                                'coa_id' => $this->coaMap[$ext['kodeKredit']],
                                'debet' => 0,
                                'kredit' => $ext['nominalK'],
                            ];
                        }
                        // Debet-only extension (F terisi, I kosong)
                        if ($ext['kodeDebet'] !== '' && $ext['nominalH'] > 0 && $ext['kodeKredit'] === ''
                            && isset($this->coaMap[$ext['kodeDebet']])) {
                            $lines[] = [
                                'coa_id' => $this->coaMap[$ext['kodeDebet']],
                                'debet' => $ext['nominalH'],
                                'kredit' => 0,
                            ];
                        }
                    }
                    $buffer[] = [
                        'no_bukti' => $noBukti,
                        'tanggal' => $tanggal,
                        'keterangan' => $row['keterangan'] ?: null,
                        'kategori_bukti' => $kategori,
                        'lines' => $lines,
                    ];
                    $jurnalByNoBukti[$noBukti] = count($buffer) - 1;
                }
            } else {
                $nominal = $nominalK > 0 ? $nominalK : $nominalH;
                $buffer[] = [
                    'no_bukti' => $noBukti,
                    'tanggal' => $tanggal,
                    'keterangan' => $row['keterangan'] ?: null,
                    'kategori_bukti' => $kategori,
                    'lines' => [
                        ['coa_id' => $this->coaMap[$kodeDebet], 'debet' => $nominal, 'kredit' => 0],
                        ['coa_id' => $this->coaMap[$kodeKredit], 'debet' => 0, 'kredit' => $nominal],
                    ],
                ];
                $jurnalByNoBukti[$noBukti] = count($buffer) - 1;
            }

            $bar->advance();
            $bar->setMessage((string) $this->stats['jurnal_added'], 'added');
            $bar->setMessage((string) $this->stats['jurnal_skipped'], 'skipped');
        }

        // POST-SCAN: cari orphan extension (F kosong I terisi K>0) yang belum ke-pakai di adjacent
        // Match by no_bukti ke jurnal existing → tambahkan kredit line
        $orphanCount = 0;
        foreach ($rows as $rr => $rd) {
            if (isset($usedExtRows[$rr])) {
                continue;
            }
            if ($rd['kodeDebet'] === '' && $rd['kodeKredit'] !== '' && $rd['nominalK'] > 0
                && $rd['noBukti'] !== '' && isset($this->coaMap[$rd['kodeKredit']])
                && isset($jurnalByNoBukti[$rd['noBukti']])) {
                $idx = $jurnalByNoBukti[$rd['noBukti']];
                $buffer[$idx]['lines'][] = [
                    'coa_id' => $this->coaMap[$rd['kodeKredit']],
                    'debet' => 0,
                    'kredit' => $rd['nominalK'],
                ];
                $usedExtRows[$rr] = true;
                $orphanCount++;
            }
        }
        if ($orphanCount > 0) {
            $this->line("  ↳ Post-scan: gabung $orphanCount row orphan extension ke jurnal existing");
        }

        // Flush semua di akhir (bukan per chunk, biar reference index tetap valid)
        if (! empty($buffer)) {
            $chunks = array_chunk($buffer, $chunk);
            foreach ($chunks as $c) {
                $this->flushJurnalBuffer($c);
            }
        }

        $bar->finish();
        $this->newLine();

        $this->info(sprintf('  ✓ Jurnal: %d added, %d skipped',
            $this->stats['jurnal_added'], $this->stats['jurnal_skipped']));

        // Show top 10 errors
        if (! empty($this->stats['jurnal_errors'])) {
            $this->warn('  Errors (sample 10):');
            foreach (array_slice($this->stats['jurnal_errors'], 0, 10) as $err) {
                $this->line('    - '.$err);
            }
            if (count($this->stats['jurnal_errors']) > 10) {
                $this->line('    ... dan '.(count($this->stats['jurnal_errors']) - 10).' error lain');
            }
        }
        $this->newLine();

        $sp->disconnectWorksheets();
        unset($sp);
    }

    /** Counter global: [kategori/mm/yy => next seq]. */
    protected array $noBuktiCounter = [];

    /**
     * Extract numeric value from cell — handle formula, cached value, or literal.
     * Kalau cell = formula (mis. "=60000000+4500000"), gunakan cached calculated value.
     */
    protected function numericCell($cell): float
    {
        $v = $cell->getValue();
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        // String formula → try cached calculated value
        if (is_string($v) && str_starts_with($v, '=')) {
            $cached = $cell->getOldCalculatedValue();
            if (is_numeric($cached)) {
                return (float) $cached;
            }
            // Fallback: try simple eval "60000000+4500000" or "225000*3" atau "12+34-5"
            $expr = substr($v, 1); // strip '='
            if (preg_match('/^[\d\.\+\-\*\/\(\) ]+$/', $expr)) {
                // Safe: hanya angka dan operator dasar
                try {
                    $result = @eval("return $expr;");
                    if (is_numeric($result)) {
                        return (float) $result;
                    }
                } catch (\Throwable $e) { /* ignore */
                }
            }

            return 0.0;
        }

        return (float) $v;
    }

    /** Generate no bukti generic {KATEGORI}/mm/yy/xxxx dengan sequence per kategori-bulan. */
    protected function nextNoBuktiGeneric(string $kategori, string $tanggal): string
    {
        $tgl = Carbon::parse($tanggal);
        $prefix = strtoupper($kategori).'/'.$tgl->format('m').'/'.$tgl->format('y');

        if (! isset($this->noBuktiCounter[$prefix])) {
            // Seed dari DB (kalau ada existing dgn prefix ini)
            $existing = Jurnal::where('perusahaan_id', $this->perusahaan->id)
                ->where('no_bukti', 'like', $prefix.'/%')
                ->pluck('no_bukti')
                ->map(fn ($n) => (int) preg_replace('/^.*\//', '', $n))
                ->max() ?? 0;
            $this->noBuktiCounter[$prefix] = $existing;
        }

        $this->noBuktiCounter[$prefix]++;

        return $prefix.'/'.str_pad((string) $this->noBuktiCounter[$prefix], 4, '0', STR_PAD_LEFT);
    }

    /** Batch insert jurnal + jurnal_detail (support multi-line) dalam 1 transaction. */
    protected function flushJurnalBuffer(array $buffer): void
    {
        DB::transaction(function () use ($buffer) {
            foreach ($buffer as $b) {
                $noBukti = $this->nextNoBuktiGeneric($b['kategori_bukti'], $b['tanggal']);

                $jurnal = Jurnal::create([
                    'perusahaan_id' => $this->perusahaan->id,
                    'tanggal' => $b['tanggal'],
                    'no_bukti' => $noBukti,
                    'tipe' => 'umum',
                    'kategori_bukti' => $b['kategori_bukti'],
                    'keterangan' => $b['keterangan'],
                    'status' => 'posted',
                    'posted_by_user_id' => $this->userId,
                    'posted_at' => now(),
                    'created_by_user_id' => $this->userId,
                ]);

                $detailData = [];
                foreach ($b['lines'] as $ln) {
                    $detailData[] = [
                        'jurnal_id' => $jurnal->id,
                        'coa_id' => $ln['coa_id'],
                        'debet' => $ln['debet'],
                        'kredit' => $ln['kredit'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                JurnalDetail::insert($detailData);

                $this->stats['jurnal_added']++;
            }
        });
    }

    /** Derive kategori dari prefix no bukti. */
    protected function deriveKategori(string $noBukti): string
    {
        $upper = strtoupper($noBukti);
        // Bank keywords
        foreach (['BANK', 'BTN', 'BCA', 'NOBU', 'BSN', 'BSI', 'BJB', 'MANDIRI', 'BRI', 'BNI', 'CIMB', 'DANAMON', 'PERMATA', 'MEGA', 'OCBC', 'MAYBANK'] as $kw) {
            if (str_starts_with($upper, $kw)) {
                return 'BANK';
            }
        }
        // Kas keywords
        if (str_starts_with($upper, 'KAS')) {
            return 'KAS';
        }
        if (str_starts_with($upper, 'RJE')) {
            return 'RJE';
        }
        if (str_starts_with($upper, 'PENJ') || str_starts_with($upper, 'SALES')) {
            return 'PENJ';
        }
        if (str_starts_with($upper, 'HPP')) {
            return 'HPP';
        }
        if (str_starts_with($upper, 'AKM')) {
            return 'AKM';
        }

        return 'AKM'; // default fallback (Akuntansi Memorial)
    }

    protected function summary(): void
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║  RINGKASAN IMPORT                                          ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->line(sprintf('  COA        : %d added, %d skipped', $this->stats['coa_added'], $this->stats['coa_skipped']));
        $this->line(sprintf('  Aktiva     : %d added', $this->stats['aktiva_added']));
        $this->line(sprintf('  Jurnal     : %d added, %d skipped', $this->stats['jurnal_added'], $this->stats['jurnal_skipped']));

        $total = Jurnal::count();
        $totalDebet = (float) JurnalDetail::sum('debet');
        $this->newLine();
        $this->line('  Total Jurnal di DB : '.number_format($total));
        $this->line('  Total Volume Debet : Rp '.number_format($totalDebet, 0, ',', '.'));
        $this->newLine();
        $this->comment('Reconciliation: buka /akunting/neraca & /akunting/laba-rugi → bandingkan angka dgn sheet NR & LR di Excel.');
    }
}
