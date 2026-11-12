<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa sudah membuka dan mengonfirmasi membaca panduan PDF per modul. Satu baris
 * per orang per panduan per versi: kalau panduan dicetak ulang (versi baru), status
 * orang itu kembali "belum" sampai ia membaca versi barunya. Pembacanya user ERP
 * atau sales DBOS, yang login lewat jalur terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panduan_baca', function (Blueprint $table) {
            $table->id();
            $table->morphs('pembaca');
            $table->string('kunci', 50);
            $table->string('versi', 20);
            $table->unsignedInteger('jumlah_dibuka')->default(0);
            $table->timestamp('dibuka_pertama_at')->nullable();
            $table->timestamp('dibuka_terakhir_at')->nullable();
            $table->timestamp('dibaca_at')->nullable();
            $table->timestamps();

            $table->unique(['pembaca_type', 'pembaca_id', 'kunci', 'versi']);
            $table->index(['kunci', 'versi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panduan_baca');
    }
};
