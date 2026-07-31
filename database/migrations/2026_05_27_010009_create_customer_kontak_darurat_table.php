<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_kontak_darurat', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('customer_id')->constrained('customer')->cascadeOnDelete();
            $table->string('nama');
            $table->enum('hubungan', ['orang_tua', 'saudara', 'pasangan', 'anak', 'teman', 'lainnya']);
            $table->string('nomor_telepon', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_kontak_darurat');
    }
};
