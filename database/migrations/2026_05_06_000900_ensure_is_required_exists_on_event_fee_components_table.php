<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_fee_components') || Schema::hasColumn('event_fee_components', 'is_required')) {
            return;
        }

        Schema::table('event_fee_components', function (Blueprint $table) {
            $table->boolean('is_required')->default(true);
        });
    }

    public function down(): void
    {
        //
    }
};
