<?php

namespace App\Console\Commands;

use App\Models\Master\Perusahaan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateManualPdf extends Command
{
    protected $signature = 'manual:generate {role? : Role tertentu (pm|keuangan|admin-sales), kosongkan untuk generate semua}';

    protected $description = 'Generate PDF panduan pengguna per role. Output di storage/app/manual/.';

    public function handle(): int
    {
        $roles = ['pm', 'keuangan', 'admin-sales'];
        $target = $this->argument('role');

        if ($target && ! in_array($target, $roles, true)) {
            $this->error("Role '{$target}' tidak dikenal. Pilihan: ".implode(', ', $roles));

            return self::FAILURE;
        }

        $selected = $target ? [$target] : $roles;
        $perusahaan = Perusahaan::first();

        Storage::disk('local')->makeDirectory('manual');

        foreach ($selected as $role) {
            $judul = match ($role) {
                'pm' => 'Panduan Project Manager',
                'keuangan' => 'Panduan Keuangan',
                'admin-sales' => 'Panduan Admin Sales',
            };

            $pdf = Pdf::loadView("exports.manual-{$role}-pdf", [
                'perusahaan' => $perusahaan,
                'judul' => $judul,
                'tanggalCetak' => now(),
            ])->setPaper('a4', 'portrait');

            $filename = 'manual/Panduan-'.ucfirst($role).'-'.now()->format('Ymd').'.pdf';
            Storage::disk('local')->put($filename, $pdf->output());

            $absPath = Storage::disk('local')->path($filename);
            $this->info("✓ {$judul} → {$absPath}");
        }

        $this->newLine();
        $this->comment('Selesai. Lokasi file: '.Storage::disk('local')->path('manual/'));

        return self::SUCCESS;
    }
}
