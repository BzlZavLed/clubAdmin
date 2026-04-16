<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('union_club_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('union_id')->constrained('unions')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['union_id', 'name'], 'union_club_catalogs_union_name_unique');
            $table->index(['union_id', 'sort_order'], 'union_club_catalogs_union_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('union_club_catalogs');
    }
};
