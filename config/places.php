<?php

return [
    'provider' => env('PLACES_PROVIDER', 'google_maps'),
    'google' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        'geocode_url' => env('GOOGLE_MAPS_GEOCODE_URL', 'https://maps.googleapis.com/maps/api/geocode/json'),
        'places_url' => env('GOOGLE_MAPS_PLACES_URL', 'https://maps.googleapis.com/maps/api/place/nearbysearch/json'),
        'text_search_url' => env('GOOGLE_MAPS_TEXTSEARCH_URL', 'https://maps.googleapis.com/maps/api/place/textsearch/json'),
        'distance_matrix_url' => env('GOOGLE_MAPS_DISTANCE_MATRIX_URL', 'https://maps.googleapis.com/maps/api/distancematrix/json'),
    ],
    'default_radius_km' => (int) env('PLACES_DEFAULT_RADIUS_KM', 25),
    'max_results' => (int) env('PLACES_MAX_RESULTS', 8),
    'include_distance' => (bool) env('PLACES_INCLUDE_DISTANCE', true),
];
