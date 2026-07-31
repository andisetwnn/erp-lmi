<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spr_termin_pembayaran', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('spr_id')->constrained('spr')->cascadeOnDelete();

            // jenis: BF (Booking Fee/UTJ), UM-1..UM-10, atau SBUM
            // urutan: angka 0 (BF), 1..10 (UM-1..UM-10) untuk sorting
            $table->enum('jenis', ['bf', 'um', 'sbum'])->default('um');
            $table->unsignedTinyInteger('urutan')->default(0);

            // Data realisasi pembayaran
            $table->string('nomor_kwitansi', 30)->nullable();
            $table->date('tanggal_realisasi')->nullable();
            $table->decimal('jumlah', 15, 2)->default(0);
            $table->text('keterangan')->nullable();

            // Audit
            $table->foreignId('input_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['spr_id', 'jenis', 'urutan'], 'spr_termin_order_idx');
            $table->unique(['spr_id', 'jenis', 'urutan'], 'spr_termin_unique_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spr_termin_pembayaran');
    }
};
