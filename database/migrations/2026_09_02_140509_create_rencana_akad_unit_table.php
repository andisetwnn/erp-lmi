<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unit yang dijadwalkan di satu Rencana Akad, sekaligus lembar penyelesaian akadnya.
 *
 * Angka yang bisa dihitung dari SPR — harga jual, diskon, PPN, harga net, plafon KPR,
 * UM seharusnya, setoran UM — sengaja TIDAK disalin ke sini. Menyalinnya berarti
 * angka akad bisa berbeda dari angka SPR tanpa ada yang tahu mana yang benar.
 *
 * Yang disimpan di sini hanya yang tidak ada di tempat lain: biaya yang muncul saat
 * akad, promo yang menutup kewajiban, dan tanggal peristiwanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rencana_akad_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rencana_akad_id')->constrained('rencana_akad')->cascadeOnDelete();
            $table->foreignId('spr_id')->constrained('spr')->cascadeOnDelete();
            $table->unsignedSmallInteger('urutan')->default(0);

            $table->enum('jenis_akad', ['ppjb', 'ajb'])->default('ppjb');
            $table->decimal('nominal_ppjb', 15, 2)->default(0);

            /** Nomor sertifikat HGB. Boleh kosong — aturan Juli 2024 membolehkan akad tanpa sertifikat. */
            $table->string('hgb', 60)->nullable();

            // batal: konsumen tidak hadir di hari H. Satu unit gugur, sisanya tetap jalan,
            // dan unit itu bebas dijadwalkan di rencana berikutnya.
            $table->enum('status', ['draft', 'fix', 'batal'])->default('draft');
            $table->text('alasan_batal')->nullable();
            $table->timestamp('dibatalkan_at')->nullable();
            $table->foreignId('dibatalkan_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /** Nilai akta jual beli — dasar hitung PPh 4(2) dan BPHTB, bukan biaya. */
            $table->decimal('nilai_ajb', 15, 2)->default(0);

            // Biaya & potongan saat akad. Dicatat apa adanya, bukan dihitung sistem —
            // BPHTB dan biaya notaris ditentukan di luar aplikasi ini.
            // bayar_ps4a2 = PPh Pasal 4 ayat 2. bayar_bi_notaris = biaya proses akad.
            $table->decimal('bp2bt', 15, 2)->default(0);
            $table->decimal('setoran_ajb', 15, 2)->default(0);
            $table->decimal('setoran_bphtb', 15, 2)->default(0);
            $table->decimal('promo_um', 15, 2)->default(0);
            $table->decimal('promo_ajb', 15, 2)->default(0);
            $table->decimal('promo_bphtb', 15, 2)->default(0);
            $table->decimal('bayar_ps4a2', 15, 2)->default(0);
            $table->decimal('bayar_bi_notaris', 15, 2)->default(0);
            $table->decimal('bayar_bphtb', 15, 2)->default(0);

            // Peristiwa akad yang benar-benar terjadi. PPJB dan AJB dua acara berbeda,
            // jaraknya bisa berbulan-bulan.
            $table->date('tanggal_ppjb')->nullable();
            $table->date('tanggal_ajb')->nullable();
            $table->string('ntpn', 40)->nullable();

            $table->text('catatan')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu SPR tidak boleh dua kali di rencana yang sama.
            $table->unique(['rencana_akad_id', 'spr_id']);
            $table->index('spr_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rencana_akad_unit');
    }
};
