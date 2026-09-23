<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan singkat untuk tahap Wawancara.
 *
 * Hasil wawancara sering perlu keterangan yang tidak terwakili oleh tanggal —
 * misalnya wawancara ulang, diwakilkan pasangan, atau menunggu berkas susulan.
 * Sebelumnya hanya ada kolom `catatan` untuk seluruh pemberkasan, sehingga
 * keterangan yang khusus wawancara ikut tercampur dan tidak bisa ditampilkan
 * di bawah tanggalnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spr_pemberkasan', function (Blueprint $table) {
            if (! Schema::hasColumn('spr_pemberkasan', 'wcr_catatan')) {
                $table->string('wcr_catatan', 255)->nullable()->after('wcr_tanggal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('spr_pemberkasan', function (Blueprint $table) {
            if (Schema::hasColumn('spr_pemberkasan', 'wcr_catatan')) {
                $table->dropColumn('wcr_catatan');
            }
        });
    }
};
