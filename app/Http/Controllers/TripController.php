<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TripController extends Controller
{
    private $models = [
        
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        
    ];

    private function getApiKeys()
    {
        // List of API keys. 
        // Ideally, these should be loaded from config/env.
        // We start with the provided key and allow for expansion.
        $keys = [
            env('GEMINI_API_KEY'),
            // 'AIzaSy...', // Add API Key 2
            // 'AIzaSy...', // Add API Key 3
            // ...
        ];
        
        // Filter out empty keys just in case
        return array_filter($keys);
    }

    public function index(Request $request)
    {
        return $request->user()->trips()->with('dayPlans.activities')->latest()->get();
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return \App\Models\Trip::with('dayPlans.activities')->findOrFail($id);
        }

        return $user->trips()->with('dayPlans.activities')->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $trip = $request->user()->trips()->findOrFail($id);
        
        $validated = $request->validate([
            'trip_title' => 'sometimes|string',
            'summary' => 'sometimes|string',
            // Add other fields as needed
        ]);

        $trip->update($validated);

        return response()->json($trip);
    }

    public function destroy(Request $request, $id)
    {
        $trip = $request->user()->trips()->findOrFail($id);
        $trip->delete();

        return response()->json(['message' => 'Trip deleted successfully']);
    }

    private function getTripAdvisorData($query) {
        $apiKey = env('TRIPADVISOR_API_KEY', '83702C78952B4CA2B80D94F58C4A905C'); 
        if (!$apiKey) return null;

        try {
            // TripAdvisor Location Search
            $response = Http::withHeaders([
                'accept' => 'application/json',
            ])->get("https://api.content.tripadvisor.com/api/v1/location/search", [
                'key' => $apiKey,
                'searchQuery' => $query,
                'language' => 'en'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['data'])) {
                    // Get the first (best) match
                    $place = $data['data'][0];
                    
                    // We might need to fetch details to get lat/long if not in search response
                    // Note: Basic Search often creates minimal data. 
                    // Let's assume we proceed if we have an ID, but for lat/long we might need a second call 
                    // OR we accept just the name verification and use Nominatim for coords if TA lacks them.
                    
                    // Actually, let's use the 'details' endpoint if we have an ID to be sure, 
                    // but to save API calls (limit), let's check what search returns. 
                    // Search usually doesn't return lat/long in the list in v1. 
                    // So we do: Search -> Get ID -> Get Details (for Geoloc).
                    
                    $locationId = $place['location_id'];
                    $detailsResponse = Http::withHeaders(['accept' => 'application/json'])
                        ->get("https://api.content.tripadvisor.com/api/v1/location/{$locationId}/details", [
                            'key' => $apiKey,
                            'language' => 'en',
                            'currency' => 'USD'
                        ]);
                        
                    if ($detailsResponse->successful()) {
                        $details = $detailsResponse->json();
                        return [
                            'name' => $details['name'] ?? $place['name'],
                            'lat' => $details['latitude'] ?? null,
                            'lon' => $details['longitude'] ?? null,
                            'rating' => $details['rating'] ?? null,
                            'url' => $details['web_url'] ?? null
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("TripAdvisor lookup failed for {$query}: " . $e->getMessage());
        }
        return null;
    }

    private function getRealCoordinates($location, $destination) {
        // Try TripAdvisor First (It's the requested "Real Place" source)
        $taData = $this->getTripAdvisorData("{$location} in {$destination}");
        
        if ($taData && $taData['lat'] && $taData['lon']) {
            return $taData; // Returns ['lat', 'lon', 'name', ...]
        }

        // Fallback to Nominatim (OpenStreetMap) if TripAdvisor fails or has no coords
        try {
            $query = urlencode("{$location}, {$destination}");
            $url = "https://nominatim.openstreetmap.org/search?q={$query}&format=json&limit=1";

            $response = Http::withHeaders([
                'User-Agent' => 'ToplagoTravelApp/1.0 (contact@toplago.com)' 
            ])->get($url);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
                    return [
                        'lat' => $data[0]['lat'],
                        'lon' => $data[0]['lon'],
                        'name' => null // Keep original name if using fallback
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Nominatim Geocoding failed for {$location}: " . $e->getMessage());
        }
        return null;
    }

    public function generatePlan(Request $request)
    {
        $request->validate([
            'destination' => 'required|string',
            'duration' => 'required|integer',
            'budget' => 'required|string',
            'interests' => 'nullable|string',
        ]);

        $destination = $request->input('destination');
        $duration = $request->input('duration');
        $budget = $request->input('budget');
        $interests = $request->input('interests', 'General sightseeing');

        $prompt = "Act as an expert travel planner with access to TripAdvisor ratings and Viator inventory. Create a detailed {$duration}-day trip itinerary for {$destination} with a {$budget} budget.
        The traveler is interested in: {$interests}.

        CRITICAL INSTRUCTIONS FOR LOGIC & QUALITY:
        1.  **Trajectory Logic:** Arrange activities in a logical geographical order (Morning -> Afternoon -> Evening) to minimize travel time. Treat each day as a connected route.
        2.  **Cluster by Neighborhood:** Group activities by neighborhood (e.g., Morning in Area A, Afternoon in Area A or B). Avoid zig-zagging across the city.
        3.  **Quality Recommendations:** Prioritize activities that are **Top-Rated on TripAdvisor** and usually **bookable on Viator**.
        4.  **Real Locations:** Ensure every location is a real, specific place.

        Generate the response strictly in JSON format with the following structure:
        {
            \"trip_title\": \"Trip to {$destination}\",
            \"summary\": \"A curated itinerary...\",
            \"days\": [
                {
                    \"day\": 1,
                    \"theme\": \"Historical Exploration\",
                    \"activities\": [
                        {
                            \"time\": \"Morning\",
                            \"description\": \"Visit the iconic landmark...\",
                            \"location\": \"Specific Location Name\"
                        }
                    ]
                }
            ]
        }
        
        IMPORTANT: Do not include markdown formatting. Return raw JSON only.";

        $maxRetries = 3;
        $attempt = 0;
        $tripData = null;

        // Rotation Logic
        $requestCount = \Illuminate\Support\Facades\Cache::increment('gemini_request_count');
        $i = $requestCount - 1; // 0-indexed

        $models = $this->models;
        $apiKeys = array_values($this->getApiKeys()); // Ensure indexed array

        if (empty($apiKeys)) {
            return response()->json(['error' => 'No API keys configured'], 500);
        }

        $numModels = count($models);
        $numKeys = count($apiKeys);

        // Model rotates every request: 0, 1, 2, 3, 0, ...
        $modelIndex = $i % $numModels;
        
        // API Key rotates after every full cycle of models: 
        // Requests 0-3 use Key 0
        // Requests 4-7 use Key 1
        $apiKeyIndex = floor($i / $numModels) % $numKeys;

        $selectedModel = $models[$modelIndex];
        $apiKey = $apiKeys[$apiKeyIndex];

        while ($attempt < $maxRetries && !$tripData) {
            $attempt++;
            
            try {
                Log::info("Attempt {$attempt}/{$maxRetries}: Using Model: {$selectedModel}");

                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$selectedModel}:generateContent?key={$apiKey}";
                
                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->post($url, [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['responseMimeType' => 'application/json']
                    ]);

                if ($response->status() === 429) {
                    Log::warning("Rate limit hit. Retrying in 2 seconds...");
                    sleep(2);
                    continue; // Retry loop
                }

                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                        $textResponse = $data['candidates'][0]['content']['parts'][0]['text'];
                        
                        // Robust JSON Extraction
                        $startIndex = strpos($textResponse, '{');
                        $endIndex = strrpos($textResponse, '}');
                        
                        if ($startIndex !== false && $endIndex !== false) {
                            $jsonString = substr($textResponse, $startIndex, $endIndex - $startIndex + 1);
                            $tripData = json_decode($jsonString, true);
                        }
                    }
                } else {
                    Log::warning("Gemini API Error: " . $response->body());
                }

            } catch (\Exception $e) {
                Log::error("Attempt {$attempt} Exception: " . $e->getMessage());
            }

            if (!$tripData && $attempt < $maxRetries) {
                sleep(1); // Wait a bit before retrying on failure
            }
        }

        if ($tripData) {
             // Save to Database
             try {
                \Illuminate\Support\Facades\DB::beginTransaction();

                $trip = \App\Models\Trip::create([
                    'user_id' => $request->user('sanctum') ? $request->user('sanctum')->id : null,
                    'destination' => $destination,
                    'duration' => $duration,
                    'budget' => $budget,
                    'interests' => $interests,
                    'trip_title' => $tripData['trip_title'] ?? 'My Trip',
                    'summary' => $tripData['summary'] ?? '',
                ]);

                if (isset($tripData['days'])) {
                    foreach ($tripData['days'] as $dayData) {
                        $dayPlan = $trip->dayPlans()->create([
                            'day_number' => $dayData['day'],
                            'theme' => $dayData['theme'] ?? null,
                        ]);

                        if (isset($dayData['activities'])) {
                            foreach ($dayData['activities'] as $activityData) {
                                
                                // Get Real Place Data (TripAdvisor -> Nominatim Fallback)
                                $coords = null;
                                $verifiedName = null;
                                
                                if (!empty($activityData['location'])) {
                                    $placeData = $this->getRealCoordinates($activityData['location'], $destination);
                                    if ($placeData) {
                                        $coords = $placeData;
                                        // If TripAdvisor gave us a specific name, use it to improve data quality
                                        if (!empty($placeData['name'])) {
                                            $verifiedName = $placeData['name'];
                                        }
                                    }
                                    // Rate Limiting protection
                                    usleep(100000); // 0.1s
                                }

                                $dayPlan->activities()->create([
                                    'time_of_day' => $activityData['time'] ?? 'Anytime',
                                    'description' => $activityData['description'] ?? '',
                                    'location' => $verifiedName ?? ($activityData['location'] ?? null),
                                    'latitude' => $coords ? $coords['lat'] : ($activityData['latitude'] ?? null),
                                    'longitude' => $coords ? $coords['lon'] : ($activityData['longitude'] ?? null),
                                ]);
                            }
                        }
                    }
                }

                \Illuminate\Support\Facades\DB::commit();
                
                return response()->json($trip->load('dayPlans.activities'));

            } catch (\Exception $e) {
                \Illuminate\Support\Facades\DB::rollBack();
                Log::error("Database Save Error: " . $e->getMessage());
                return response()->json(['error' => 'Database error while saving trip.'], 500);
            }
        }

        return response()->json(['error' => 'Failed to generate a valid itinerary after multiple attempts. Please try again.'], 500);
    }
}
