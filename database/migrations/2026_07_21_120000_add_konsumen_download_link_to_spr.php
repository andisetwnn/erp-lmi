<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur #6 lanjutan: Link download PDF final ber-materai untuk konsumen.
 * Dibuat setelah SPR final (materai + TTD lengkap). Sales share link ke konsumen via WA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->string('konsumen_download_link_hash', 64)->nullable()->unique()->after('spr_finalized_at');
            $table->timestamp('konsumen_download_link_expires_at')->nullable()->after('konsumen_download_link_hash');
        });
    }

    public function down(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->dropColumn(['konsumen_download_link_hash', 'konsumen_download_link_expires_at']);
        });
    }
};
