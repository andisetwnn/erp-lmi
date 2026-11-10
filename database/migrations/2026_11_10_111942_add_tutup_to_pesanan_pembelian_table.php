<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tutup PO: supplier tidak sanggup mengirim sisa barang. Jumlah tiap barang
 * dipangkas menjadi yang sudah datang dan nilai PO dihitung ulang; jumlah dan
 * nilai semula disimpan di kolom *_awal supaya jejaknya tidak hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesanan_pembelian', function (Blueprint $table) {
            $table->decimal('total_awal', 15, 2)->nullable()->after('total');
            $table->text('alasan_tutup')->nullable()->after('alasan_batal');
            $table->timestamp('ditutup_at')->nullable()->after('alasan_tutup');
            $table->foreignId('ditutup_by_user_id')->nullable()->after('ditutup_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('pesanan_pembelian_item', function (Blueprint $table) {
            $table->decimal('jumlah_awal', 12, 2)->nullable()->after('jumlah');
        });
    }

    public function down(): void
    {
        Schema::table('pesanan_pembelian_item', function (Blueprint $table) {
            $table->dropColumn('jumlah_awal');
        });

        Schema::table('pesanan_pembelian', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ditutup_by_user_id');
            $table->dropColumn(['total_awal', 'alasan_tutup', 'ditutup_at']);
        });
    }
};
