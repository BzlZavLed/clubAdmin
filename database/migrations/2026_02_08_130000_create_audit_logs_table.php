<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action', 64)->index();
            $table->string('entity_type', 128)->nullable()->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('entity_label')->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_class')->nullable();
            $table->string('route')->nullable();
            $table->string('method', 16)->nullable();
            $table->text('url')->nullable();
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
