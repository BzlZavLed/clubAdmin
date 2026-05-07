<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('members')) {
            return;
        }

        Schema::table('members', function (Blueprint $table) {
            if (!Schema::hasColumn('members', 'is_sda')) {
                $table->boolean('is_sda')->default(true)->after('status');
            }

            if (!Schema::hasColumn('members', 'baptism_date')) {
                $table->date('baptism_date')->nullable()->after('is_sda');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('members')) {
            return;
        }

        Schema::table('members', function (Blueprint $table) {
            if (Schema::hasColumn('members', 'baptism_date')) {
                $table->dropColumn('baptism_date');
            }

            if (Schema::hasColumn('members', 'is_sda')) {
                $table->dropColumn('is_sda');
            }
        });
    }
};
