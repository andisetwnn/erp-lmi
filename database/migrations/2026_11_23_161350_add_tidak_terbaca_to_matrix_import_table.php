<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sel tanggal di berkas Matrix yang isinya ada tapi tidak bisa dibaca.
     *
     * Disimpan bersama unggahannya supaya Admin KPR bisa melihat sel mana yang
     * perlu dirapikan — sebelumnya sel seperti itu hilang tanpa jejak.
     */
    public function up(): void
    {
        Schema::table('matrix_import', function (Blueprint $table) {
            $table->json('tidak_terbaca')->nullable()->after('jumlah_baris');
        });
    }

    public function down(): void
    {
        Schema::table('matrix_import', function (Blueprint $table) {
            $table->dropColumn('tidak_terbaca');
        });
    }
};
