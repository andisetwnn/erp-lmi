<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LPA hanya diurus untuk bank tertentu — selama ini BTN, konvensional maupun
 * syariah. Sebelumnya daftar itu ditulis berulang di dalam kode sebagai
 * ['CBN', 'BSY'], di empat tempat berbeda.
 *
 * Dijadikan sifat banknya sendiri supaya kalau nanti ada bank lain yang juga
 * mensyaratkan LPA, cukup dicentang di master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_kpr', function (Blueprint $table) {
            if (! Schema::hasColumn('bank_kpr', 'lpa_wajib')) {
                $table->boolean('lpa_wajib')->default(false)->after('biaya_proses_akad');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bank_kpr', function (Blueprint $table) {
            if (Schema::hasColumn('bank_kpr', 'lpa_wajib')) {
                $table->dropColumn('lpa_wajib');
            }
        });
    }
};
