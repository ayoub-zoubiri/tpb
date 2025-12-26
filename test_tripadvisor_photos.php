<?php

use Illuminate\Support\Facades\Http;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- Testing TripAdvisor Photos API ---\n";

$apiKey = env('TRIPADVISOR_API_KEY');
if (!$apiKey) {
    echo "ERROR: TRIPADVISOR_API_KEY is missing in .env\n";
    exit(1);
}

$locationId = '188151'; // Eiffel Tower

echo "Fetching photos for location ID: $locationId\n";

try {
    $response = Http::withHeaders([
        'accept' => 'application/json',
    ])->get("https://api.content.tripadvisor.com/api/v1/location/{$locationId}/photos", [
        'key' => $apiKey,
        'language' => 'en'
    ]);

    if ($response->successful()) {
        $data = $response->json();
        // print_r($data);
        if (!empty($data['data'])) {
            echo "Found " . count($data['data']) . " photos.\n";
            $firstPhoto = $data['data'][0];
            echo "First Photo URL (large): " . ($firstPhoto['images']['large']['url'] ?? 'N/A') . "\n";
            echo "First Photo URL (original): " . ($firstPhoto['images']['original']['url'] ?? 'N/A') . "\n";
        } else {
            echo "No photos found.\n";
        }
    } else {
        echo "Error fetching photos: " . $response->status() . "\n";
        echo $response->body() . "\n";
    }

    echo "\nFetching details again to see reviews info...\n";
    $detailsResponse = Http::withHeaders(['accept' => 'application/json'])
        ->get("https://api.content.tripadvisor.com/api/v1/location/{$locationId}/details", [
            'key' => $apiKey,
            'language' => 'en',
            'currency' => 'USD'
        ]);
    
    if ($detailsResponse->successful()) {
        $details = $detailsResponse->json();
        echo "Num Reviews: " . ($details['num_reviews'] ?? 'N/A') . "\n";
        echo "Rating: " . ($details['rating'] ?? 'N/A') . "\n";
        // Check if there is review snippet or awards
        if (isset($details['awards'])) {
            // print_r($details['awards']);
        }
    }

} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
