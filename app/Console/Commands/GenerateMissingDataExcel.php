<?php

namespace App\Console\Commands;

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\VirtualAccount;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateMissingDataExcel extends Command
{
    protected $signature = 'export:missing-data';

    protected $description = 'Generate Excel consolidated file untuk data yang kurang (customer, sales, SPR, VA).';

    public function handle(): int
    {
        $spreadsheet = new Spreadsheet();

        $applyHeader = function ($sheet, string $lastCol, string $colorHex = '305496'): void {
            $range = "A1:{$lastCol}1";
            $sheet->getStyle($range)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorHex);
            $sheet->getStyle($range)->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        };

        $mark = fn ($v) => ($v && $v !== '') ? '✓' : '❌';

        // SHEET 0: RINGKASAN
        $sheet0 = $spreadsheet->getActiveSheet();
        $sheet0->setTitle('Ringkasan');
        $summary = [
            ['ENTITY', 'TOTAL', 'MISSING FIELD UTAMA'],
            ['CUSTOMER', ProspectCustomer::count(), 'Bank rekening (172), Kontak darurat min 2 (171), Biodata KTP (71), Foto KTP (66)'],
            ['SALES', Sales::count(), 'Nama lengkap (9), Alamat (9), Telepon real (9), Bank (9), TTD digital (9)'],
            ['SPR', Spr::count(), 'Cabang Bank KPR (174), Bukti UTJ (174), TTD digital (174), Dokumen SPR TTD Customer (174)'],
            ['VIRTUAL ACCOUNT', VirtualAccount::count(), 'COA mapping (146)'],
            ['', '', ''],
            ['SPR BATAL PENDING', 6, 'Panji Ilham (DD-05), Rizky, Sunarti, Paradigma, Raden, Marni — nunggu nomor SPR'],
        ];
        foreach ($summary as $r => $rowData) {
            foreach ($rowData as $c => $v) $sheet0->setCellValueByColumnAndRow($c + 1, $r + 1, $v);
        }
        $applyHeader($sheet0, 'C', 'ED7D31');
        foreach (['A','B','C'] as $c) $sheet0->getColumnDimension($c)->setAutoSize(true);
        $sheet0->getStyle('A1:C7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet0->getColumnDimension('C')->setWidth(70);

        // SHEET 1: CUSTOMER
        $sheet1 = $spreadsheet->createSheet();
        $sheet1->setTitle('Customer');
        $headers1 = ['No', 'Nama Lengkap', 'NIK', 'HP', 'No SPR', 'Unit', 'Sales', 'Alamat KTP',
            'Foto KTP', 'Tempat/Tgl Lahir', 'Jenis Kelamin', 'Agama', 'Status Kawin', 'RT/RW',
            'Pekerjaan', 'Penghasilan', 'Tempat Kerja', 'BI Check', 'Bank Rekening', 'No Rekening',
            'Kontak Darurat', 'TOTAL Missing'];
        foreach ($headers1 as $i => $h) $sheet1->setCellValueByColumnAndRow($i + 1, 1, $h);
        $applyHeader($sheet1, 'V');

        $row = 2; $no = 1;
        foreach (ProspectCustomer::with('kontakDarurat')->orderBy('nama_lengkap')->get() as $p) {
            $spr = Spr::where('prospect_customer_id', $p->id)->with('rumah', 'sales')->first();
            $missing = 0;
            if (!$p->foto_ktp) $missing++;
            if (!$p->tempat_lahir || !$p->tanggal_lahir) $missing++;
            foreach (['jenis_kelamin','agama','status_perkawinan','rt_rw','pekerjaan_ktp','penghasilan_bulanan','tempat_kerja_id','bi_kol','bank_id','nomor_rekening'] as $f) {
                if (!$p->$f) $missing++;
            }
            $kondarCount = $p->kontakDarurat->count();
            if ($kondarCount < 2) $missing++;

            $ttl = ($p->tempat_lahir && $p->tanggal_lahir) ? '✓' : '❌';
            $kondar = $kondarCount >= 2 ? "✓ ($kondarCount)" : "❌ ($kondarCount/2)";

            $data = [$no++, $p->nama_lengkap, $p->nik, $p->hp,
                $spr?->nomor_spr ?? '-',
                $spr && $spr->rumah ? $spr->rumah->blok.'-'.$spr->rumah->nomor_unit : '-',
                $spr?->sales?->nama ?? '-',
                $p->alamat ?? '-',
                $mark($p->foto_ktp), $ttl, $mark($p->jenis_kelamin), $mark($p->agama), $mark($p->status_perkawinan), $mark($p->rt_rw),
                $mark($p->pekerjaan_ktp), $mark($p->penghasilan_bulanan), $mark($p->tempat_kerja_id), $mark($p->bi_kol),
                $mark($p->bank_id), $mark($p->nomor_rekening), $kondar, $missing];
            foreach ($data as $i => $v) $sheet1->setCellValueByColumnAndRow($i + 1, $row, $v);
            $row++;
        }
        foreach (range('A', 'V') as $c) $sheet1->getColumnDimension($c)->setAutoSize(true);
        $sheet1->freezePane('A2');
        $sheet1->getStyle('A1:V'.($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // SHEET 2: SALES
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Sales');
        $headers2 = ['No', 'Kode', 'Nama Depan (Existing)', 'Nama Lengkap', 'Alamat', 'Telepon Real', 'Bank', 'No Rekening', 'TTD Digital', 'TOTAL Missing'];
        foreach ($headers2 as $i => $h) $sheet2->setCellValueByColumnAndRow($i + 1, 1, $h);
        $applyHeader($sheet2, 'J', '70AD47');

        $row = 2; $no = 1;
        foreach (Sales::orderBy('kode')->get() as $s) {
            $missing = 1; // nama lengkap
            if (!$s->alamat) $missing++;
            $telIsPlaceholder = strpos($s->telepon ?? '', '08123456') === 0;
            if ($telIsPlaceholder) $missing++;
            if (!$s->bank_id) $missing++;
            if (!$s->nomor_rekening) $missing++;
            if (!$s->tanda_tangan_path) $missing++;

            $data = [$no++, $s->kode, $s->nama, '❌', $mark($s->alamat),
                $telIsPlaceholder ? '❌ (placeholder)' : $mark($s->telepon),
                $mark($s->bank_id), $mark($s->nomor_rekening), $mark($s->tanda_tangan_path), $missing];
            foreach ($data as $i => $v) $sheet2->setCellValueByColumnAndRow($i + 1, $row, $v);
            $row++;
        }
        foreach (range('A', 'J') as $c) $sheet2->getColumnDimension($c)->setAutoSize(true);
        $sheet2->freezePane('A2');
        $sheet2->getStyle('A1:J'.($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // SHEET 3: SPR
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('SPR');
        $headers3 = ['No', 'No SPR', 'Customer', 'Unit', 'Sales', 'Tgl SPR', 'Status',
            'Bank KPR (verify)', 'Cabang Bank KPR', 'Bukti Transfer UTJ', 'TTD Sales', 'TTD Finance', 'TTD PM',
            'Dokumen SPR TTD+Materai', 'Catatan Angsuran', 'TOTAL Missing'];
        foreach ($headers3 as $i => $h) $sheet3->setCellValueByColumnAndRow($i + 1, 1, $h);
        $applyHeader($sheet3, 'P', 'C00000');

        $row = 2; $no = 1;
        foreach (Spr::with('prospectCustomer', 'rumah', 'sales', 'bankKpr')->orderBy('nomor_spr')->get() as $s) {
            $missing = 0;
            $bankKprVerify = $s->bankKpr ? '⚠️ verify' : '❌';
            if (!$s->bankKpr) $missing++;
            $missing++; // cabang
            if (!$s->utj_bukti_path) $missing++;
            if (!$s->ttd_sales_path) $missing++;
            if (!$s->ttd_finance_path) $missing++;
            if (!$s->ttd_pm_path) $missing++;
            if (!($s->dokumen_signed_path ?? null)) $missing++;
            if (!$s->catatan_angsuran) $missing++;

            $data = [$no++, $s->nomor_spr, $s->prospectCustomer?->nama_lengkap,
                ($s->rumah?->blok.'-'.$s->rumah?->nomor_unit), $s->sales?->nama,
                $s->tanggal_spr?->format('d/m/Y'), $s->status,
                ($s->bankKpr?->nama ?? '-').' ('.$bankKprVerify.')',
                '❌ perlu isi (Syariah Bogor / Konven Bogor / Ciputat / dll)',
                $mark($s->utj_bukti_path), $mark($s->ttd_sales_path), $mark($s->ttd_finance_path), $mark($s->ttd_pm_path),
                $mark($s->dokumen_signed_path ?? null), $mark($s->catatan_angsuran), $missing];
            foreach ($data as $i => $v) $sheet3->setCellValueByColumnAndRow($i + 1, $row, $v);
            $row++;
        }
        foreach (range('A', 'P') as $c) $sheet3->getColumnDimension($c)->setAutoSize(true);
        $sheet3->freezePane('A2');
        $sheet3->getStyle('A1:P'.($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // SHEET 4: VIRTUAL ACCOUNT
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('Virtual Account');
        $headers4 = ['No', 'Nomor VA', 'Bank', 'Rumah (Blok-Unit)', 'Customer', 'COA (Kas/Bank)', 'Status Aktif', 'TOTAL Missing'];
        foreach ($headers4 as $i => $h) $sheet4->setCellValueByColumnAndRow($i + 1, 1, $h);
        $applyHeader($sheet4, 'H', '7030A0');

        $row = 2; $no = 1;
        foreach (VirtualAccount::with('rumah', 'bank')->orderBy('nomor_va')->get() as $va) {
            $unit = $va->rumah ? $va->rumah->blok.'-'.$va->rumah->nomor_unit : '-';
            $customer = '-';
            if ($va->rumah) {
                $spr = Spr::where('rumah_id', $va->rumah_id)->with('prospectCustomer')->first();
                $customer = $spr?->prospectCustomer?->nama_lengkap ?? '-';
            }
            $data = [$no++, $va->nomor_va, $va->bank?->nama ?? '-', $unit, $customer, '❌',
                $va->is_aktif ? 'Aktif' : 'Nonaktif', 1];
            foreach ($data as $i => $v) $sheet4->setCellValueByColumnAndRow($i + 1, $row, $v);
            $row++;
        }
        foreach (range('A', 'H') as $c) $sheet4->getColumnDimension($c)->setAutoSize(true);
        $sheet4->freezePane('A2');
        $sheet4->getStyle('A1:H'.($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $spreadsheet->setActiveSheetIndex(0);

        $filename = storage_path('app/private/data-kurang-import-'.now()->format('Ymd-His').'.xlsx');
        (new Xlsx($spreadsheet))->save($filename);

        $this->info("File saved: {$filename}");
        $this->info('Sheets:');
        foreach ($spreadsheet->getSheetNames() as $name) $this->info("  - {$name}");

        return self::SUCCESS;
    }
}
