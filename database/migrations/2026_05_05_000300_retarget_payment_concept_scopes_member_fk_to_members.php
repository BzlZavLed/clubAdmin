<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_concept_scopes') || !Schema::hasColumn('payment_concept_scopes', 'member_id')) {
            return;
        }

        Schema::table('payment_concept_scopes', function (Blueprint $table) {
            try {
                $table->dropForeign(['member_id']);
            } catch (\Throwable $e) {
                // Ignore when the legacy FK does not exist on this connection.
            }
        });

        Schema::table('payment_concept_scopes', function (Blueprint $table) {
            $table->foreign('member_id')
                ->references('id')
                ->on('members')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payment_concept_scopes') || !Schema::hasColumn('payment_concept_scopes', 'member_id')) {
            return;
        }

        Schema::table('payment_concept_scopes', function (Blueprint $table) {
            try {
                $table->dropForeign(['member_id']);
            } catch (\Throwable $e) {
                // Ignore when the FK is already absent.
            }
        });

        Schema::table('payment_concept_scopes', function (Blueprint $table) {
            $table->foreign('member_id')
                ->references('id')
                ->on('members_adventurers')
                ->nullOnDelete();
        });
    }
};
