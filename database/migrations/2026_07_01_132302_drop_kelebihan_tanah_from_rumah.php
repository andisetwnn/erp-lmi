<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rumah', function (Blueprint $table) {
            $table->dropColumn('kelebihan_tanah');
        });
    }

    public function down(): void
    {
        Schema::table('rumah', function (Blueprint $table) {
            $table->decimal('kelebihan_tanah', 8, 2)->default(0)->after('nomor_unit');
        });
    }
};
