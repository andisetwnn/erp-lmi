<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya sesi rencana akad — nama & nominal bebas.
 *
 * Awalnya 5 kolom fixed di tabel rencana_akad (pengecekan sertifikat, koordinasi,
 * kursi, snack, kebersihan). Tapi kebutuhan lapangan lebih luas: kadang ada
 * air mineral, parkir, transport materai, dll. Dijadikan tabel supaya user
 * bisa nambah baris apa aja lewat form.
 *
 * Biaya per unit (By Proses Akad, BPHTB, PPh 4(2)) TIDAK disimpan di sini —
 * itu tetap per baris rencana_akad_unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rencana_akad_biaya_sesi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rencana_akad_id')->constrained('rencana_akad')->cascadeOnDelete();
            $table->string('nama', 120);
            $table->decimal('nominal', 15, 2)->default(0);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();

            $table->index('rencana_akad_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rencana_akad_biaya_sesi');
    }
};
