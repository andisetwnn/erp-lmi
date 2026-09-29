<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail untuk persetujuan Direksi via magic link.
 *
 * Kolom yang ada di disetujui_at/disetujui_by memang mencatat siapa yang menekan
 * "Setujui". Yang belum tercatat: alat/tempat aksesnya (IP + user agent) dan
 * kapan link-nya di-generate. Buat auditor keuangan, dua hal ini penting kalau
 * ada dispute "beliau nggak pernah menandatangani ini".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rencana_akad', function (Blueprint $table) {
            // Kapan link direksi terakhir dibuat (setiap regenerate, timestamp
            // diperbarui). Kalau kolom sudah null tapi disetujui_at terisi,
            // artinya persetujuan lewat login biasa, bukan link.
            $table->timestamp('direksi_link_generated_at')->nullable()->after('disetujui_by_user_id');

            // User Direksi tujuan link — diset saat generate, dicek saat direksi
            // klik "Setujui" (harus cocok dengan user yang membuka link).
            $table->foreignId('direksi_target_user_id')
                ->nullable()
                ->after('direksi_link_generated_at')
                ->constrained('users')
                ->nullOnDelete();

            // Jejak akses saat persetujuan berhasil.
            $table->string('direksi_setuju_ip', 45)->nullable()->after('direksi_target_user_id');
            $table->string('direksi_setuju_user_agent', 500)->nullable()->after('direksi_setuju_ip');
        });
    }

    public function down(): void
    {
        Schema::table('rencana_akad', function (Blueprint $table) {
            $table->dropConstrainedForeignId('direksi_target_user_id');
            $table->dropColumn([
                'direksi_link_generated_at',
                'direksi_setuju_ip',
                'direksi_setuju_user_agent',
            ]);
        });
    }
};
