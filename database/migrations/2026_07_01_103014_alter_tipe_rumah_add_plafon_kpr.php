<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipe_rumah', function (Blueprint $table) {
            $table->decimal('plafon_kpr', 15, 2)->default(0)->after('harga_all_in');
        });

        // Prefill plafon_kpr untuk tipe existing berdasarkan nilai historic.
        // Tipe Arjuna (subsidi): 179.000.000 (dari data SPR historic).
        if (DB::table('tipe_rumah')->exists()) {
            DB::table('tipe_rumah')
                ->where('nama_tipe', 'like', '%Arjuna%')
                ->update(['plafon_kpr' => 179_000_000]);
        }
    }

    public function down(): void
    {
        Schema::table('tipe_rumah', function (Blueprint $table) {
            $table->dropColumn('plafon_kpr');
        });
    }
};
