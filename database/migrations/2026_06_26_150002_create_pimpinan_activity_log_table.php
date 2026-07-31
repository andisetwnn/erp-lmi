<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pimpinan_activity_log', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('pimpinan_sales_id')->constrained('sales')->cascadeOnDelete();
            // Tipe aksi: set_target, reassign_prospect, dismiss_notif, lainnya
            $table->string('action', 50)->index();
            // Subject yg ditarget aksi (mis. nama anggota, customer)
            $table->string('subject')->nullable();
            // Detail metadata (JSON)
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['pimpinan_sales_id', 'created_at'], 'pal_pimpinan_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pimpinan_activity_log');
    }
};
