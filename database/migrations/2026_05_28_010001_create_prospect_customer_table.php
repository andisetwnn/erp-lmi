<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_customer', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('sales_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();

            $table->string('nama_lengkap');
            $table->string('hp', 30);
            $table->string('hp_2', 30)->nullable();
            $table->string('sumber'); // "dari mana konsumen ini diperoleh" - free text
            $table->string('nik', 32)->nullable();
            $table->string('npwp', 32)->nullable();
            $table->string('foto_ktp')->nullable();

            // Pekerjaan — reuse master tempat_kerja
            $table->foreignId('tempat_kerja_id')->nullable()->constrained('tempat_kerja')->nullOnDelete();

            // Rekening customer (untuk pembayaran balik / refund / verifikasi)
            $table->foreignId('bank_id')->nullable()->constrained('bank')->nullOnDelete();
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('rekening_atas_nama')->nullable();

            // BI Checking — wajib diisi sebelum status bisa FINISH
            $table->enum('bi_kol', ['1', '2', '3', '4', '5'])->nullable();
            $table->decimal('bi_dbr', 5, 2)->nullable(); // persen 0-100
            $table->text('bi_keterangan')->nullable();

            // Alamat KTP
            $table->string('alamat', 500)->nullable();
            $table->string('provinsi_code', 10)->nullable()->index();
            $table->string('provinsi_nama')->nullable();
            $table->string('kota_code', 10)->nullable()->index();
            $table->string('kota_nama')->nullable();
            $table->string('kecamatan_code', 13)->nullable()->index();
            $table->string('kecamatan_nama')->nullable();
            $table->string('kelurahan_code', 16)->nullable()->index();
            $table->string('kelurahan_nama')->nullable();

            // cold   → baru lead
            // warm   → sudah ada interaksi, ada interest
            // hot    → siap booking, butuh tindak lanjut serius
            // finish → data lengkap (NIK + foto KTP + BI), siap di-bookingkan unit
            // archive → di-skip karena gak ada respon setelah follow-up berkali-kali (parking lot)
            $table->enum('status', ['cold', 'warm', 'hot', 'finish', 'archive'])->default('cold');
            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->index(['sales_id', 'status']);
            $table->index(['proyek_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_customer');
    }
};
