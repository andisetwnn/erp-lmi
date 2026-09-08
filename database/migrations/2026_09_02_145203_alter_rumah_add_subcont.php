<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subkontraktor pembangun unit (mis. WIN, EK).
 *
 * Ditaruh di rumah, bukan di baris rencana akad, karena ini sifat unitnya — siapa yang
 * membangunnya tidak berubah tiap kali akad dijadwalkan ulang. Lembar Aju Dana
 * menampilkannya per unit, dan Teknik juga membutuhkannya untuk progres bangunan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rumah', function (Blueprint $table) {
            $table->string('subcont', 60)->nullable()->after('lot');
        });
    }

    public function down(): void
    {
        Schema::table('rumah', function (Blueprint $table) {
            $table->dropColumn('subcont');
        });
    }
};
