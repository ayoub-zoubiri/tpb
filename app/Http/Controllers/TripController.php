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

        $prompt = "Act as an expert travel planner. Create a detailed {$duration}-day trip itinerary for {$destination} with a {$budget} budget.
        The traveler is interested in: {$interests}.

        Generate the response strictly in JSON format with the following structure:
        {
            \"trip_title\": \"Title of the trip\",
            \"summary\": \"Brief summary of the trip\",
            \"days\": [
                {
                    \"day\": 1,
                    \"theme\": \"Theme of the day\",
                    \"activities\": [
                        {
                            \"time\": \"Morning\",
                            \"description\": \"Activity description\",
                            \"location\": \"Location name\",
                            \"latitude\": 48.8584,
                            \"longitude\": 2.2945
                        },
                        {
                            \"time\": \"Afternoon\",
                            \"description\": \"Activity description\",
                            \"location\": \"Location name\",
                            \"latitude\": 48.8584,
                            \"longitude\": 2.2945
                        },
                         {
                            \"time\": \"Evening\",
                            \"description\": \"Activity description\",
                            \"location\": \"Location name\",
                            \"latitude\": 48.8584,
                            \"longitude\": 2.2945
                        }
                    ]
                }
            ]
        }
        Do not include any markdown formatting (like ```json). Just return the raw JSON string. Ensure coordinates are accurate for the location. Ensure EVERY activity has valid latitude and longitude coordinates.";

        $maxRetries = 3;
        $attempt = 0;
        $tripData = null;

        while ($attempt < $maxRetries && !$tripData) {
            $attempt++;
            
            try {
                // Determine model and key for this attempt
                // Rotation Logic (Simplified for retry context)
                $requestCount = \Illuminate\Support\Facades\Cache::increment('gemini_request_count');
                $i = $requestCount - 1; 
                $models = $this->models;
                $apiKeys = array_values($this->getApiKeys());

                if (empty($apiKeys)) {
                    return response()->json(['error' => 'No API keys configured'], 500);
                }

                $modelIndex = $i % count($models);
                $apiKeyIndex = floor($i / count($models)) % count($apiKeys);
                $selectedModel = $models[$modelIndex];
                $apiKey = $apiKeys[$apiKeyIndex];

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
                                $dayPlan->activities()->create([
                                    'time_of_day' => $activityData['time'] ?? 'Anytime',
                                    'description' => $activityData['description'] ?? '',
                                    'location' => $activityData['location'] ?? null,
                                    'latitude' => $activityData['latitude'] ?? null,
                                    'longitude' => $activityData['longitude'] ?? null,
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
