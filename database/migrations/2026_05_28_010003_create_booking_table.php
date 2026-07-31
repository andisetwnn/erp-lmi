<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('sales_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->foreignId('rumah_id')->constrained('rumah')->cascadeOnDelete();
            // Booking WAJIB pilih dari prospect yg sudah FINISH (BI checking lengkap)
            $table->foreignId('prospect_customer_id')->constrained('prospect_customer')->cascadeOnDelete();

            $table->date('tanggal_booking');
            // Deadline expired (hari kerja). +1 hari kerja sejak booking (stage 1),
            // diperpanjang jadi +2 hari kerja saat SPR diinput.
            $table->date('tanggal_expired')->nullable();
            // Tanggal unit dilepas balik ke available (saat batal / expired).
            // Dipakai untuk hitung cooldown re-booking 2 hari kerja.
            $table->date('unit_dilepas_at')->nullable();
            // aktif  → baru booking, belum SPR (atau sudah SPR tapi belum expired)
            // sukses → SPR sudah terbit, belum akad
            // akad   → akad selesai
            // batal  → dibatalkan manual
            $table->enum('status', ['aktif', 'sukses', 'batal', 'akad'])->default('aktif');
            $table->text('keterangan_batal')->nullable();

            $table->timestamps();

            $table->index(['sales_id', 'status']);
            $table->index('rumah_id');
            $table->index('prospect_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking');
    }
};
