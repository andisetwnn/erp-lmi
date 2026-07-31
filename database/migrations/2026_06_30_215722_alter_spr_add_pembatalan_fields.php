<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah kolom pembatalan + refund
        Schema::table('spr', function (Blueprint $table) {
            $table->foreignId('alasan_pembatalan_id')->nullable()->after('alasan_reject')->constrained('alasan_pembatalan')->nullOnDelete();
            $table->text('cancel_keterangan')->nullable()->after('alasan_pembatalan_id');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_keterangan');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            // refund tracking — summary saja
            $table->enum('refund_status', ['pending', 'tidak_ada_refund', 'partial', 'full'])->nullable()->after('cancelled_by_user_id');
            $table->decimal('refund_amount', 15, 2)->default(0)->after('refund_status');
            $table->date('refund_at')->nullable()->after('refund_amount');
            $table->text('refund_keterangan')->nullable()->after('refund_at');

            $table->index('cancelled_at');
        });

        // 2. Extend status enum: tambah 'cancelled'
        // SQLite tidak benar-benar enforce enum, MySQL perlu ALTER COLUMN
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE spr MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'submitted'");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            // Revert ke enum lama (data 'cancelled' akan jadi invalid kalau ada)
            DB::statement("UPDATE spr SET status = 'rejected' WHERE status = 'cancelled'");
            DB::statement("ALTER TABLE spr MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected') NOT NULL DEFAULT 'submitted'");
        }

        Schema::table('spr', function (Blueprint $table) {
            $table->dropForeign(['alasan_pembatalan_id']);
            $table->dropForeign(['cancelled_by_user_id']);
            $table->dropColumn([
                'alasan_pembatalan_id',
                'cancel_keterangan',
                'cancelled_at',
                'cancelled_by_user_id',
                'refund_status',
                'refund_amount',
                'refund_at',
                'refund_keterangan',
            ]);
        });
    }
};
