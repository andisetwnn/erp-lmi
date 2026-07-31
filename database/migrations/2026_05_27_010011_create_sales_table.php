<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('jenis_sales_id')->nullable()->constrained('jenis_sales')->nullOnDelete();
            $table->foreignId('sales_grup_id')->nullable()->constrained('sales_grup')->nullOnDelete();
            $table->string('kode', 30)->unique();
            $table->string('nama');
            $table->string('alamat', 500)->nullable();
            $table->string('telepon', 30)->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('bank')->nullOnDelete();
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('nama_pemilik_rekening')->nullable();
            $table->boolean('is_aktif')->default(true);
            $table->string('dbos_username', 100)->nullable()->unique();
            $table->string('dbos_password')->nullable();
            $table->rememberToken();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
