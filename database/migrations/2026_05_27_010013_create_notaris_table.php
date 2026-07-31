<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notaris', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('nama');
            $table->string('nik', 32);
            $table->foreignId('bank_id')->nullable()->constrained('bank')->nullOnDelete();
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('hp', 30)->nullable();
            $table->string('alamat', 500)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notaris');
    }
};
