<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lembar SP3K dari bank ikut disimpan, seperti berkas lengkap.
     *
     * Dulu SP3K hanya dicatat tanggal, nomor, dan nominalnya; lembarnya
     * tersimpan di luar sistem sehingga harus dicari saat dibutuhkan.
     */
    public function up(): void
    {
        Schema::table('spr_pemberkasan', function (Blueprint $table) {
            $table->string('sp3k_file_path')->nullable()->after('sp3k_nominal');
            $table->string('sp3k_file_original_name')->nullable()->after('sp3k_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('spr_pemberkasan', function (Blueprint $table) {
            $table->dropColumn(['sp3k_file_path', 'sp3k_file_original_name']);
        });
    }
};
