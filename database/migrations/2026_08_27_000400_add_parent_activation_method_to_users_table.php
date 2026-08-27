<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'parent_activation_method')) {
                $table->string('parent_activation_method', 16)->nullable()->after('email_verified_at');
                $table->index(['profile_type', 'parent_activation_method']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'parent_activation_method')) {
                $table->dropIndex(['profile_type', 'parent_activation_method']);
                $table->dropColumn('parent_activation_method');
            }
        });
    }
};
