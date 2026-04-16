<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('union_class_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('union_club_catalog_id')->constrained('union_club_catalogs')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['union_club_catalog_id', 'name'], 'union_class_catalogs_club_name_unique');
            $table->index(['union_club_catalog_id', 'sort_order'], 'union_class_catalogs_club_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('union_class_catalogs');
    }
};
