<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas pendukung jurnal — invoice, bukti transfer, kwitansi.
 *
 * Tabel terpisah (bukan kolom di jurnal) karena satu jurnal lazim punya beberapa
 * berkas sekaligus, dan berkas susulan sering menyusul lama setelah jurnal diposting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurnal_lampiran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jurnal_id')->constrained('jurnal')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_original_name');
            $table->unsignedBigInteger('ukuran')->default(0);
            $table->string('mime', 100)->nullable();
            $table->string('keterangan')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('jurnal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurnal_lampiran');
    }
};
