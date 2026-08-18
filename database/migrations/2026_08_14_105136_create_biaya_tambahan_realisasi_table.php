<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Realisasi pembayaran biaya tambahan unit rumah (kavling hook, view, dll).
 * Terpisah dari SPR — nominal di rumah.biaya_tambahan tidak lagi masuk ke SPR.
 * Multiple record per unit (bisa cicil). Ada support refund (is_refunded flag).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biaya_tambahan_realisasi', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('rumah_id')->constrained('rumah')->cascadeOnDelete();
            $table->foreignId('spr_id')->nullable()->constrained('spr')->nullOnDelete();
            $table->date('tanggal_bayar');
            $table->string('nomor_kuitansi', 50);
            $table->decimal('jumlah', 15, 2)->default(0);
            $table->enum('metode', ['cash', 'transfer'])->default('cash');
            $table->text('keterangan')->nullable();
            // Refund tracking — saat SPR dibatalkan
            $table->boolean('is_refunded')->default(false);
            $table->date('refunded_at')->nullable();
            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('refund_keterangan')->nullable();
            $table->foreignId('input_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['rumah_id', 'is_refunded']);
            $table->index('spr_id');
            $table->index('tanggal_bayar');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biaya_tambahan_realisasi');
    }
};
