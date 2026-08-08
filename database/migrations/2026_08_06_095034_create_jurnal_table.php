<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurnal', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('perusahaan_id')->constrained('perusahaan')->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('no_bukti', 50);
            $table->enum('tipe', ['umum', 'bank', 'kas_kecil', 'penyesuaian'])->default('umum');
            $table->text('keterangan')->nullable();

            // Polymorphic sumber: auto-generated dari SPR/kwitansi/dll, atau manual (null)
            $table->string('sumber_type', 100)->nullable();
            $table->unsignedBigInteger('sumber_id')->nullable();

            // Status: draft bisa edit, posted immutable
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            // Reversal pointer: kalau ini jurnal reversal, tunjuk ke jurnal asal
            $table->foreignId('reversed_from_jurnal_id')->nullable()->constrained('jurnal')->nullOnDelete();

            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->unique(['perusahaan_id', 'no_bukti']);
            $table->index(['perusahaan_id', 'tanggal']);
            $table->index(['sumber_type', 'sumber_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurnal');
    }
};
