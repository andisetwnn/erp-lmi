<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log audit trail perubahan progres fisik rumah.
 * Auto-created oleh RumahObserver setiap kali kolom progres_fisik berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rumah_progres_log', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('rumah_id')->constrained('rumah')->cascadeOnDelete();
            $table->unsignedTinyInteger('progres_dari')->nullable();
            $table->unsignedTinyInteger('progres_ke');
            $table->text('catatan')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['rumah_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rumah_progres_log');
    }
};
