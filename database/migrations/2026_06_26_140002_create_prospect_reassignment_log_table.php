<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_reassignment_log', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('prospect_customer_id')->constrained('prospect_customer')->cascadeOnDelete();
            $table->foreignId('from_sales_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('to_sales_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->text('alasan');
            $table->foreignId('reassigned_by_sales_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['prospect_customer_id', 'created_at'], 'pcrl_prospect_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_reassignment_log');
    }
};
