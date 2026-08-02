<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field switching untuk fitur Pindah Kavling (PR 2).
 * - switched_from_spr_id: SPR ini berasal dari SPR mana (SPR baru → SPR lama)
 * - switched_to_spr_id: SPR ini pindah ke SPR mana (SPR lama voided → SPR baru)
 * Kedua field null kalau SPR normal (bukan hasil switching).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->foreignId('switched_from_spr_id')
                ->nullable()
                ->after('alasan_pembatalan_id')
                ->constrained('spr')
                ->nullOnDelete();

            $table->foreignId('switched_to_spr_id')
                ->nullable()
                ->after('switched_from_spr_id')
                ->constrained('spr')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->dropForeign(['switched_from_spr_id']);
            $table->dropForeign(['switched_to_spr_id']);
            $table->dropColumn(['switched_from_spr_id', 'switched_to_spr_id']);
        });
    }
};
