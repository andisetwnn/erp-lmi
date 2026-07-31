<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipe_rumah', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->string('tipe', 50);                  // "Arjuna 30/60"
            $table->string('nama_tipe');                 // "Arjuna"
            $table->enum('kategori', ['komersial', 'subsidi'])->default('komersial');

            // ===== Luas dasar (semua unit dengan tipe ini sama) =====
            $table->decimal('luas_tanah', 8, 2)->default(0);              // m² tanah dasar
            $table->decimal('luas_bangunan', 8, 2)->default(0);           // m² bangunan
            $table->decimal('harga_per_m2_kelebihan', 15, 2)->default(0); // harga/m² untuk kelebihan tanah hook

            // ===== Pricing standar (rumah inherit dari sini) =====
            $table->decimal('harga_jual', 15, 2)->default(0);             // harga jual base
            $table->decimal('harga_all_in', 15, 2)->default(0);           // total = jual + admin (- discount)
            $table->decimal('discount', 15, 2)->default(0);               // diskon program (kalau ada)
            $table->decimal('biaya_administrasi', 15, 2)->default(0);     // notaris, BPHTB, splitsing
            $table->decimal('dana_titipan', 15, 2)->default(0);
            $table->decimal('utj', 15, 2)->default(0);                    // BF/UTJ default

            // ===== Template KPR/Pembayaran (prefill SPR) =====
            $table->decimal('nilai_um', 15, 2)->default(0);               // UM/DP (sebelum SBUM)
            $table->decimal('sbum', 15, 2)->default(0);                   // Subsidi BUM (subsidi only)
            $table->unsignedSmallInteger('tenor_bulan')->default(240);    // tenor KPR
            $table->foreignId('bank_kpr_default_id')->nullable()          // bank KPR default
                ->constrained('bank')->nullOnDelete();

            // ===== Marketing & status =====
            $table->text('spesifikasi')->nullable();                      // KT, KM, garasi, dapur, dst
            $table->boolean('is_aktif')->default(true);

            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipe_rumah');
    }
};
