<?php

namespace App\Services;

use App\Models\AiRequestLog;
use App\Models\AiUsageDaily;
use App\Models\Event;
use App\Models\EventBudgetItem;
use App\Models\EventParticipant;
use App\Models\EventPlan;
use App\Models\EventTask;
use App\Models\User;
use App\Services\EventTaskTemplateService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Log;
use RuntimeException;
use App\Services\PlacesService;

class AiPlannerService
{
    protected ?int $lastLogId = null;
    protected ?array $lastRawResponse = null;
    protected ?array $lastToolDebug = null;
    protected ?array $lastIntentDebug = null;

    public function __construct(
        private AiClient $client,
        private PlacesService $places,
        private RentalQuoteService $rentalQuotes,
        private PlannerIntentEngine $intentEngine,
        private EventTaskTemplateService $taskTemplates,
    ) {
    }

    public function handleMessage(Event $event, User $user, string $message, array $options = []): array
    {
        $event->load(['plan', 'tasks', 'budgetItems', 'participants', 'documents', 'club.church']);
        $plan = $event->plan ?? $this->initializePlan($event);

        $this->applyPlannerPreferences($plan, $options);

        if (empty($plan->missing_items_json)) {
            $defaults = $this->defaultMissingItems($event->event_type);
            if (!empty($defaults)) {
                $plan->missing_items_json = $defaults;
                $plan->save();
            }
        }

        $this->validateAndBackfillTasks($event, $plan);

        $this->assertWithinCaps($event, $user, $plan);

        $conversation = $plan->conversation_json ?? [];
        $conversation[] = [
            'role' => 'user',
            'content' => $message,
            'at' => now()->toIso8601String(),
        ];

        $systemPrompt = $this->buildSystemPrompt($event);
        $tools = $this->toolDefinitions();
        $assistantMessage = null;
        $lastToolSummary = null;

        $forceNoTools = $this->isDocumentRequest($message);
        $isNonPlaceRequest = $this->isNonPlaceRequest($message);
        $hasLocationInput = $this->looksLikeAddress($message)
            || $this->extractLocationHint($message)
            || $this->extractRadiusKm($message);
        $rentalDetails = $this->extractRentalDetails($message, $event);
        $legacyIntent = $this->inferToolIntent($message);
        $intentDecision = $this->intentEngine->decide($message, [
            'force_no_tools' => $forceNoTools,
            'is_non_place_request' => $isNonPlaceRequest,
            'has_location_input' => (bool) $hasLocationInput,
            'has_rental_details' => $rentalDetails !== null,
            'detect_place_intent' => $this->detectPlaceIntent($message),
            'detect_rental_agency_intent' => $this->detectRentalAgencyIntent($message),
            'legacy_intent' => $legacyIntent,
        ]);
        $selectedIntents = $intentDecision['selected_intents'] ?? [];
        $inferredTool = $intentDecision['primary_intent'] ?? $legacyIntent;
        $rentalAgencyIntent = in_array('find_rental_agencies', $selectedIntents, true);
        $rentalCostIntent = in_array('estimate_rental_costs', $selectedIntents, true);
        $placeIntent = in_array('find_recommended_places', $selectedIntents, true);
        $forcedTool = null;
        if (count($selectedIntents) === 1 && in_array($inferredTool, ['find_rental_agencies', 'find_recommended_places'], true)) {
            $forcedTool = $inferredTool;
        }
        $this->lastIntentDebug = config('ai.debug_return') ? [
            'primary' => $inferredTool,
            'selected' => $selectedIntents,
            'ranked' => $intentDecision['ranked_intents'] ?? [],
            'signals' => [
                'force_no_tools' => $forceNoTools,
                'is_non_place_request' => $isNonPlaceRequest,
                'has_location_input' => (bool) $hasLocationInput,
                'has_rental_details' => $rentalDetails !== null,
                'legacy_intent' => $legacyIntent,
            ],
        ] : null;

        $pendingIntent = $plan->plan_json['pending_place_intent'] ?? null;
        if (!$pendingIntent && !$placeIntent && !$isNonPlaceRequest && !$forceNoTools) {
            $pendingIntent = $this->findLastPlaceIntent($conversation);
        }
        if ($forceNoTools) {
            $placeIntent = false;
            $forcedTool = null;
            $hasLocationInput = false;
            if ($pendingIntent) {
                $planJson = $plan->plan_json ?? ['sections' => []];
                unset($planJson['pending_place_intent']);
                $plan->plan_json = $planJson;
                $plan->save();
                $pendingIntent = null;
            }
        } elseif ($isNonPlaceRequest) {
            $placeIntent = false;
            $forcedTool = null;
            $hasLocationInput = false;
            if ($pendingIntent) {
                $planJson = $plan->plan_json ?? ['sections' => []];
                unset($planJson['pending_place_intent']);
                $plan->plan_json = $planJson;
                $plan->save();
                $pendingIntent = null;
            }
        }
        if ($pendingIntent && !$placeIntent && !$hasLocationInput) {
            $planJson = $plan->plan_json ?? ['sections' => []];
            unset($planJson['pending_place_intent']);
            $plan->plan_json = $planJson;
            $plan->save();
            $pendingIntent = null;
        }
        $pendingAction = $plan->plan_json['pending_action'] ?? null;

        $shouldHandlePlaceIntent = $placeIntent && $inferredTool === 'find_recommended_places';
        if ($shouldHandlePlaceIntent || ($pendingIntent && $hasLocationInput && !$rentalCostIntent)) {
            $intent = $shouldHandlePlaceIntent ? $message : $pendingIntent;
            $locationHint = $this->extractLocationHint($message);
            $radiusKm = $this->extractRadiusKm($message);
            $requestedCount = $this->extractRequestedCount($message) ?? $this->extractRequestedCount($intent);
            $addressOverride = $shouldHandlePlaceIntent ? $locationHint : $message;
            $addressOverride = $this->composeSearchAddress(
                $event->club?->church?->address,
                $addressOverride
            );

            $toolOutput = $this->handleFindRecommendedPlaces($event, [
                'event_id' => $event->id,
                'intent' => $intent,
                'address' => $addressOverride,
                'radius_km' => $radiusKm,
                'max_results' => $requestedCount,
            ]);
            $this->lastToolDebug = $toolOutput['debug'] ?? null;

            if (isset($toolOutput['error'])) {
                $assistantMessage = $toolOutput['error'];
                $planJson = $plan->plan_json ?? ['sections' => []];
                $planJson['pending_place_intent'] = $intent;
                $plan->plan_json = $planJson;
            } else {
                $count = $toolOutput['count'] ?? 0;
                $assistantMessage = $count
                    ? "I found {$count} recommended places near the church address."
                    : 'I tried searching nearby but found no results. Please share a city, state, ZIP code, or a specific park name.';

                $planJson = $plan->plan_json ?? ['sections' => []];
                unset($planJson['pending_place_intent']);
                $plan->plan_json = $planJson;
            }

            $conversation[] = [
                'role' => 'assistant',
                'content' => $assistantMessage,
                'at' => now()->toIso8601String(),
            ];

            $plan->conversation_json = $conversation;
            $plan->last_generated_at = now();
            $plan->ai_summary = $assistantMessage;
            $plan->save();

            $event->load(['plan', 'tasks', 'budgetItems', 'participants', 'documents']);

            $response = [
                'assistant_message' => $assistantMessage,
                'event' => $event,
                'eventPlan' => $event->plan,
                'tasks' => $event->tasks,
                'budget_items' => $event->budgetItems,
                'participants' => $event->participants,
                'documents' => $event->documents,
                'missing_items' => $event->plan?->missing_items_json ?? [],
            ];
            return $this->withDebug($response);
        }

        if ($pendingAction && !$inferredTool) {
            $tool = $pendingAction['tool'] ?? null;
            if ($tool) {
                $handled = $this->handlePendingAction($event, $plan, $conversation, $tool, $message);
                if ($handled) {
                    return $this->withDebug($handled);
                }
            }
        }

        if ($inferredTool === 'estimate_rental_costs') {
            $details = $rentalDetails;
            if ($details) {
                $toolOutput = $this->handleEstimateRentalCosts($event, array_merge([
                    'event_id' => $event->id,
                ], $details));

                if (isset($toolOutput['error'])) {
                    $assistantMessage = 'I could not estimate rental costs. Please provide vehicle type, passenger count, and dates.';
                } else {
                    $assistantMessage = $this->buildRentalEstimateMessage($toolOutput, $details);
                }

                $conversation[] = [
                    'role' => 'assistant',
                    'content' => $assistantMessage,
                    'at' => now()->toIso8601String(),
                ];
                $plan->conversation_json = $conversation;
                $plan->last_generated_at = now();
                $plan->ai_summary = $assistantMessage;
                $plan->save();

                $event->load(['plan', 'tasks', 'budgetItems', 'participants', 'documents']);

                $response = [
                    'assistant_message' => $assistantMessage,
                    'event' => $event,
                    'eventPlan' => $event->plan,
                    'tasks' => $event->tasks,
                    'budget_items' => $event->budgetItems,
                    'participants' => $event->participants,
                    'documents' => $event->documents,
                    'missing_items' => $event->plan?->missing_items_json ?? [],
                ];
                return $this->withDebug($response);
            }
        }


        $payload = $this->buildPayload(array_merge([
            ['role' => 'system', 'content' => $systemPrompt],
        ], $conversation), $tools, $forcedTool, $forceNoTools ? 'none' : null);

        $latestPlaces = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->callAndLog($event, $user, $payload);
            $assistantMessage = $this->extractAssistantMessage($response);
            $toolCalls = $this->extractToolCalls($response);

            if (empty($toolCalls)) {
                if ($assistantMessage) {
                    $conversation[] = [
                        'role' => 'assistant',
                        'content' => $assistantMessage,
                        'at' => now()->toIso8601String(),
                    ];
                }
                if (!$assistantMessage && $inferredTool) {
                    $assistantMessage = $this->toolGuidanceMessage($inferredTool);
                    $conversation[] = [
                        'role' => 'assistant',
                        'content' => $assistantMessage,
                        'at' => now()->toIso8601String(),
                    ];
                    $planJson = $plan->plan_json ?? ['sections' => []];
                    $planJson['pending_action'] = [
                        'tool' => $inferredTool,
                        'requested_at' => now()->toIso8601String(),
                        'last_prompt' => $message,
                    ];
                    $plan->plan_json = $planJson;
                }
                $plan->conversation_json = $conversation;
                $plan->last_generated_at = now();
                if ($assistantMessage) {
                    $plan->ai_summary = $assistantMessage;
                }
                $plan->save();
                break;
            }

            $conversation[] = [
                'role' => 'assistant',
                'content' => $assistantMessage ?? '',
                'tool_calls' => $toolCalls,
                'at' => now()->toIso8601String(),
            ];

            $toolOutputs = [];

            foreach ($toolCalls as $call) {
                $toolOutput = $this->executeToolCall($event, $call);
                $toolOutputs[] = $toolOutput;

                if (($toolOutput['name'] ?? null) === 'find_recommended_places') {
                    $count = $toolOutput['result']['count'] ?? null;
                    if ($count !== null) {
                        $lastToolSummary = "I found {$count} recommended places near the church address.";
                    }
                    $latestPlaces = $toolOutput['result']['places'] ?? null;
                }

                if (($toolOutput['name'] ?? null) === 'find_rental_agencies') {
                    $count = $toolOutput['result']['count'] ?? null;
                    if ($count !== null) {
                        $lastToolSummary = "I found {$count} rental agencies near the church address.";
                    }
                    $latestPlaces = $toolOutput['result']['agencies'] ?? null;
                }
            }

            foreach ($toolOutputs as $output) {
                $conversation[] = [
                    'role' => 'tool',
                    'name' => $output['name'],
                    'tool_call_id' => $output['id'] ?? null,
                    'content' => json_encode($output['result']),
                    'at' => now()->toIso8601String(),
                ];
            }

            $payload = $this->buildPayload(array_merge([
                ['role' => 'system', 'content' => $systemPrompt],
            ], $conversation), $tools, null, $forceNoTools ? 'none' : null);
        }

        $event->load(['plan', 'tasks', 'budgetItems', 'participants', 'documents']);

        if (!$assistantMessage && $lastToolSummary) {
            $assistantMessage = $lastToolSummary;
        }
        if (!$assistantMessage) {
            $assistantMessage = 'I’m here and ready. Tell me what you want to accomplish next (tasks, budget, participants, plan outline, or place recommendations).';
        }

        if ($latestPlaces) {
            $lastIndex = count($conversation) - 1;
            $lastMessage = $lastIndex >= 0 ? $conversation[$lastIndex] : null;
            $shouldAppend = !($lastMessage && ($lastMessage['role'] ?? null) === 'assistant')
                || !empty($lastMessage['tool_calls'])
                || empty($lastMessage['content'] ?? null);
            if ($shouldAppend) {
                $conversation[] = [
                    'role' => 'assistant',
                    'content' => $assistantMessage,
                    'places' => $latestPlaces,
                    'at' => now()->toIso8601String(),
                ];
            } else {
                $conversation[$lastIndex]['places'] = $latestPlaces;
                $conversation[$lastIndex]['content'] = $assistantMessage;
            }
            $plan->conversation_json = $conversation;
            $plan->last_generated_at = now();
            $plan->ai_summary = $assistantMessage;
            $plan->save();
        }

        $response = [
            'assistant_message' => $assistantMessage,
            'event' => $event,
            'eventPlan' => $event->plan,
            'tasks' => $event->tasks,
            'budget_items' => $event->budgetItems,
            'participants' => $event->participants,
            'documents' => $event->documents,
            'missing_items' => $event->plan?->missing_items_json ?? [],
        ];
        return $this->withDebug($response);
    }

    protected function initializePlan(Event $event): EventPlan
    {
        return EventPlan::create([
            'event_id' => $event->id,
            'schema_version' => 1,
            'plan_json' => ['sections' => []],
            'missing_items_json' => [],
            'conversation_json' => [],
        ]);
    }

    protected function assertWithinCaps(Event $event, User $user, EventPlan $plan): void
    {
        $messageCap = config('ai.event_message_cap');
        $userMessageCount = collect($plan->conversation_json ?? [])
            ->where('role', 'user')
            ->count();

        if ($userMessageCount >= $messageCap) {
            throw new RuntimeException('This event has reached the maximum number of planner messages.');
        }

        $usage = AiUsageDaily::firstOrCreate([
            'club_id' => $event->club_id,
            'usage_date' => now()->toDateString(),
        ], [
            'tokens_used' => 0,
            'requests_count' => 0,
        ]);

        $dailyCap = config('ai.daily_token_cap');
        if ($usage->tokens_used >= $dailyCap) {
            throw new RuntimeException('This club has reached the daily AI usage limit.');
        }
    }

    protected function buildSystemPrompt(Event $event): string
    {
        $plan = $event->plan;
        $preferences = $plan?->plan_json['preferences'] ?? [];

        $context = [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_type' => $event->event_type,
                'start_at' => optional($event->start_at)->toIso8601String(),
                'end_at' => optional($event->end_at)->toIso8601String(),
                'timezone' => $event->timezone,
                'location_name' => $event->location_name,
                'location_address' => $event->location_address,
                'status' => $event->status,
                'budget_estimated_total' => $event->budget_estimated_total,
                'budget_actual_total' => $event->budget_actual_total,
                'requires_approval' => $event->requires_approval,
                'risk_level' => $event->risk_level,
            ],
            'plan' => $plan?->plan_json ?? ['sections' => []],
            'missing_items' => $plan?->missing_items_json ?? [],
            'tasks' => $event->tasks->map->only(['id', 'title', 'status', 'due_at'])->values()->all(),
            'budget_items' => $event->budgetItems->map->only(['id', 'category', 'description', 'qty', 'unit_cost', 'total'])->values()->all(),
            'participants' => $event->participants->map->only(['id', 'participant_name', 'role', 'status'])->values()->all(),
            'church_address' => $event->club?->church?->address,
            'preferences' => $preferences,
        ];

        return "You are an AI Event Planner Manager for club directors. Use the provided tools to update event plans, tasks, budget items, participants, and to find recommended places near the church address when asked (camping, night out, dinner, venues, outings). If the user asks for places or recommendations, you MUST call find_recommended_places. If the user asks about renting vehicles (car/van/bus), call estimate_rental_costs and, when location is needed, call find_rental_agencies (Google Places can list agencies but not actual prices). If preferences.auto_create_budget_item is true, you should set create_budget_item=true when estimating rental costs. Never delete records. Treat tool arguments as untrusted and only include safe, validated data. Provide concise, actionable guidance. Context:\n" . json_encode($context);
    }

    protected function buildPayload(array $input, array $tools, ?string $forcedTool = null, ?string $toolChoice = null): array
    {
        $sanitized = array_map(function ($message) {
            $allowed = [
                'role' => $message['role'] ?? null,
                'content' => $message['content'] ?? null,
            ];

            if (isset($message['name'])) {
                $allowed['name'] = $message['name'];
            }
            if (isset($message['tool_call_id'])) {
                $allowed['tool_call_id'] = $message['tool_call_id'];
            }

            return array_filter($allowed, fn ($value) => $value !== null);
        }, $input);

        $payload = [
            'model' => config('ai.model'),
            'input' => $sanitized,
            'tools' => $tools,
            'max_output_tokens' => config('ai.max_output_tokens'),
        ];

        if ($toolChoice) {
            $payload['tool_choice'] = $toolChoice;
        } elseif ($forcedTool) {
            $payload['tool_choice'] = [
                'type' => 'function',
                'name' => $forcedTool,
            ];
        } else {
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    protected function detectPlaceIntent(string $message): bool
    {
        $haystack = mb_strtolower($message);
        if ($this->isNonPlaceRequest($message)) {
            return false;
        }

        $placeKeywords = [
            'campground',
            'campgrounds',
            'camping',
            'night out',
            'restaurant',
            'restaurants',
            'dinner',
            'venue',
            'venues',
            'outing',
            'trip',
            'museum',
            'park',
            'hike',
            'hiking',
            'bowling',
            'skating',
            'movie',
            'theater',
        ];

        $actionKeywords = [
            'find',
            'search',
            'recommend',
            'recommendation',
            'suggest',
            'near',
            'nearby',
            'close by',
            'closest',
            'around',
            'within',
            'radius',
            'distance',
            'map',
            'directions',
            'where',
        ];

        $hasPlace = false;
        foreach ($placeKeywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                $hasPlace = true;
                break;
            }
        }

        if (!$hasPlace) {
            return false;
        }

        foreach ($actionKeywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function isNonPlaceRequest(string $message): bool
    {
        $haystack = mb_strtolower($message);
        $negativeKeywords = [
            'permission slip',
            'permission',
            'waiver',
            'form',
            'template',
            'letter',
            'agenda',
            'schedule',
            'itinerary',
            'budget',
            'task',
            'checklist',
            'participant',
            'plan outline',
            'document',
            'notice',
        ];

        foreach ($negativeKeywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function isDocumentRequest(string $message): bool
    {
        $haystack = mb_strtolower($message);
        $documentKeywords = [
            'permission slip',
            'permission',
            'waiver',
            'form',
            'template',
            'letter',
            'document',
            'printable',
            'print',
        ];

        foreach ($documentKeywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function extractLocationHint(string $message): ?string
    {
        $text = mb_strtolower($message);
        $states = [
            'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
            'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
            'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID',
            'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
            'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
            'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
            'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
            'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
            'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
            'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
            'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
            'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
            'wisconsin' => 'WI', 'wyoming' => 'WY',
        ];

        foreach ($states as $name => $abbr) {
            if (str_contains($text, $name)) {
                return ucfirst($name) . ' ' . $abbr;
            }
            if (preg_match('/\\b' . strtolower($abbr) . '\\b/', $text)) {
                return strtoupper($abbr);
            }
        }

        if (preg_match('/\\b\\d{5}(?:-\\d{4})?\\b/', $text, $matches)) {
            return $matches[0];
        }

        return null;
    }

    protected function extractRadiusKm(string $message): ?int
    {
        $text = mb_strtolower($message);
        if (preg_match('/\\b(\\d{1,3})\\s*(miles|mi)\\b/', $text, $matches)) {
            return (int) round(((int) $matches[1]) * 1.60934);
        }
        if (preg_match('/\\b(\\d{1,3})\\s*(km|kilometers|kilometres)\\b/', $text, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    protected function composeSearchAddress(?string $churchAddress, ?string $override): ?string
    {
        if ($override && $churchAddress) {
            return trim($churchAddress . ', ' . $override, ' ,');
        }
        return $override ?: $churchAddress;
    }

    protected function extractRequestedCount(string $message): ?int
    {
        $text = mb_strtolower($message);
        if (preg_match('/\\b(\\d{1,2})\\b/', $text, $matches)) {
            $count = (int) $matches[1];
            if ($count >= 1 && $count <= 20) {
                return $count;
            }
        }

        $words = [
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
        ];
        foreach ($words as $word => $value) {
            if (str_contains($text, $word)) {
                return $value;
            }
        }

        return null;
    }

    protected function looksLikeAddress(string $message): bool
    {
        $value = trim($message);
        if (strlen($value) < 6) {
            return false;
        }

        $hasNumber = preg_match('/\\d+/', $value) === 1;
        $hasComma = str_contains($value, ',');
        $hasStreetHint = preg_match('/\\b(st|street|rd|road|ave|avenue|blvd|boulevard|ln|lane|dr|drive|way|ct|court)\\b/i', $value) === 1;
        $hasZip = preg_match('/\\b\\d{5}(?:-\\d{4})?\\b/', $value) === 1;

        return ($hasNumber && ($hasStreetHint || $hasComma)) || $hasZip;
    }

    protected function detectRentalAgencyIntent(string $message): bool
    {
        $text = mb_strtolower($message);
        if (!(str_contains($text, 'rent') || str_contains($text, 'rental') || str_contains($text, 'agency') || str_contains($text, 'agencies'))) {
            return false;
        }
        return str_contains($text, 'where')
            || str_contains($text, 'near')
            || str_contains($text, 'suggest')
            || str_contains($text, 'find')
            || str_contains($text, 'recommend');
    }

    protected function inferToolIntent(string $message): ?string
    {
        $text = mb_strtolower($message);

        if ($this->detectRentalAgencyIntent($message)) {
            return 'find_rental_agencies';
        }

        if (str_contains($text, 'rent') || str_contains($text, 'rental') || str_contains($text, 'van') || str_contains($text, 'bus') || str_contains($text, 'coach') || str_contains($text, 'minivan') || str_contains($text, 'car')) {
            return 'estimate_rental_costs';
        }

        if ($this->detectPlaceIntent($message)) {
            return 'find_recommended_places';
        }

        if (str_contains($text, 'task') || str_contains($text, 'checklist') || str_contains($text, 'to do')) {
            return 'create_tasks';
        }

        if (str_contains($text, 'budget') || str_contains($text, 'cost') || str_contains($text, 'expense')) {
            return 'create_budget_items';
        }

        if (str_contains($text, 'participant') || str_contains($text, 'chaperone') || str_contains($text, 'driver')) {
            return 'add_participants';
        }

        if (str_contains($text, 'missing') || str_contains($text, 'complete')) {
            return 'set_missing_items';
        }

        if (str_contains($text, 'update') || str_contains($text, 'change') || str_contains($text, 'reschedule')) {
            return 'update_event_spine';
        }

        if (str_contains($text, 'plan section') || str_contains($text, 'outline')) {
            return 'update_plan_section';
        }

        return null;
    }

    protected function toolGuidanceMessage(string $tool): string
    {
        return match ($tool) {
            'find_recommended_places' => 'No tools were executed. Please provide a city, state, or ZIP code (or confirm the church address) so I can find nearby recommendations.',
            'find_rental_agencies' => 'No tools were executed. Please provide a pickup city/ZIP (or confirm the church address) so I can list nearby rental agencies.',
            'estimate_rental_costs' => 'No tools were executed. Please provide vehicle type (car/van/bus), passenger count, and dates so I can estimate costs.',
            'create_tasks' => 'No tools were executed. Please list the tasks you want created (e.g., title and due date if known).',
            'create_budget_items' => 'No tools were executed. Please share budget items with category, description, and estimated cost.',
            'add_participants' => 'No tools were executed. Please provide participant names, roles, and statuses.',
            'set_missing_items' => 'No tools were executed. Please list the missing items you want tracked.',
            'update_event_spine' => 'No tools were executed. Please specify which event fields to update (title, dates, location, status, etc.).',
            'update_plan_section' => 'No tools were executed. Please name the plan section and the details you want added.',
            default => 'No tools were executed. Please provide more detail so I can act.',
        };
    }

    protected function defaultMissingItems(string $eventType): array
    {
        $text = strtolower($eventType);
        $base = [
            'Confirm date/time with venue',
            'Finalize attendee list',
            'Collect permission slips',
            'Assign chaperones/staff',
            'Arrange transportation',
            'Emergency contact list ready',
        ];

        $sportsTournament = [
            'Confirm tournament format and rules',
            'Open team registration',
            'Finalize team roster list',
            'Publish match schedule',
            'Confirm field or court availability',
            'Assign referees and scorekeepers',
            'Prepare equipment and uniforms',
            'Set up hydration and first aid station',
            'Confirm check-in table and signage',
            'Prepare awards or recognition',
        ];

        if (
            str_contains($text, 'soccer')
            || str_contains($text, 'football')
            || str_contains($text, 'basketball')
            || str_contains($text, 'baseball')
            || str_contains($text, 'volleyball')
            || str_contains($text, 'tournament')
            || str_contains($text, 'torneo')
            || str_contains($text, 'league')
            || str_contains($text, 'match')
            || str_contains($text, 'game day')
        ) {
            return $sportsTournament;
        }

        return match ($eventType) {
            'camp' => array_merge($base, [
                'Campsite reservation confirmed',
                'Tent/cabin assignments',
                'Meal plan & supplies list',
                'First aid kit & medical forms',
                'Weather contingency plan',
            ]),
            'fundraiser' => array_merge($base, [
                'Fundraising goal defined',
                'Pricing & payment plan',
                'Promotion plan',
                'Cash handling plan',
            ]),
            'museum_trip' => array_merge($base, [
                'Tickets purchased or held',
                'Museum rules shared',
                'Docent/tour scheduled',
            ]),
            'sports_outing' => array_merge($base, [
                'Facility reservation',
                'Equipment checklist',
                'Waivers/insurance confirmed',
            ]),
            default => $base,
        };
    }

    protected function validateAndBackfillTasks(Event $event, EventPlan $plan): void
    {
        $templates = $this->taskTemplates->templatesForEventType($event->club_id, $event->event_type);
        $requiredTitles = $templates->isNotEmpty()
            ? $templates->pluck('title')->filter()->values()->all()
            : $this->defaultMissingItems($event->event_type);

        if (empty($requiredTitles)) {
            return;
        }

        $existingTasks = $event->tasks()->get(['id', 'title', 'status', 'checklist_json']);
        $existingByTitle = [];
        foreach ($existingTasks as $task) {
            $normalized = strtolower(trim((string) $task->title));
            if ($normalized !== '') {
                $existingByTitle[$normalized] = true;
            }
        }

        $createdAny = false;
        foreach ($requiredTitles as $title) {
            $normalized = strtolower(trim((string) $title));
            if ($normalized === '' || isset($existingByTitle[$normalized])) {
                continue;
            }

            EventTask::create([
                'event_id' => $event->id,
                'title' => $title,
                'status' => 'todo',
                'checklist_json' => [
                    'source' => 'ai_validation',
                    'task_key' => $this->taskKeyFromTitle($title),
                ],
            ]);
            $existingByTitle[$normalized] = true;
            $createdAny = true;
        }

        if ($createdAny) {
            $openTitles = $event->tasks()
                ->where('status', '!=', 'done')
                ->orderBy('id')
                ->pluck('title')
                ->values()
                ->all();
            $plan->missing_items_json = $openTitles;
            $plan->save();
        }
    }

    protected function taskKeyFromTitle(string $title): ?string
    {
        $normalized = strtolower($title);
        $mappings = [
            ['confirm date/time with venue', 'camp_reservation'],
            ['confirm date with venue', 'camp_reservation'],
            ['confirm venue', 'camp_reservation'],
            ['venue confirmation', 'camp_reservation'],
            ['collect permission slips', 'permission_slips'],
            ['permission slips', 'permission_slips'],
            ['permission slip', 'permission_slips'],
            ['finalize attendee list', 'finalize_attendee_list'],
            ['attendee list', 'finalize_attendee_list'],
            ['arrange transportation', 'transportation_plan'],
            ['transportation', 'transportation_plan'],
            ['emergency contact list', 'emergency_contacts'],
            ['emergency contacts', 'emergency_contacts'],
            ['assign chaperones', 'chaperone_assignments'],
            ['chaperones', 'chaperone_assignments'],
        ];
        foreach ($mappings as [$needle, $key]) {
            if (str_contains($normalized, $needle)) {
                return $key;
            }
        }

        return null;
    }

    protected function handlePendingAction(Event $event, EventPlan $plan, array $conversation, string $tool, string $message): ?array
    {
        $toolOutput = null;
        $assistantMessage = null;

        if ($tool === 'create_tasks') {
            $titles = $this->parseListItems($message);
            if ($titles) {
                $toolOutput = $this->handleCreateTasks($event, [
                    'event_id' => $event->id,
                    'tasks' => array_map(fn ($title) => ['title' => $title], $titles),
                ]);
                $assistantMessage = 'Tasks created from your follow-up details.';
            }
        } elseif ($tool === 'create_budget_items') {
            $items = $this->parseBudgetItems($message);
            if ($items) {
                $toolOutput = $this->handleCreateBudgetItems($event, [
                    'event_id' => $event->id,
                    'items' => $items,
                ]);
                $assistantMessage = 'Budget items created from your follow-up details.';
            }
        } elseif ($tool === 'add_participants') {
            $participants = $this->parseParticipants($message);
            if ($participants) {
                $toolOutput = $this->handleAddParticipants($event, [
                    'event_id' => $event->id,
                    'participants' => $participants,
                ]);
                $assistantMessage = 'Participants added from your follow-up details.';
            }
        } elseif ($tool === 'set_missing_items') {
            $items = $this->parseListItems($message);
            if ($items) {
                $toolOutput = $this->handleSetMissingItems($event, [
                    'event_id' => $event->id,
                    'missing_items' => $items,
                ]);
                $assistantMessage = 'Missing items updated from your follow-up details.';
            }
        } elseif ($tool === 'find_rental_agencies') {
            $locationHint = $this->extractLocationHint($message);
            $radiusKm = $this->extractRadiusKm($message);
            $toolOutput = $this->handleFindRentalAgencies($event, [
                'event_id' => $event->id,
                'location' => $locationHint ?: $message,
                'radius_km' => $radiusKm,
            ]);
            $count = $toolOutput['count'] ?? null;
            $assistantMessage = $count
                ? "I found {$count} rental agencies near the church address."
                : 'I could not find rental agencies. Please provide a city, state, or ZIP code.';
        } elseif ($tool === 'estimate_rental_costs') {
            $details = $this->extractRentalDetails($message, $event);
            if ($details) {
                $toolOutput = $this->handleEstimateRentalCosts($event, array_merge([
                    'event_id' => $event->id,
                ], $details));
                if (isset($toolOutput['error'])) {
                    $assistantMessage = 'I could not estimate rental costs. Please provide vehicle type, passenger count, and dates.';
                } else {
                    $assistantMessage = $this->buildRentalEstimateMessage($toolOutput, $details);
                }
            }
        } elseif ($tool === 'update_event_spine' || $tool === 'update_plan_section') {
            // Use model assistance to interpret the follow-up details.
            $systemPrompt = $this->buildSystemPrompt($event);
            $tools = $this->toolDefinitions();
            $forcedTool = $tool;
            $payload = $this->buildPayload(array_merge([
                ['role' => 'system', 'content' => $systemPrompt],
            ], $conversation, [
                ['role' => 'user', 'content' => $message],
            ]), $tools, $forcedTool);

            $response = $this->callAndLog($event, $event->creator ?? $event->creator()->first() ?? auth()->user(), $payload);
            $assistantMessage = $this->extractAssistantMessage($response) ?? 'Update processed.';
            $toolCalls = $this->extractToolCalls($response);
            if ($toolCalls) {
                foreach ($toolCalls as $call) {
                    $toolOutput = $this->executeToolCall($event, $call);
                }
            }
        }

        if (!$assistantMessage) {
            return null;
        }

        $conversation[] = [
            'role' => 'assistant',
            'content' => $assistantMessage,
            'at' => now()->toIso8601String(),
        ];

        $planJson = $plan->plan_json ?? ['sections' => []];
        unset($planJson['pending_action']);
        $plan->plan_json = $planJson;
        $plan->conversation_json = $conversation;
        $plan->last_generated_at = now();
        $plan->ai_summary = $assistantMessage;
        $plan->save();

        $event->load(['plan', 'tasks', 'budgetItems', 'participants', 'documents']);

        return [
            'assistant_message' => $assistantMessage,
            'event' => $event,
            'eventPlan' => $event->plan,
            'tasks' => $event->tasks,
            'budget_items' => $event->budgetItems,
            'participants' => $event->participants,
            'documents' => $event->documents,
            'missing_items' => $event->plan?->missing_items_json ?? [],
        ];
    }

    protected function parseListItems(string $message): array
    {
        $parts = preg_split('/\\r?\\n|;|,/', $message) ?: [];
        $items = [];
        foreach ($parts as $part) {
            $value = trim($part, " \t\n\r\0\x0B-•*");
            if ($value !== '') {
                $items[] = $value;
            }
        }
        return array_values(array_unique($items));
    }

    protected function buildRentalEstimateMessage(array $toolOutput, ?array $details = null): string
    {
        $daily = $toolOutput['daily_range'] ?? [];
        $total = $toolOutput['total_range'] ?? [];
        $days = max(1, (int) ($toolOutput['days'] ?? 1));
        $vehicleType = $toolOutput['vehicle_type'] ?? 'rental vehicle';
        $vehiclesCount = max(1, (int) ($toolOutput['vehicles_count'] ?? 1));
        $dailyLow = (float) ($daily[0] ?? 0);
        $dailyHigh = (float) ($daily[1] ?? 0);
        $totalLow = (float) ($total[0] ?? 0);
        $totalHigh = (float) ($total[1] ?? 0);
        $fleetDaily = $toolOutput['fleet_daily_range'] ?? [];
        $fleetDailyLow = (float) ($fleetDaily[0] ?? ($dailyLow * $vehiclesCount));
        $fleetDailyHigh = (float) ($fleetDaily[1] ?? ($dailyHigh * $vehiclesCount));

        $parts = [];
        $parts[] = "Estimated {$vehiclesCount} {$vehicleType} rental(s) for {$days} day(s).";
        $parts[] = "Per vehicle per day: {$this->formatUsd($dailyLow)} - {$this->formatUsd($dailyHigh)}.";
        $parts[] = "Fleet cost per day ({$vehiclesCount} vehicles): {$this->formatUsd($fleetDailyLow)} - {$this->formatUsd($fleetDailyHigh)}.";
        $parts[] = "Total rental for {$days} day(s): {$this->formatUsd($totalLow)} - {$this->formatUsd($totalHigh)}.";

        $gasEstimate = $toolOutput['gas_estimate'] ?? null;
        $gasPerVehicle = $toolOutput['gas_per_vehicle'] ?? null;
        $distanceText = $toolOutput['distance']['distance_text'] ?? null;
        if ($gasEstimate !== null) {
            $gasText = $this->formatUsd((float) $gasEstimate);
            $gasPerVehicleText = $gasPerVehicle !== null ? $this->formatUsd((float) $gasPerVehicle) : null;
            if ($distanceText && $gasPerVehicleText) {
                $parts[] = "Estimated gas per vehicle (round trip {$distanceText}): {$gasPerVehicleText}. Total gas ({$vehiclesCount} vehicles): {$gasText}.";
            } elseif ($distanceText) {
                $parts[] = "Estimated gas (round trip {$distanceText}): {$gasText}.";
            } elseif ($gasPerVehicleText) {
                $parts[] = "Estimated gas per vehicle: {$gasPerVehicleText}. Total gas ({$vehiclesCount} vehicles): {$gasText}.";
            } else {
                $parts[] = "Estimated gas: {$gasText}.";
            }
        }

        if (!empty($details['vehicles_count_inferred'])) {
            $parts[] = 'Vehicle count was inferred from passenger capacity; tell me if you want to adjust it.';
        }

        $sources = $toolOutput['sources'] ?? [];
        $liveSource = $sources['live_web_quotes'] ?? null;
        if (is_array($liveSource) && !empty($liveSource['found'])) {
            $checkedAt = $liveSource['checked_at'] ?? null;
            $quoteCount = (int) ($liveSource['quote_count'] ?? 0);
            $provider = strtoupper((string) ($liveSource['provider'] ?? 'web'));
            $parts[] = "Source (live web quotes): {$provider}" . ($quoteCount > 0 ? " ({$quoteCount} quote snippet(s))" : '') . ($checkedAt ? ", checked at {$checkedAt}." : '.');

            $quoteSources = $liveSource['sources'] ?? [];
            if (is_array($quoteSources) && !empty($quoteSources)) {
                $labels = [];
                foreach (array_slice($quoteSources, 0, 3) as $quoteSource) {
                    $name = trim((string) ($quoteSource['source_name'] ?? 'Source'));
                    $url = trim((string) ($quoteSource['url'] ?? ''));
                    $price = isset($quoteSource['daily_price_usd']) ? $this->formatUsd((float) $quoteSource['daily_price_usd']) : null;
                    $labels[] = $price
                        ? ($url !== '' ? "{$name} {$price} ({$url})" : "{$name} {$price}")
                        : ($url !== '' ? "{$name} ({$url})" : $name);
                }
                if (!empty($labels)) {
                    $parts[] = 'Live quote samples: ' . implode('; ', $labels) . '.';
                }
            }
        } elseif (is_array($liveSource) && !empty($liveSource['reason'])) {
            $parts[] = 'Live quote lookup unavailable: ' . $liveSource['reason'];
        }

        $heuristicSource = $sources['heuristic'] ?? null;
        if (is_array($heuristicSource)) {
            $checkedAt = $heuristicSource['checked_at'] ?? null;
            $method = $heuristicSource['method'] ?? null;
            $parts[] = 'Fallback source (pricing): ' . trim(($method ? $method . '. ' : '') . ($checkedAt ? "Checked at {$checkedAt}." : ''));
        }
        $providerSources = $sources['providers'] ?? [];
        if (is_array($providerSources) && !empty($providerSources)) {
            $providerLabels = [];
            foreach (array_slice($providerSources, 0, 3) as $provider) {
                $name = trim((string) ($provider['name'] ?? 'Rental provider'));
                $mapsUrl = trim((string) ($provider['maps_url'] ?? ''));
                $providerLabels[] = $mapsUrl !== '' ? "{$name} ({$mapsUrl})" : $name;
            }
            if (!empty($providerLabels)) {
                $parts[] = 'Provider sources: ' . implode('; ', $providerLabels) . '.';
            }
        }

        return implode(' ', $parts);
    }

    protected function formatUsd(float $amount): string
    {
        return '$' . number_format(round($amount, 2), 2, '.', ',');
    }

    protected function parseBudgetItems(string $message): array
    {
        $lines = $this->parseListItems($message);
        $items = [];
        foreach ($lines as $line) {
            $amount = $this->extractMoney($line);
            $items[] = [
                'category' => 'General',
                'description' => $line,
                'qty' => 1,
                'unit_cost' => $amount ?? 0,
            ];
        }
        return $items;
    }

    protected function parseParticipants(string $message): array
    {
        $lines = $this->parseListItems($message);
        $participants = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', preg_split('/\\s*-\\s*/', $line) ?: []);
            $name = $parts[0] ?? $line;
            $role = $parts[1] ?? 'guest';
            $status = $parts[2] ?? 'invited';
            if ($name !== '') {
                $participants[] = [
                    'participant_name' => $name,
                    'role' => $role,
                    'status' => $status,
                ];
            }
        }
        return $participants;
    }

    protected function extractMoney(string $text): ?float
    {
        if (preg_match('/\\$\\s*([0-9]+(?:\\.[0-9]+)?)/', $text, $matches)) {
            return (float) $matches[1];
        }
        if (preg_match('/\\b([0-9]+(?:\\.[0-9]+)?)\\b/', $text, $matches)) {
            return (float) $matches[1];
        }
        return null;
    }

    protected function withDebug(array $response): array
    {
        if (!config('ai.debug_return')) {
            return $response;
        }

        $response['ai_debug'] = [
            'request_log_id' => $this->lastLogId,
            'raw_response' => $this->lastRawResponse,
            'tool_debug' => $this->lastToolDebug,
            'intent_debug' => $this->lastIntentDebug,
        ];

        return $response;
    }

    protected function findLastPlaceIntent(array $conversation): ?string
    {
        $messages = array_reverse($conversation);
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }
            $content = (string) ($message['content'] ?? '');
            if ($content !== '' && $this->detectPlaceIntent($content)) {
                return $content;
            }
        }
        return null;
    }

    protected function callAndLog(Event $event, User $user, array $payload): array
    {
        $start = microtime(true);
        $log = AiRequestLog::create([
            'event_id' => $event->id,
            'club_id' => $event->club_id,
            'user_id' => $user->id,
            'provider' => config('ai.provider'),
            'model' => $payload['model'] ?? config('ai.model'),
            'request_json' => [
                'source' => 'planner_message',
                'endpoint' => rtrim(config('ai.base_url'), '/') . '/responses',
                'payload' => $payload,
            ],
            'status' => 'pending',
        ]);

        try {
            $response = $this->client->responses($payload);
            $latency = (int) round((microtime(true) - $start) * 1000);

            $usage = $response['usage'] ?? [];
            $inputTokens = $usage['input_tokens'] ?? null;
            $outputTokens = $usage['output_tokens'] ?? null;
            $totalTokens = $usage['total_tokens'] ?? null;

            $log->update([
                'response_json' => $response,
                'latency_ms' => $latency,
                'status' => 'success',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
            ]);

            $this->lastLogId = $log->id;
            $this->lastRawResponse = $response;

            if ($totalTokens) {
                $usageRow = AiUsageDaily::firstOrCreate([
                    'club_id' => $event->club_id,
                    'usage_date' => now()->toDateString(),
                ], [
                    'tokens_used' => 0,
                    'requests_count' => 0,
                ]);

                $usageRow->increment('tokens_used', $totalTokens);
                $usageRow->increment('requests_count');
            }

            return $response;
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            $log->update([
                'latency_ms' => $latency,
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);

            $this->lastLogId = $log->id;
            $this->lastRawResponse = null;

            throw $e;
        }
    }

    protected function extractAssistantMessage(array $response): ?string
    {
        $output = $response['output'] ?? [];
        foreach ($output as $item) {
            if (($item['type'] ?? null) === 'message' && ($item['role'] ?? null) === 'assistant') {
                $content = $item['content'] ?? [];
                if (is_string($content)) {
                    return $content;
                }
                if (is_array($content)) {
                    $textParts = array_map(function ($part) {
                        return is_array($part) ? ($part['text'] ?? '') : '';
                    }, $content);
                    return trim(implode('', $textParts)) ?: null;
                }
            }
        }

        return $response['output_text'] ?? null;
    }

    protected function extractToolCalls(array $response): array
    {
        $toolCalls = [];
        $output = $response['output'] ?? [];

        foreach ($output as $item) {
            if (($item['type'] ?? null) === 'tool_call') {
                $toolCalls[] = [
                    'name' => $item['name'] ?? null,
                    'arguments' => $item['arguments'] ?? null,
                    'id' => $item['id'] ?? ($item['call_id'] ?? null),
                ];
                continue;
            }

            if (($item['type'] ?? null) === 'message' && isset($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $contentItem) {
                    if (($contentItem['type'] ?? null) === 'tool_call') {
                        $toolCalls[] = [
                            'name' => $contentItem['name'] ?? null,
                            'arguments' => $contentItem['arguments'] ?? null,
                            'id' => $contentItem['id'] ?? ($contentItem['call_id'] ?? null),
                        ];
                    }
                }
            }
        }

        return $toolCalls;
    }

    protected function executeToolCall(Event $event, array $call): array
    {
        $name = $call['name'] ?? '';
        $rawArgs = $call['arguments'] ?? '{}';
        $args = is_string($rawArgs) ? json_decode($rawArgs, true) : (array) $rawArgs;

        $result = match ($name) {
            'update_event_spine' => $this->handleUpdateEventSpine($event, $args),
            'update_plan_section' => $this->handleUpdatePlanSection($event, $args),
            'create_tasks' => $this->handleCreateTasks($event, $args),
            'create_budget_items' => $this->handleCreateBudgetItems($event, $args),
            'set_missing_items' => $this->handleSetMissingItems($event, $args),
            'add_participants' => $this->handleAddParticipants($event, $args),
            'find_recommended_places' => $this->handleFindRecommendedPlaces($event, $args),
            'find_rental_agencies' => $this->handleFindRentalAgencies($event, $args),
            'estimate_rental_costs' => $this->handleEstimateRentalCosts($event, $args),
            default => ['error' => 'Unknown tool call: ' . $name],
        };

        return [
            'name' => $name,
            'result' => $result,
            'id' => $call['id'] ?? null,
        ];
    }

    protected function handleUpdateEventSpine(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'patch' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $allowed = [
            'title',
            'event_type',
            'start_at',
            'end_at',
            'timezone',
            'location_name',
            'location_address',
            'status',
            'budget_estimated_total',
            'budget_actual_total',
            'requires_approval',
            'risk_level',
        ];

        $patch = Arr::only($args['patch'], $allowed);
        $event->fill($patch);
        $event->save();

        return ['updated' => array_keys($patch)];
    }

    protected function handleUpdatePlanSection(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'section_name' => ['required', 'string'],
            'section_patch' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $plan = $event->plan ?? $this->initializePlan($event);
        $planJson = $plan->plan_json ?? ['sections' => []];
        $sections = $planJson['sections'] ?? [];
        $sectionName = $args['section_name'];
        $sectionPatch = $args['section_patch'];

        $found = false;
        foreach ($sections as &$section) {
            if (($section['name'] ?? null) === $sectionName) {
                $section = array_merge($section, $sectionPatch);
                $found = true;
                break;
            }
        }
        unset($section);

        if (!$found) {
            $sections[] = array_merge(['name' => $sectionName], $sectionPatch);
        }

        $planJson['sections'] = $sections;
        $plan->plan_json = $planJson;
        $plan->save();

        return ['section' => $sectionName, 'updated' => true];
    }

    protected function handleCreateTasks(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'tasks' => ['required', 'array'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'tasks.*.due_at' => ['nullable', 'date'],
            'tasks.*.status' => ['nullable', 'string'],
            'tasks.*.checklist_json' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $created = [];
        DB::transaction(function () use (&$created, $event, $args) {
            foreach ($args['tasks'] as $taskData) {
                $created[] = EventTask::create([
                    'event_id' => $event->id,
                    'title' => $taskData['title'],
                    'description' => $taskData['description'] ?? null,
                    'assigned_to_user_id' => $taskData['assigned_to_user_id'] ?? null,
                    'due_at' => $taskData['due_at'] ?? null,
                    'status' => $taskData['status'] ?? 'todo',
                    'checklist_json' => $taskData['checklist_json'] ?? null,
                ])->id;
            }
        });

        return ['created_task_ids' => $created];
    }

    protected function handleCreateBudgetItems(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'items' => ['required', 'array'],
            'items.*.category' => ['required', 'string', 'max:255'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['nullable', 'numeric'],
            'items.*.unit_cost' => ['nullable', 'numeric'],
            'items.*.funding_source' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $created = [];
        DB::transaction(function () use (&$created, $event, $args) {
            foreach ($args['items'] as $itemData) {
                $created[] = EventBudgetItem::create([
                    'event_id' => $event->id,
                    'category' => $itemData['category'],
                    'description' => $itemData['description'],
                    'qty' => $itemData['qty'] ?? 1,
                    'unit_cost' => $itemData['unit_cost'] ?? 0,
                    'funding_source' => $itemData['funding_source'] ?? null,
                    'notes' => $itemData['notes'] ?? null,
                ])->id;
            }
        });

        return ['created_budget_item_ids' => $created];
    }

    protected function handleSetMissingItems(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'missing_items' => ['required', 'array'],
            'missing_items.*' => ['string'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $plan = $event->plan ?? $this->initializePlan($event);
        $plan->missing_items_json = $args['missing_items'];
        $plan->save();

        return ['missing_items' => $args['missing_items']];
    }

    protected function handleAddParticipants(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'participants' => ['required', 'array'],
            'participants.*.member_id' => ['nullable', 'integer', 'exists:members,id'],
            'participants.*.participant_name' => ['required', 'string', 'max:255'],
            'participants.*.role' => ['required', 'string', 'max:255'],
            'participants.*.status' => ['required', 'string', 'max:255'],
            'participants.*.permission_received' => ['nullable', 'boolean'],
            'participants.*.medical_form_received' => ['nullable', 'boolean'],
            'participants.*.emergency_contact_json' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $created = [];
        DB::transaction(function () use (&$created, $event, $args) {
            foreach ($args['participants'] as $participant) {
                $created[] = EventParticipant::create([
                    'event_id' => $event->id,
                    'member_id' => $participant['member_id'] ?? null,
                    'participant_name' => $participant['participant_name'],
                    'role' => $participant['role'],
                    'status' => $participant['status'],
                    'permission_received' => $participant['permission_received'] ?? false,
                    'medical_form_received' => $participant['medical_form_received'] ?? false,
                    'emergency_contact_json' => $participant['emergency_contact_json'] ?? null,
                ])->id;
            }
        });

        return ['created_participant_ids' => $created];
    }

    protected function handleFindRecommendedPlaces(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'intent' => ['required', 'string'],
            'address' => ['nullable', 'string'],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:100'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:20'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $address = $args['address'] ?? $event->club?->church?->address;
        if (!$address) {
            return ['error' => 'Church address is missing for this club.'];
        }

        if (!config('places.google.api_key')) {
            return [
                'error' => 'Google Maps API key is missing. Please set GOOGLE_MAPS_API_KEY.',
                'debug' => config('ai.debug_return') ? [
                    'address' => $address,
                    'intent' => $args['intent'] ?? null,
                    'radius_km' => $args['radius_km'] ?? null,
                ] : null,
            ];
        }

        try {
            $places = $this->places->findRecommendedPlaces(
                $address,
                $args['intent'],
                $args['radius_km'] ?? null,
                $args['max_results'] ?? null,
                $args['min_rating'] ?? null,
            );
            Log::info('Places found', ['count' => count($places), 'address' => $address, 'intent' => $args['intent'] ?? null]);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'referer restrictions')) {
                $message = 'Google Maps API key is restricted by HTTP referrer. Use a server key restricted by IP addresses.';
            }
            return [
                'error' => $message,
                'details' => $e->getMessage(),
                'debug' => config('ai.debug_return') ? [
                    'address' => $address,
                    'intent' => $args['intent'] ?? null,
                    'radius_km' => $args['radius_km'] ?? null,
                ] : null,
            ];
        }

        $plan = $event->plan ?? $this->initializePlan($event);
        $planJson = $plan->plan_json ?? ['sections' => []];
        $sections = $planJson['sections'] ?? [];

        $sectionName = 'Recommendations';
        $items = array_map(function ($place) {
            $label = $place['name'] ?? 'Place';
            $detail = trim(($place['address'] ?? '') . ($place['rating'] ? " (Rating {$place['rating']})" : ''));
            return [
                'label' => $label,
                'detail' => $detail,
                'meta' => $place,
            ];
        }, $places);

        $found = false;
        foreach ($sections as &$section) {
            if (($section['name'] ?? null) === $sectionName) {
                $section['items'] = $items;
                $section['summary'] = 'Recommended places based on the church address.';
                $found = true;
                break;
            }
        }
        unset($section);

        if (!$found) {
            $sections[] = [
                'name' => $sectionName,
                'summary' => 'Recommended places based on the church address.',
                'items' => $items,
            ];
        }

        $planJson['sections'] = $sections;
        $plan->plan_json = $planJson;
        $plan->save();

        return [
            'address_used' => $address,
            'count' => count($places),
            'places' => $places,
            'debug' => config('ai.debug_return') ? [
                'address' => $address,
                'intent' => $args['intent'] ?? null,
                'radius_km' => $args['radius_km'] ?? null,
                'results_count' => count($places),
            ] : null,
        ];
    }

    protected function handleFindRentalAgencies(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'location' => ['nullable', 'string'],
            'vehicle_type' => ['nullable', 'string'],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:100'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:20'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $vehicleType = $args['vehicle_type'] ?? null;
        $intent = trim(($vehicleType ? "{$vehicleType} " : '') . 'rental');
        $location = $args['location'] ?? null;
        $address = $this->composeSearchAddress($event->club?->church?->address, $location);

        if (!$address) {
            return ['error' => 'Church address is missing for this club.'];
        }

        if (!config('places.google.api_key')) {
            return [
                'error' => 'Google Maps API key is missing. Please set GOOGLE_MAPS_API_KEY.',
                'debug' => config('ai.debug_return') ? [
                    'address' => $address,
                    'intent' => $intent,
                    'radius_km' => $args['radius_km'] ?? null,
                ] : null,
            ];
        }

        try {
            $places = $this->places->findRecommendedPlaces(
                $address,
                $intent,
                $args['radius_km'] ?? null,
                $args['max_results'] ?? null,
                $args['min_rating'] ?? null,
            );
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'referer restrictions')) {
                $message = 'Google Maps API key is restricted by HTTP referrer. Use a server key restricted by IP addresses.';
            }
            return [
                'error' => $message,
                'details' => $e->getMessage(),
                'debug' => config('ai.debug_return') ? [
                    'address' => $address,
                    'intent' => $intent,
                    'radius_km' => $args['radius_km'] ?? null,
                ] : null,
            ];
        }

        $plan = $event->plan ?? $this->initializePlan($event);
        $planJson = $plan->plan_json ?? ['sections' => []];
        $sections = $planJson['sections'] ?? [];

        $sectionName = 'Transportation Options';
        $items = array_map(function ($place) {
            $label = $place['name'] ?? 'Rental Agency';
            $detail = trim(($place['address'] ?? '') . ($place['rating'] ? " (Rating {$place['rating']})" : ''));
            return [
                'label' => $label,
                'detail' => $detail,
                'meta' => $place,
            ];
        }, $places);

        $found = false;
        foreach ($sections as &$section) {
            if (($section['name'] ?? null) === $sectionName) {
                $section['items'] = $items;
                $section['summary'] = 'Rental agencies near the church address.';
                $found = true;
                break;
            }
        }
        unset($section);

        if (!$found) {
            $sections[] = [
                'name' => $sectionName,
                'summary' => 'Rental agencies near the church address.',
                'items' => $items,
            ];
        }

        $planJson['sections'] = $sections;
        $plan->plan_json = $planJson;
        $plan->save();

        return [
            'address_used' => $address,
            'count' => count($places),
            'agencies' => $places,
            'sources' => [
                'provider' => 'Google Places API',
                'intent' => $intent,
                'checked_at' => now()->toIso8601String(),
            ],
            'debug' => config('ai.debug_return') ? [
                'address' => $address,
                'intent' => $intent,
                'radius_km' => $args['radius_km'] ?? null,
                'results_count' => count($places),
            ] : null,
        ];
    }

    protected function handleEstimateRentalCosts(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'vehicle_type' => ['required', 'string'],
            'vehicles_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:80'],
            'pickup_location' => ['nullable', 'string'],
            'destination' => ['nullable', 'string'],
            'create_budget_item' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $vehicleType = mb_strtolower($args['vehicle_type']);
        $vehiclesCount = (int) ($args['vehicles_count'] ?? 1);
        $vehiclesCount = max(1, $vehiclesCount);
        $days = (int) ($args['days'] ?? $this->calculateEventDays($event));
        $days = max(1, $days);
        $passengers = $args['passengers'] ?? null;
        $destination = $args['destination'] ?? null;

        $ranges = $this->rentalRateRanges();
        $range = $ranges[$vehicleType] ?? $ranges[$this->normalizeVehicleType($vehicleType)] ?? $ranges['car'];
        $heuristicDailyLow = (float) $range['low'];
        $heuristicDailyHigh = (float) $range['high'];
        $dailyLow = $heuristicDailyLow;
        $dailyHigh = $heuristicDailyHigh;

        $pickupAddress = $this->composeSearchAddress($event->club?->church?->address, $args['pickup_location'] ?? null);
        $liveQuoteData = null;
        if ($pickupAddress) {
            $liveQuoteData = $this->rentalQuotes->fetchDailyQuoteRange(
                $vehicleType,
                $pickupAddress,
                optional($event->start_at)->toDateString(),
                optional($event->end_at)->toDateString()
            );
            if (!empty($liveQuoteData['found'])) {
                $liveRange = $liveQuoteData['daily_range'] ?? null;
                if (is_array($liveRange) && count($liveRange) >= 2) {
                    $dailyLow = (float) $liveRange[0];
                    $dailyHigh = (float) $liveRange[1];
                }
            }
        }

        $totalLow = $dailyLow * $days * $vehiclesCount;
        $totalHigh = $dailyHigh * $days * $vehiclesCount;
        $fleetDailyLow = $dailyLow * $vehiclesCount;
        $fleetDailyHigh = $dailyHigh * $vehiclesCount;

        $suggested = [];
        if ($passengers !== null) {
            if ($passengers > 12 && !str_contains($vehicleType, 'bus')) {
                $suggested[] = 'Passenger count suggests a mini bus or coach.';
            } elseif ($passengers > 6 && !str_contains($vehicleType, 'van')) {
                $suggested[] = 'Consider a minivan or passenger van for this group size.';
            }
        }

        $gasEstimate = null;
        $gasPerVehicle = null;
        $distanceInfo = null;
        $origin = $event->club?->church?->address;
        if ($origin && $destination && config('places.google.api_key')) {
            $distanceInfo = $this->places->getDistanceEstimate($origin, $destination);
            if ($distanceInfo) {
                $roundTripMiles = ($distanceInfo['distance_miles'] ?? 0) * 2;
                $mpg = str_contains($vehicleType, 'bus') || str_contains($vehicleType, 'coach') ? 7 : (str_contains($vehicleType, 'van') ? 15 : 22);
                $gasPrice = 3.5;
                $gasPerVehicle = round(($roundTripMiles / max(1, $mpg)) * $gasPrice, 2);
                $gasEstimate = round($gasPerVehicle * $vehiclesCount, 2);
            }
        }

        $providerSources = $this->findRentalProviderSources(
            $event,
            $vehicleType,
            $args['pickup_location'] ?? null
        );

        $budgetItemId = null;
        $createBudgetItem = $args['create_budget_item'] ?? $this->getPlanPreference($event, 'auto_create_budget_item');
        if ($createBudgetItem) {
            $event->budgetItems()
                ->where('category', 'Transportation')
                ->whereIn(DB::raw('lower(description)'), ['vehicle rental', 'gas reimbursement'])
                ->where(function ($query) {
                    $query->whereNull('unit_cost')
                        ->orWhere('unit_cost', '<=', 0);
                })
                ->delete();

            $qty = $vehiclesCount;
            $unitCostHighForTrip = $dailyHigh * $days;
            $budgetNotes = [];
            $budgetNotes[] = 'Pricing mode: conservative high-end estimate.';
            $budgetNotes[] = "Vehicle count: {$vehiclesCount}.";
            $budgetNotes[] = "Budget line uses qty={$vehiclesCount} vehicles and unit cost={$this->formatUsd($unitCostHighForTrip)} per vehicle for {$days} day(s).";
            $budgetNotes[] = "Per vehicle per day: {$this->formatUsd($dailyLow)} - {$this->formatUsd($dailyHigh)}.";
            $budgetNotes[] = "Fleet per day ({$vehiclesCount} vehicles): {$this->formatUsd($fleetDailyLow)} - {$this->formatUsd($fleetDailyHigh)}.";
            $budgetNotes[] = "Rental total for {$days} day(s): {$this->formatUsd($totalLow)} - {$this->formatUsd($totalHigh)}.";
            if ($gasEstimate !== null) {
                $budgetNotes[] = "Gas estimate per vehicle: {$this->formatUsd((float) ($gasPerVehicle ?? 0))}.";
                $budgetNotes[] = "Gas estimate total ({$vehiclesCount} vehicles): {$this->formatUsd((float) $gasEstimate)}.";
                if (!empty($distanceInfo['distance_text'])) {
                    $budgetNotes[] = "Distance basis: round trip {$distanceInfo['distance_text']} from church.";
                }
            }
            $budgetNotesText = implode(' ', $budgetNotes);
            $existingItem = $event->budgetItems()
                ->where('category', 'Transportation')
                ->where(function ($query) use ($vehicleType) {
                    $query->where('description', 'ilike', '%rental%')
                        ->orWhere('description', 'ilike', '%' . $vehicleType . '%');
                })
                ->first();

            if ($existingItem) {
                $existingItem->update([
                    'qty' => $qty,
                    'unit_cost' => $unitCostHighForTrip,
                    'notes' => $budgetNotesText,
                ]);
                $budgetItemId = $existingItem->id;
            } else {
                $budgetItemId = EventBudgetItem::create([
                    'event_id' => $event->id,
                    'category' => 'Transportation',
                    'description' => ucfirst($vehicleType) . " rental estimate ({$vehiclesCount}x)",
                    'qty' => $qty,
                    'unit_cost' => $unitCostHighForTrip,
                    'notes' => $budgetNotesText,
                ])->id;
            }

            if ($gasEstimate !== null) {
                $gasItem = $event->budgetItems()
                    ->where('category', 'Transportation')
                    ->where('description', 'ilike', '%gas%')
                    ->first();
                if ($gasItem) {
                    $gasItem->update([
                        'qty' => 1,
                        'unit_cost' => $gasEstimate,
                        'notes' => "Per vehicle: {$this->formatUsd((float) ($gasPerVehicle ?? 0))}. Total for {$vehiclesCount} vehicle(s): {$this->formatUsd((float) $gasEstimate)}." . ($distanceInfo ? " Round trip distance: {$distanceInfo['distance_text']}." : ''),
                    ]);
                } else {
                    EventBudgetItem::create([
                        'event_id' => $event->id,
                        'category' => 'Transportation',
                        'description' => 'Gas reimbursement estimate',
                        'qty' => 1,
                        'unit_cost' => $gasEstimate,
                        'notes' => "Per vehicle: {$this->formatUsd((float) ($gasPerVehicle ?? 0))}. Total for {$vehiclesCount} vehicle(s): {$this->formatUsd((float) $gasEstimate)}." . ($distanceInfo ? " Round trip distance: {$distanceInfo['distance_text']}." : ''),
                    ]);
                }
            }
        }

        $plan = $event->plan ?? $this->initializePlan($event);
        $planJson = $plan->plan_json ?? ['sections' => []];
        $sections = $planJson['sections'] ?? [];
        $sectionName = 'Transportation Options';
        $detail = "Estimated {$vehiclesCount} vehicle(s) for {$days} day(s): \${$totalLow} - \${$totalHigh} (\${$dailyLow}-\${$dailyHigh}/day each). Fleet/day: \${$fleetDailyLow} - \${$fleetDailyHigh}.";
        if ($gasEstimate !== null) {
            $distanceText = $distanceInfo['distance_text'] ?? null;
            $detail .= $distanceText
                ? " Gas est: \${$gasEstimate} total (\${$gasPerVehicle}/vehicle, round trip {$distanceText})."
                : " Gas est: \${$gasEstimate} total (\${$gasPerVehicle}/vehicle).";
        }
        $latestEstimateItem = [
            'label' => ucfirst($vehicleType) . ' rental estimate',
            'detail' => $detail,
            'meta' => [
                'vehicle_type' => $vehicleType,
                'vehicles_count' => $vehiclesCount,
                'days' => $days,
                'passengers' => $passengers,
                'distance' => $distanceInfo,
                'gas_estimate' => $gasEstimate,
                'gas_per_vehicle' => $gasPerVehicle,
            ],
        ];

        $found = false;
        foreach ($sections as &$section) {
            if (($section['name'] ?? null) === $sectionName) {
                $existingItems = collect($section['items'] ?? [])
                    ->reject(function ($item) {
                        $label = mb_strtolower((string) ($item['label'] ?? ''));
                        return str_contains($label, 'rental estimate');
                    })
                    ->values()
                    ->all();
                $existingItems[] = $latestEstimateItem;
                $section['items'] = $existingItems;
                $section['summary'] = 'Rental cost estimates (heuristic).';
                $found = true;
                break;
            }
        }
        unset($section);

        if (!$found) {
            $sections[] = [
                'name' => $sectionName,
                'summary' => 'Rental cost estimates (heuristic).',
                'items' => [$latestEstimateItem],
            ];
        }

        $planJson['sections'] = $sections;
        $plan->plan_json = $planJson;
        $plan->save();

        return [
            'vehicle_type' => $vehicleType,
            'vehicles_count' => $vehiclesCount,
            'days' => $days,
            'passengers' => $passengers,
            'daily_range' => [$dailyLow, $dailyHigh],
            'total_range' => [$totalLow, $totalHigh],
            'fleet_daily_range' => [$fleetDailyLow, $fleetDailyHigh],
            'gas_estimate' => $gasEstimate,
            'gas_per_vehicle' => $gasPerVehicle,
            'distance' => $distanceInfo,
            'assumptions' => [
                'Estimates are heuristic and vary by season/location.',
                'Actual prices require rental provider quotes.',
            ],
            'suggestions' => $suggested,
            'budget_item_id' => $budgetItemId,
            'sources' => [
                'heuristic' => [
                    'method' => 'Internal rental heuristics table',
                    'rate_range_per_vehicle_per_day' => [
                        'low' => $heuristicDailyLow,
                        'high' => $heuristicDailyHigh,
                    ],
                    'vehicle_type' => $vehicleType,
                    'checked_at' => now()->toIso8601String(),
                ],
                'live_web_quotes' => $liveQuoteData,
                'providers' => $providerSources,
            ],
        ];
    }

    protected function findRentalProviderSources(Event $event, string $vehicleType, ?string $pickupLocation = null): array
    {
        if (!config('places.google.api_key')) {
            return [];
        }

        $address = $this->composeSearchAddress($event->club?->church?->address, $pickupLocation);
        if (!$address) {
            return [];
        }

        $intent = trim($vehicleType . ' rental agency');
        try {
            $providers = $this->places->findRecommendedPlaces($address, $intent, 25, 5, null);
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(function (array $provider) {
            return [
                'name' => $provider['name'] ?? null,
                'address' => $provider['address'] ?? null,
                'formatted_phone_number' => $provider['formatted_phone_number'] ?? null,
                'rating' => $provider['rating'] ?? null,
                'maps_url' => $provider['maps_url'] ?? null,
                'place_id' => $provider['place_id'] ?? null,
            ];
        }, array_values($providers));
    }

    protected function rentalRateRanges(): array
    {
        return [
            'car' => ['low' => 45, 'high' => 90],
            'suv' => ['low' => 70, 'high' => 130],
            'minivan' => ['low' => 90, 'high' => 160],
            '12-passenger van' => ['low' => 140, 'high' => 230],
            '15-passenger van' => ['low' => 170, 'high' => 280],
            'passenger van' => ['low' => 150, 'high' => 260],
            'van' => ['low' => 140, 'high' => 260],
            'shuttle bus' => ['low' => 260, 'high' => 520],
            'mini bus' => ['low' => 350, 'high' => 700],
            'school bus' => ['low' => 400, 'high' => 850],
            'bus' => ['low' => 500, 'high' => 1000],
            'coach' => ['low' => 900, 'high' => 1400],
        ];
    }

    protected function normalizeVehicleType(string $vehicleType): string
    {
        if (str_contains($vehicleType, '15')) {
            return '15-passenger van';
        }
        if (str_contains($vehicleType, '12')) {
            return '12-passenger van';
        }
        if (str_contains($vehicleType, 'school')) {
            return 'school bus';
        }
        if (str_contains($vehicleType, 'shuttle')) {
            return 'shuttle bus';
        }
        if (str_contains($vehicleType, 'coach')) {
            return 'coach';
        }
        if (str_contains($vehicleType, 'minivan')) {
            return 'minivan';
        }
        if (str_contains($vehicleType, 'bus')) {
            return 'bus';
        }
        if (str_contains($vehicleType, 'mini')) {
            return 'mini bus';
        }
        if (str_contains($vehicleType, 'passenger')) {
            return 'passenger van';
        }
        if (str_contains($vehicleType, 'van')) {
            return 'van';
        }
        if (str_contains($vehicleType, 'suv')) {
            return 'suv';
        }
        return 'car';
    }

    protected function calculateEventDays(Event $event): int
    {
        if ($event->start_at && $event->end_at) {
            $days = $event->start_at->diffInDays($event->end_at) + 1;
            return max(1, $days);
        }
        return 1;
    }

    protected function extractDestination(string $message): ?string
    {
        if (preg_match('/\\bto\\s+go\\s+to\\s+([^\\?\\.,]+)/i', $message, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/\\bgo(?:ing)?\\s+to\\s+([^\\?\\.,]+)/i', $message, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/\\bto\\s+([^\\?\\.,]+)/i', $message, $matches)) {
            $candidate = trim($matches[1]);
            if (preg_match('/^go\\s+to\\s+(.+)$/i', $candidate, $nested)) {
                return trim($nested[1]);
            }
            return $candidate;
        }
        return null;
    }

    protected function extractRentalDetails(string $message, Event $event): ?array
    {
        $text = mb_strtolower($message);
        $vehicleType = null;
        if (preg_match('/\\b15\\s*(passenger|people|person)\\b/', $text)) {
            $vehicleType = '15-passenger van';
        } elseif (preg_match('/\\b12\\s*(passenger|people|person)\\b/', $text)) {
            $vehicleType = '12-passenger van';
        } elseif (str_contains($text, 'school bus')) {
            $vehicleType = 'school bus';
        } elseif (str_contains($text, 'shuttle bus')) {
            $vehicleType = 'shuttle bus';
        }

        $vehicles = ['15-passenger van', '12-passenger van', 'school bus', 'shuttle bus', 'coach', 'bus', 'mini bus', 'minibus', 'passenger van', 'van', 'minivan', 'suv', 'car'];
        if (!$vehicleType) {
            foreach ($vehicles as $candidate) {
                if (str_contains($text, $candidate)) {
                    $vehicleType = $candidate;
                    break;
                }
            }
        }

        if (!$vehicleType) {
            return null;
        }

        $passengers = null;
        if (preg_match('/\\b(\\d{1,2})\\s*(people|passengers|kids|students|riders)\\b/', $text, $matches)) {
            $passengers = (int) $matches[1];
        }

        if (!$vehicleType && str_contains($text, 'van') && $passengers) {
            $vehicleType = $passengers >= 13 ? '15-passenger van' : '12-passenger van';
        }

        if ($vehicleType === 'van' && $passengers) {
            $vehicleType = $passengers >= 13 ? '15-passenger van' : '12-passenger van';
        }

        $days = null;
        if (preg_match('/\\b(\\d{1,2})\\s*(day|days|night|nights)\\b/', $text, $matches)) {
            $days = (int) $matches[1];
        }

        $vehiclesCount = null;
        $vehiclesCountInferred = false;
        if (preg_match('/\\b(\\d{1,2})\\s*(?:x\\s*)?(?:vehicles|vans|buses|cars|rentals)\\b/', $text, $matches)) {
            $vehiclesCount = (int) $matches[1];
        }
        if (!$vehiclesCount && preg_match('/\\b([1-5])\\b(?:\\s+\\w+){0,3}\\s+(vehicles|vans|buses|cars|rentals)\\b/i', $message, $matches)) {
            $vehiclesCount = (int) $matches[1];
        }
        if (!$vehiclesCount && preg_match('/\\b(\\d{1,2})\\s+\\d{1,2}\\s*(?:passenger|people|person)\\s+(vans|buses|cars|vehicles|rentals)\\b/i', $message, $matches)) {
            $vehiclesCount = (int) $matches[1];
        }
        if (!$vehiclesCount) {
            $wordCounts = [
                'one' => 1,
                'two' => 2,
                'three' => 3,
                'four' => 4,
                'five' => 5,
            ];
            foreach ($wordCounts as $word => $count) {
                $pattern = '/\\b' . $word . '\\b(?:\\s+\\w+){0,3}\\s+(vehicles|vans|buses|cars|rentals)\\b/i';
                if (preg_match($pattern, $message)) {
                    $vehiclesCount = $count;
                    break;
                }
            }
        }

        if (!$vehiclesCount && $passengers) {
            $capacity = 0;
            if (str_contains($vehicleType, '15')) {
                $capacity = 15;
            } elseif (str_contains($vehicleType, '12')) {
                $capacity = 12;
            } elseif (str_contains($vehicleType, 'bus') || str_contains($vehicleType, 'coach')) {
                $capacity = 40;
            } elseif (str_contains($vehicleType, 'van')) {
                $capacity = 12;
            } elseif (str_contains($vehicleType, 'minivan')) {
                $capacity = 7;
            }

            if ($capacity > 0) {
                $vehiclesCount = (int) ceil($passengers / $capacity);
                $vehiclesCountInferred = true;
            }
        }

        return [
            'vehicle_type' => $vehicleType,
            'vehicles_count' => $vehiclesCount,
            'vehicles_count_inferred' => $vehiclesCountInferred,
            'days' => $days ?? $this->calculateEventDays($event),
            'passengers' => $passengers,
            'pickup_location' => $this->extractLocationHint($message),
            'destination' => $this->extractDestination($message),
        ];
    }

    protected function toolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'name' => 'update_event_spine',
                'description' => 'Update top-level event details like title, dates, location, status, or risk level.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'patch' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'event_type' => ['type' => 'string'],
                                'start_at' => ['type' => 'string'],
                                'end_at' => ['type' => ['string', 'null']],
                                'timezone' => ['type' => 'string'],
                                'location_name' => ['type' => ['string', 'null']],
                                'location_address' => ['type' => ['string', 'null']],
                                'status' => ['type' => 'string'],
                                'budget_estimated_total' => ['type' => ['number', 'null']],
                                'budget_actual_total' => ['type' => ['number', 'null']],
                                'requires_approval' => ['type' => 'boolean'],
                                'risk_level' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                    'required' => ['event_id', 'patch'],
                ],
                'strict' => false,
            ],
            [
                'type' => 'function',
                'name' => 'update_plan_section',
                'description' => 'Update or create a section in the JSON plan outline.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'section_name' => ['type' => 'string'],
                        'section_patch' => ['type' => 'object'],
                    ],
                    'required' => ['event_id', 'section_name', 'section_patch'],
                ],
                'strict' => false,
            ],
            [
                'type' => 'function',
                'name' => 'create_tasks',
                'description' => 'Create new tasks for the event plan.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'tasks' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'description' => ['type' => ['string', 'null']],
                                    'assigned_to_user_id' => ['type' => ['integer', 'null']],
                                    'due_at' => ['type' => ['string', 'null']],
                                    'status' => ['type' => ['string', 'null']],
                                    'checklist_json' => [
                                        'type' => ['array', 'null'],
                                        'items' => [
                                            'type' => 'object',
                                            'additionalProperties' => false,
                                            'properties' => [
                                                'label' => ['type' => 'string'],
                                                'done' => ['type' => 'boolean'],
                                            ],
                                            'required' => ['label', 'done'],
                                        ],
                                    ],
                                ],
                                'required' => ['title'],
                            ],
                        ],
                    ],
                    'required' => ['event_id', 'tasks'],
                ],
                'strict' => false,
            ],
            [
                'type' => 'function',
                'name' => 'create_budget_items',
                'description' => 'Create budget line items for the event.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'category' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'qty' => ['type' => ['number', 'null']],
                                    'unit_cost' => ['type' => ['number', 'null']],
                                    'funding_source' => ['type' => ['string', 'null']],
                                    'notes' => ['type' => ['string', 'null']],
                                ],
                                'required' => ['category', 'description'],
                            ],
                        ],
                    ],
                    'required' => ['event_id', 'items'],
                ],
                'strict' => false,
            ],
            [
                'type' => 'function',
                'name' => 'set_missing_items',
                'description' => 'Replace the list of missing plan items.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'missing_items' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['event_id', 'missing_items'],
                ],
                'strict' => true,
            ],
            [
                'type' => 'function',
                'name' => 'add_participants',
                'description' => 'Add participants to the event roster.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'participants' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'member_id' => ['type' => ['integer', 'null']],
                                    'participant_name' => ['type' => 'string'],
                                    'role' => ['type' => 'string'],
                                    'status' => ['type' => 'string'],
                                    'permission_received' => ['type' => ['boolean', 'null']],
                                    'medical_form_received' => ['type' => ['boolean', 'null']],
                                    'emergency_contact_json' => [
                                        'type' => ['array', 'null'],
                                        'items' => [
                                            'type' => 'object',
                                            'additionalProperties' => false,
                                            'properties' => [
                                                'name' => ['type' => 'string'],
                                                'phone' => ['type' => 'string'],
                                                'relation' => ['type' => ['string', 'null']],
                                            ],
                                            'required' => ['name', 'phone'],
                                        ],
                                    ],
                                ],
                                'required' => ['participant_name', 'role', 'status'],
                            ],
                        ],
                    ],
                    'required' => ['event_id', 'participants'],
                ],
                'strict' => false,
            ],
            [
                'type' => 'function',
                'name' => 'find_recommended_places',
                'description' => 'Find recommended places near the church address for an event intent (camping, night out, etc.).',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'intent' => ['type' => 'string'],
                        'address' => ['type' => ['string', 'null']],
                        'radius_km' => ['type' => ['integer', 'null']],
                        'max_results' => ['type' => ['integer', 'null']],
                        'min_rating' => ['type' => ['number', 'null']],
                    ],
                    'required' => ['event_id', 'intent'],
                ],
                'strict' => false,
            ],
            [
                'type' => 'function',
                'name' => 'find_rental_agencies',
                'description' => 'Find rental agencies (car/van/bus) near the church address or pickup location.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'location' => ['type' => ['string', 'null']],
                        'vehicle_type' => ['type' => ['string', 'null']],
                        'radius_km' => ['type' => ['integer', 'null']],
                        'max_results' => ['type' => ['integer', 'null']],
                        'min_rating' => ['type' => ['number', 'null']],
                    ],
                    'required' => ['event_id'],
                ],
                'strict' => false,
            ],
            [
                'type' => 'function',
                'name' => 'estimate_rental_costs',
                'description' => 'Estimate rental costs using heuristic ranges (daily and total).',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'vehicle_type' => ['type' => 'string', 'description' => 'Example: car, minivan, 12-passenger van, 15-passenger van, school bus, coach'],
                        'vehicles_count' => ['type' => ['integer', 'null']],
                        'days' => ['type' => ['integer', 'null']],
                        'passengers' => ['type' => ['integer', 'null']],
                        'pickup_location' => ['type' => ['string', 'null']],
                        'destination' => ['type' => ['string', 'null']],
                        'create_budget_item' => ['type' => ['boolean', 'null']],
                    ],
                    'required' => ['event_id', 'vehicle_type'],
                ],
                'strict' => false,
            ],
        ];
    }

    protected function applyPlannerPreferences(EventPlan $plan, array $options): void
    {
        if (!array_key_exists('create_budget_item', $options)) {
            return;
        }

        $planJson = $plan->plan_json ?? ['sections' => []];
        $preferences = $planJson['preferences'] ?? [];
        $value = $options['create_budget_item'];
        if ($value !== null) {
            $preferences['auto_create_budget_item'] = (bool) $value;
        }
        $planJson['preferences'] = $preferences;
        $plan->plan_json = $planJson;
        $plan->save();
    }

    protected function getPlanPreference(Event $event, string $key): bool
    {
        $plan = $event->plan;
        $preferences = $plan?->plan_json['preferences'] ?? [];
        return (bool) ($preferences[$key] ?? false);
    }
}
