<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dismissed_notif', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('sales_id')->constrained('sales')->cascadeOnDelete();
            // Notification key (mis. 'hot-stagnan', 'booking-expiring', 'stagnan-anggota', dll)
            $table->string('notif_key', 50)->index();
            // Sampai kapan di-dismiss (auto reappear setelah expired)
            $table->timestamp('dismissed_until')->nullable();
            $table->timestamps();

            $table->unique(['sales_id', 'notif_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dismissed_notif');
    }
};
