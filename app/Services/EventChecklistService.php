<?php

namespace App\Services;

use App\Models\Event;

class EventChecklistService
{
    public function syncPermissionSlips(Event $event): void
    {
        $kidParticipants = $event->participants()
            ->where('role', 'kid')
            ->get(['id', 'member_id']);

        $kidMemberIds = $kidParticipants
            ->pluck('member_id')
            ->filter()
            ->unique()
            ->values();

        $kidParticipantIds = $kidParticipants
            ->pluck('id')
            ->unique()
            ->values();

        $requiredCount = $kidParticipants->count();
        $uploadedMemberIds = collect();
        $uploadedParticipantIds = collect();

        $event->documents()
            ->where(function ($query) {
                $query
                    ->whereRaw("lower(coalesce(doc_type, type, '')) like ?", ['%permission%'])
                    ->orWhereRaw("lower(coalesce(doc_type, type, '')) like ?", ['%slip%']);
            })
            ->get(['member_id', 'meta_json'])
            ->each(function ($document) use (&$uploadedMemberIds, &$uploadedParticipantIds) {
                if ($document->member_id) {
                    $uploadedMemberIds->push($document->member_id);
                }

                $meta = $document->meta_json ?? [];
                $memberIds = $meta['member_ids'] ?? [];
                if (is_array($memberIds)) {
                    $uploadedMemberIds = $uploadedMemberIds->merge($memberIds);
                }

                $participantIds = $meta['participant_ids'] ?? [];
                if (is_array($participantIds)) {
                    $uploadedParticipantIds = $uploadedParticipantIds->merge($participantIds);
                }
            });

        $uploadedMemberIds = $uploadedMemberIds->filter()->unique();
        $uploadedParticipantIds = $uploadedParticipantIds->filter()->unique();

        $memberMatches = $kidMemberIds->intersect($uploadedMemberIds)->count();
        $participantMatches = $kidParticipantIds->intersect($uploadedParticipantIds)->count();
        $uploadedCount = $memberMatches + $participantMatches;
        $isComplete = $requiredCount > 0 && $uploadedCount >= $requiredCount;

        $event->tasks()
            ->where(function ($query) {
                $query
                    ->whereRaw("checklist_json->>'task_key' = ?", ['permission_slips'])
                    ->orWhereRaw('lower(title) like ?', ['%permission slip%'])
                    ->orWhereRaw('lower(title) like ?', ['%permission slips%']);
            })
            ->update([
                'status' => $isComplete ? 'done' : 'todo',
            ]);
    }
}
