<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan Pembelian: PO yang terbit tapi tidak dibayar sama sekali lebih dari
 * sekian hari dibatalkan otomatis. Kosong = tidak aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perusahaan', function (Blueprint $table) {
            $table->unsignedSmallInteger('batas_po_gantung_hari')->nullable()->after('batas_persetujuan_direktur');
        });
    }

    public function down(): void
    {
        Schema::table('perusahaan', function (Blueprint $table) {
            $table->dropColumn('batas_po_gantung_hari');
        });
    }
};
