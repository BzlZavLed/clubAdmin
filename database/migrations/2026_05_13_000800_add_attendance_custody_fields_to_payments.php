<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'source_type')) {
                $table->string('source_type')->nullable()->after('settles_expense_id');
            }
            if (!Schema::hasColumn('payments', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            }
            if (!Schema::hasColumn('payments', 'source_line_id')) {
                $table->unsignedBigInteger('source_line_id')->nullable()->after('source_id');
            }
            if (!Schema::hasColumn('payments', 'custody_status')) {
                $table->string('custody_status', 40)->nullable()->after('source_line_id');
            }
            if (!Schema::hasColumn('payments', 'held_by_user_id')) {
                $table->foreignId('held_by_user_id')->nullable()->after('custody_status')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'remittance_batch_id')) {
                $table->string('remittance_batch_id', 64)->nullable()->after('held_by_user_id');
            }
            if (!Schema::hasColumn('payments', 'remittance_method')) {
                $table->string('remittance_method', 32)->nullable()->after('remittance_batch_id');
            }
            if (!Schema::hasColumn('payments', 'remittance_reference')) {
                $table->string('remittance_reference', 160)->nullable()->after('remittance_method');
            }
            if (!Schema::hasColumn('payments', 'remittance_notes')) {
                $table->text('remittance_notes')->nullable()->after('remittance_reference');
            }
            if (!Schema::hasColumn('payments', 'remitted_at')) {
                $table->timestamp('remitted_at')->nullable()->after('remittance_notes');
            }
            if (!Schema::hasColumn('payments', 'custody_validated_by_user_id')) {
                $table->foreignId('custody_validated_by_user_id')->nullable()->after('remitted_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'custody_validated_at')) {
                $table->timestamp('custody_validated_at')->nullable()->after('custody_validated_by_user_id');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['source_type', 'source_id'], 'payments_source_idx');
            $table->index(['custody_status', 'held_by_user_id'], 'payments_custody_holder_idx');
            $table->index(['club_id', 'remittance_batch_id'], 'payments_remit_batch_idx');
        });

        Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
            if (!Schema::hasColumn('rep_assistance_adv_merits', 'member_id')) {
                $table->foreignId('member_id')->nullable()->after('mem_adv_id')->constrained('members')->nullOnDelete();
            }
            if (!Schema::hasColumn('rep_assistance_adv_merits', 'payment_id')) {
                $table->foreignId('payment_id')->nullable()->after('member_id')->constrained('payments')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
            if (Schema::hasColumn('rep_assistance_adv_merits', 'payment_id')) {
                $table->dropConstrainedForeignId('payment_id');
            }
            if (Schema::hasColumn('rep_assistance_adv_merits', 'member_id')) {
                $table->dropConstrainedForeignId('member_id');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_source_idx');
            $table->dropIndex('payments_custody_holder_idx');
            $table->dropIndex('payments_remit_batch_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            foreach ([
                'custody_validated_at',
                'custody_validated_by_user_id',
                'remitted_at',
                'remittance_notes',
                'remittance_reference',
                'remittance_method',
                'remittance_batch_id',
                'held_by_user_id',
                'custody_status',
                'source_line_id',
                'source_id',
                'source_type',
            ] as $column) {
                if (!Schema::hasColumn('payments', $column)) {
                    continue;
                }

                if (in_array($column, ['held_by_user_id', 'custody_validated_by_user_id'], true)) {
                    $table->dropConstrainedForeignId($column);
                } else {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
