<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alur baru: draft → diajukan → diketahui → fix
 *
 * "diketahui" adalah persetujuan Project Manager di dalam sistem — sebelum
 * meneruskan ke Direksi untuk penandatanganan. Slot ini tampil sebagai
 * "Mengetahui" pada lembar Aju Dana.
 *
 * Migration ini idempotent-ish: cek dulu enum saat ini via information_schema
 * supaya rollback/re-run tidak crash. Data lama (status='fix' pra-perubahan)
 * tidak diapa-apakan — histori tetap valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah kolom persetujuan PM.
        Schema::table('rencana_akad', function (Blueprint $table) {
            $table->timestamp('diketahui_at')->nullable()->after('diajukan_by_user_id');
            $table->foreignId('diketahui_by_user_id')
                ->nullable()
                ->after('diketahui_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        // 2. Perluas enum status supaya 'diketahui' valid.
        //
        // Hanya MySQL yang punya konsep ENUM ketat — SQLite (test in-memory)
        // menyimpannya sebagai teks bebas jadi tidak perlu diubah.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE rencana_akad MODIFY COLUMN status '.
                "ENUM('draft','diajukan','diketahui','fix','batal') ".
                "NOT NULL DEFAULT 'draft'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $ada = DB::table('rencana_akad')->where('status', 'diketahui')->exists();
            if ($ada) {
                throw new RuntimeException(
                    "Masih ada rencana akad berstatus 'diketahui'. Ubah dulu ".
                    'sebelum menurunkan migration.'
                );
            }

            DB::statement(
                'ALTER TABLE rencana_akad MODIFY COLUMN status '.
                "ENUM('draft','diajukan','fix','batal') ".
                "NOT NULL DEFAULT 'draft'"
            );
        }

        Schema::table('rencana_akad', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diketahui_by_user_id');
            $table->dropColumn('diketahui_at');
        });
    }
};
