<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master log 1 event Pindah Kavling (Switching).
 * Setiap kali user pindah unit / swap 2 SPR → 1 record di sini dengan nomor
 * PK/YYYY/MM/XXXX. Realisasi yg terpengaruh (moved & refund) link ke ID ini
 * lewat spr_realisasi_pembayaran.switching_id.
 *
 * Tipe:
 *   pindah — 1 SPR pindah ke unit baru. spr_lama_b/spr_baru_b NULL.
 *   swap   — 2 SPR silang unit. Kedua sisi terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spr_switching', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_switching', 30)->unique();
            $table->enum('tipe', ['pindah', 'swap']);
            $table->string('alasan');

            $table->foreignId('spr_lama_a_id')->constrained('spr')->cascadeOnDelete();
            $table->foreignId('spr_baru_a_id')->constrained('spr')->cascadeOnDelete();

            $table->foreignId('spr_lama_b_id')->nullable()->constrained('spr')->nullOnDelete();
            $table->foreignId('spr_baru_b_id')->nullable()->constrained('spr')->nullOnDelete();

            $table->decimal('selisih_a', 15, 2)->default(0);
            $table->decimal('selisih_b', 15, 2)->default(0);

            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('processed_at');
            $table->timestamps();
        });

        Schema::table('spr_realisasi_pembayaran', function (Blueprint $table) {
            $table->foreignId('switching_id')
                ->nullable()
                ->after('spr_id')
                ->constrained('spr_switching')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spr_realisasi_pembayaran', function (Blueprint $table) {
            $table->dropConstrainedForeignId('switching_id');
        });

        Schema::dropIfExists('spr_switching');
    }
};
