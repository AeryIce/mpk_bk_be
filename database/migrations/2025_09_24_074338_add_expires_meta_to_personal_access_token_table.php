<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('personal_access_tokens', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->index();
            }
            if (!Schema::hasColumn('personal_access_tokens', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->index();
            }
            if (!Schema::hasColumn('personal_access_tokens', 'user_agent')) {
                $table->string('user_agent', 255)->nullable();
            }
        });
    }

    public function down(): void {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
                $table->dropColumn('expires_at');
            }
            if (Schema::hasColumn('personal_access_tokens', 'ip_address')) {
                $table->dropColumn('ip_address');
            }
            if (Schema::hasColumn('personal_access_tokens', 'user_agent')) {
                $table->dropColumn('user_agent');
            }
        });
    }
};
