<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spr', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();

            // Relasi (denormalize sales_id, prospect_customer_id, rumah_id untuk query cepat)
            $table->foreignId('booking_id')->unique()->constrained('booking')->cascadeOnDelete();
            $table->foreignId('sales_id')->constrained('sales');
            $table->foreignId('prospect_customer_id')->constrained('prospect_customer');
            $table->foreignId('rumah_id')->constrained('rumah');

            $table->string('nomor_spr', 50)->unique(); // SPR/2026/06/0001
            $table->date('tanggal_spr');

            // ===== Step 2: Detail Harga =====
            $table->decimal('harga_jual', 15, 2)->default(0);          // harga dari tipe_rumah/rumah
            $table->decimal('biaya_administrasi', 15, 2)->default(0);  // biaya-biaya lain (notaris, splitsing, dll)
            $table->decimal('biaya_bahan_hook', 15, 2)->default(0);    // unit hook punya biaya tambahan
            $table->decimal('diskon', 15, 2)->default(0);              // potongan harga
            $table->decimal('kelebihan_tanah_m2', 8, 2)->default(0);   // luas kelebihan tanah (m²)
            $table->decimal('harga_per_m2', 15, 2)->default(0);        // harga per m² untuk kelebihan
            $table->decimal('total_harga', 15, 2)->default(0);         // computed: jual + admin + hook - diskon + (kelebihan × harga_m2)

            // ===== Step 3: Detail Angsuran =====
            $table->enum('jenis_pembayaran', ['cash', 'cash_bertahap', 'kpr'])->default('kpr');
            $table->foreignId('bank_kpr_id')->nullable()->constrained('bank')->nullOnDelete();
            $table->decimal('dp_persen', 5, 2)->default(0);            // % DP
            $table->decimal('dp_nominal', 15, 2)->default(0);          // UM total (sebelum SBUM)
            $table->decimal('sbum', 15, 2)->default(0);                // Subsidi BUM dari pemerintah (untuk unit subsidi)
            $table->date('tgl_cair_bum')->nullable();                  // tanggal SBUM cair dari bank
            $table->decimal('um_net', 15, 2)->default(0);              // = dp_nominal - sbum (yang ditagih ke customer)
            $table->unsignedSmallInteger('tenor_bulan')->default(0);   // 0 untuk cash
            $table->decimal('nilai_kpr', 15, 2)->default(0);           // total - dp
            $table->decimal('angsuran_per_bulan', 15, 2)->default(0);
            $table->text('catatan_angsuran')->nullable();

            // ===== Step 4: Bukti Pembayaran UTJ (input dari sales DBOS) =====
            $table->decimal('utj_nominal', 15, 2)->default(0);
            $table->date('utj_tanggal_bayar')->nullable();
            $table->enum('utj_metode', ['transfer', 'tunai'])->default('transfer');
            $table->string('utj_bukti_path')->nullable(); // file path di storage
            $table->text('utj_keterangan')->nullable();

            // ===== Konfirmasi UTJ oleh Finance (sistem pusat) =====
            // Sales submit nominal & tanggal di DBOS. Finance verify sesuai bukti mutasi
            // rekening: kalau ada selisih atau tanggal beda, Finance input yang aktual.
            $table->date('utj_tanggal_transaksi')->nullable(); // tanggal masuk ke rekening (verified)
            $table->decimal('utj_nominal_aktual', 15, 2)->nullable(); // nominal aktual yang masuk
            $table->foreignId('utj_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('utj_confirmed_at')->nullable();

            // Status & meta
            // draft     → sales masih edit (belum final)
            // submitted → sudah disubmit, nunggu approval Finance
            // approved  → di-approve Finance
            // rejected  → ditolak Finance
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('submitted');
            $table->text('catatan')->nullable();

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('alasan_reject')->nullable();

            $table->timestamps();

            $table->index(['sales_id', 'status']);
            $table->index(['rumah_id']);
            $table->index('tanggal_spr');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spr');
    }
};
