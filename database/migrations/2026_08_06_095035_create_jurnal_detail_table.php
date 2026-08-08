<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurnal_detail', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('jurnal_id')->constrained('jurnal')->cascadeOnDelete();
            $table->foreignId('coa_id')->constrained('coa');

            // 1 line = debet atau kredit (salah satu > 0, yg lain 0)
            $table->decimal('debet', 15, 2)->default(0);
            $table->decimal('kredit', 15, 2)->default(0);

            // Polymorphic rekanan: customer / sales / notaris / user / null (kalau internal)
            $table->string('rekanan_type', 100)->nullable();
            $table->unsignedBigInteger('rekanan_id')->nullable();

            $table->timestamps();

            $table->index(['coa_id', 'jurnal_id']);
            $table->index(['rekanan_type', 'rekanan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurnal_detail');
    }
};
