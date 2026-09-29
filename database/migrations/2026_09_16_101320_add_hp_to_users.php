<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `hp` di users untuk kontak WhatsApp — dipakai fitur "Generate Link
 * Persetujuan Direksi" pada Rencana Akad supaya Admin KPR bisa langsung buka
 * WA dengan nomor & pesan pre-fill.
 *
 * Format bebas (Indonesia biasa 08xx atau 62xx). Normalisasi ke +62 dilakukan
 * saat build link wa.me.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'hp')) {
                $table->string('hp', 20)->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'hp')) {
                $table->dropColumn('hp');
            }
        });
    }
};
