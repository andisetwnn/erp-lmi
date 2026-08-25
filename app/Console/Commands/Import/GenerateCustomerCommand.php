<?php

namespace App\Console\Commands\Import;

use App\Models\Master\Customer;
use App\Models\Master\ProspectCustomer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bentuk master Customer dari prospect yang sudah finish, dicocokkan lewat NIK.
 *
 * Import historis hanya mengisi prospect_customer — record Customer tidak ikut terbentuk,
 * padahal modul akad & pemberkasan bekerja di atas Customer. Perintah ini menutup jarak itu
 * dan aman dijalankan berulang: NIK yang sudah punya Customer dilewati.
 *
 * Jalankan SESUDAH `import:sop` dan SEBELUM `import:sop-detail` — detail (alamat kantor,
 * pekerjaan, BI check) di-backfill oleh perintah tersebut ke prospect dan customer sekaligus.
 */
class GenerateCustomerCommand extends Command
{
    protected $signature = 'import:generate-customer
        {--status=finish : Status prospect yang dijadikan customer}
        {--dry-run : Tampilkan rencana tanpa menyimpan}';

    protected $description = 'Bentuk master Customer dari prospect finish (dedup by NIK)';

    /** Kolom yang namanya sama persis di kedua tabel — disalin apa adanya. */
    protected const KOLOM_SAMA = [
        'proyek_id', 'nama_lengkap', 'nik', 'tempat_lahir', 'tanggal_lahir',
        'jenis_kelamin', 'agama', 'status_perkawinan', 'npwp', 'hp', 'hp_2',
        'sumber', 'tempat_kerja_id', 'rt_rw', 'provinsi_code', 'provinsi_nama',
        'kota_code', 'kota_nama', 'kecamatan_code', 'kecamatan_nama',
        'kelurahan_code', 'kelurahan_nama', 'catatan', 'foto_ktp', 'bank_id',
        'nomor_rekening', 'rekening_atas_nama', 'penghasilan_bulanan',
        'bi_kol', 'bi_dbr', 'bi_keterangan',
    ];

    /** Kolom yang berganti nama antar tabel: prospect => customer. */
    protected const KOLOM_GANTI_NAMA = [
        'alamat' => 'alamat_ktp',
        'pekerjaan_ktp' => 'jenis_pekerjaan',
    ];

    public function handle(): int
    {
        $status = (string) $this->option('status');

        $prospects = ProspectCustomer::where('status', $status)
            ->whereNotNull('nik')
            ->where('nik', '!=', '')
            ->orderBy('id')
            ->get();

        $this->info('GENERATE CUSTOMER dari prospect');
        $this->line("Status prospect : $status");
        $this->line('Kandidat        : '.$prospects->count());
        $this->line('Customer saat ini: '.Customer::count());
        $this->newLine();

        $dibuat = 0;
        $sudahAda = 0;

        DB::beginTransaction();

        foreach ($prospects as $p) {
            if (Customer::where('nik', $p->nik)->exists()) {
                $sudahAda++;

                continue;
            }

            $data = [];
            foreach (self::KOLOM_SAMA as $kolom) {
                $data[$kolom] = $p->{$kolom};
            }
            foreach (self::KOLOM_GANTI_NAMA as $dari => $ke) {
                $data[$ke] = $p->{$dari};
            }

            Customer::create($data);
            $dibuat++;
        }

        // Prospect finish tanpa NIK tidak bisa dijadikan customer — NIK adalah kunci dedup.
        $tanpaNik = ProspectCustomer::where('status', $status)
            ->where(fn ($q) => $q->whereNull('nik')->orWhere('nik', ''))
            ->count();

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->warn('DRY-RUN: tidak ada perubahan disimpan.');
        } else {
            DB::commit();
        }

        $this->line("  Customer dibuat        : $dibuat");
        $this->line("  Sudah ada (dilewati)   : $sudahAda");
        $this->line("  Prospect finish tanpa NIK: $tanpaNik".($tanpaNik ? '  ← tidak bisa dibuatkan customer' : ''));
        $this->newLine();
        $this->info('Selesai. Lanjutkan dengan: php artisan import:sop-detail');

        return self::SUCCESS;
    }
}
