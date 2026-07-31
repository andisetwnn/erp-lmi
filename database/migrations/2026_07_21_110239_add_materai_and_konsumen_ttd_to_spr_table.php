<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur #6: TTD Digital Konsumen + Materai Digital.
 * Flow: Finance verifikasi UTJ → Finance upload PDF ber-materai → PM approve →
 *       Sales generate link (token 40 char, expiry 30 menit) → Konsumen input NIK + TTD → final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            // === Materai (Finance upload PDF ber-materai) ===
            $table->timestamp('materai_stamped_at')->nullable()->after('pm_approved_at');
            $table->foreignId('materai_by_user_id')->nullable()->after('materai_stamped_at')
                ->constrained('users')->nullOnDelete();
            $table->string('materai_file_path')->nullable()->after('materai_by_user_id');

            // === Link tanda tangan konsumen (Sales generate) ===
            $table->string('konsumen_signing_link_hash', 64)->nullable()->unique()->after('materai_file_path');
            $table->timestamp('konsumen_signing_link_expires_at')->nullable()->after('konsumen_signing_link_hash');

            // === TTD konsumen (setelah konsumen sign di public page) ===
            $table->timestamp('konsumen_signed_at')->nullable()->after('konsumen_signing_link_expires_at');
            $table->string('konsumen_ttd_path')->nullable()->after('konsumen_signed_at');

            // === Finalization ===
            $table->timestamp('spr_finalized_at')->nullable()->after('konsumen_ttd_path');
        });
    }

    public function down(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->dropForeign(['materai_by_user_id']);
            $table->dropColumn([
                'materai_stamped_at',
                'materai_by_user_id',
                'materai_file_path',
                'konsumen_signing_link_hash',
                'konsumen_signing_link_expires_at',
                'konsumen_signed_at',
                'konsumen_ttd_path',
                'spr_finalized_at',
            ]);
        });
    }
};
