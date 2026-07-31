<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah discount di rumah (per unit, seperti biaya_tambahan)
        Schema::table('rumah', function (Blueprint $table) {
            $table->decimal('discount', 15, 2)->default(0)->after('biaya_tambahan');
        });

        // Drop dana_titipan & discount dari tipe_rumah (sekarang per unit di rumah)
        Schema::table('tipe_rumah', function (Blueprint $table) {
            $table->dropColumn(['dana_titipan', 'discount']);
        });
    }

    public function down(): void
    {
        Schema::table('tipe_rumah', function (Blueprint $table) {
            $table->decimal('dana_titipan', 15, 2)->default(0)->after('biaya_administrasi');
            $table->decimal('discount', 15, 2)->default(0)->after('harga_all_in');
        });

        Schema::table('rumah', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }
};
