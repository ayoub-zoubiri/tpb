<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\City;

class CityController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('q');
        $limit = $request->input('limit', 10);

        if (!$query || strlen($query) < 2) {
            return response()->json([]);
        }

        // Prioritize:
        // 1. Exact matches (or starts with) on city name
        // 2. Sort by population (descending) to show major cities first
        
        $cities = City::where('city_ascii', 'like', "{$query}%")
            ->orWhere('city', 'like', "{$query}%")
            ->orWhere('country', 'like', "{$query}%")
            ->orderByRaw("CASE WHEN city_ascii LIKE '{$query}%' THEN 1 ELSE 2 END")
            ->orderBy('population', 'desc')
            ->limit($limit)
            ->get();
            
        // Map to a frontend-friendly format
        $results = $cities->map(function ($city) {
            return [
                'id' => $city->id,
                'city' => $city->city,
                'country' => $city->country,
                'iso2' => $city->iso2,
                'admin_name' => $city->admin_name,
                'label' => "{$city->city}, {$city->country}", // Useful for input value
                'flag_url' => "https://flagcdn.com/w40/" . strtolower($city->iso2) . ".png" // Add flag
            ];
        });

        return response()->json($results);
    }
}
