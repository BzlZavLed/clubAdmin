<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubIntegrationConfig;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Log;

class ClubSettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $clubIds = ClubHelper::clubIdsForUser($user);
        $clubs = Club::whereIn('id', $clubIds)->orderBy('club_name')->get(['id', 'club_name']);
        $selectedClubId = $request->input('club_id') ?: $user->club_id ?: ($clubs->first()->id ?? null);
        if ($selectedClubId && !$clubs->contains('id', $selectedClubId)) {
            abort(403, 'Not allowed to view this club settings.');
        }

        $config = $selectedClubId
            ? ClubIntegrationConfig::where('club_id', $selectedClubId)->first()
            : null;

        return Inertia::render('ClubDirector/Settings', [
            'auth_user' => $user,
            'clubs' => $clubs,
            'selected_club_id' => $selectedClubId,
            'integration_config' => $config,
        ]);
    }

    public function fetchCatalog(Request $request)
    {
        $payload = $request->validate([
            'invite_code' => ['required', 'string'],
            'club_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $clubId = $payload['club_id'] ?: $user->club_id;
        $allowedClubIds = ClubHelper::clubIdsForUser($user)->all();
        if ($clubId && !in_array($clubId, $allowedClubIds)) {
            abort(403, 'Not allowed to fetch catalog for this club.');
        }

        $baseUrl = rtrim(config('services.mychurchadmin.base_url'), '/');
        $token = config('services.mychurchadmin.token');
        if (!$baseUrl) {
            abort(422, 'Missing mychurchadmin base URL.');
        }
        if (!$token) {
            abort(422, 'Missing integration token.');
        }

        $url = $baseUrl . '/api/integrations/clubs/catalog';
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->get($url, ['invite_code' => $payload['invite_code']]);
        } catch (\Throwable $e) {
            Log::warning('Catalog fetch failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Catalog request failed.'], 502);
        }

        if (!$response->successful()) {
            return response()->json([
                'message' => 'Catalog fetch failed.',
                'status' => $response->status(),
                'error' => $response->json() ?? $response->body(),
            ], $response->status());
        }

        return response()->json($response->json());
    }

    public function saveConfig(Request $request)
    {
        $payload = $request->validate([
            'invite_code' => ['required', 'string'],
            'club_id' => ['nullable', 'integer'],
            'catalog' => ['required', 'array'],
            'catalog.status' => ['nullable', 'string'],
            'catalog.church' => ['nullable', 'array'],
            'catalog.church.id' => ['nullable', 'integer'],
            'catalog.church.name' => ['nullable', 'string'],
            'catalog.church.slug' => ['nullable', 'string'],
            'catalog.church_slug' => ['nullable', 'string'],
            'catalog.departments' => ['nullable', 'array'],
            'catalog.objectives' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $clubId = $payload['club_id'] ?: $user->club_id;
        $allowedClubIds = ClubHelper::clubIdsForUser($user)->all();
        if ($clubId && !in_array($clubId, $allowedClubIds)) {
            abort(403, 'Not allowed to save settings for this club.');
        }

        $catalog = $payload['catalog'];
        $church = $catalog['church'] ?? [];
        $config = ClubIntegrationConfig::updateOrCreate(
            ['club_id' => $clubId],
            [
                'invite_code' => $payload['invite_code'],
                'status' => $catalog['status'] ?? null,
                'church_id' => $church['id'] ?? null,
                'church_name' => $church['name'] ?? null,
                'church_slug' => $catalog['church_slug'] ?? ($church['slug'] ?? null),
                'departments' => $catalog['departments'] ?? [],
                'objectives' => $catalog['objectives'] ?? [],
                'fetched_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'ok',
            'config' => $config,
        ]);
    }
}
