<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventClubSettlement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventClubSettlementService
{
    public function recordSettlement(Event $event, Club $club, int $userId, array $breakdown, float $amount, \DateTimeInterface $depositedAt, ?string $reference = null, ?string $notes = null): EventClubSettlement
    {
        return DB::transaction(function () use ($event, $club, $userId, $breakdown, $amount, $depositedAt, $reference, $notes) {
            Club::query()->whereKey($club->id)->lockForUpdate()->first(['id']);

            $issuedAt = now();
            $receiptYear = (int) $issuedAt->format('Y');
            $clubCode = $this->clubCode($club);
            $clubSequence = $this->nextClubSequence($club->id, $receiptYear);

            return EventClubSettlement::query()->create([
                'event_id' => $event->id,
                'club_id' => $club->id,
                'created_by_user_id' => $userId,
                'organizer_scope_type' => (string) ($event->scope_type ?: 'club'),
                'organizer_scope_id' => (int) ($event->scope_id ?: $event->club_id),
                'amount' => round($amount, 2),
                'breakdown_json' => array_values($breakdown),
                'deposited_at' => $depositedAt,
                'reference' => $reference,
                'notes' => $notes,
                'club_code' => $clubCode,
                'receipt_year' => $receiptYear,
                'club_sequence' => $clubSequence,
                'receipt_number' => $this->receiptNumber($receiptYear, $clubCode, $clubSequence),
                'issued_at' => $issuedAt,
            ]);
        });
    }

    public function organizerLabel(EventClubSettlement $settlement): string
    {
        $scopeType = (string) $settlement->organizer_scope_type;
        $scopeId = (int) $settlement->organizer_scope_id;

        return match ($scopeType) {
            'union' => 'Union: ' . (\App\Models\Union::query()->whereKey($scopeId)->value('name') ?: "#{$scopeId}"),
            'association' => 'Association: ' . (\App\Models\Association::query()->whereKey($scopeId)->value('name') ?: "#{$scopeId}"),
            'district' => 'District: ' . (\App\Models\District::query()->whereKey($scopeId)->value('name') ?: "#{$scopeId}"),
            'church' => 'Church: ' . (\App\Models\Church::query()->whereKey($scopeId)->value('church_name') ?: "#{$scopeId}"),
            default => 'Club',
        };
    }

    protected function receiptNumber(int $year, string $clubCode, int $sequence): string
    {
        return sprintf('EVTDEP-%s-%s-%06d', $year, $clubCode, $sequence);
    }

    protected function clubCode(Club $club): string
    {
        $letters = Str::upper(preg_replace('/[^A-Z0-9]/i', '', $club->club_name ?: 'CLUB'));
        $prefix = substr($letters ?: 'CLUB', 0, 4);
        $suffix = str_pad((string) $club->id, 7, '0', STR_PAD_LEFT);

        return substr(str_pad($prefix, 4, 'X') . $suffix, 0, 12);
    }

    protected function nextClubSequence(int $clubId, int $year): int
    {
        $max = EventClubSettlement::withTrashed()
            ->where('club_id', $clubId)
            ->where('receipt_year', $year)
            ->max('club_sequence');

        return ((int) $max) + 1;
    }
}
