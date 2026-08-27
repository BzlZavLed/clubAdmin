<?php

namespace App\Services;

use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\User;
use Illuminate\Support\Str;

class ParentChildIdentityMatcher
{
    public function evaluate(User $parent, MemberAdventurer|MemberPathfinder $member): array
    {
        $parentName = $this->normalize($parent->name);
        $parentEmail = strtolower(trim((string) $parent->email));
        $guardians = $this->guardians($member);
        $nameMatches = collect($guardians)->contains(
            fn (array $guardian) => $parentName !== '' && $parentName === $this->normalize($guardian['name'])
        );
        $emailMatches = $parent->hasVerifiedEmail() && collect($guardians)->contains(
            fn (array $guardian) => $parentEmail !== '' && $parentEmail === strtolower(trim((string) $guardian['email']))
        );
        $guardianPairMatches = $parent->hasVerifiedEmail() && collect($guardians)->contains(
            fn (array $guardian) => $parentName !== ''
                && $parentName === $this->normalize($guardian['name'])
                && $parentEmail !== ''
                && $parentEmail === strtolower(trim((string) $guardian['email']))
        );
        $factors = [
            'last_name' => $this->lastNameMatches($parent->name, $member->applicant_name),
            'parent_name' => $nameMatches,
            'email' => $emailMatches,
        ];
        $matchedCount = count(array_filter($factors));

        return [
            'factors' => $factors,
            'matched_count' => $matchedCount,
            'guardian_pair_matches' => $guardianPairMatches,
            'can_link_immediately' => $matchedCount === 3 && $guardianPairMatches,
            'requires_director_approval' => $matchedCount === 2 || ($matchedCount === 3 && ! $guardianPairMatches),
            'eligible' => $matchedCount >= 2,
            'snapshot' => [
                'parent_name' => $parent->name,
                'parent_email' => $parent->email,
                'parent_email_verified' => $parent->hasVerifiedEmail(),
                'member_name' => $member->applicant_name,
                'registered_guardians' => $guardians,
            ],
        ];
    }

    public function detail(string $memberType, int $id): MemberAdventurer|MemberPathfinder|null
    {
        return $memberType === 'adventurers'
            ? MemberAdventurer::query()->find($id)
            : MemberPathfinder::query()->find($id);
    }

    private function guardians(MemberAdventurer|MemberPathfinder $member): array
    {
        if ($member instanceof MemberAdventurer) {
            return [[
                'name' => $member->parent_name,
                'email' => $member->email_address,
            ]];
        }

        return array_values(array_filter([
            ['name' => $member->father_guardian_name, 'email' => $member->father_guardian_email],
            ['name' => $member->mother_guardian_name, 'email' => $member->mother_guardian_email],
        ], fn (array $guardian) => $guardian['name'] || $guardian['email']));
    }

    public function lastNameMatches(?string $parentName, ?string $memberName): bool
    {
        $parentFirstSurname = $this->firstSurname($parentName);
        $memberFirstSurname = $this->firstSurname($memberName);

        return $parentFirstSurname !== null
            && $memberFirstSurname !== null
            && $parentFirstSurname === $memberFirstSurname;
    }

    private function firstSurname(?string $name): ?string
    {
        $ignored = ['de', 'del', 'la', 'las', 'los', 'y'];
        $parts = array_values(array_filter(
            $this->nameParts($name),
            fn (string $part) => ! in_array($part, $ignored, true)
        ));

        if (count($parts) < 2) {
            return null;
        }

        return count($parts) === 2
            ? $parts[array_key_last($parts)]
            : $parts[count($parts) - 2];
    }

    private function nameParts(?string $value): array
    {
        return array_values(array_filter(explode(' ', $this->normalize($value))));
    }

    private function normalize(?string $value): string
    {
        $ascii = Str::lower(Str::ascii((string) $value));
        $withoutPunctuation = preg_replace('/[^a-z0-9\s]/', ' ', $ascii) ?: '';

        return preg_replace('/\s+/', ' ', trim($withoutPunctuation)) ?: '';
    }
}
