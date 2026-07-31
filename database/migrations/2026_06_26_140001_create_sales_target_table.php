<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_target', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('sales_id')->constrained('sales')->cascadeOnDelete();
            // Periode pakai format YYYY-MM (mis. "2026-06") supaya gampang group/filter
            $table->string('periode', 7)->index();
            $table->unsignedInteger('target_prospect')->default(0);
            $table->unsignedInteger('target_booking')->default(0);
            // Sales pimpinan yang men-set target (untuk audit)
            $table->foreignId('set_by_sales_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sales_id', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_target');
    }
};
