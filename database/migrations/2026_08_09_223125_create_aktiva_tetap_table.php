<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aktiva_tetap', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('perusahaan_id')->constrained('perusahaan')->cascadeOnDelete();
            $table->string('kode', 30)->nullable();
            $table->string('nama');
            $table->string('kategori', 50)->nullable(); // mis. Kendaraan, Inventaris, Bangunan, Tanah
            $table->date('tgl_perolehan');
            $table->decimal('harga_perolehan', 15, 2)->default(0);
            $table->integer('umur_ekonomis_bulan')->default(0); // 0 = tidak disusutkan (mis. tanah)
            $table->enum('metode_penyusutan', ['garis_lurus', 'saldo_menurun', 'tidak_disusutkan'])->default('garis_lurus');
            $table->decimal('nilai_residu', 15, 2)->default(0);
            $table->decimal('akumulasi_penyusutan', 15, 2)->default(0);
            $table->string('lokasi')->nullable();
            $table->text('keterangan')->nullable();
            $table->enum('status', ['aktif', 'dijual', 'dihapus', 'rusak'])->default('aktif');
            $table->date('tgl_status_akhir')->nullable();
            $table->foreignId('coa_aset_id')->nullable()->constrained('coa')->nullOnDelete();
            $table->foreignId('coa_akumulasi_id')->nullable()->constrained('coa')->nullOnDelete();
            $table->foreignId('coa_penyusutan_id')->nullable()->constrained('coa')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['perusahaan_id', 'kode']);
            $table->index(['perusahaan_id', 'kategori']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aktiva_tetap');
    }
};
