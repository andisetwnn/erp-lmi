<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->string('dokumen_signed_path')->nullable()->after('ttd_pm_path');
            $table->timestamp('dokumen_signed_at')->nullable()->after('dokumen_signed_path');
            $table->foreignId('dokumen_signed_by_user_id')->nullable()->after('dokumen_signed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dokumen_signed_by_user_id');
            $table->dropColumn(['dokumen_signed_path', 'dokumen_signed_at']);
        });
    }
};
