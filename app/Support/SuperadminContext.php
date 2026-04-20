<?php

namespace App\Support;

use App\Models\Association;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\Union;

class SuperadminContext
{
    public static function fromSession(): array
    {
        return self::normalize([
            'union_id' => session('superadmin_context.union_id'),
            'association_id' => session('superadmin_context.association_id'),
            'district_id' => session('superadmin_context.district_id'),
            'church_id' => session('superadmin_context.church_id'),
            'club_id' => session('superadmin_context.club_id'),
        ]);
    }

    public static function normalize(array $input): array
    {
        $club = null;
        $church = null;
        $district = null;
        $association = null;
        $union = null;

        if (!empty($input['club_id'])) {
            $club = Club::query()
                ->withoutGlobalScopes()
                ->where('status', '!=', 'deleted')
                ->with('church.district.association.union')
                ->find((int) $input['club_id']);

            if ($club) {
                $church = $club->church;
                $district = $church?->district;
                $association = $district?->association;
                $union = $association?->union;
            }
        }

        if (!$church && !empty($input['church_id'])) {
            $church = Church::query()
                ->with('district.association.union')
                ->find((int) $input['church_id']);

            if ($church) {
                $district = $church->district;
                $association = $district?->association;
                $union = $association?->union;
            }
        }

        if (!$district && !empty($input['district_id'])) {
            $district = District::query()
                ->with('association.union')
                ->find((int) $input['district_id']);

            if ($district) {
                $association = $district->association;
                $union = $association?->union;
            }
        }

        if (!$association && !empty($input['association_id'])) {
            $association = Association::query()
                ->with('union')
                ->find((int) $input['association_id']);

            if ($association) {
                $union = $association->union;
            }
        }

        if (!$union && !empty($input['union_id'])) {
            $union = Union::query()->find((int) $input['union_id']);
        }

        $actingRole = match (true) {
            (bool) $club => 'club_director',
            (bool) $district => 'district_pastor',
            (bool) $association => 'association_youth_director',
            (bool) $union => 'union_youth_director',
            default => 'superadmin',
        };

        $dashboardUrl = match ($actingRole) {
            'club_director' => '/club-director/dashboard',
            'district_pastor' => '/district/dashboard',
            'association_youth_director' => '/association/dashboard',
            'union_youth_director' => '/union/dashboard',
            default => '/super-admin/dashboard',
        };

        return [
            'role' => $actingRole,
            'dashboard_url' => $dashboardUrl,
            'evaluation_system' => $union?->evaluation_system ?: 'honors',
            'union_id' => $union?->id,
            'union_name' => $union?->name,
            'association_id' => $association?->id,
            'association_name' => $association?->name,
            'district_id' => $district?->id,
            'district_name' => $district?->name,
            'church_id' => $church?->id,
            'church_name' => $church?->church_name,
            'club_id' => $club?->id,
            'club_name' => $club?->club_name,
        ];
    }
}
