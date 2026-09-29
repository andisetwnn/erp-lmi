<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master bank KPR — terpisah dari master `bank`.
 *
 * Master `bank` berisi penerbit rekening (BCA, Mandiri, BNI, …) dan dipakai
 * rekening konsumen, sales, notaris, serta virtual account.
 *
 * Yang di sini lain: cabang tempat berkas KPR diurus — "BTN KC Cibinong" dan
 * "BTN Syariah" dua-duanya BTN tapi prosesnya berbeda, dan biaya proses
 * akadnya pun berbeda. Master bank umum tidak bisa menampung perbedaan itu.
 *
 * Sebelumnya daftar ini berupa konstanta di dalam kode, sehingga menambah bank
 * berarti mengubah kode dan deploy ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_kpr', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();

            // Kode dipertahankan karena sudah dipakai spr_pemberkasan.bank_kode
            // dan berkas Matrix dari Admin KPR.
            $table->string('kode', 10)->unique();
            $table->string('nama');

            // Biaya proses akad per unit, berbeda tiap bank.
            //
            // Boleh kosong dan sengaja TIDAK berdefault nol: nol berarti
            // "gratis", sedangkan kosong berarti "tarifnya belum diketahui".
            // Dua hal itu tidak boleh terbaca sama di lembar Aju Dana.
            $table->decimal('biaya_proses_akad', 15, 2)->nullable();

            $table->boolean('is_aktif')->default(true);
            $table->string('keterangan')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_aktif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_kpr');
    }
};
