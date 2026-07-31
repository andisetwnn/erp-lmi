<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:reset-transactional-data {--force : Skip konfirmasi interaktif}')]
#[Description('Reset semua data transaksi & data operasional. Master perusahaan/proyek/tipe_rumah/coa/users tetap dipertahankan.')]
class ResetTransactionalData extends Command
{
    /**
     * Tabel yang di-truncate.
     * TIDAK termasuk: perusahaan, proyek, tipe_rumah, coa, users,
     * roles, permissions, role_has_permissions, model_has_*, bank,
     * indonesia_*, migrations, sessions, cache*, jobs*, password_reset_tokens.
     */
    private array $tablesToReset = [
        // SPR & transaksi
        'spr_termin_pembayaran',
        'spr',
        'booking',

        // Master unit rumah (dianggap operasional, akan diinput ulang per proyek)
        'virtual_account',
        'rumah',

        // Prospect & customer
        'prospect_reassignment_log',
        'prospect_customer_status_log',
        'prospect_customer_kontak_darurat',
        'prospect_customer',
        'customer_kontak_darurat',
        'customer',
        'tempat_kerja',

        // Sales
        'sales_log_perpindahan',
        'sales_target',
        'sales',
        'sales_grup',
        'jenis_sales',

        // Notaris
        'notaris_biaya_ajb_history',
        'notaris',

        // Pembatalan & lain-lain
        'alasan_pembatalan',
        'pimpinan_activity_log',
        'dismissed_notif',
    ];

    public function handle(): int
    {
        $this->warn('=== RESET DATA TRANSAKSI ERP LMI ===');
        $this->line('');
        $this->line('Tabel yang akan di-truncate:');
        foreach ($this->tablesToReset as $t) {
            $this->line('  - '.$t);
        }
        $this->line('');
        $this->info('Yang DIPERTAHANKAN: perusahaan, proyek, tipe_rumah, coa, users, roles, permissions, bank, indonesia_*');
        $this->line('');

        if (! $this->option('force') && ! $this->confirm('Lanjut reset? Data yang di-truncate tidak bisa dikembalikan.')) {
            $this->line('Dibatalkan.');

            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        $truncated = 0;
        $skipped = [];

        foreach ($this->tablesToReset as $table) {
            try {
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    $skipped[] = $table.' (tidak ada)';

                    continue;
                }
                DB::table($table)->truncate();
                $this->info("✓ {$table}");
                $truncated++;
            } catch (\Throwable $e) {
                $skipped[] = $table.' ('.$e->getMessage().')';
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');

        $this->line('');
        $this->info("Selesai. {$truncated} tabel di-truncate.");

        if (! empty($skipped)) {
            $this->line('');
            $this->warn('Di-skip:');
            foreach ($skipped as $s) {
                $this->line('  - '.$s);
            }
        }

        $this->line('');
        $this->info('Master yang tetap ada: perusahaan, proyek, tipe_rumah, coa, users + role, bank.');
        $this->info('Silakan buat ulang: sales, prospect customer, booking, SPR.');

        return self::SUCCESS;
    }
}
