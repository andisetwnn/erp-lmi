<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospect_customer', function (Blueprint $table) {
            $table->decimal('penghasilan_bulanan', 15, 2)->nullable()->after('pekerjaan_ktp');
        });

        Schema::table('customer', function (Blueprint $table) {
            $table->decimal('penghasilan_bulanan', 15, 2)->nullable()->after('jenis_pekerjaan');
        });
    }

    public function down(): void
    {
        Schema::table('prospect_customer', function (Blueprint $table) {
            $table->dropColumn('penghasilan_bulanan');
        });

        Schema::table('customer', function (Blueprint $table) {
            $table->dropColumn('penghasilan_bulanan');
        });
    }
};
