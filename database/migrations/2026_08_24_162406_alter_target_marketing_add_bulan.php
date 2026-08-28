<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Breakdown target marketing per BULAN (1-12) supaya bisa track vs realisasi bulanan.
 * Unique lama (proyek_id, tahun) → jadi (proyek_id, tahun, bulan).
 */
return new class extends Migration
{
    public function up(): void
    {
        // FK proyek_id pakai index unique lama → drop FK dulu supaya index bisa didrop.
        Schema::table('target_marketing', function (Blueprint $table) {
            $table->dropForeign(['proyek_id']);
            $table->dropUnique(['proyek_id', 'tahun']);
            $table->unsignedTinyInteger('bulan')->default(1)->after('tahun');
            $table->unique(['proyek_id', 'tahun', 'bulan']);
            $table->foreign('proyek_id')->references('id')->on('proyek')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('target_marketing', function (Blueprint $table) {
            $table->dropForeign(['proyek_id']);
            $table->dropUnique(['proyek_id', 'tahun', 'bulan']);
            $table->dropColumn('bulan');
            $table->unique(['proyek_id', 'tahun']);
            $table->foreign('proyek_id')->references('id')->on('proyek')->cascadeOnDelete();
        });
    }
};
