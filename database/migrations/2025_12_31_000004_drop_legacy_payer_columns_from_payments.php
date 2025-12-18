<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payments')) {
            return;
        }

        $driver = DB::getDriverName();

        // SQLite has trouble dropping columns with foreign keys; rebuild the table instead.
        if ($driver === 'sqlite') {
            Schema::disableForeignKeyConstraints();

            if (Schema::hasTable('payments_old')) {
                Schema::drop('payments_old');
            }

            Schema::rename('payments', 'payments_old');

            Schema::create('payments', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('club_id');
                $table->unsignedBigInteger('payment_concept_id');
                $table->unsignedBigInteger('member_id')->nullable();
                $table->unsignedBigInteger('staff_id')->nullable();

                $table->decimal('amount_paid', 10, 2);
                $table->decimal('expected_amount', 10, 2)->nullable();
                $table->decimal('balance_due_after', 10, 2)->nullable();

                // Avoid indexes in SQLite rebuild to prevent name collisions with payments_old indexes.
                $table->date('payment_date');
                $table->string('payment_type');
                $table->string('zelle_phone', 32)->nullable();
                $table->string('check_image_path', 512)->nullable();

                $table->unsignedBigInteger('received_by_user_id');
                $table->text('notes')->nullable();

                $table->timestamps();
                $table->softDeletes();
            });

            // Copy data over (only columns that exist in the new schema).
            $rows = DB::table('payments_old')->select([
                'id',
                'club_id',
                'payment_concept_id',
                'member_id',
                'staff_id',
                'amount_paid',
                'expected_amount',
                'balance_due_after',
                'payment_date',
                'payment_type',
                'zelle_phone',
                'check_image_path',
                'received_by_user_id',
                'notes',
                'created_at',
                'updated_at',
                'deleted_at',
            ])->get();

            foreach ($rows as $row) {
                DB::table('payments')->insert((array) $row);
            }

            Schema::drop('payments_old');
            Schema::enableForeignKeyConstraints();

            return;
        }

        // Postgres/MySQL: dropping the column will also drop dependent indexes/constraints.
        // Avoid dropForeign/dropIndex because names vary across environments.
        if (Schema::hasColumn('payments', 'member_adventurer_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('member_adventurer_id');
            });
        }

        if (Schema::hasColumn('payments', 'staff_adventurer_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('staff_adventurer_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'member_adventurer_id')) {
                $table->unsignedBigInteger('member_adventurer_id')->nullable();
            }
            if (!Schema::hasColumn('payments', 'staff_adventurer_id')) {
                $table->unsignedBigInteger('staff_adventurer_id')->nullable();
            }
        });
    }
};
