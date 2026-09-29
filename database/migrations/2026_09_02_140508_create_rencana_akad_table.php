<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rencana Akad — satu sesi akad berjamaah: satu notaris, satu bank, satu tanggal,
 * memuat banyak unit.
 *
 * Tanggal disimpan dua kali dengan sengaja. tanggal_rencana adalah usulan waktu
 * disusun; tanggal_fix adalah yang akhirnya disetujui bank. Kalau ditimpa jadi satu
 * kolom, jejak seberapa sering jadwal digeser bank hilang — padahal itu justru yang
 * berguna dievaluasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rencana_akad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->string('nomor', 60)->unique();

            $table->date('tanggal_rencana');
            $table->date('tanggal_fix')->nullable();

            $table->foreignId('bank_kpr_id')->nullable()->constrained('bank_kpr')->nullOnDelete();
            $table->foreignId('notaris_id')->nullable()->constrained('notaris')->nullOnDelete();

            // draft   : masih disusun, unit boleh keluar-masuk
            // diajukan: menunggu persetujuan PM/direktur
            // fix     : disetujui + tanggal terkunci (satu langkah)
            // batal   : dibatalkan, unitnya bebas dijadwalkan ulang
            $table->enum('status', ['draft', 'diajukan', 'fix', 'batal'])->default('draft');

            $table->timestamp('diajukan_at')->nullable();
            $table->foreignId('diajukan_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('disetujui_at')->nullable();
            $table->foreignId('disetujui_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('fix_at')->nullable();
            $table->foreignId('fix_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Biaya sesi akad — nama & nominal bebas, disimpan di tabel
            // rencana_akad_biaya_sesi. Yang per unit (by proses akad, PPh, BPHTB)
            // tetap dijumlahkan dari rencana_akad_unit.

            $table->text('alasan_tolak')->nullable();
            $table->text('catatan')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['proyek_id', 'status']);
            $table->index('tanggal_rencana');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rencana_akad');
    }
};
