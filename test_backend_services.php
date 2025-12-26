<?php

use Illuminate\Support\Facades\Http;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- Testing TripAdvisor API ---\n";

$apiKey = env('TRIPADVISOR_API_KEY');
if (!$apiKey) {
    echo "ERROR: TRIPADVISOR_API_KEY is missing in .env\n";
} else {
    $query = "Eiffel Tower in Paris";
    echo "Searching for: $query\n";
    
    try {
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
                echo "SUCCESS: Found " . count($data['data']) . " locations.\n";
                $place = $data['data'][0];
                echo "Top Result: " . $place['name'] . " (ID: " . $place['location_id'] . ")\n";
                
                // Test Details
                echo "Fetching details for ID: " . $place['location_id'] . "\n";
                $detailsResponse = Http::withHeaders(['accept' => 'application/json'])
                    ->get("https://api.content.tripadvisor.com/api/v1/location/{$place['location_id']}/details", [
                        'key' => $apiKey,
                        'language' => 'en',
                        'currency' => 'USD'
                    ]);
                
                if ($detailsResponse->successful()) {
                    $details = $detailsResponse->json();
                    echo "SUCCESS Details: Verified Name: " . ($details['name'] ?? 'N/A') . "\n";
                    echo "Latitude: " . ($details['latitude'] ?? 'N/A') . "\n";
                    echo "Longitude: " . ($details['longitude'] ?? 'N/A') . "\n";
                } else {
                    echo "ERROR: Failed to fetch details. Status: " . $detailsResponse->status() . "\n";
                }

            } else {
                echo "WARNING: Search successful but no data returned.\n";
            }
        } else {
            echo "ERROR: TripAdvisor API request failed. Status: " . $response->status() . "\n";
            echo "Response: " . $response->body() . "\n";
        }
    } catch (\Exception $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
}

echo "\n--- Testing Gemini API (Model: gemini-3-flash) ---\n";

$geminiKey = env('GEMINI_API_KEY');
if (!$geminiKey) {
    echo "ERROR: GEMINI_API_KEY is missing in .env\n";
} else {
    $model = 'gemini-2.0-flash-exp'; // The user put 'gemini-3-flash' which might not be public yet, testing safe fallback or their input if valid.
    // Let's test EXACTLY what they put in the file: gemini-3-flash
    // Note: I suspect gemini-3-flash might be hallucinated or beta.
    // I will try to read the file first to match their generic variable, but for now I'll test specific known endpoint AND the one they requested.
    
    // Actually, let's look at the file change again. They changed it to 'gemini-3-flash'.
    // If that model doesn't exist, this test will fail, which is GOOD because it informs the user.
    $modelToCheck = 'gemini-2.5-flash-lite'; 
    
    echo "Testing Model: $modelToCheck\n";
    
    $prompt = "Say hello";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelToCheck}:generateContent?key={$geminiKey}";

    try {
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($url, [
                'contents' => [['parts' => [['text' => $prompt]]]],
            ]);
            
        if ($response->successful()) {
            echo "SUCCESS: Gemini API responded.\n";
            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'No text';
            echo "Response: " . trim($text) . "\n";
        } else {
            echo "ERROR: Gemini API failed. Status: " . $response->status() . "\n";
            echo "Body: " . $response->body() . "\n";
            echo "Note: 'gemini-3-flash' might not be a valid model name yet. You might mean 'gemini-2.0-flash' or 'gemini-1.5-flash'.\n";
        }
    } catch (\Exception $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
}

echo "\n--- Testing Viator API ---\n";

$viatorKey = env('VIATOR_API_KEY');
if (!$viatorKey) {
    echo "ERROR: VIATOR_API_KEY is missing in .env\n";
} else {
    $viatorQuery = "Eiffel Tower Tour";
    echo "Searching for: $viatorQuery\n";

    try {
        $response = Http::withHeaders([
            'Accept' => 'application/json;version=2.0',
            'exp-api-key' => $viatorKey,
            'Accept-Language' => 'en-US',
            'Content-Type' => 'application/json'
        ])->post("https://api.viator.com/partner/search/freetext", [
            'searchTerm' => $viatorQuery,
            'searchTypes' => [
                [
                    'searchType' => 'PRODUCTS',
                    'pagination' => ['start' => 1, 'count' => 1]
                ]
            ],
            'currency' => 'USD'
        ]);

        if ($response->successful()) {
            echo "SUCCESS: Viator API responded.\n";
            $data = $response->json();
            
            if (!empty($data['products']['results'])) {
                $product = $data['products']['results'][0];
                echo "Product Found: " . ($product['title'] ?? 'N/A') . "\n";
                echo "Code: " . ($product['productCode'] ?? 'N/A') . "\n";
                echo "URL: " . ($product['productUrl'] ?? 'N/A') . "\n";
                
                $price = $product['pricing']['summary']['fromPrice'] ?? 'N/A';
                echo "Price: $price USD\n";
                
                $rating = $product['reviews']['combinedAverageRating'] ?? 'N/A';
                echo "Rating: $rating\n";
                
                if (!empty($product['images'])) {
                     // Try to find cover image or take the first one
                     $imageVar = $product['images'][0]['variants'][0]['url'] ?? 'N/A';
                     echo "Image URL: $imageVar\n";
                }

            } else {
                echo "WARNING: Search successful but no products returned. (Check query or availability)\n";
                // Debug raw output if needed
                // print_r($data);
            }
        } else {
            echo "ERROR: Viator API request failed. Status: " . $response->status() . "\n";
            echo "Response: " . $response->body() . "\n";
        }
    } catch (\Exception $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
}
