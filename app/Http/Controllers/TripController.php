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
                            \"location\": \"Location name\"
                        },
                        {
                            \"time\": \"Afternoon\",
                            \"description\": \"Activity description\",
                            \"location\": \"Location name\"
                        },
                         {
                            \"time\": \"Evening\",
                            \"description\": \"Activity description\",
                            \"location\": \"Location name\"
                        }
                    ]
                }
            ]
        }
        Do not include any markdown formatting (like ```json). Just return the raw JSON string.";

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

        Log::info("Request #{$requestCount}: Selected Model: {$selectedModel}, API Key Index: {$apiKeyIndex}");

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$selectedModel}:generateContent?key={$apiKey}";
        
        $makeRequest = function() use ($url, $prompt) {
            return Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json'
                ]
            ]);
        };

        $response = $makeRequest();

        // Handle Rate Limiting (429)
        if ($response->status() === 429) {
            Log::warning("Rate limit hit with {$selectedModel}. Retrying in 5 seconds...");
            sleep(5);
            $response = $makeRequest();
        }

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $textResponse = $data['candidates'][0]['content']['parts'][0]['text'];
                $textResponse = str_replace(['```json', '```'], '', $textResponse);
                
                $tripData = json_decode($textResponse, true);

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
                                        ]);
                                    }
                                }
                            }
                        }

                        \Illuminate\Support\Facades\DB::commit();
                        
                        // Return the saved trip with relations
                        return response()->json($trip->load('dayPlans.activities'));

                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\DB::rollBack();
                        Log::error("Database Save Error: " . $e->getMessage());
                        // If save fails, we can't return the DB model. 
                        // We could return the raw JSON but with a warning, or just error out.
                        // For now, let's return the raw JSON but without an ID, so the frontend knows it wasn't saved.
                        return response($textResponse)->header('Content-Type', 'application/json');
                    }
                }
                
                // If json_decode failed
                return response()->json(['error' => 'Failed to parse AI response'], 500);
            }
        }

        Log::error("Gemini API Error ({$selectedModel}): " . $response->body());

        return response()->json(['error' => 'Failed to generate itinerary.', 'details' => $response->json()], 500);
    }
}
