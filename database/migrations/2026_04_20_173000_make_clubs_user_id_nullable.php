<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('clubs') || !Schema::hasColumn('clubs', 'user_id')) {
            return;
        }

        Schema::table('clubs', function (Blueprint $table) {
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
                // ignore if the constraint name differs or was already removed
            }
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE clubs ALTER COLUMN user_id DROP NOT NULL');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE clubs MODIFY user_id BIGINT UNSIGNED NULL');
        }

        Schema::table('clubs', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('clubs') || !Schema::hasColumn('clubs', 'user_id')) {
            return;
        }

        Schema::table('clubs', function (Blueprint $table) {
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
                // ignore if missing
            }
        });

        DB::table('clubs')->whereNull('user_id')->delete();

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE clubs ALTER COLUMN user_id SET NOT NULL');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE clubs MODIFY user_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('clubs', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
