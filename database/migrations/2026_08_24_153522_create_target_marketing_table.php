<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Target penjualan & akad per proyek per tahun.
 * Diinput manual oleh Direktur/Super Admin sebagai RAB tahunan.
 * Ditampilkan sebagai baris "TARGET" di matrix Marketing Performance dashboard direksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('target_marketing', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->unsignedSmallInteger('tahun');
            $table->unsignedInteger('target_akad')->default(0);
            $table->unsignedInteger('target_penjualan')->default(0);
            $table->text('catatan')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['proyek_id', 'tahun']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_marketing');
    }
};
