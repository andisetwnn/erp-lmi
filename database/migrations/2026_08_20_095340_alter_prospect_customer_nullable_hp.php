<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HP customer historis kadang tidak tercatat lengkap.
 * Field `hp` di prospect_customer & customer dibuat nullable supaya import histori lolos.
 * Form input baru tetap validasi HP di app level (bukan DB level).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospect_customer', function (Blueprint $table) {
            $table->string('hp', 30)->nullable()->change();
        });
        Schema::table('customer', function (Blueprint $table) {
            $table->string('hp', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Isi placeholder dulu supaya bisa non-nullable
        DB::table('prospect_customer')->whereNull('hp')->update(['hp' => '-']);
        DB::table('customer')->whereNull('hp')->update(['hp' => '-']);
        Schema::table('prospect_customer', function (Blueprint $table) {
            $table->string('hp', 30)->nullable(false)->change();
        });
        Schema::table('customer', function (Blueprint $table) {
            $table->string('hp', 30)->nullable(false)->change();
        });
    }
};
