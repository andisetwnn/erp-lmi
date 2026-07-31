<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sales — TTD tersimpan sekali, dipakai berulang saat submit SPR
        Schema::table('sales', function (Blueprint $table) {
            $table->string('tanda_tangan_path')->nullable()->after('nama_pemilik_rekening');
        });

        // Users web (Finance / PM / Direktur / dll)
        Schema::table('users', function (Blueprint $table) {
            $table->string('tanda_tangan_path')->nullable()->after('email');
        });

        // SPR — snapshot 3 TTD + jejak approval PM
        Schema::table('spr', function (Blueprint $table) {
            $table->string('ttd_sales_path')->nullable()->after('utj_bukti_path');
            $table->string('ttd_finance_path')->nullable()->after('ttd_sales_path');
            $table->string('ttd_pm_path')->nullable()->after('ttd_finance_path');

            $table->timestamp('pm_approved_at')->nullable()->after('approved_at');
            $table->foreignId('pm_approved_by_user_id')->nullable()->after('approved_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->text('pm_catatan')->nullable()->after('pm_approved_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('spr', function (Blueprint $table) {
            $table->dropForeign(['pm_approved_by_user_id']);
            $table->dropColumn([
                'ttd_sales_path',
                'ttd_finance_path',
                'ttd_pm_path',
                'pm_approved_at',
                'pm_approved_by_user_id',
                'pm_catatan',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tanda_tangan_path');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('tanda_tangan_path');
        });
    }
};
