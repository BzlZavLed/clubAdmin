<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_member_evidence_access_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->text('last_used_user_agent')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'revoked_at']);
            $table->index(['club_id', 'expires_at']);
        });

        Schema::table('parent_carpeta_requirement_evidences', function (Blueprint $table) {
            if (!Schema::hasColumn('parent_carpeta_requirement_evidences', 'submitted_by_member_id')) {
                $table->unsignedBigInteger('submitted_by_member_id')
                    ->nullable()
                    ->after('submitted_by_user_id');
                $table->foreign('submitted_by_member_id', 'pcre_submitted_by_member_fk')
                    ->references('id')
                    ->on('members')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('parent_carpeta_requirement_evidences', 'submitted_via')) {
                $table->string('submitted_via')->default('parent')->after('submitted_by_member_id');
            }

            if (!Schema::hasColumn('parent_carpeta_requirement_evidences', 'access_code_id')) {
                $table->foreignId('access_code_id')
                    ->nullable()
                    ->after('submitted_via')
                    ->constrained('public_member_evidence_access_codes')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('parent_carpeta_requirement_evidences', 'submitted_ip')) {
                $table->string('submitted_ip', 45)->nullable()->after('access_code_id');
            }

            if (!Schema::hasColumn('parent_carpeta_requirement_evidences', 'submitted_user_agent')) {
                $table->text('submitted_user_agent')->nullable()->after('submitted_ip');
            }
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE parent_carpeta_requirement_evidences ALTER COLUMN submitted_by_user_id DROP NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE parent_carpeta_requirement_evidences MODIFY submitted_by_user_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        Schema::table('parent_carpeta_requirement_evidences', function (Blueprint $table) {
            if (Schema::hasColumn('parent_carpeta_requirement_evidences', 'access_code_id')) {
                $table->dropConstrainedForeignId('access_code_id');
            }
            if (Schema::hasColumn('parent_carpeta_requirement_evidences', 'submitted_by_member_id')) {
                $table->dropForeign('pcre_submitted_by_member_fk');
                $table->dropColumn('submitted_by_member_id');
            }
            if (Schema::hasColumn('parent_carpeta_requirement_evidences', 'submitted_via')) {
                $table->dropColumn('submitted_via');
            }
            if (Schema::hasColumn('parent_carpeta_requirement_evidences', 'submitted_ip')) {
                $table->dropColumn('submitted_ip');
            }
            if (Schema::hasColumn('parent_carpeta_requirement_evidences', 'submitted_user_agent')) {
                $table->dropColumn('submitted_user_agent');
            }
        });

        Schema::dropIfExists('public_member_evidence_access_codes');
    }
};
