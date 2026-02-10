<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Log;
use RuntimeException;

class PlacesService
{
    public function findRecommendedPlaces(string $address, string $intent, ?int $radiusKm = null, ?int $maxResults = null, ?float $minRating = null): array
    {
        $coords = $this->safeGeocodeAddress($address);
        $radius = ($radiusKm ?? config('places.default_radius_km')) * 1000;

        if ($coords) {
            $results = $this->nearbySearch($coords['lat'], $coords['lng'], $intent, $radius);
        } else {
            $results = $this->textSearch($intent, $address);
        }
        Log::info('Initial places search', ['result' => $results, 'results_count' => count($results ?? []), 'address' => $address, 'intent' => $intent]);
        if (!$results) {
            $fallbacks = [
                'campground',
                'campsite',
                'camping',
                'rv park',
                'state park campground',
                'national park campground',
            ];
            foreach ($fallbacks as $fallback) {
                $query = trim($fallback . ' ' . $intent);
                $results = $coords
                    ? $this->nearbySearch($coords['lat'], $coords['lng'], $query, $radius)
                    : $this->textSearch($query, $address);
                if ($results) {
                    break;
                }
            }
        }

        $filtered = array_filter($results, function ($place) use ($minRating) {
            if ($minRating === null) {
                return true;
            }
            return ($place['rating'] ?? 0) >= $minRating;
        });

        usort($filtered, function ($a, $b) {
            $ratingA = $a['rating'] ?? 0;
            $ratingB = $b['rating'] ?? 0;
            if ($ratingA === $ratingB) {
                return ($b['user_ratings_total'] ?? 0) <=> ($a['user_ratings_total'] ?? 0);
            }
            return $ratingB <=> $ratingA;
        });

        $max = $maxResults ?? config('places.max_results');
        $deduped = [];
        foreach ($filtered as $place) {
            $id = $place['place_id'] ?? null;
            if ($id && isset($deduped[$id])) {
                continue;
            }
            $deduped[$id ?? uniqid('place_', true)] = $place;
        }

        $trimmed = array_slice(array_values($deduped), 0, $max);

        if (config('places.include_distance') && $trimmed) {
            $trimmed = $this->attachDistances($address, $trimmed);
        }

        return array_map(function ($place) {
            return [
                'name' => $place['name'] ?? null,
                'rating' => $place['rating'] ?? null,
                'user_ratings_total' => $place['user_ratings_total'] ?? null,
                'address' => $place['vicinity'] ?? ($place['formatted_address'] ?? null),
                'international_phone_number' => $place['international_phone_number'] ?? null,
                'formatted_phone_number' => $place['formatted_phone_number'] ?? null,
                'distance_text' => $place['distance_text'] ?? null,
                'distance_value' => $place['distance_value'] ?? null,
                'duration_text' => $place['duration_text'] ?? null,
                'duration_value' => $place['duration_value'] ?? null,
                'place_id' => $place['place_id'] ?? null,
                'types' => $place['types'] ?? [],
            ];
        }, $trimmed);
    }

    protected function geocodeAddress(string $address): array
    {
        $apiKey = $this->apiKey();
        $response = Http::get(config('places.google.geocode_url'), [
            'address' => $address,
            'key' => $apiKey,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to geocode address.');
        }

        $data = $response->json();
        if (($data['status'] ?? null) === 'REQUEST_DENIED') {
            $message = $data['error_message'] ?? 'Google Places request denied.';
            throw new RuntimeException($message);
        }
        if (($data['status'] ?? null) === 'OVER_QUERY_LIMIT') {
            throw new RuntimeException('Google Places quota exceeded.');
        }
        if (($data['status'] ?? null) === 'INVALID_REQUEST') {
            throw new RuntimeException('Google Places request invalid.');
        }
        $result = $data['results'][0] ?? null;
        $location = $result['geometry']['location'] ?? null;

        if (!$location) {
            throw new RuntimeException('No geocoding results for address.');
        }

        return [
            'lat' => $location['lat'],
            'lng' => $location['lng'],
            'formatted_address' => $result['formatted_address'] ?? $address,
        ];
    }

    protected function safeGeocodeAddress(string $address): ?array
    {
        try {
            return $this->geocodeAddress($address);
        } catch (RuntimeException $e) {
            return null;
        }
    }

    protected function nearbySearch(float $lat, float $lng, string $keyword, int $radiusMeters): array
    {
        $apiKey = $this->apiKey();
        $response = Http::get(config('places.google.places_url'), [
            'location' => $lat . ',' . $lng,
            'radius' => $radiusMeters,
            'keyword' => $keyword,
            'key' => $apiKey,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Places search failed.');
        }

        $data = $response->json();
        if (($data['status'] ?? null) === 'REQUEST_DENIED') {
            $message = $data['error_message'] ?? 'Google Places request denied.';
            throw new RuntimeException($message);
        }
        if (($data['status'] ?? null) === 'OVER_QUERY_LIMIT') {
            throw new RuntimeException('Google Places quota exceeded.');
        }
        if (($data['status'] ?? null) === 'INVALID_REQUEST') {
            throw new RuntimeException('Google Places request invalid.');
        }
        return $data['results'] ?? [];
    }

    protected function textSearch(string $intent, string $address): array
    {
        $apiKey = $this->apiKey();
        $query = trim($intent . ' near ' . $address);
        $response = Http::get(config('places.google.text_search_url'), [
            'query' => $query,
            'key' => $apiKey,
        ]);
        Log::info('Text search query', ['query' => $query, 'address' => $address, 'intent' => $intent, 'response' => $response->json()]);
        if ($response->failed()) {
            throw new RuntimeException('Places text search failed.');
        }

        $data = $response->json();
        if (($data['status'] ?? null) === 'REQUEST_DENIED') {
            $message = $data['error_message'] ?? 'Google Places request denied.';
            throw new RuntimeException($message);
        }
        if (($data['status'] ?? null) === 'OVER_QUERY_LIMIT') {
            throw new RuntimeException('Google Places quota exceeded.');
        }
        if (($data['status'] ?? null) === 'INVALID_REQUEST') {
            throw new RuntimeException('Google Places request invalid.');
        }
        return $data['results'] ?? [];
    }

    protected function attachDistances(string $origin, array $places): array
    {
        $apiKey = $this->apiKey();
        $destinations = array_map(function ($place) {
            $placeId = $place['place_id'] ?? null;
            if ($placeId) {
                return 'place_id:' . $placeId;
            }
            $address = $place['vicinity'] ?? ($place['formatted_address'] ?? null);
            return $address ?: ($place['name'] ?? '');
        }, $places);

        $response = Http::get(config('places.google.distance_matrix_url'), [
            'origins' => $origin,
            'destinations' => implode('|', $destinations),
            'key' => $apiKey,
        ]);

        if ($response->failed()) {
            return $places;
        }

        $data = $response->json();
        if (($data['status'] ?? null) !== 'OK') {
            return $places;
        }

        $elements = $data['rows'][0]['elements'] ?? [];
        foreach ($places as $index => $place) {
            $element = $elements[$index] ?? null;
            if (!$element || ($element['status'] ?? null) !== 'OK') {
                continue;
            }
            $place['distance_text'] = $element['distance']['text'] ?? null;
            $place['distance_value'] = $element['distance']['value'] ?? null;
            $place['duration_text'] = $element['duration']['text'] ?? null;
            $place['duration_value'] = $element['duration']['value'] ?? null;
            $places[$index] = $place;
        }

        return $places;
    }

    protected function apiKey(): string
    {
        $apiKey = config('places.google.api_key');
        if (!$apiKey) {
            throw new RuntimeException('GOOGLE_MAPS_API_KEY is not configured.');
        }
        return $apiKey;
    }
}
