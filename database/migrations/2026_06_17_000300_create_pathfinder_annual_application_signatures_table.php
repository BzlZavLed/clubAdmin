<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pathfinder_annual_application_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pathfinder_annual_application_id')
                ->constrained('pathfinder_annual_applications')
                ->cascadeOnDelete();
            $table->string('role', 40);
            $table->string('signer_name')->nullable();
            $table->string('signer_email')->nullable();
            $table->string('signature_type', 20)->nullable();
            $table->string('signature_text')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('request_token', 80)->nullable()->unique();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 40)->default('pending');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->unique(['pathfinder_annual_application_id', 'role'], 'paa_signature_application_role_unique');
            $table->index(['role', 'status']);
            $table->index(['signer_email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pathfinder_annual_application_signatures');
    }
};
