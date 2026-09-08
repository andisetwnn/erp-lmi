<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subcont di data rumah: dari teks bebas jadi tautan ke master.
 *
 * Selama masih teks, "WIN", "Win", dan "PT WIN" jadi tiga pemborong berbeda dan
 * laporan per subcon tidak mungkin dipercaya. Nilai yang sudah terlanjur diketik
 * dipindahkan ke master dulu, baru kolom lamanya dibuang — supaya tidak ada yang hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rumah', function (Blueprint $table) {
            $table->foreignId('subcon_id')->nullable()->after('lot')
                ->constrained('subcon')->nullOnDelete();
        });

        if (! Schema::hasColumn('rumah', 'subcont')) {
            return;
        }

        // Tiap nama yang sudah diketik dibuatkan entri master, lalu unitnya ditautkan.
        $nama = DB::table('rumah')
            ->whereNotNull('subcont')
            ->where('subcont', '!=', '')
            ->distinct()
            ->pluck('subcont');

        foreach ($nama as $n) {
            $id = DB::table('subcon')->where('nama', $n)->value('id')
                ?? DB::table('subcon')->insertGetId([
                    'nama' => $n,
                    'is_aktif' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('rumah')->where('subcont', $n)->update(['subcon_id' => $id]);
        }

        Schema::table('rumah', function (Blueprint $table) {
            $table->dropColumn('subcont');
        });
    }

    public function down(): void
    {
        Schema::table('rumah', function (Blueprint $table) {
            $table->string('subcont', 60)->nullable()->after('lot');
        });

        DB::table('rumah')
            ->join('subcon', 'subcon.id', 'rumah.subcon_id')
            ->update(['rumah.subcont' => DB::raw('subcon.nama')]);

        Schema::table('rumah', function (Blueprint $table) {
            $table->dropForeign(['subcon_id']);
            $table->dropColumn('subcon_id');
        });
    }
};
