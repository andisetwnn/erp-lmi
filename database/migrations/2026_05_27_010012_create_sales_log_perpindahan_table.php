<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_log_perpindahan', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('sales_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('dari_grup_id')->nullable()->constrained('sales_grup')->nullOnDelete();
            $table->foreignId('ke_grup_id')->nullable()->constrained('sales_grup')->nullOnDelete();
            $table->string('dari_jenis', 20)->nullable();
            $table->string('ke_jenis', 20)->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('dipindah_oleh_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_log_perpindahan');
    }
};
