<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->string('instansi', 150);
            $table->string('pic', 120);
            $table->string('jabatan', 120)->nullable();
            $table->string('email', 190);
            $table->string('wa', 50);
            $table->string('alamat', 255);
            $table->string('kelurahan', 120)->nullable();
            $table->string('kecamatan', 120)->nullable();
            $table->string('kota', 120);
            $table->string('provinsi', 120);
            $table->string('kodepos', 20)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['kota', 'provinsi']);
            $table->index('email');
        });
    }

    public function down(): void {
        Schema::dropIfExists('registrations');
    }
};
