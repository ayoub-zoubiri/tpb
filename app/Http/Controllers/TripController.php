<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TripController extends Controller
{


    public function index(Request $request)
    {
        return $request->user()->trips()->with('dayPlans.activities')->latest()->get();
    }

    public function show(Request $request, $id)
    {
        $trip = \App\Models\Trip::with('dayPlans.activities')->findOrFail($id);
        $this->authorize('view', $trip);
        return $trip;
    }

    public function update(Request $request, $id)
    {
        $trip = \App\Models\Trip::findOrFail($id);
        $this->authorize('update', $trip);
        
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
        $trip = \App\Models\Trip::findOrFail($id);
        $this->authorize('delete', $trip);
        $trip->delete();

        return response()->json(['message' => 'Trip deleted successfully']);
    }

    private function getTripAdvisorData($query) {
        $apiKey = env('TRIPADVISOR_API_KEY'); 
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
                    $place = $data['data'][0];
                    $locationId = $place['location_id'];
                    
                    // 1. Get Details (Rating, Web URL)
                    $detailsResponse = Http::withHeaders(['accept' => 'application/json'])
                        ->get("https://api.content.tripadvisor.com/api/v1/location/{$locationId}/details", [
                            'key' => $apiKey,
                            'language' => 'en',
                            'currency' => 'USD'
                        ]);

                    $details = [];
                    if ($detailsResponse->successful()) {
                        $details = $detailsResponse->json();
                    }

                    // 2. Get Photos
                    $imageUrl = null;
                    $photosResponse = Http::withHeaders(['accept' => 'application/json'])
                        ->get("https://api.content.tripadvisor.com/api/v1/location/{$locationId}/photos", [
                            'key' => $apiKey,
                            'language' => 'en',
                            'limit' => 1
                        ]);
                    
                    if ($photosResponse->successful()) {
                        $photos = $photosResponse->json();
                        if (!empty($photos['data'])) {
                            $imageUrl = $photos['data'][0]['images']['large']['url'] ?? $photos['data'][0]['images']['original']['url'] ?? null;
                        }
                    }

                    return [
                        'name' => $details['name'] ?? $place['name'],
                        'lat' => $details['latitude'] ?? ($place['address_obj']['lat'] ?? null), //Fallback if needed
                        'lon' => $details['longitude'] ?? ($place['address_obj']['lng'] ?? null),
                        'rating' => $details['rating'] ?? null,
                        'url' => $details['web_url'] ?? null,
                        'image' => $imageUrl
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("TripAdvisor lookup failed for {$query}: " . $e->getMessage());
        }
        return null;
    }

    private function getRealCoordinates($location, $destination) {
        
        $cleanLocation = preg_replace('/\s*\(.*?\)\s*/', '', $location);
        $cleanLocation = trim($cleanLocation);

        // This already returns the full TripAdvisor data array structure we defined above
        $taData = $this->getTripAdvisorData("{$cleanLocation} in {$destination}");
        
        if ($taData && $taData['lat'] && $taData['lon']) {
            return $taData; 
        }

        try {
            $query = urlencode("{$cleanLocation}, {$destination}");
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
                        'name' => null,
                        'rating' => null,
                        'url' => null,
                        'image' => null
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Nominatim Geocoding failed for {$location}: " . $e->getMessage());
        }
        return null;
    }



    public function generatePlan(\App\Http\Requests\GeneratePlanRequest $request)
    {
        // Increase execution time to 5 minutes to handle multiple API calls
        set_time_limit(300);
        
        $validated = $request->validated();

        $destination = $validated['destination'];
        $duration = $validated['duration'];
        $budget = $validated['budget'];
        $interests = $request->input('interests', 'General sightseeing');

        $prompt = "Act as an expert travel planner with access to TripAdvisor ratings. Create a detailed {$duration}-day trip itinerary for {$destination} with a {$budget} budget.
        The traveler is interested in: {$interests}.

        CRITICAL INSTRUCTIONS FOR LOGIC & QUALITY:
        1.  **Trajectory Logic:** Arrange activities in a logical geographical order (Morning -> Afternoon -> Evening) to minimize travel time. Treat each day as a connected route.
        2.  **Cluster by Neighborhood:** Group activities by neighborhood (e.g., Morning in Area A, Afternoon in Area A or B). Avoid zig-zagging across the city.
        3.  **Quality Recommendations:** Prioritize activities that are **Top-Rated on TripAdvisor**.
        4.  **Real Locations:** Ensure every location is a real place listed on TripAdvisor.
        5.  **Unique Experiences:** Never repeat the same location or activity. Each recommended activity must be unique across the entire trip.

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
        
        IMPORTANT: 
        - YOU MUST GENERATE EXACTLY {$duration} DAYS. Do not generate fewer days.
        - Do not include markdown formatting. Return raw JSON only.";

        $maxRetries = 3;
        $attempt = 0;
        $tripData = null;

        // Single Model and Key Logic
        $selectedModel = 'gemini-2.5-flash-lite';
        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey) {
            return response()->json(['error' => 'API key is not configured'], 500);
        }

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

                // Fetch City Center Coordinates for Fallback
                $cityCenter = \App\Models\City::where('city_ascii', $destination)
                                ->orWhere('city', $destination)
                                ->orderBy('population', 'desc')
                                ->first();

                $defaultLat = $cityCenter ? $cityCenter->lat : 0;
                $defaultLng = $cityCenter ? $cityCenter->lng : 0;

                // Fetch a generic Fallback Image for the Destination (City)
                $fallbackImage = null;
                try {
                    $destinationData = $this->getTripAdvisorData($destination);
                    if ($destinationData && !empty($destinationData['image'])) {
                        $fallbackImage = $destinationData['image'];
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to fetch fallback city image: " . $e->getMessage());
                }

                $usedLocations = [];

                if (isset($tripData['days'])) {
                    foreach ($tripData['days'] as $dayData) {
                        $dayPlan = $trip->dayPlans()->create([
                            'day_number' => $dayData['day'],
                            'theme' => $dayData['theme'] ?? null,
                        ]);

                        if (isset($dayData['activities'])) {
                            foreach ($dayData['activities'] as $index => $activityData) {
                                
                                // Get Real Place Data (TripAdvisor -> Nominatim Fallback)
                                $coords = null;
                                $verifiedName = null;
                                
                                if (!empty($activityData['location'])) {
                                    $placeData = $this->getRealCoordinates($activityData['location'], $destination);
                                    
                                    // STRICT CHECK: Must be a valid TripAdvisor location (must have URL)
                                    if (empty($placeData) || empty($placeData['url'])) {
                                        continue; 
                                    }

                                    $coords = $placeData;
                                    // If TripAdvisor gave us a specific name, use it to improve data quality
                                    if (!empty($placeData['name'])) {
                                        $verifiedName = $placeData['name'];
                                    }
                                }

                                // Deduplicate Locations
                                $locationName = $verifiedName ?? ($activityData['location'] ?? null);
                                if ($locationName) {
                                    $normalizedLoc = strtolower(trim($locationName));
                                    if (in_array($normalizedLoc, $usedLocations)) {
                                        continue; // Skip duplicate activity
                                    }
                                    $usedLocations[] = $normalizedLoc;
                                }

                                // Fallback Logic: Use City Center + Random Offset if geocoding failed
                                $finalLat = $coords ? $coords['lat'] : ($activityData['latitude'] ?? null);
                                $finalLng = $coords ? $coords['lon'] : ($activityData['longitude'] ?? null);

                                if ((!$finalLat || !$finalLng) && $defaultLat != 0) {
                                  // Add small random offset so they don't stack perfectly (approx 500m-1km radius)
                                  $offsetLat = (mt_rand(-100, 100) / 10000) * 1.5; 
                                  $offsetLng = (mt_rand(-100, 100) / 10000) * 1.5;
                                  
                                  $finalLat = $defaultLat + $offsetLat;
                                  $finalLng = $defaultLng + $offsetLng;
                                }
                                
                                // Determine final image: specific location image -> city fallback image -> null
                                $finalImage = $coords['image'] ?? $fallbackImage;

                                $dayPlan->activities()->create([
                                    'time_of_day' => $activityData['time'] ?? 'Anytime',
                                    'description' => $activityData['description'] ?? '',
                                    'location' => $locationName,
                                    'latitude' => $finalLat,
                                    'longitude' => $finalLng,
                                    'booking_url' => $coords['url'] ?? null,
                                    'activity_rating' => $coords['rating'] ?? null,
                                    'activity_image_url' => $finalImage,
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
