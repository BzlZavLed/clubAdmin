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
use App\Support\ClubHelper;
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
        $event->load(['plan', 'tasks.formResponse', 'budgetItems', 'participants', 'documents', 'club.church']);
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

        $agentRun = $this->beginAgentRun($plan, $message, $options);

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
                'agent' => $this->buildAgentMessageMeta($agentRun, [
                    'status' => isset($toolOutput['error']) ? 'waiting_for_input' : 'completed',
                    'completed' => !isset($toolOutput['error']),
                    'steps_taken' => 1,
                    'tool_calls_executed' => 1,
                    'last_tool' => 'find_recommended_places',
                ]),
                'at' => now()->toIso8601String(),
            ];

            $plan->conversation_json = $conversation;
            $plan->last_generated_at = now();
            $plan->ai_summary = $assistantMessage;
            $this->finalizeAgentRun($plan, $agentRun, $assistantMessage, [
                'status' => isset($toolOutput['error']) ? 'waiting_for_input' : 'completed',
                'completed' => !isset($toolOutput['error']),
                'steps_taken' => 1,
                'tool_calls_executed' => 1,
                'last_tool' => 'find_recommended_places',
                'waiting_for' => isset($toolOutput['error']) ? 'location_confirmation' : null,
            ]);
            $plan->save();
            return $this->withDebug($this->baseResponse($event));
        }

        if ($pendingAction && !$inferredTool) {
            $tool = $pendingAction['tool'] ?? null;
            if ($tool) {
                $handled = $this->handlePendingAction($event, $plan, $conversation, $tool, $message, $agentRun);
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
                    'agent' => $this->buildAgentMessageMeta($agentRun, [
                        'status' => isset($toolOutput['error']) ? 'waiting_for_input' : 'completed',
                        'completed' => !isset($toolOutput['error']),
                        'steps_taken' => 1,
                        'tool_calls_executed' => 1,
                        'last_tool' => 'estimate_rental_costs',
                    ]),
                    'at' => now()->toIso8601String(),
                ];
                $plan->conversation_json = $conversation;
                $plan->last_generated_at = now();
                $plan->ai_summary = $assistantMessage;
                $this->finalizeAgentRun($plan, $agentRun, $assistantMessage, [
                    'status' => isset($toolOutput['error']) ? 'waiting_for_input' : 'completed',
                    'completed' => !isset($toolOutput['error']),
                    'steps_taken' => 1,
                    'tool_calls_executed' => 1,
                    'last_tool' => 'estimate_rental_costs',
                    'waiting_for' => isset($toolOutput['error']) ? 'rental_details' : null,
                ]);
                $plan->save();
                return $this->withDebug($this->baseResponse($event));
            }
        }

        if ($this->isTaskReevaluationRequest($message)) {
            $toolOutput = $this->handleGenerateEventTypeTasks($event, [
                'event_id' => $event->id,
                'refresh_if_safe' => true,
            ]);
            $taskCount = (int) ($toolOutput['open_task_count'] ?? $toolOutput['task_count'] ?? 0);
            $assistantMessage = $taskCount > 0
                ? "I reevaluated the event tasks and refreshed the checklist. There are {$taskCount} open planning tasks right now."
                : 'I reevaluated the event tasks, but I could not produce a refreshed checklist.';

            $conversation[] = [
                'role' => 'assistant',
                'content' => $assistantMessage,
                'agent' => $this->buildAgentMessageMeta($agentRun, [
                    'status' => 'completed',
                    'completed' => true,
                    'steps_taken' => 1,
                    'tool_calls_executed' => 1,
                    'last_tool' => 'generate_event_type_tasks',
                ]),
                'at' => now()->toIso8601String(),
            ];
            $plan->conversation_json = $conversation;
            $plan->last_generated_at = now();
            $plan->ai_summary = $assistantMessage;
            $this->finalizeAgentRun($plan, $agentRun, $assistantMessage, [
                'status' => 'completed',
                'completed' => true,
                'steps_taken' => 1,
                'tool_calls_executed' => 1,
                'executed_tools' => ['generate_event_type_tasks'],
                'last_tool' => 'generate_event_type_tasks',
            ]);
            $plan->save();

            return $this->withDebug($this->baseResponse($event));
        }

        $explicitTaskTitle = $this->extractExplicitTaskTitle($message);
        if ($explicitTaskTitle) {
            $toolOutput = $this->handleCreateTasks($event, [
                'event_id' => $event->id,
                'tasks' => [[
                    'title' => $explicitTaskTitle,
                ]],
            ]);
            $assistantMessage = isset($toolOutput['error'])
                ? 'I could not create that task.'
                : "I created the task \"{$explicitTaskTitle}\".";

            $conversation[] = [
                'role' => 'assistant',
                'content' => $assistantMessage,
                'agent' => $this->buildAgentMessageMeta($agentRun, [
                    'status' => isset($toolOutput['error']) ? 'waiting_for_input' : 'completed',
                    'completed' => !isset($toolOutput['error']),
                    'steps_taken' => 1,
                    'tool_calls_executed' => 1,
                    'last_tool' => 'create_tasks',
                ]),
                'at' => now()->toIso8601String(),
            ];
            $plan->conversation_json = $conversation;
            $plan->last_generated_at = now();
            $plan->ai_summary = $assistantMessage;
            $this->finalizeAgentRun($plan, $agentRun, $assistantMessage, [
                'status' => isset($toolOutput['error']) ? 'waiting_for_input' : 'completed',
                'completed' => !isset($toolOutput['error']),
                'steps_taken' => 1,
                'tool_calls_executed' => 1,
                'executed_tools' => ['create_tasks'],
                'last_tool' => 'create_tasks',
            ]);
            $plan->save();

            return $this->withDebug($this->baseResponse($event));
        }

        if ($this->isTeamRosterQuery($message)) {
            $workspace = $this->handleGetEventWorkspace($event, [
                'event_id' => $event->id,
            ]);
            $assistantMessage = $this->buildTeamVerificationMessage($workspace);

            $conversation[] = [
                'role' => 'assistant',
                'content' => $assistantMessage,
                'agent' => $this->buildAgentMessageMeta($agentRun, [
                    'status' => 'completed',
                    'completed' => true,
                    'steps_taken' => 1,
                    'tool_calls_executed' => 1,
                    'last_tool' => 'get_event_workspace',
                ]),
                'at' => now()->toIso8601String(),
            ];
            $plan->conversation_json = $conversation;
            $plan->last_generated_at = now();
            $plan->ai_summary = $assistantMessage;
            $this->finalizeAgentRun($plan, $agentRun, $assistantMessage, [
                'status' => 'completed',
                'completed' => true,
                'steps_taken' => 1,
                'tool_calls_executed' => 1,
                'executed_tools' => ['get_event_workspace'],
                'last_tool' => 'get_event_workspace',
            ]);
            $plan->save();

            return $this->withDebug($this->baseResponse($event));
        }

        $referencedTask = $this->findReferencedTaskFromMessage($event, $message);
        if ($referencedTask && $this->messageImpliesTaskHasStoredData($message)) {
            $sync = $this->syncTournamentTeamsFromTask($event, $referencedTask);
            $assistantMessage = $this->buildReferencedTaskDataMessage($referencedTask, $sync);

            $conversation[] = [
                'role' => 'assistant',
                'content' => $assistantMessage,
                'agent' => $this->buildAgentMessageMeta($agentRun, [
                    'status' => 'completed',
                    'completed' => true,
                    'steps_taken' => 1,
                    'tool_calls_executed' => 1,
                    'last_tool' => 'sync_tournament_teams',
                ]),
                'at' => now()->toIso8601String(),
            ];
            $plan->conversation_json = $conversation;
            $plan->last_generated_at = now();
            $plan->ai_summary = $assistantMessage;
            $this->finalizeAgentRun($plan, $agentRun, $assistantMessage, [
                'status' => 'completed',
                'completed' => true,
                'steps_taken' => 1,
                'tool_calls_executed' => 1,
                'executed_tools' => ['sync_tournament_teams'],
                'last_tool' => 'sync_tournament_teams',
            ]);
            $plan->save();

            return $this->withDebug($this->baseResponse($event));
        }


        $payload = $this->buildPayload(array_merge([
            ['role' => 'system', 'content' => $systemPrompt],
        ], $conversation), $tools, $forcedTool, $forceNoTools ? 'none' : null);

        $latestPlaces = null;
        $stepsTaken = 0;
        $toolCallsExecuted = 0;
        $executedTools = [];
        $agentStatus = 'completed';
        $maxIterations = max(1, (int) config('ai.agent_max_iterations', 5));
        $maxToolCalls = max(1, (int) config('ai.agent_max_tool_calls', 8));

        for ($attempt = 0; $attempt < $maxIterations; $attempt++) {
            $stepsTaken++;
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
                    $agentStatus = 'waiting_for_input';
                }
                $plan->conversation_json = $conversation;
                $plan->last_generated_at = now();
                if ($assistantMessage) {
                    $plan->ai_summary = $assistantMessage;
                }
                $this->finalizeAgentRun($plan, $agentRun, $assistantMessage, [
                    'status' => $agentStatus,
                    'completed' => $agentStatus === 'completed',
                    'steps_taken' => $stepsTaken,
                    'tool_calls_executed' => $toolCallsExecuted,
                    'executed_tools' => array_values(array_unique($executedTools)),
                    'waiting_for' => $agentStatus === 'waiting_for_input' ? ($inferredTool ?? 'details') : null,
                ]);
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
                if ($toolCallsExecuted >= $maxToolCalls) {
                    $assistantMessage = 'I reached the planner tool budget for this turn. Review the latest updates and send a follow-up to continue.';
                    $agentStatus = 'paused';
                    break 2;
                }

                $toolOutput = $this->executeToolCall($event, $call);
                $toolOutputs[] = $toolOutput;
                $toolCallsExecuted++;
                $executedTools[] = $toolOutput['name'] ?? 'unknown';

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

        $event->load(['plan', 'tasks.formResponse', 'budgetItems', 'participants', 'documents']);

        if (!$assistantMessage && $lastToolSummary) {
            $assistantMessage = $lastToolSummary;
        }
        if (!$assistantMessage) {
            $assistantMessage = 'I’m here and ready. Tell me what you want to accomplish next (tasks, budget, participants, plan outline, or place recommendations).';
        }

        $agentMeta = $this->buildAgentMessageMeta($agentRun, [
            'status' => $agentStatus,
            'completed' => $agentStatus === 'completed',
            'steps_taken' => $stepsTaken,
            'tool_calls_executed' => $toolCallsExecuted,
            'executed_tools' => array_values(array_unique($executedTools)),
        ]);

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
                    'agent' => $agentMeta,
                    'at' => now()->toIso8601String(),
                ];
            } else {
                $conversation[$lastIndex]['places'] = $latestPlaces;
                $conversation[$lastIndex]['content'] = $assistantMessage;
                $conversation[$lastIndex]['agent'] = $agentMeta;
            }
            $plan->conversation_json = $conversation;
            $plan->last_generated_at = now();
            $plan->ai_summary = $assistantMessage;
        } else {
            $lastIndex = count($conversation) - 1;
            $lastMessage = $lastIndex >= 0 ? $conversation[$lastIndex] : null;
            $shouldAppend = !($lastMessage && ($lastMessage['role'] ?? null) === 'assistant')
                || !empty($lastMessage['tool_calls'])
                || empty($lastMessage['content'] ?? null);
            if ($shouldAppend) {
                $conversation[] = [
                    'role' => 'assistant',
                    'content' => $assistantMessage,
                    'agent' => $agentMeta,
                    'at' => now()->toIso8601String(),
                ];
            } else {
                $conversation[$lastIndex]['agent'] = $agentMeta;
            }
            $plan->conversation_json = $conversation;
            $plan->last_generated_at = now();
            $plan->ai_summary = $assistantMessage;
        }

        $this->finalizeAgentRun($plan, $agentRun, $assistantMessage, [
            'status' => $agentStatus,
            'completed' => $agentStatus === 'completed',
            'steps_taken' => $stepsTaken,
            'tool_calls_executed' => $toolCallsExecuted,
            'executed_tools' => array_values(array_unique($executedTools)),
        ]);
        $plan->save();

        return $this->withDebug($this->baseResponse($event));
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

    protected function beginAgentRun(EventPlan $plan, string $message, array $options = []): array
    {
        $run = [
            'run_id' => (string) str()->uuid(),
            'mode' => config('ai.agent_execution_mode', 'autonomous'),
            'enabled' => (bool) config('ai.agent_enabled', true),
            'started_at' => now()->toIso8601String(),
            'status' => 'running',
            'completed' => false,
            'goal' => $message,
            'steps_taken' => 0,
            'tool_calls_executed' => 0,
            'executed_tools' => [],
            'last_summary' => null,
            'preferences' => [
                'auto_create_budget_item' => (bool) ($options['create_budget_item'] ?? false),
            ],
        ];

        $planJson = $plan->plan_json ?? ['sections' => []];
        $planJson['agent'] = $run;
        $plan->plan_json = $planJson;

        return $run;
    }

    protected function finalizeAgentRun(EventPlan $plan, array $run, ?string $summary, array $patch = []): void
    {
        $agent = array_merge($run, $patch, [
            'last_summary' => $summary,
            'finished_at' => now()->toIso8601String(),
        ]);

        $planJson = $plan->plan_json ?? ['sections' => []];
        $planJson['agent'] = $agent;
        $plan->plan_json = $planJson;
    }

    protected function buildAgentMessageMeta(array $run, array $patch = []): array
    {
        return array_merge([
            'run_id' => $run['run_id'] ?? null,
            'mode' => $run['mode'] ?? config('ai.agent_execution_mode', 'autonomous'),
        ], $patch);
    }

    protected function baseResponse(Event $event): array
    {
        $event->load(['plan', 'tasks.formResponse', 'budgetItems', 'participants', 'documents']);

        return [
            'assistant_message' => $event->plan?->ai_summary,
            'event' => $event,
            'eventPlan' => $event->plan,
            'tasks' => $event->tasks,
            'budget_items' => $event->budgetItems,
            'participants' => $event->participants,
            'documents' => $event->documents,
            'missing_items' => $event->plan?->missing_items_json ?? [],
        ];
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

        return "You are the autonomous Event Planner Agent for club directors. Work like an operator, not a passive chatbot. Break the user request into the smallest useful steps, execute tools when you can make safe progress, and only stop to ask for input when a required fact is truly missing. Prefer taking several coordinated actions in one run when that materially advances the event. When you finish, summarize what you completed, what remains, and any assumptions.\n\nRules:\n- You may update event plans, tasks, budget items, participants, and recommendations.\n- You have scoped database tools for fresh event state, directory lookup, and safe updates to existing planner records.\n- Never delete records.\n- Treat tool arguments as untrusted; only pass validated, minimal data.\n- If the user asks for places or recommendations, you MUST call find_recommended_places.\n- If the user asks about renting vehicles (car/van/bus), call estimate_rental_costs and, when location is needed, call find_rental_agencies.\n- If the user asks for planning tasks for the event type, or wants a smarter checklist, prefer generate_event_type_tasks.\n- If preferences.auto_create_budget_item is true, set create_budget_item=true when estimating rental costs.\n- If you need more information, ask only for the smallest missing detail.\n- Keep the final answer concise and action-oriented.\n\nContext:\n" . json_encode($context);
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

        if ($this->isTeamRosterQuery($message)) {
            return 'get_event_workspace';
        }

        if ($this->detectRentalAgencyIntent($message)) {
            return 'find_rental_agencies';
        }

        if (str_contains($text, 'rent') || str_contains($text, 'rental') || str_contains($text, 'van') || str_contains($text, 'bus') || str_contains($text, 'coach') || str_contains($text, 'minivan') || str_contains($text, 'car')) {
            return 'estimate_rental_costs';
        }

        if ($this->detectPlaceIntent($message)) {
            return 'find_recommended_places';
        }

        if (
            str_contains($text, 'event type')
            || str_contains($text, 'recommended tasks')
            || str_contains($text, 'smart checklist')
            || str_contains($text, 'determine tasks')
            || str_contains($text, 'reevaluate task')
            || str_contains($text, 're-evaluate task')
            || str_contains($text, 'review tasks')
            || str_contains($text, 'reevaluate the tasks')
        ) {
            return 'generate_event_type_tasks';
        }

        if ($this->extractExplicitTaskTitle($message)) {
            return 'create_tasks';
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
            'get_event_workspace' => 'No tools were executed. I can refresh the current event workspace from the database whenever needed.',
            'find_club_directory' => 'No tools were executed. Please provide a member or staff name if you want me to look someone up in the club directory.',
            'find_recommended_places' => 'No tools were executed. Please provide a city, state, or ZIP code (or confirm the church address) so I can find nearby recommendations.',
            'find_rental_agencies' => 'No tools were executed. Please provide a pickup city/ZIP (or confirm the church address) so I can list nearby rental agencies.',
            'estimate_rental_costs' => 'No tools were executed. Please provide vehicle type (car/van/bus), passenger count, and dates so I can estimate costs.',
            'generate_event_type_tasks' => 'No tools were executed. Please confirm the event type or share a short description so I can generate a better task plan.',
            'create_tasks' => 'No tools were executed. Please list the tasks you want created (e.g., title and due date if known).',
            'update_tasks' => 'No tools were executed. Please tell me which task should change and what fields to update.',
            'create_budget_items' => 'No tools were executed. Please share budget items with category, description, and estimated cost.',
            'update_budget_items' => 'No tools were executed. Please tell me which budget line should change and the new values.',
            'add_participants' => 'No tools were executed. Please provide participant names, roles, and statuses.',
            'update_participants' => 'No tools were executed. Please tell me which participant should change and what to update.',
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

    protected function handlePendingAction(Event $event, EventPlan $plan, array $conversation, string $tool, string $message, array $agentRun): ?array
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
            'agent' => $this->buildAgentMessageMeta($agentRun, [
                'status' => 'completed',
                'completed' => true,
                'steps_taken' => 1,
                'tool_calls_executed' => $toolOutput ? 1 : 0,
                'last_tool' => $tool,
            ]),
            'at' => now()->toIso8601String(),
        ];

        $planJson = $plan->plan_json ?? ['sections' => []];
        unset($planJson['pending_action']);
        $plan->plan_json = $planJson;
        $plan->conversation_json = $conversation;
        $plan->last_generated_at = now();
        $plan->ai_summary = $assistantMessage;
        $this->finalizeAgentRun($plan, $agentRun, $assistantMessage, [
            'status' => 'completed',
            'completed' => true,
            'steps_taken' => 1,
            'tool_calls_executed' => $toolOutput ? 1 : 0,
            'executed_tools' => $toolOutput ? [$tool] : [],
            'last_tool' => $tool,
        ]);
        $plan->save();

        return $this->baseResponse($event);
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

    protected function isTaskReevaluationRequest(string $message): bool
    {
        $text = mb_strtolower($message);

        return (str_contains($text, 'reevaluate') || str_contains($text, 're-evaluate') || str_contains($text, 'review') || str_contains($text, 'regenerate') || str_contains($text, 'refresh'))
            && (str_contains($text, 'task') || str_contains($text, 'checklist'));
    }

    protected function isTeamRosterQuery(string $message): bool
    {
        $text = mb_strtolower($message);

        $teamSignals = str_contains($text, 'team')
            || str_contains($text, 'teams')
            || str_contains($text, 'roster')
            || str_contains($text, 'team members');

        $actionSignals = str_contains($text, 'verify')
            || str_contains($text, 'query')
            || str_contains($text, 'check')
            || str_contains($text, 'show')
            || str_contains($text, 'list');

        return $teamSignals && $actionSignals;
    }

    protected function extractExplicitTaskTitle(string $message): ?string
    {
        $value = trim($message);

        if (preg_match('/^(?:(?:can|could|would|will)\s+you\s+|please\s+)?(?:create|add)\s+(?:a\s+)?task\s+(?:to\s+)?(.+)$/i', $value, $matches)) {
            $title = trim($matches[1], " \t\n\r\0\x0B?.!");
            return $title !== '' ? ucfirst($title) : null;
        }

        return null;
    }

    protected function buildTeamVerificationMessage(array $workspace): string
    {
        $normalizedTeams = collect(data_get($workspace, 'tournament.teams', []));
        $participants = collect($workspace['participants'] ?? []);
        $tasks = collect($workspace['tasks'] ?? []);
        $teamTaskWithData = $tasks->first(function ($task) {
            $title = mb_strtolower((string) ($task['title'] ?? ''));
            $responseData = $task['form_response_data'] ?? null;
            $hasRows = is_array($responseData['rows'] ?? null) && !empty($responseData['rows']);
            $hasObject = is_array($responseData) && !empty($responseData);

            return (str_contains($title, 'team') || str_contains($title, 'roster')) && ($hasRows || $hasObject);
        });

        $teamTasks = $tasks->filter(function ($task) {
            $title = mb_strtolower((string) ($task['title'] ?? ''));
            return str_contains($title, 'team') || str_contains($title, 'roster');
        })->values();

        $participantCount = $participants->count();
        if ($participantCount > 0) {
            $roles = $participants->pluck('role')->filter()->unique()->values()->all();
            $rolesText = !empty($roles) ? ' Roles on file: ' . implode(', ', $roles) . '.' : '';

            return "I checked the event data in the database. This event currently has {$participantCount} participant record(s)." . $rolesText;
        }

        if ($normalizedTeams->isNotEmpty()) {
            $teamNames = $normalizedTeams->pluck('name')->filter()->take(4)->implode('; ');
            return "I checked the normalized tournament data in the database. I found {$normalizedTeams->count()} team(s): {$teamNames}.";
        }

        if ($teamTaskWithData) {
            $referencedTask = (object) $teamTaskWithData;
            return $this->buildReferencedTaskDataMessage($referencedTask);
        }

        if ($teamTasks->isNotEmpty()) {
            $titles = $teamTasks->pluck('title')->take(3)->implode('; ');
            return "I checked the event data in the database. I do not see participant roster records yet, but I found team-related planning tasks: {$titles}.";
        }

        return 'I checked the event data in the database. I do not see participant roster records or team-specific task entries yet for this tournament.';
    }

    protected function messageImpliesTaskHasStoredData(string $message): bool
    {
        $text = mb_strtolower($message);

        return str_contains($text, 'already defined')
            || str_contains($text, 'already has')
            || str_contains($text, 'has the teams')
            || str_contains($text, 'teams already')
            || str_contains($text, 'defined there')
            || str_contains($text, 'stored there');
    }

    protected function findReferencedTaskFromMessage(Event $event, string $message): ?object
    {
        $messageText = mb_strtolower($message);
        $tasks = $event->tasks;

        foreach ($tasks as $task) {
            $title = trim((string) $task->title);
            if ($title === '') {
                continue;
            }

            if (str_contains($messageText, mb_strtolower($title))) {
                return $task;
            }
        }

        return $tasks->first(function ($task) use ($messageText) {
            $title = mb_strtolower((string) $task->title);
            $words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]+/i', ' ', $title) ?? '') ?: [];
            $words = array_values(array_filter($words, fn ($word) => strlen($word) >= 4));

            if (count($words) < 2) {
                return false;
            }

            $matches = 0;
            foreach ($words as $word) {
                if (str_contains($messageText, $word)) {
                    $matches++;
                }
            }

            return $matches >= min(3, count($words));
        });
    }

    protected function buildReferencedTaskDataMessage(object $task, ?array $sync = null): string
    {
        $title = (string) ($task->title ?? 'Selected task');
        $responseData = data_get($task, 'formResponse.data_json')
            ?? data_get($task, 'form_response.data_json')
            ?? data_get($task, 'form_response_data')
            ?? [];

        if (is_array($responseData['rows'] ?? null) && !empty($responseData['rows'])) {
            $rows = collect($responseData['rows']);
            $first = (array) ($rows->first() ?? []);
            $keys = array_values(array_filter(array_keys($first), fn ($key) => $key !== '_row_id'));
            $keysText = !empty($keys) ? ' Fields captured: ' . implode(', ', array_slice($keys, 0, 5)) . '.' : '';
            $syncText = $sync && !empty($sync['teams_count'])
                ? " I normalized {$sync['teams_count']} team(s) into the tournament workspace. " . $this->describeNormalizedTeams($sync['teams'] ?? [])
                : '';

            return "I checked the task \"{$title}\" and it does contain stored form records. I found {$rows->count()} row(s) in that task." . $keysText . $syncText;
        }

        if (is_array($responseData) && !empty($responseData)) {
            $keys = array_values(array_keys($responseData));
            $syncText = $sync && !empty($sync['teams_count'])
                ? " I normalized {$sync['teams_count']} team(s) into the tournament workspace. " . $this->describeNormalizedTeams($sync['teams'] ?? [])
                : '';
            return "I checked the task \"{$title}\" and it contains stored form data. Captured fields: " . implode(', ', array_slice($keys, 0, 6)) . '.' . $syncText;
        }

        return "I checked the task \"{$title}\", but I do not see stored form data on it yet.";
    }

    protected function syncTournamentTeamsFromTask(Event $event, object $task): array
    {
        $responseData = data_get($task, 'formResponse.data_json')
            ?? data_get($task, 'form_response.data_json')
            ?? data_get($task, 'form_response_data')
            ?? [];

        $teams = $this->extractTournamentTeamsFromTaskData($responseData);
        if (empty($teams)) {
            return [
                'teams_count' => 0,
                'teams' => [],
            ];
        }

        $plan = $event->plan ?? $this->initializePlan($event);
        $planJson = $plan->plan_json ?? ['sections' => []];
        $tournament = $planJson['tournament'] ?? [];
        $existingTeams = collect($tournament['teams'] ?? [])
            ->keyBy(fn ($team) => mb_strtolower(trim((string) ($team['name'] ?? ''))));

        foreach ($teams as $team) {
            $key = mb_strtolower(trim((string) ($team['name'] ?? '')));
            if ($key === '') {
                continue;
            }

            $previous = $existingTeams->get($key, []);
            $existingTeams->put($key, array_merge($previous, $team, [
                'source_task_id' => $task->id ?? data_get($task, 'id'),
                'source_task_title' => $task->title ?? data_get($task, 'title'),
                'synced_at' => now()->toIso8601String(),
            ]));
        }

        $tournament['teams'] = $existingTeams->values()->all();
        $tournament['last_team_sync_at'] = now()->toIso8601String();
        $planJson['tournament'] = $tournament;
        $plan->plan_json = $planJson;
        $plan->save();

        return [
            'teams_count' => count($teams),
            'teams' => $teams,
        ];
    }

    protected function extractTournamentTeamsFromTaskData(array $responseData): array
    {
        $rows = [];
        if (is_array($responseData['rows'] ?? null) && !empty($responseData['rows'])) {
            $rows = $responseData['rows'];
        } elseif (!empty($responseData)) {
            $rows = [$responseData];
        }

        $teams = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $teamName = $this->firstNonEmptyValue($row, [
                'team_name', 'team', 'name', 'club_name', 'group_name',
            ]);
            if (!$teamName) {
                continue;
            }

            $membersRaw = $this->firstNonEmptyValue($row, [
                'members', 'team_members', 'players', 'participant_names', 'player_names',
            ]);
            $members = $this->normalizeTeamMembers($membersRaw);

            $teams[] = array_filter([
                'name' => $teamName,
                'fee_status' => $this->firstNonEmptyValue($row, ['fee_status', 'fees_status', 'payment_status', 'status']),
                'fee_amount' => $this->firstNonEmptyValue($row, ['fee_amount', 'fees', 'registration_fee', 'amount_paid', 'entry_fee_paid']),
                'coach' => $this->firstNonEmptyValue($row, ['coach', 'manager', 'leader', 'captain_name']),
                'captain' => $this->firstNonEmptyValue($row, ['captain_name', 'captain', 'team_captain']),
                'members' => $members,
                'members_count' => count($members),
            ], function ($value) {
                if (is_array($value)) {
                    return !empty($value);
                }

                return $value !== null && $value !== '';
            });
        }

        return array_values($teams);
    }

    protected function firstNonEmptyValue(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if ($value === null) {
                continue;
            }

            return $value;
        }

        return null;
    }

    protected function normalizeTeamMembers(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(function ($item) {
                return is_scalar($item) ? trim((string) $item) : null;
            }, $value)));
        }

        if (is_string($value)) {
            $parts = preg_split('/\r?\n|,|;|\|/', $value) ?: [];
            return array_values(array_filter(array_map(fn ($part) => trim($part), $parts)));
        }

        return [];
    }

    protected function describeNormalizedTeams(array $teams): string
    {
        if (empty($teams)) {
            return '';
        }

        $labels = [];
        foreach (array_slice($teams, 0, 4) as $team) {
            $name = trim((string) ($team['name'] ?? 'Unnamed team'));
            $captain = trim((string) ($team['captain'] ?? $team['coach'] ?? ''));
            $membersCount = (int) ($team['members_count'] ?? 0);
            $fee = $team['fee_amount'] ?? null;

            $parts = [$name];
            if ($captain !== '') {
                $parts[] = "captain {$captain}";
            }
            if ($membersCount > 0) {
                $parts[] = "{$membersCount} player(s)";
            }
            if ($fee !== null && $fee !== '') {
                $parts[] = "fee {$fee}";
            }

            $labels[] = implode(', ', $parts);
        }

        return 'Teams: ' . implode('; ', $labels) . '.';
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
            'get_event_workspace' => $this->handleGetEventWorkspace($event, $args),
            'find_club_directory' => $this->handleFindClubDirectory($event, $args),
            'update_event_spine' => $this->handleUpdateEventSpine($event, $args),
            'update_plan_section' => $this->handleUpdatePlanSection($event, $args),
            'create_tasks' => $this->handleCreateTasks($event, $args),
            'generate_event_type_tasks' => $this->handleGenerateEventTypeTasks($event, $args),
            'update_tasks' => $this->handleUpdateTasks($event, $args),
            'create_budget_items' => $this->handleCreateBudgetItems($event, $args),
            'update_budget_items' => $this->handleUpdateBudgetItems($event, $args),
            'set_missing_items' => $this->handleSetMissingItems($event, $args),
            'add_participants' => $this->handleAddParticipants($event, $args),
            'update_participants' => $this->handleUpdateParticipants($event, $args),
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

    protected function handleGetEventWorkspace(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $event->load(['plan', 'tasks.formResponse', 'budgetItems', 'participants', 'documents', 'club.church']);

        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'start_at' => optional($event->start_at)->toIso8601String(),
                'end_at' => optional($event->end_at)->toIso8601String(),
                'location_name' => $event->location_name,
                'location_address' => $event->location_address,
            ],
            'plan_sections' => $event->plan?->plan_json['sections'] ?? [],
            'tournament' => $event->plan?->plan_json['tournament'] ?? null,
            'missing_items' => $event->plan?->missing_items_json ?? [],
            'tasks' => $event->tasks->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'due_at' => optional($task->due_at)->toIso8601String(),
                'assigned_to_user_id' => $task->assigned_to_user_id,
                'form_response_data' => $task->formResponse?->data_json,
            ])->values()->all(),
            'budget_items' => $event->budgetItems->map(fn ($item) => [
                'id' => $item->id,
                'category' => $item->category,
                'description' => $item->description,
                'qty' => $item->qty,
                'unit_cost' => $item->unit_cost,
                'total' => $item->total,
                'funding_source' => $item->funding_source,
            ])->values()->all(),
            'participants' => $event->participants->map(fn ($participant) => [
                'id' => $participant->id,
                'member_id' => $participant->member_id,
                'participant_name' => $participant->participant_name,
                'role' => $participant->role,
                'status' => $participant->status,
                'permission_received' => $participant->permission_received,
                'medical_form_received' => $participant->medical_form_received,
            ])->values()->all(),
        ];
    }

    protected function handleFindClubDirectory(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'query' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $query = mb_strtolower(trim((string) ($args['query'] ?? '')));
        $limit = (int) ($args['limit'] ?? 12);

        $members = ClubHelper::membersOfClub($event->club_id)
            ->filter(function ($member) use ($query) {
                if ($query === '') {
                    return true;
                }

                return str_contains(mb_strtolower((string) ($member['applicant_name'] ?? '')), $query);
            })
            ->take($limit)
            ->map(fn ($member) => [
                'member_id' => $member['member_id'] ?? null,
                'name' => $member['applicant_name'] ?? null,
                'class_id' => $member['class_id'] ?? null,
                'member_type' => $member['member_type'] ?? null,
            ])
            ->values()
            ->all();

        $staff = ClubHelper::staffOfClub($event->club_id)
            ->filter(function ($staffRow) use ($query) {
                $name = $staffRow->user?->name ?? '';
                if ($query === '') {
                    return true;
                }

                return str_contains(mb_strtolower($name), $query);
            })
            ->take($limit)
            ->map(fn ($staffRow) => [
                'staff_id' => $staffRow->id,
                'user_id' => $staffRow->user_id,
                'name' => $staffRow->user?->name,
                'email' => $staffRow->user?->email,
                'assigned_class' => $staffRow->assigned_class,
                'status' => $staffRow->status,
                'type' => $staffRow->type,
            ])
            ->values()
            ->all();

        return [
            'query' => $args['query'] ?? null,
            'members' => $members,
            'staff' => $staff,
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

    protected function handleGenerateEventTypeTasks(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'refresh_if_safe' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        if (!empty($args['refresh_if_safe'])) {
            $this->taskTemplates->reseedEventTasksIfSafe($event);
            $event->refresh();
        }

        $tasks = $this->taskTemplates->seedEventTasks($event);
        $event->load('tasks');

        $openTitles = $event->tasks()
            ->where('status', '!=', 'done')
            ->orderBy('id')
            ->pluck('title')
            ->values()
            ->all();

        $plan = $event->plan ?? $this->initializePlan($event);
        $plan->missing_items_json = $openTitles;
        $plan->save();

        return [
            'event_type' => $event->event_type,
            'task_count' => count($tasks),
            'task_titles' => collect($tasks)->map(fn ($task) => $task->title)->values()->all(),
            'open_task_count' => count($openTitles),
            'open_task_titles' => $openTitles,
        ];
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

        return [
            'created_task_ids' => $created,
            'created_count' => count($created),
        ];
    }

    protected function handleUpdateTasks(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'updates' => ['required', 'array'],
            'updates.*.task_id' => ['required', 'integer', 'exists:event_tasks,id'],
            'updates.*.patch' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $updated = [];
        $allowed = ['title', 'description', 'assigned_to_user_id', 'due_at', 'status', 'checklist_json'];

        DB::transaction(function () use (&$updated, $event, $args, $allowed) {
            foreach ($args['updates'] as $update) {
                $task = $event->tasks()->whereKey($update['task_id'])->first();
                if (!$task) {
                    continue;
                }

                $patch = Arr::only((array) $update['patch'], $allowed);
                if (array_key_exists('assigned_to_user_id', $patch) && $patch['assigned_to_user_id']) {
                    $userExists = User::query()->whereKey($patch['assigned_to_user_id'])->exists();
                    if (!$userExists) {
                        unset($patch['assigned_to_user_id']);
                    }
                }

                $task->fill($patch);
                $task->save();

                $updated[] = [
                    'task_id' => $task->id,
                    'updated_fields' => array_keys($patch),
                ];
            }
        });

        return [
            'updated_count' => count($updated),
            'updated_tasks' => $updated,
        ];
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

        return [
            'created_budget_item_ids' => $created,
            'created_count' => count($created),
        ];
    }

    protected function handleUpdateBudgetItems(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'updates' => ['required', 'array'],
            'updates.*.budget_item_id' => ['required', 'integer', 'exists:event_budget_items,id'],
            'updates.*.patch' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $allowed = ['category', 'description', 'qty', 'unit_cost', 'funding_source', 'expense_date', 'notes'];
        $updated = [];

        DB::transaction(function () use (&$updated, $event, $args, $allowed) {
            foreach ($args['updates'] as $update) {
                $item = $event->budgetItems()->whereKey($update['budget_item_id'])->first();
                if (!$item) {
                    continue;
                }

                $patch = Arr::only((array) $update['patch'], $allowed);
                $item->fill($patch);
                $item->save();

                $updated[] = [
                    'budget_item_id' => $item->id,
                    'updated_fields' => array_keys($patch),
                ];
            }
        });

        return [
            'updated_count' => count($updated),
            'updated_budget_items' => $updated,
        ];
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

        return [
            'created_participant_ids' => $created,
            'created_count' => count($created),
        ];
    }

    protected function handleUpdateParticipants(Event $event, array $args): array
    {
        $validator = Validator::make($args, [
            'event_id' => ['required', 'integer'],
            'updates' => ['required', 'array'],
            'updates.*.participant_id' => ['required', 'integer', 'exists:event_participants,id'],
            'updates.*.patch' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->toArray()];
        }

        if ((int) $args['event_id'] !== (int) $event->id) {
            return ['error' => 'Event mismatch'];
        }

        $allowed = [
            'member_id',
            'participant_name',
            'role',
            'status',
            'permission_received',
            'medical_form_received',
            'emergency_contact_json',
        ];
        $updated = [];

        DB::transaction(function () use (&$updated, $event, $args, $allowed) {
            foreach ($args['updates'] as $update) {
                $participant = $event->participants()->whereKey($update['participant_id'])->first();
                if (!$participant) {
                    continue;
                }

                $patch = Arr::only((array) $update['patch'], $allowed);
                $participant->fill($patch);
                $participant->save();

                $updated[] = [
                    'participant_id' => $participant->id,
                    'updated_fields' => array_keys($patch),
                ];
            }
        });

        return [
            'updated_count' => count($updated),
            'updated_participants' => $updated,
        ];
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
                'name' => 'get_event_workspace',
                'description' => 'Fetch the latest event planner workspace state directly from the database.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                    ],
                    'required' => ['event_id'],
                ],
                'strict' => true,
            ],
            [
                'type' => 'function',
                'name' => 'find_club_directory',
                'description' => 'Search club members and staff from the database for assignment or roster work.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'query' => ['type' => ['string', 'null']],
                        'limit' => ['type' => ['integer', 'null']],
                    ],
                    'required' => ['event_id'],
                ],
                'strict' => false,
            ],
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
                'name' => 'update_tasks',
                'description' => 'Update existing tasks in the event planner database.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'updates' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'task_id' => ['type' => 'integer'],
                                    'patch' => ['type' => 'object'],
                                ],
                                'required' => ['task_id', 'patch'],
                            ],
                        ],
                    ],
                    'required' => ['event_id', 'updates'],
                ],
                'strict' => false,
            ],
            [
                'type' => 'function',
                'name' => 'generate_event_type_tasks',
                'description' => 'Generate or refresh a planning checklist tailored to the event type and event context.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'refresh_if_safe' => ['type' => ['boolean', 'null']],
                    ],
                    'required' => ['event_id'],
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
                'name' => 'update_budget_items',
                'description' => 'Update existing budget items in the event planner database.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'updates' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'budget_item_id' => ['type' => 'integer'],
                                    'patch' => ['type' => 'object'],
                                ],
                                'required' => ['budget_item_id', 'patch'],
                            ],
                        ],
                    ],
                    'required' => ['event_id', 'updates'],
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
                'name' => 'update_participants',
                'description' => 'Update existing participants in the event planner database.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'event_id' => ['type' => 'integer'],
                        'updates' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'participant_id' => ['type' => 'integer'],
                                    'patch' => ['type' => 'object'],
                                ],
                                'required' => ['participant_id', 'patch'],
                            ],
                        ],
                    ],
                    'required' => ['event_id', 'updates'],
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
