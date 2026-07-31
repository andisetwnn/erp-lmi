<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tempat_kerja', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('nama')->unique();
            $table->string('alamat', 500)->nullable();
            $table->string('bidang_usaha')->nullable();
            $table->string('no_telepon', 30)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tempat_kerja');
    }
};
