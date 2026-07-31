<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notaris_biaya_ajb_history', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('notaris_id')->constrained('notaris')->cascadeOnDelete();
            $table->decimal('nominal_biaya_ajb', 15, 2);
            $table->decimal('pph_promo_ajb', 15, 2)->nullable();
            $table->date('berlaku_mulai');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['notaris_id', 'berlaku_mulai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notaris_biaya_ajb_history');
    }
};
