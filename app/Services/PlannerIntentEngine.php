<?php

namespace App\Services;

class PlannerIntentEngine
{
    public function decide(string $message, array $signals = []): array
    {
        $text = mb_strtolower(trim($message));
        $scores = [];

        foreach ($this->intentPatterns() as $intent => $patterns) {
            $score = 0.0;
            foreach ($patterns as $pattern => $weight) {
                if (preg_match($pattern, $text) === 1) {
                    $score += $weight;
                }
            }
            $scores[$intent] = $score;
        }

        $this->applySignalBoosts($scores, $signals);
        $scores = array_map(fn ($value) => round(min(1.0, max(0.0, $value)), 4), $scores);
        arsort($scores);

        $ranked = [];
        foreach ($scores as $name => $score) {
            $ranked[] = ['name' => $name, 'score' => $score];
        }

        if (!empty($signals['force_no_tools'])) {
            return [
                'primary_intent' => null,
                'selected_intents' => [],
                'ranked_intents' => $ranked,
            ];
        }

        $minScore = (float) config('ai.intent_min_score', 0.35);
        $multiDelta = (float) config('ai.intent_multi_delta', 0.12);

        $top = $ranked[0] ?? null;
        if (!$top || $top['score'] < $minScore) {
            return [
                'primary_intent' => null,
                'selected_intents' => [],
                'ranked_intents' => $ranked,
            ];
        }

        $selected = [$top['name']];
        foreach (array_slice($ranked, 1, 2) as $candidate) {
            if ($candidate['score'] < $minScore) {
                continue;
            }
            if (($top['score'] - $candidate['score']) <= $multiDelta) {
                $selected[] = $candidate['name'];
            }
        }

        return [
            'primary_intent' => $top['name'],
            'selected_intents' => array_values(array_unique($selected)),
            'ranked_intents' => $ranked,
        ];
    }

    protected function intentPatterns(): array
    {
        return [
            'estimate_rental_costs' => [
                '/\b(rent|rental|hire)\b/' => 0.24,
                '/\b(van|bus|minivan|coach|car|vehicle)\b/' => 0.28,
                '/\b(cost|price|estimate|budget|how much)\b/' => 0.24,
                '/\b\d{1,2}\s*(passenger|people|person)\b/' => 0.2,
            ],
            'find_rental_agencies' => [
                '/\b(rental|rent|agency|agencies)\b/' => 0.26,
                '/\b(where|near|nearby|find|search|recommend|suggest)\b/' => 0.24,
                '/\b(van|bus|car|vehicle)\b/' => 0.2,
            ],
            'find_recommended_places' => [
                '/\b(camp|camping|campground|park|venue|restaurant|outing|trip|museum|hike)\b/' => 0.26,
                '/\b(find|search|recommend|suggest|near|nearby|closest|where)\b/' => 0.24,
                '/\b(place|location|spots|ideas)\b/' => 0.2,
            ],
            'create_tasks' => [
                '/\b(task|tasks|todo|to do|checklist)\b/' => 0.55,
                '/\b(create|add|make|generate)\b/' => 0.2,
            ],
            'create_budget_items' => [
                '/\b(budget|expense|expenses|costs|line item|line items)\b/' => 0.55,
                '/\b(create|add|estimate|breakdown)\b/' => 0.2,
            ],
            'add_participants' => [
                '/\b(participant|participants|attendee|attendees|chaperone|driver|staff|member)\b/' => 0.55,
                '/\b(add|invite|include|register)\b/' => 0.2,
            ],
            'set_missing_items' => [
                '/\b(missing|incomplete|not done|remaining)\b/' => 0.55,
                '/\b(items|requirements|checklist)\b/' => 0.2,
            ],
            'update_event_spine' => [
                '/\b(update|change|edit|reschedule)\b/' => 0.3,
                '/\b(event|date|time|location|status|title)\b/' => 0.28,
            ],
            'update_plan_section' => [
                '/\b(plan section|section|outline)\b/' => 0.45,
                '/\b(update|add|edit|write)\b/' => 0.2,
            ],
        ];
    }

    protected function applySignalBoosts(array &$scores, array $signals): void
    {
        if (!empty($signals['detect_place_intent'])) {
            $scores['find_recommended_places'] = ($scores['find_recommended_places'] ?? 0) + 0.25;
        }

        if (!empty($signals['detect_rental_agency_intent'])) {
            $scores['find_rental_agencies'] = ($scores['find_rental_agencies'] ?? 0) + 0.3;
        }

        if (!empty($signals['has_rental_details'])) {
            $scores['estimate_rental_costs'] = ($scores['estimate_rental_costs'] ?? 0) + 0.3;
        }

        if (!empty($signals['has_location_input'])) {
            $scores['find_recommended_places'] = ($scores['find_recommended_places'] ?? 0) + 0.16;
            $scores['find_rental_agencies'] = ($scores['find_rental_agencies'] ?? 0) + 0.16;
        }

        if (!empty($signals['is_non_place_request'])) {
            $scores['find_recommended_places'] = ($scores['find_recommended_places'] ?? 0) - 0.45;
        }

        $legacyIntent = $signals['legacy_intent'] ?? null;
        if ($legacyIntent && isset($scores[$legacyIntent])) {
            $scores[$legacyIntent] += 0.15;
        }
    }
}
