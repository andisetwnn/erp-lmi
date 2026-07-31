<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtual_account', function (Blueprint $table) {
            $table->boolean('is_aktif')->default(true)->after('nomor_va');
            $table->unique(['rumah_id', 'bank_id'], 'va_rumah_bank_unique');
        });
    }

    public function down(): void
    {
        Schema::table('virtual_account', function (Blueprint $table) {
            $table->dropUnique('va_rumah_bank_unique');
            $table->dropColumn('is_aktif');
        });
    }
};
