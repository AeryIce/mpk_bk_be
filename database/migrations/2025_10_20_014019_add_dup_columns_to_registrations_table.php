<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            // grup duplikat
            $table->uuid('dup_group_id')->nullable()->index()->after('meta');
            $table->integer('dup_score')->default(0)->after('dup_group_id');
            $table->text('dup_reason')->nullable()->after('dup_score');
            $table->boolean('is_primary')->default(false)->after('dup_reason');

            // status pipeline sederhana (pakai text + check constraint di Postgres)
            $table->string('status', 32)->default('new')->after('is_primary');
        });

        // Postgres CHECK constraint untuk status
        DB::statement("
            ALTER TABLE registrations
            ADD CONSTRAINT registrations_status_check
            CHECK (status IN (
                'new',
                'possible_duplicate',
                'duplicate',
                'contacted',
                'confirmed',
                'shipped'
            ));
        ");

        // Inisialisasi data lama:
        // - setiap baris lama jadi grup tunggal (dup_group_id=gen_random_uuid)
        // - tandai is_primary=true
        DB::statement("
            UPDATE registrations
            SET
              dup_group_id = COALESCE(dup_group_id, gen_random_uuid()),
              is_primary = COALESCE(is_primary, true),
              status = COALESCE(status, 'new')
        ");
    }

    public function down(): void
    {
        // hapus constraint dulu
        DB::statement("ALTER TABLE registrations DROP CONSTRAINT IF EXISTS registrations_status_check");

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn(['dup_group_id', 'dup_score', 'dup_reason', 'is_primary', 'status']);
        });
    }
};
