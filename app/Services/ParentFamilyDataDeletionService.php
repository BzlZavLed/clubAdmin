<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ParentFamilyDataDeletionService
{
    /**
     * Permanently remove every child record owned by the parent.
     *
     * Financial ledger rows are retained for accounting integrity, but all
     * parent/member identity and free-form personal information is removed.
     */
    public function deleteChildrenFor(User $parent): int
    {
        $files = [];

        $deletedCount = DB::transaction(function () use ($parent, &$files): int {
            $legacyLinks = Schema::hasTable('parent_members')
                ? DB::table('parent_members')
                    ->join('clubs', 'clubs.id', '=', 'parent_members.club_id')
                    ->where('parent_members.user_id', $parent->id)
                    ->get(['parent_members.member_id', 'parent_members.club_id', 'clubs.club_type'])
                : collect();

            $legacyTargets = $legacyLinks->map(function ($link): ?array {
                if ($link->club_type === 'adventurers') {
                    return [
                        'club_id' => (int) $link->club_id,
                        'detail_id' => (int) $link->member_id,
                        'type' => 'adventurers',
                        'legacy_pathfinder_id' => null,
                    ];
                }

                if (! Schema::hasTable('members_pathfinders')) {
                    return null;
                }

                $detail = DB::table('members_pathfinders')
                    ->where('club_id', $link->club_id)
                    ->where('source_temp_member_pathfinder_id', $link->member_id)
                    ->first(['id', 'source_temp_member_pathfinder_id'])
                    ?? DB::table('members_pathfinders')
                        ->where('club_id', $link->club_id)
                        ->where('id', $link->member_id)
                        ->first(['id', 'source_temp_member_pathfinder_id']);

                return $detail ? [
                    'club_id' => (int) $link->club_id,
                    'detail_id' => (int) $detail->id,
                    'type' => 'pathfinders',
                    'legacy_pathfinder_id' => $detail->source_temp_member_pathfinder_id
                        ? (int) $detail->source_temp_member_pathfinder_id
                        : null,
                ] : null;
            })->filter()->values();

            $members = DB::table('members')
                ->where(function ($query) use ($parent, $legacyTargets): void {
                    $query->where('parent_id', $parent->id);

                    foreach ($legacyTargets as $target) {
                        $types = $target['type'] === 'adventurers'
                            ? ['adventurers']
                            : ['pathfinders', 'temp_pathfinder'];
                        $query->orWhere(function ($legacyQuery) use ($target, $types): void {
                            $legacyQuery->where('club_id', $target['club_id'])
                                ->where('id_data', $target['detail_id'])
                                ->whereIn('type', $types);
                        });
                    }
                })
                ->lockForUpdate()
                ->get(['id', 'type', 'id_data']);

            $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();
            $adventurerIds = $members->where('type', 'adventurers')->pluck('id_data')
                ->merge($legacyTargets->where('type', 'adventurers')->pluck('detail_id'))
                ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $pathfinderIds = $members->whereIn('type', ['pathfinders', 'temp_pathfinder'])->pluck('id_data')
                ->merge($legacyTargets->where('type', 'pathfinders')->pluck('detail_id'))
                ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $legacyPathfinderIds = $legacyTargets->pluck('legacy_pathfinder_id')
                ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

            if ($memberIds === [] && $adventurerIds === [] && $pathfinderIds === []) {
                return 0;
            }

            $this->collectPublicFiles($files, 'parent_carpeta_requirement_evidences', 'file_path', 'member_id', $memberIds);
            $this->collectStoredFiles(
                $files,
                'parent_payment_submissions',
                'receipt_image_path',
                'receipt_image_disk',
                'member_id',
                $memberIds,
            );
            $this->collectPublicFiles($files, 'members_adventurers', 'signature_path', 'id', $adventurerIds);
            $this->collectPublicFiles($files, 'members_pathfinders', 'signature_path', 'id', $pathfinderIds);

            if ($pathfinderIds !== [] && Schema::hasTable('member_pathfinder_insurance_cards')) {
                DB::table('member_pathfinder_insurance_cards')
                    ->whereIn('member_pathfinder_id', $pathfinderIds)
                    ->get(['disk', 'path'])
                    ->each(function ($file) use (&$files): void {
                        if ($file->path) {
                            $files[] = [$file->disk ?: 'public', $file->path];
                        }
                    });
            }

            // Receipts and payments belong to the club's accounting history.
            // Keep their monetary data while permanently detaching family PII.
            if (Schema::hasTable('payment_receipts')) {
                DB::table('payment_receipts')
                    ->where(function ($query) use ($memberIds, $parent): void {
                        $query->whereIn('member_id', $memberIds)
                            ->orWhere('parent_user_id', $parent->id);
                    })
                    ->update([
                        'member_id' => null,
                        'parent_user_id' => null,
                        'issued_to_email' => null,
                        'issued_to_type' => 'deleted_family',
                        'updated_at' => now(),
                    ]);
            }

            if (Schema::hasTable('payments')) {
                $paymentChanges = [
                    'member_id' => null,
                    'payer_name' => null,
                    'payer_email' => null,
                    'notes' => null,
                    'updated_at' => now(),
                ];

                DB::table('payments')->whereIn('member_id', $memberIds)->update($paymentChanges);

                if (Schema::hasColumn('payments', 'member_adventurer_id') && $adventurerIds !== []) {
                    DB::table('payments')->whereIn('member_adventurer_id', $adventurerIds)->update([
                        'member_adventurer_id' => null,
                        'payer_name' => null,
                        'payer_email' => null,
                        'notes' => null,
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('parent_child_link_requests')
                ->where('parent_user_id', $parent->id)
                ->orWhereIn('member_id', $memberIds)
                ->delete();

            DB::table('parent_members')
                ->where('user_id', $parent->id)
                ->delete();

            $this->purgeChildAuditHistory($memberIds, $adventurerIds, $pathfinderIds);

            // FK cascades remove evidence, submissions, assignments, location
            // history, tasks, notes, and other member-owned records.
            DB::table('members')->whereIn('id', $memberIds)->delete();

            if ($adventurerIds !== []) {
                DB::table('members_adventurers')->whereIn('id', $adventurerIds)->delete();
            }

            if ($pathfinderIds !== []) {
                DB::table('members_pathfinders')->whereIn('id', $pathfinderIds)->delete();
                if (Schema::hasTable('temp_member_pathfinder') && $legacyPathfinderIds !== []) {
                    DB::table('temp_member_pathfinder')->whereIn('id', $legacyPathfinderIds)->delete();
                }
            }

            return max(count($memberIds), count($adventurerIds) + count($pathfinderIds));
        });

        foreach (array_unique($files, SORT_REGULAR) as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        return $deletedCount;
    }

    public function deleteAccount(User $parent): void
    {
        DB::transaction(function () use ($parent): void {
            if (DB::table('members')->where('parent_id', $parent->id)->lockForUpdate()->exists()) {
                throw new \LogicException('Child records must be deleted first.');
            }

            if (Schema::hasTable('payment_receipts')) {
                DB::table('payment_receipts')->where('parent_user_id', $parent->id)->update([
                    'parent_user_id' => null,
                    'issued_to_email' => null,
                    'issued_to_type' => 'deleted_parent',
                    'updated_at' => now(),
                ]);

                // A user may have previously served as club staff before the
                // account became a parent account. Preserve the immutable
                // receipt while removing that historical user reference.
                DB::table('payment_receipts')->where('staff_user_id', $parent->id)->update([
                    'staff_user_id' => null,
                    'issued_to_email' => null,
                    'issued_to_type' => 'deleted_account',
                    'updated_at' => now(),
                ]);
            }

            DB::table('audit_logs')
                ->where('actor_id', $parent->id)
                ->orWhere(function ($query) use ($parent): void {
                    $query->where('entity_type', 'User')->where('entity_id', $parent->id);
                })
                ->delete();

            // Query-builder deletion intentionally avoids writing a new audit
            // snapshot containing the personal data the user just removed.
            DB::table('users')->where('id', $parent->id)->delete();

            // The logout event runs before this transaction and may create one
            // final row, so purge by actor once more after deleting the user.
            DB::table('audit_logs')
                ->where('actor_id', $parent->id)
                ->orWhere(function ($query) use ($parent): void {
                    $query->where('entity_type', 'User')->where('entity_id', $parent->id);
                })
                ->delete();
        });
    }

    private function collectPublicFiles(array &$files, string $table, string $pathColumn, string $keyColumn, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $pathColumn)) {
            return;
        }

        DB::table($table)->whereIn($keyColumn, $ids)->pluck($pathColumn)->filter()->each(
            function ($path) use (&$files): void {
                $files[] = ['public', $path];
            }
        );
    }

    private function collectStoredFiles(
        array &$files,
        string $table,
        string $pathColumn,
        string $diskColumn,
        string $keyColumn,
        array $ids,
    ): void {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $pathColumn)) {
            return;
        }

        $columns = [$pathColumn];
        $hasDiskColumn = Schema::hasColumn($table, $diskColumn);
        if ($hasDiskColumn) {
            $columns[] = $diskColumn;
        }

        DB::table($table)->whereIn($keyColumn, $ids)->get($columns)->each(
            function ($record) use (&$files, $pathColumn, $diskColumn, $hasDiskColumn): void {
                $path = $record->{$pathColumn};
                if (! $path) {
                    return;
                }

                $disk = $hasDiskColumn ? $record->{$diskColumn} : null;
                if (! in_array($disk, ['local', 'public'], true)) {
                    $disk = Storage::disk('local')->exists($path) ? 'local' : 'public';
                }

                $files[] = [$disk, $path];
            }
        );
    }

    private function purgeChildAuditHistory(array $memberIds, array $adventurerIds, array $pathfinderIds): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->where(function ($query) use ($memberIds, $adventurerIds, $pathfinderIds): void {
            $query->where(function ($memberQuery) use ($memberIds): void {
                $memberQuery->where('entity_type', 'Member')->whereIn('entity_id', $memberIds);
            });

            if ($adventurerIds !== []) {
                $query->orWhere(function ($detailQuery) use ($adventurerIds): void {
                    $detailQuery->where('entity_type', 'MemberAdventurer')->whereIn('entity_id', $adventurerIds);
                });
            }

            if ($pathfinderIds !== []) {
                $query->orWhere(function ($detailQuery) use ($pathfinderIds): void {
                    $detailQuery->whereIn('entity_type', ['MemberPathfinder', 'MemberPathfinderInsuranceCard'])
                        ->whereIn('entity_id', $pathfinderIds);
                });
            }
        })->delete();
    }
}
