<?php

namespace App\Console\Commands\Import;

use App\Models\Master\Customer;
use App\Models\Master\ProspectCustomer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pasang foto KTP ke prospect & customer berdasarkan nama file = NIK.
 *
 * File sudah dikonversi ke JPG di lokal (PDF hasil scan tidak bisa ditampilkan <img>),
 * lalu diunggah apa adanya ke storage/app/public/prospect-ktp/. Perintah ini hanya
 * menautkan path-nya ke record — aman dijalankan berulang.
 */
class AttachKtpCommand extends Command
{
    protected $signature = 'import:attach-ktp
        {--dir=prospect-ktp : Folder di dalam storage/app/public}
        {--overwrite : Timpa foto_ktp yang sudah terisi}
        {--dry-run : Tampilkan rencana tanpa menyimpan}';

    protected $description = 'Tautkan file KTP (nama file = NIK) ke prospect_customer & customer';

    public function handle(): int
    {
        $dir = trim((string) $this->option('dir'), '/');
        $path = storage_path("app/public/$dir");

        if (! is_dir($path)) {
            $this->error("Folder tidak ditemukan: $path");
            $this->line('Unggah dulu hasil konversi JPG ke folder tersebut.');

            return self::FAILURE;
        }

        $berkas = glob("$path/*.{jpg,jpeg,png,JPG,JPEG,PNG}", GLOB_BRACE) ?: [];
        if (! $berkas) {
            $this->error("Tidak ada file gambar di $path");

            return self::FAILURE;
        }

        $this->info('ATTACH KTP');
        $this->line("Folder : $path");
        $this->line('Berkas : '.count($berkas));
        $this->newLine();

        $overwrite = (bool) $this->option('overwrite');
        $terpasangProspect = 0;
        $terpasangCustomer = 0;
        $sudahAda = 0;
        $tanpaRecord = [];

        DB::beginTransaction();

        foreach ($berkas as $f) {
            $nik = pathinfo($f, PATHINFO_FILENAME);
            if (! ctype_digit($nik)) {
                $tanpaRecord[] = basename($f).' (nama file bukan NIK)';

                continue;
            }

            $relatif = $dir.'/'.basename($f);
            $adaRecord = false;

            foreach ([ProspectCustomer::class, Customer::class] as $model) {
                $dipasang = 0;
                foreach ($model::where('nik', $nik)->get() as $rec) {
                    $adaRecord = true;

                    if ($rec->foto_ktp === $relatif || ($rec->foto_ktp && ! $overwrite)) {
                        $sudahAda++;

                        continue;
                    }

                    $rec->update(['foto_ktp' => $relatif]);
                    $dipasang++;
                }

                if ($model === ProspectCustomer::class) {
                    $terpasangProspect += $dipasang;
                } else {
                    $terpasangCustomer += $dipasang;
                }
            }

            if (! $adaRecord) {
                $tanpaRecord[] = "$nik (tidak ada prospect/customer dgn NIK ini)";
            }
        }

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->warn('DRY-RUN: tidak ada perubahan disimpan.');
        } else {
            DB::commit();
        }

        $this->line("  Prospect dipasangi KTP : $terpasangProspect");
        $this->line("  Customer dipasangi KTP : $terpasangCustomer");
        $this->line("  Sudah terpasang (dilewati) : $sudahAda");
        $this->line('  Berkas tanpa record : '.count($tanpaRecord));

        if ($tanpaRecord) {
            $this->newLine();
            $this->warn('Berkas yang tidak menemukan pemiliknya:');
            foreach (array_slice($tanpaRecord, 0, 15) as $t) {
                $this->line("  · $t");
            }
            if (count($tanpaRecord) > 15) {
                $this->line('  ... ('.(count($tanpaRecord) - 15).' lagi)');
            }
        }

        $this->newLine();
        $this->info('Selesai.');

        return self::SUCCESS;
    }
}
