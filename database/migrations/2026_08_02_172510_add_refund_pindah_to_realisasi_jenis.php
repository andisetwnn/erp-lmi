<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend enum spr_realisasi_pembayaran.jenis dgn nilai baru: refund_pindah
 * Dipakai saat customer switching ke unit lebih murah / UM overpaid.
 * MySQL: ALTER TABLE ... MODIFY COLUMN ... ENUM(...)
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite: enum disimpan sbg TEXT tanpa constraint schema — skip ALTER.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE spr_realisasi_pembayaran MODIFY COLUMN jenis ENUM('bf','um','sbum','kpr','refund_pindah') NOT NULL DEFAULT 'um'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE spr_realisasi_pembayaran MODIFY COLUMN jenis ENUM('bf','um','sbum','kpr') NOT NULL DEFAULT 'um'");
    }
};
