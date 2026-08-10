<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jurnal', function (Blueprint $table) {
            // Kategori bukti untuk auto-generate no bukti prefix:
            // BANK, KAS, PENJ, AKM, RJE, HPP
            $table->string('kategori_bukti', 10)->nullable()->after('tipe');
            $table->index(['perusahaan_id', 'kategori_bukti', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::table('jurnal', function (Blueprint $table) {
            $table->dropIndex(['perusahaan_id', 'kategori_bukti', 'tanggal']);
            $table->dropColumn('kategori_bukti');
        });
    }
};
