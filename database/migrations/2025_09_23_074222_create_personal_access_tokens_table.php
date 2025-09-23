<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Polymorphic owner (User model atau model lain bila perlu)
            $table->morphs('tokenable'); // tokenable_type, tokenable_id

            $table->string('name');

            // Sanctum menyimpan token dalam bentuk hash (64 chars)
            $table->string('token', 64)->unique();

            // Bisa diisi ["*"] atau daftar ability; null = tanpa batas
            $table->text('abilities')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Index tambahan (opsional tapi bagus untuk query by owner)
            $table->index(['tokenable_type', 'tokenable_id'], 'pat_tokenable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
