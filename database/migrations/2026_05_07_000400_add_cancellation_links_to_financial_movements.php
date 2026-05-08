<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns('payments');
        $this->addColumns('expenses');
        $this->addColumns('treasury_movements');

        $this->backfillLinks('payments', 'reversed_payment_id');
        $this->backfillLinks('expenses', 'reversed_expense_id');
    }

    public function down(): void
    {
        $this->dropColumns('treasury_movements');
        $this->dropColumns('expenses');
        $this->dropColumns('payments');
    }

    protected function addColumns(string $tableName): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        $needsIsCancelled = !Schema::hasColumn($tableName, 'is_cancelled');
        $needsRelated = !Schema::hasColumn($tableName, 'related_canceled_movement_id');
        $needsCanceling = !Schema::hasColumn($tableName, 'canceling_id');

        if (!$needsIsCancelled && !$needsRelated && !$needsCanceling) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($needsIsCancelled, $needsRelated, $needsCanceling) {
            if ($needsIsCancelled) {
                $table->boolean('is_cancelled')->default(false);
            }

            if ($needsRelated) {
                $table->unsignedBigInteger('related_canceled_movement_id')->nullable();
            }

            if ($needsCanceling) {
                $table->unsignedBigInteger('canceling_id')->nullable();
            }
        });
    }

    protected function dropColumns(string $tableName): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        $columns = collect([
            'is_cancelled',
            'related_canceled_movement_id',
            'canceling_id',
        ])->filter(fn (string $column) => Schema::hasColumn($tableName, $column))->values()->all();

        if (empty($columns)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    protected function backfillLinks(string $tableName, string $legacyColumn): void
    {
        if (!Schema::hasTable($tableName)
            || !Schema::hasColumn($tableName, $legacyColumn)
            || !Schema::hasColumn($tableName, 'is_cancelled')
            || !Schema::hasColumn($tableName, 'related_canceled_movement_id')
            || !Schema::hasColumn($tableName, 'canceling_id')) {
            return;
        }

        DB::table($tableName)
            ->whereNotNull($legacyColumn)
            ->orderBy('id')
            ->select(['id', $legacyColumn])
            ->chunkById(500, function ($rows) use ($tableName, $legacyColumn) {
                foreach ($rows as $row) {
                    $originalId = $row->{$legacyColumn};
                    if (!$originalId) {
                        continue;
                    }

                    DB::table($tableName)
                        ->where('id', $row->id)
                        ->update(['canceling_id' => $originalId]);

                    DB::table($tableName)
                        ->where('id', $originalId)
                        ->update([
                            'is_cancelled' => true,
                            'related_canceled_movement_id' => $row->id,
                        ]);
                }
            });
    }
};
