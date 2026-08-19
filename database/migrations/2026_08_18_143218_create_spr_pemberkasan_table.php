<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Pemberkasan — 1 record per SPR yg jenis pembayaran KPR + status approved/akad.
 * Track 5 tahap Admin KPR: BM → WCR → SP3K → LPA (khusus BTN) → Rencana Akad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spr_pemberkasan', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('spr_id')->unique()->constrained('spr')->cascadeOnDelete();
            $table->enum('bank_kode', ['CBN', 'BSN', 'NBU', 'BCA'])->nullable();

            // Tahap 1: Berkas Masuk (satu-satunya field yg punya upload file lengkap customer)
            $table->date('bm_tanggal')->nullable();
            $table->string('bm_file_path')->nullable();
            $table->string('bm_file_original_name')->nullable();

            // Tahap 2: Wawancara Credit (tanggal saja)
            $table->date('wcr_tanggal')->nullable();

            // Tahap 3: SP3K (KPR Disetujui Bank) — tanpa upload file
            $table->date('sp3k_tanggal')->nullable();
            $table->string('sp3k_nomor', 50)->nullable();
            $table->date('sp3k_expired')->nullable(); // auto SP3K + 90 hari, editable
            $table->decimal('sp3k_nominal', 15, 2)->default(0);

            // Tahap 4: LPA (khusus BTN — Laporan Penilaian Agunan) — tanggal saja
            $table->date('lpa_tanggal')->nullable();

            // Tahap 5: Rencana Akad (menu terpisah nanti)
            $table->date('rencana_akad_tanggal')->nullable();

            $table->text('catatan')->nullable();
            $table->foreignId('input_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('sp3k_expired');
            $table->index('rencana_akad_tanggal');
            $table->index('bank_kode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spr_pemberkasan');
    }
};
