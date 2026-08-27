<?php

namespace App\Services;

use App\Models\Club;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;

class SuperAdminClubDataDeletionService
{
    public function clean(Club $club, User $actor): array
    {
        $files = [];
        $absoluteFiles = [];

        $summary = DB::transaction(function () use ($club, $actor, &$files, &$absoluteFiles): array {
            $lockedClub = DB::table('clubs')->where('id', $club->id)->lockForUpdate()->first();
            if (! $lockedClub) {
                throw new LogicException('The club no longer exists.');
            }

            $memberRows = DB::table('members')->where('club_id', $club->id)->get(['id', 'type', 'id_data', 'parent_id']);
            $staffRows = DB::table('staff')->where('club_id', $club->id)->get(['id', 'type', 'id_data', 'user_id']);
            $memberIds = $memberRows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $staffIds = $staffRows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $candidateUserIds = $this->candidateUserIds($club, $memberRows, $staffRows)
                ->reject(fn ($id) => (int) $id === (int) $actor->id)
                ->values();

            $this->collectClubFiles($club, $memberIds, $files, $absoluteFiles);

            $paymentCount = $this->countForClub('payments', $club->id);
            $expenseCount = $this->countForClub('expenses', $club->id);

            $this->deleteFinancialData($club);

            // Delete canonical rows first so their FK dependents (notes,
            // evidence, assignments, locations and task work) cascade.
            if ($memberIds !== []) {
                DB::table('members')->whereIn('id', $memberIds)->delete();
            }
            if ($staffIds !== []) {
                DB::table('staff')->whereIn('id', $staffIds)->delete();
            }

            // Remove every remaining table row directly scoped to this club.
            // This includes registrations, reports, forms, notes' parent
            // records, workplans, events, integrations and access links.
            foreach ($this->clubScopedTables() as $table) {
                DB::table($table)->where('club_id', $club->id)->delete();
            }

            // Club participation in another club's fundraiser is scoped by a
            // non-standard FK and must be removed explicitly.
            $this->deleteWhereEitherClub('fundraiser_partner_transfers', $club->id, 'from_club_id', 'to_club_id');
            if (Schema::hasTable('fundraiser_event_partners')) {
                DB::table('fundraiser_event_partners')->where('partner_club_id', $club->id)->delete();
            }

            DB::table('users')->where('club_id', $club->id)->update([
                'club_id' => null,
                'updated_at' => now(),
            ]);

            DB::table('clubs')->where('id', $club->id)->update([
                'user_id' => null,
                'director_name' => null,
                'logo_path' => null,
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

            $deletedUsers = 0;
            $preservedCrossClubUsers = 0;
            foreach ($candidateUserIds->unique() as $userId) {
                if ($this->hasRemainingOperationalRelation((int) $userId)) {
                    $this->assignParentDefaultClub((int) $userId);
                    $preservedCrossClubUsers++;
                    continue;
                }

                DB::table('audit_logs')->where('actor_id', $userId)
                    ->orWhere(function ($query) use ($userId): void {
                        $query->where('entity_type', 'User')->where('entity_id', $userId);
                    })->delete();
                $deletedUsers += DB::table('users')->where('id', $userId)->where('profile_type', '!=', 'superadmin')->delete();
            }

            $this->purgeClubAuditHistory($club);

            return [
                'members_deleted' => count($memberIds),
                'staff_deleted' => count($staffIds),
                'users_deleted' => $deletedUsers,
                'cross_club_users_preserved' => $preservedCrossClubUsers,
                'payments_deleted' => $paymentCount,
                'expenses_deleted' => $expenseCount,
            ];
        });

        foreach ($files as [$disk, $path]) {
            if ($path) {
                Storage::disk($disk ?: 'public')->delete($path);
            }
        }
        foreach (array_unique($absoluteFiles) as $path) {
            if (File::isFile($path)) {
                File::delete($path);
            }
        }

        return $summary;
    }

    public function deleteClub(Club $club, User $actor): void
    {
        DB::transaction(function () use ($club, $actor): void {
            DB::table('clubs')->where('id', $club->id)->lockForUpdate()->firstOrFail();

            foreach ($this->clubScopedTables() as $table) {
                if (DB::table($table)->where('club_id', $club->id)->exists()) {
                    throw new LogicException("Club cleanup is incomplete: {$table} still contains data.");
                }
            }

            if (Schema::hasTable('fundraiser_partner_transfers') && DB::table('fundraiser_partner_transfers')
                ->where('from_club_id', $club->id)->orWhere('to_club_id', $club->id)->exists()) {
                throw new LogicException('Club cleanup is incomplete: fundraiser transfers still exist.');
            }

            DB::table('audit_logs')->where(function ($query) use ($club): void {
                $query->where('entity_type', 'Club')->where('entity_id', $club->id);
            })->delete();

            DB::table('clubs')->where('id', $club->id)->delete();

            DB::table('audit_logs')->where('actor_id', $actor->id)
                ->where('route', 'superadmin.clubs.delete')
                ->where('created_at', '>=', now()->subMinute())
                ->delete();
        });
    }

    private function deleteFinancialData(Club $club): void
    {
        $clubId = (int) $club->id;

        $this->deleteForClub('payment_receipts', $clubId);
        $this->deleteForClub('parent_payment_submissions', $clubId);

        if (Schema::hasTable('payment_allocations')) {
            DB::table('payment_allocations')->whereIn(
                'payment_id',
                DB::table('payments')->select('id')->where('club_id', $clubId)
            )->delete();
        }

        if (Schema::hasTable('fundraiser_sale_items')) {
            DB::table('fundraiser_sale_items')->whereIn(
                'fundraiser_sale_id',
                DB::table('fundraiser_sales')->select('id')->where('club_id', $clubId)
            )->delete();
        }

        $this->deleteWhereEitherClub('fundraiser_partner_transfers', $clubId, 'from_club_id', 'to_club_id');
        if (Schema::hasTable('fundraiser_event_partners')) {
            DB::table('fundraiser_event_partners')
                ->where('partner_club_id', $clubId)
                ->orWhereIn('fundraiser_event_id', DB::table('fundraiser_events')->select('id')->where('club_id', $clubId))
                ->delete();
        }

        $this->deleteForClub('fundraiser_sales', $clubId);
        $this->deleteForClub('fundraiser_events', $clubId);
        $this->deleteForClub('payments', $clubId);
        $this->deleteForClub('expenses', $clubId);
        $this->deleteForClub('treasury_movements', $clubId);
        $this->deleteForClub('payment_concept_scopes', $clubId);
        $this->deleteForClub('payment_concepts', $clubId);
        $this->deleteForClub('accounts', $clubId);
    }

    private function clubScopedTables(): array
    {
        return collect(Schema::getTables())
            ->map(fn (array $table) => $table['name'])
            ->filter(fn (string $table) => ! in_array($table, ['clubs', 'users'], true))
            ->filter(fn (string $table) => Schema::hasColumn($table, 'club_id'))
            ->values()
            ->all();
    }

    private function candidateUserIds(Club $club, Collection $members, Collection $staff): Collection
    {
        return collect([$club->user_id])
            ->merge($members->pluck('parent_id'))
            ->merge($staff->pluck('user_id'))
            ->merge(DB::table('users')->where('club_id', $club->id)->pluck('id'))
            ->merge(DB::table('club_user')->where('club_id', $club->id)->pluck('user_id'))
            ->filter()
            ->map(fn ($id) => (int) $id);
    }

    private function hasRemainingOperationalRelation(int $userId): bool
    {
        foreach ([
            ['members', 'parent_id'],
            ['staff', 'user_id'],
            ['club_user', 'user_id'],
            ['clubs', 'user_id'],
            ['payment_receipts', 'parent_user_id'],
            ['payment_receipts', 'staff_user_id'],
            ['payments', 'received_by_user_id'],
        ] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)
                && DB::table($table)->where($column, $userId)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function assignParentDefaultClub(int $userId): void
    {
        $parent = DB::table('users')->where('id', $userId)->where('profile_type', 'parent')->first(['id']);
        if (! $parent) {
            return;
        }

        $nextClub = DB::table('members')
            ->join('clubs', 'clubs.id', '=', 'members.club_id')
            ->where('members.parent_id', $userId)
            ->where('members.status', '!=', 'deleted')
            ->where('clubs.status', 'active')
            ->orderBy('members.created_at')
            ->orderBy('clubs.id')
            ->first(['clubs.id', 'clubs.church_id', 'clubs.church_name']);

        if (! $nextClub) {
            return;
        }

        DB::table('users')->where('id', $userId)->update([
            'club_id' => $nextClub->id,
            'church_id' => $nextClub->church_id,
            'church_name' => $nextClub->church_name,
            'updated_at' => now(),
        ]);
    }

    private function collectClubFiles(Club $club, array $memberIds, array &$files, array &$absoluteFiles): void
    {
        if ($club->logo_path) {
            $files[] = ['public', $club->logo_path];
        }

        foreach ($this->clubScopedTables() as $table) {
            $columns = collect(Schema::getColumnListing($table))
                ->filter(fn (string $column) => str_contains($column, 'path'));
            foreach ($columns as $column) {
                DB::table($table)->where('club_id', $club->id)->pluck($column)->filter()->each(
                    function ($path) use (&$files): void {
                        $files[] = ['public', $path];
                    }
                );
            }
        }

        if ($memberIds !== [] && Schema::hasTable('parent_carpeta_requirement_evidences')) {
            DB::table('parent_carpeta_requirement_evidences')->whereIn('member_id', $memberIds)
                ->pluck('file_path')->filter()->each(function ($path) use (&$files): void {
                    $files[] = ['public', $path];
                });
        }

        $pathfinderIds = Schema::hasTable('members_pathfinders')
            ? DB::table('members_pathfinders')->where('club_id', $club->id)->pluck('id')
            : collect();
        if ($pathfinderIds->isNotEmpty() && Schema::hasTable('member_pathfinder_insurance_cards')) {
            DB::table('member_pathfinder_insurance_cards')->whereIn('member_pathfinder_id', $pathfinderIds)
                ->get(['disk', 'path'])->each(function ($file) use (&$files): void {
                    $files[] = [$file->disk ?: 'public', $file->path];
                });
        }

        $eventIds = Schema::hasTable('events') ? DB::table('events')->where('club_id', $club->id)->pluck('id') : collect();
        $this->collectRelatedFiles('event_documents', 'event_id', $eventIds, 'path', $files);
        $this->collectRelatedFiles('event_budget_items', 'event_id', $eventIds, 'receipt_path', $files);

        $fundraiserIds = Schema::hasTable('fundraiser_events')
            ? DB::table('fundraiser_events')->where('club_id', $club->id)->pluck('id')
            : collect();
        $this->collectRelatedFiles('fundraiser_investment_receipts', 'fundraiser_event_id', $fundraiserIds, 'path', $files);

        $pathfinderApplicationIds = Schema::hasTable('pathfinder_annual_applications')
            ? DB::table('pathfinder_annual_applications')->where('club_id', $club->id)->pluck('id')
            : collect();
        $this->collectRelatedFiles('pathfinder_annual_application_signatures', 'pathfinder_annual_application_id', $pathfinderApplicationIds, 'signature_path', $files);

        $adventurerApplicationIds = Schema::hasTable('adventurer_yearly_applications')
            ? DB::table('adventurer_yearly_applications')->where('club_id', $club->id)->pluck('id')
            : collect();
        $this->collectRelatedFiles('adventurer_yearly_application_signatures', 'adventurer_yearly_application_id', $adventurerApplicationIds, 'signature_path', $files);

        $monthlyReportIds = Schema::hasTable('pathfinder_monthly_reports')
            ? DB::table('pathfinder_monthly_reports')->where('club_id', $club->id)->pluck('id')
            : collect();
        $this->collectRelatedFiles('pathfinder_monthly_report_attachments', 'pathfinder_monthly_report_id', $monthlyReportIds, 'path', $files, 'disk');

        $taskIds = Schema::hasTable('workplan_tasks') ? DB::table('workplan_tasks')->where('club_id', $club->id)->pluck('id') : collect();
        $submissionIds = $taskIds->isNotEmpty() && Schema::hasTable('workplan_task_submissions')
            ? DB::table('workplan_task_submissions')->whereIn('workplan_task_id', $taskIds)->pluck('id')
            : collect();
        $this->collectRelatedFiles('workplan_task_submission_files', 'workplan_task_submission_id', $submissionIds, 'path', $files, 'disk');

        if (Schema::hasTable('finance_ledger_export_jobs')) {
            DB::table('finance_ledger_export_jobs')->where('club_id', $club->id)->pluck('files')->filter()->each(
                function ($payload) use (&$absoluteFiles): void {
                    $data = is_array($payload) ? $payload : json_decode((string) $payload, true);
                    foreach ($this->financeExportUrls($data ?: []) as $url) {
                        $urlPath = parse_url($url, PHP_URL_PATH);
                        if ($urlPath && str_starts_with($urlPath, '/generated/finance-ledgers/')) {
                            $absoluteFiles[] = public_path(ltrim($urlPath, '/'));
                        }
                    }
                }
            );
        }

        $files = collect($files)->unique(fn ($file) => implode('|', $file))->values()->all();
    }

    private function collectRelatedFiles(
        string $table,
        string $foreignKey,
        Collection $ids,
        string $pathColumn,
        array &$files,
        ?string $diskColumn = null,
    ): void {
        if ($ids->isEmpty() || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $pathColumn)) {
            return;
        }

        $columns = $diskColumn && Schema::hasColumn($table, $diskColumn)
            ? [$diskColumn, $pathColumn]
            : [$pathColumn];
        DB::table($table)->whereIn($foreignKey, $ids)->get($columns)->each(
            function ($file) use (&$files, $pathColumn, $diskColumn): void {
                if ($file->{$pathColumn}) {
                    $files[] = [$diskColumn ? ($file->{$diskColumn} ?: 'public') : 'public', $file->{$pathColumn}];
                }
            }
        );
    }

    private function financeExportUrls(array $payload): array
    {
        return collect([$payload['url'] ?? null, $payload['appendix']['url'] ?? null])
            ->merge(collect($payload['files'] ?? [])->pluck('url'))
            ->filter()->unique()->values()->all();
    }

    private function purgeClubAuditHistory(Club $club): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')
            ->where(function ($query) use ($club): void {
                $query->where('entity_type', 'Club')->where('entity_id', $club->id);
            })
            ->orWhere('metadata->club_id', $club->id)
            ->delete();
    }

    private function deleteForClub(string $table, int $clubId): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'club_id')) {
            DB::table($table)->where('club_id', $clubId)->delete();
        }
    }

    private function deleteWhereEitherClub(string $table, int $clubId, string $first, string $second): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->where($first, $clubId)->orWhere($second, $clubId)->delete();
        }
    }

    private function countForClub(string $table, int $clubId): int
    {
        return Schema::hasTable($table) ? DB::table($table)->where('club_id', $clubId)->count() : 0;
    }
}
