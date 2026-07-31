<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rumah', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->foreignId('tipe_rumah_id')->constrained('tipe_rumah')->cascadeOnDelete();
            $table->string('blok', 20);
            $table->string('nomor_unit', 20);

            // Luas DASAR ada di tipe_rumah. Di sini hanya simpan SELISIH untuk unit hook.
            $table->decimal('kelebihan_tanah', 8, 2)->nullable();      // m² ekstra (hook only)

            // Biaya tambahan per-unit di luar harga standar tipe (mis. bahan bangunan hook,
            // upgrade fasilitas, dll). Total harga jual = tipe.harga_jual + biaya_tambahan
            //   + (kelebihan_tanah × tipe.harga_per_m2_kelebihan).
            $table->decimal('biaya_tambahan', 15, 2)->nullable();

            $table->enum('status', ['draft', 'available', 'booking', 'terjual'])->default('draft');
            $table->date('tanggal_launching')->nullable();

            // Bbox rectangle di siteplan (persen 0-100, legacy / fallback).
            $table->decimal('siteplan_x', 5, 2)->nullable();
            $table->decimal('siteplan_y', 5, 2)->nullable();
            $table->decimal('siteplan_w', 5, 2)->nullable();
            $table->decimal('siteplan_h', 5, 2)->nullable();
            // Polygon points di siteplan (akurat, hasil threshold + flood-fill atau manual draw).
            // Format JSON: [[x1,y1], [x2,y2], ...] dalam persen 0-100. Diutamakan di-render
            // kalau ada; kalau null, fallback ke siteplan_x/y/w/h rectangle di atas.
            $table->json('siteplan_points')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['proyek_id', 'blok', 'nomor_unit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rumah');
    }
};
