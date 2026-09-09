<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isi berkas Matrix, satu baris per unit.
 *
 * Semua sheet (Mikro, Makro A, Makro B, Non Lot, Akad) ditampung di satu tabel dan
 * dibedakan lewat `kategori`. Susunan kolomnya memang hampir sama; yang membedakan
 * hanya tingkat progres bangunan, dan Non Lot yang tidak punya subcon maupun
 * kolom teknik tapi punya BBA.
 *
 * Kolom disimpan apa adanya mengikuti berkas — belum dikaitkan ke tabel rumah
 * atau SPR — karena Matrix masih disusun manual dan penamaannya belum tentu sama
 * persis dengan data di sistem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matrix_unit', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('matrix_import_id')->constrained('matrix_import')->cascadeOnDelete();
            $table->enum('kategori', ['mikro', 'makro_a', 'makro_b', 'non_lot', 'akad']);
            $table->string('seksi', 60)->default('');
            $table->unsignedInteger('urutan')->default(0);

            // ─── Identitas unit & konsumen ───
            $table->string('nama');
            $table->string('blok', 20)->nullable();
            $table->string('nomor_unit', 20)->nullable();
            $table->string('sales', 60)->nullable();
            $table->date('tanggal')->nullable();
            $table->unsignedSmallInteger('luas_bangunan')->nullable();
            $table->unsignedSmallInteger('luas_tanah')->nullable();
            $table->string('tipe', 60)->nullable();
            $table->string('lot', 20)->nullable();
            $table->string('subcon', 40)->nullable();
            $table->decimal('progres', 6, 3)->nullable();

            // ─── Nilai (DECIMAL sesuai aturan kolom uang) ───
            $table->decimal('ajb_notaris', 15, 2)->default(0);
            $table->decimal('total_harga_jual', 15, 2)->default(0);
            $table->decimal('kpr', 15, 2)->default(0);
            $table->decimal('bba', 15, 2)->default(0);
            $table->decimal('sbum', 15, 2)->default(0);
            $table->decimal('total_um', 15, 2)->default(0);
            $table->date('tgl_bayar_terakhir')->nullable();
            $table->decimal('akumulasi_um', 15, 2)->default(0);
            $table->decimal('persen_um', 7, 4)->nullable();
            $table->decimal('sisa_um', 15, 2)->default(0);

            // ─── Tahapan admin KPR ───
            $table->date('bm')->nullable();
            $table->date('wcr')->nullable();
            $table->date('sp3k')->nullable();
            $table->date('exp_sp3k')->nullable();
            $table->date('lpa')->nullable();
            $table->date('rencana_akad')->nullable();

            // ─── Kode bank, legal, teknik ───
            $table->string('bank_ko', 30)->nullable();
            $table->string('bank_fl', 30)->nullable();
            $table->string('bank_ta', 30)->nullable();
            $table->string('legal_imb', 40)->nullable();
            $table->string('legal_slf', 40)->nullable();
            $table->string('legal_hgb', 40)->nullable();
            $table->string('teknik_sa', 40)->nullable();
            $table->string('teknik_ja', 40)->nullable();
            $table->string('teknik_kw', 40)->nullable();
            $table->string('teknik_sb', 40)->nullable();

            $table->string('sikumbang', 40)->nullable();
            $table->string('ktp', 40)->nullable();
            $table->text('keterangan')->nullable();

            $table->timestamps();

            $table->index(['matrix_import_id', 'kategori', 'seksi'], 'matrix_unit_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matrix_unit');
    }
};
