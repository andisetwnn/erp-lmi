<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Realisasi pembayaran (kuitansi) — track pembayaran aktual customer.
     * Terpisah dari `spr_termin_pembayaran` (yang berperan sebagai jadwal/rencana).
     * 1 realisasi = 1 kuitansi = 1 transaksi fisik.
     * Customer bisa bayar berapa saja, kapan saja — sistem alokasi ke termin FIFO di view.
     */
    public function up(): void
    {
        Schema::create('spr_realisasi_pembayaran', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('spr_id')->constrained('spr')->cascadeOnDelete();
            // bf = UTJ / booking fee, um = uang muka, sbum = subsidi, kpr = pencairan KPR
            $table->enum('jenis', ['bf', 'um', 'sbum', 'kpr'])->default('um');
            $table->date('tanggal_bayar');
            $table->decimal('jumlah', 15, 2);
            $table->string('nomor_kwitansi', 20)->nullable()->unique();
            $table->string('metode', 20)->default('transfer'); // transfer / tunai
            $table->text('keterangan')->nullable();
            $table->foreignId('input_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['spr_id', 'jenis']);
            $table->index('tanggal_bayar');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spr_realisasi_pembayaran');
    }
};
