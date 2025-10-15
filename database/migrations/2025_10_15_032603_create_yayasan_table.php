<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('yayasan', function (Blueprint $table) {
            $table->string('id')->primary(); // dari Excel
            $table->string('name');
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('yayasan');
    }
};