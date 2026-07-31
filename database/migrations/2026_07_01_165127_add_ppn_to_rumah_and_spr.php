<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PPN per unit (nominal Rp). Default 0 — mostly subsidi.
        Schema::table('rumah', function (Blueprint $table) {
            $table->decimal('ppn', 15, 2)->default(0)->after('discount');
        });

        // Snapshot PPN saat SPR dibuat.
        Schema::table('spr', function (Blueprint $table) {
            $table->decimal('ppn', 15, 2)->default(0)->after('biaya_tambahan');
        });
    }

    public function down(): void
    {
        Schema::table('rumah', function (Blueprint $table) {
            $table->dropColumn('ppn');
        });

        Schema::table('spr', function (Blueprint $table) {
            $table->dropColumn('ppn');
        });
    }
};
