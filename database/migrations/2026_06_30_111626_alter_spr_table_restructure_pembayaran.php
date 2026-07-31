<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            // Snapshot kategori unit (subsidi/komersial) saat SPR dibuat
            $table->enum('kategori', ['subsidi', 'komersial'])->default('komersial')->after('rumah_id');

            // Gabungan biaya_administrasi + biaya_bahan_hook
            $table->decimal('biaya_tambahan', 15, 2)->default(0)->after('harga_jual');
        });

        // Migrate value (skip kalau tabel kosong — terutama saat sqlite test env)
        if (DB::table('spr')->exists()) {
            // biaya_tambahan = biaya_administrasi + biaya_bahan_hook
            DB::statement('UPDATE spr SET biaya_tambahan = COALESCE(biaya_administrasi, 0) + COALESCE(biaya_bahan_hook, 0)');

            // Set kategori dari tipe_rumah lewat subquery (cross-DB compatible: MySQL + SQLite)
            DB::statement(<<<'SQL'
                UPDATE spr
                SET kategori = COALESCE((
                    SELECT t.kategori
                    FROM rumah r
                    INNER JOIN tipe_rumah t ON t.id = r.tipe_rumah_id
                    WHERE r.id = spr.rumah_id
                    LIMIT 1
                ), 'komersial')
            SQL);
        }

        Schema::table('spr', function (Blueprint $table) {
            $table->dropColumn([
                'biaya_administrasi',
                'biaya_bahan_hook',
                'tenor_bulan',
                'angsuran_per_bulan',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->decimal('biaya_administrasi', 15, 2)->default(0)->after('harga_jual');
            $table->decimal('biaya_bahan_hook', 15, 2)->default(0)->after('biaya_administrasi');
            $table->unsignedSmallInteger('tenor_bulan')->default(0)->after('um_net');
            $table->decimal('angsuran_per_bulan', 15, 2)->default(0)->after('nilai_kpr');
        });

        // Restore value (best effort: seluruh biaya_tambahan dianggap biaya_administrasi)
        DB::statement('UPDATE spr SET biaya_administrasi = biaya_tambahan');

        Schema::table('spr', function (Blueprint $table) {
            $table->dropColumn(['kategori', 'biaya_tambahan']);
        });
    }
};
