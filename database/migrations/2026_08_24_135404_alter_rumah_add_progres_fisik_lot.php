<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field teknik untuk modul Admin Teknik:
 * - progres_fisik  : persentase pembangunan (0-100)
 * - lot            : nomor lot legal/sertifikat (sumber dari Excel SOP kolom D)
 * - progres_updated_at / _by : audit trail update terakhir
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rumah', function (Blueprint $table) {
            $table->unsignedTinyInteger('progres_fisik')->default(0)->after('ppn');
            $table->unsignedSmallInteger('lot')->nullable()->after('progres_fisik');
            $table->timestamp('progres_updated_at')->nullable()->after('lot');
            $table->foreignId('progres_updated_by_user_id')->nullable()
                ->after('progres_updated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rumah', function (Blueprint $table) {
            $table->dropForeign(['progres_updated_by_user_id']);
            $table->dropColumn(['progres_fisik', 'lot', 'progres_updated_at', 'progres_updated_by_user_id']);
        });
    }
};
