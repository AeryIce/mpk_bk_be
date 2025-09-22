<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       
        Schema::create('magic_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email', 191)->index();
            $table->string('token', 64)->unique(); // token random/hashed
            $table->string('purpose', 32)->index(); // signup|reset (bisa ditambah)
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('used_at')->nullable()->index();
            $table->json('meta')->nullable(); // info tambahan: ip, ua, dsb
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('magic_links');
    }
};
