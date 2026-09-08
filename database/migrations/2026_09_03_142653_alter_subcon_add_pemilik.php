<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemilik badan usaha, terpisah dari penanggung jawab proyek.
 *
 * Di form registrasi lama keduanya diisi terpisah: `nama` adalah orang yang
 * bertanggung jawab atas pekerjaan di lapangan, sedangkan `pemilik` adalah yang
 * memiliki CV/PT-nya. Sering sama orangnya, tapi tidak selalu — dan yang menandatangani
 * kontrak bukan tentu yang mengawasi proyek.
 *
 * Hanya terisi kalau subcon punya badan usaha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcon', function (Blueprint $table) {
            $table->string('pemilik')->nullable()->after('badan_usaha');
            $table->text('alamat_pemilik')->nullable()->after('pemilik');
        });
    }

    public function down(): void
    {
        Schema::table('subcon', function (Blueprint $table) {
            $table->dropColumn(['pemilik', 'alamat_pemilik']);
        });
    }
};
