<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('magic_links', function (Blueprint $table) {
            // simpan hash token (64 hex), unik untuk memastikan 1:1
            $table->string('token_hash', 64)->nullable()->after('token');
            $table->unique('token_hash', 'magic_links_token_hash_unique');

            // plaintext token dijadikan nullable (fase transisi)
            $table->string('token', 255)->nullable()->change();

            // quality-of-life index untuk query umum
            $table->index(['email', 'purpose', 'used_at'], 'magic_links_email_purpose_used_idx');
            $table->index(['expires_at'], 'magic_links_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::table('magic_links', function (Blueprint $table) {
            // rollback dengan hati-hati
            $table->dropUnique('magic_links_token_hash_unique');
            $table->dropIndex('magic_links_email_purpose_used_idx');
            $table->dropIndex('magic_links_expires_idx');
            $table->dropColumn('token_hash');

            // kembalikan kolom token ke NOT NULL kalau di schema awal NOT NULL
            // (comment out jika awalnya memang nullable)
            $table->string('token', 255)->nullable(false)->change();
        });
    }
};
