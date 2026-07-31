<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coa', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('perusahaan_id')->constrained('perusahaan')->cascadeOnDelete();
            $table->string('kode', 30);
            $table->string('nama');
            $table->enum('tipe', ['aset', 'kewajiban', 'modal', 'pendapatan', 'beban']);
            $table->enum('saldo_normal', ['debit', 'kredit']);
            $table->foreignId('parent_id')->nullable()->constrained('coa')->nullOnDelete();
            $table->boolean('is_header')->default(false);
            $table->boolean('is_aktif')->default(true);
            $table->string('satuan_hpp', 30)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['perusahaan_id', 'kode']);
            $table->index(['perusahaan_id', 'tipe', 'kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coa');
    }
};
