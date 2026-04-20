<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('clubs') || !Schema::hasColumn('clubs', 'director_name')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE clubs ALTER COLUMN director_name DROP NOT NULL');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE clubs MODIFY director_name VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('clubs') || !Schema::hasColumn('clubs', 'director_name')) {
            return;
        }

        DB::table('clubs')->whereNull('director_name')->update(['director_name' => '']);

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE clubs ALTER COLUMN director_name SET NOT NULL');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE clubs MODIFY director_name VARCHAR(255) NOT NULL');
        }
    }
};
