<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_customer_status_log', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('prospect_customer_id')->constrained('prospect_customer')->cascadeOnDelete();
            $table->string('status_dari', 20)->nullable(); // null = log pertama (saat create)
            $table->string('status_ke', 20);
            $table->text('catatan')->nullable();
            $table->foreignId('changed_by_sales_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['prospect_customer_id', 'created_at'], 'pcsl_prospect_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_customer_status_log');
    }
};
