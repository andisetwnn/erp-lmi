<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('prospect_customer', function (Blueprint $table) {
            $table->boolean('tempat_kerja_menyusul')->default(false)->after('tempat_kerja_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospect_customer', function (Blueprint $table) {
            $table->dropColumn('tempat_kerja_menyusul');
        });
    }
};
