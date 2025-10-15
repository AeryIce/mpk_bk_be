<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sekolah', function (Blueprint $table) {
            $table->string('id')->primary();            // NPSN; fallback kalau kosong
            $table->string('yayasan_id');
            $table->foreign('yayasan_id')->references('id')->on('yayasan')->onDelete('cascade');
            $table->string('name');
            $table->string('jenjang')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kabupaten')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('npsn')->nullable()->unique();
            $table->timestamps();
            $table->index(['yayasan_id']);
            $table->index(['name']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('sekolah');
    }
};