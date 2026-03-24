<?php

namespace App\Http\Controllers;

use App\Models\AiRequestLog;
use Inertia\Inertia;
use Illuminate\Http\Request;

class SuperAdminAiLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AiRequestLog::query()
            ->with([
                'event:id,title,club_id,event_type',
                'club:id,club_name',
                'user:id,name,email',
            ])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('source')) {
            $query->where('request_json->source', $request->string('source'));
        }

        $logs = $query->paginate(30)->withQueryString()
            ->through(function (AiRequestLog $log) {
                $requestJson = is_array($log->request_json) ? $log->request_json : [];
                $payload = is_array($requestJson['payload'] ?? null) ? $requestJson['payload'] : [];
                $input = $payload['input'] ?? [];
                $prompt = collect($input)
                    ->filter(fn ($entry) => ($entry['role'] ?? null) === 'user')
                    ->pluck('content')
                    ->filter()
                    ->implode("\n\n");

                return [
                    'id' => $log->id,
                    'created_at' => optional($log->created_at)?->toDateTimeString(),
                    'status' => $log->status,
                    'provider' => $log->provider,
                    'model' => $log->model,
                    'latency_ms' => $log->latency_ms,
                    'input_tokens' => $log->input_tokens,
                    'output_tokens' => $log->output_tokens,
                    'total_tokens' => $log->total_tokens,
                    'error_message' => $log->error_message,
                    'source' => $requestJson['source'] ?? null,
                    'event' => $log->event ? [
                        'id' => $log->event->id,
                        'title' => $log->event->title,
                        'event_type' => $log->event->event_type,
                    ] : null,
                    'club' => $log->club ? [
                        'id' => $log->club->id,
                        'club_name' => $log->club->club_name,
                    ] : null,
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'email' => $log->user->email,
                    ] : null,
                    'prompt' => $prompt,
                    'request_json' => $requestJson,
                    'response_json' => $log->response_json,
                ];
            });

        $sources = AiRequestLog::query()
            ->select('request_json')
            ->latest()
            ->get()
            ->map(function (AiRequestLog $log) {
                $requestJson = is_array($log->request_json) ? $log->request_json : [];
                return $requestJson['source'] ?? null;
            })
            ->filter()
            ->unique()
            ->values();

        return Inertia::render('SuperAdmin/AiLogs', [
            'logs' => $logs,
            'sources' => $sources,
            'filters' => [
                'status' => $request->input('status', ''),
                'source' => $request->input('source', ''),
            ],
        ]);
    }
}
