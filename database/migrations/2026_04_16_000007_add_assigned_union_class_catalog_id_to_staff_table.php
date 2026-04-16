<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('staff', 'assigned_union_class_catalog_id')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->foreignId('assigned_union_class_catalog_id')
                    ->nullable()
                    ->after('assigned_class')
                    ->constrained('union_class_catalogs')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('staff', 'assigned_union_class_catalog_id')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assigned_union_class_catalog_id');
            });
        }
    }
};
