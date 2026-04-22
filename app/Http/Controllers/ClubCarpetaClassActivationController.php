<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubCarpetaClassActivation;
use App\Models\UnionClassCatalog;
use App\Support\ClubHelper;
use Illuminate\Http\Request;

class ClubCarpetaClassActivationController extends Controller
{
    protected function normalizeValue(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    protected function normalizeClubType(?string $value): string
    {
        $normalized = str_replace(['-', '_'], ' ', $this->normalizeValue($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return match ($normalized) {
            'adventurers', 'adventurer', 'aventureros', 'aventurero' => 'adventurers',
            'pathfinders', 'pathfinder', 'conquistadores', 'conquistador' => 'pathfinders',
            'master guide', 'master guides', 'guia mayor', 'guia mayores', 'guia mayor avanzado' => 'master_guide',
            default => $normalized,
        };
    }

    protected function resolveAccessibleClub(Request $request, int $clubId): Club
    {
        return ClubHelper::clubForUser($request->user(), $clubId);
    }

    protected function resolveCatalogClassForClub(Club $club, int $catalogClassId): UnionClassCatalog
    {
        $catalogClass = UnionClassCatalog::query()
            ->with('clubCatalog.union')
            ->findOrFail($catalogClassId);

        $clubUnionId = $club->district?->association?->union?->id;

        if (!$clubUnionId) {
            abort(422, 'Este club no tiene una union asociada. Verifica la iglesia y el distrito del club.');
        }

        $belongsToClubUnion = (int) $catalogClass->clubCatalog?->union_id === (int) $clubUnionId;
        $matchesClubType = $this->normalizeClubType($catalogClass->clubCatalog?->club_type ?: $catalogClass->clubCatalog?->name) === $this->normalizeClubType($club->club_type);

        if (!$belongsToClubUnion || !$matchesClubType) {
            abort(422, 'La clase seleccionada no pertenece al catalogo de carpetas de este tipo de club. Revisa que la union tenga configuradas clases para este club.');
        }

        return $catalogClass;
    }

    public function store(Request $request, Club $club)
    {
        $club = $this->resolveAccessibleClub($request, (int) $club->id);

        if (($club->evaluation_system ?? 'honors') !== 'carpetas') {
            abort(422, 'Solo los clubes configurados con carpetas pueden activar clases desde el catalogo de la union.');
        }

        $validated = $request->validate([
            'union_class_catalog_id' => ['required', 'integer', 'exists:union_class_catalogs,id'],
        ]);

        $catalogClass = $this->resolveCatalogClassForClub($club, (int) $validated['union_class_catalog_id']);

        $activation = ClubCarpetaClassActivation::firstOrCreate([
            'club_id' => $club->id,
            'union_class_catalog_id' => $catalogClass->id,
        ]);

        return response()->json([
            'message' => 'Clase de carpeta activada correctamente.',
            'activation' => $activation,
        ], 201);
    }

    public function destroy(Request $request, ClubCarpetaClassActivation $activation)
    {
        $club = $this->resolveAccessibleClub($request, (int) $activation->club_id);

        if ((int) $club->id !== (int) $activation->club_id) {
            abort(403);
        }

        $activation->delete();

        return response()->json([
            'message' => 'Clase de carpeta desactivada correctamente.',
        ]);
    }
}
