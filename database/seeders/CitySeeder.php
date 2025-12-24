<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\City;
use Illuminate\Support\Facades\DB;

class CitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable query log to save memory
        DB::disableQueryLog();
        DB::table('cities')->truncate();

        $csvFile = base_path('worldcities.csv');
        
        if (!file_exists($csvFile)) {
            $this->command->error("CSV file not found at: $csvFile");
            return;
        }

        $file = fopen($csvFile, 'r');
        $header = fgetcsv($file); // Skip header

        $batchSize = 1000;
        $data = [];

        $this->command->info("Importing cities...");

        while (($row = fgetcsv($file)) !== false) {
            // "city","city_ascii","lat","lon","country","iso2","iso3","admin_name","capital","population","id"
            // 0      1            2     3     4         5      6      7            8         9            10
            
            $population = is_numeric($row[9]) ? $row[9] : 0;
            
            // Filter out very small cities to keep DB size manageable if needed, 
            // but user asked for "worldcities", so we try to keep most.
            // Let's at least filter out those with 0 population if they are not capitals, to avoid clutter?
            // For now, let's just import everything.
            
            $data[] = [
                'city' => $row[0],
                'city_ascii' => $row[1],
                'lat' => $row[2],
                'lng' => $row[3],
                'country' => $row[4],
                'iso2' => $row[5],
                'admin_name' => $row[7],
                'population' => $population,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($data) >= $batchSize) {
                DB::table('cities')->insert($data);
                $data = [];
            }
        }

        if (!empty($data)) {
            DB::table('cities')->insert($data);
        }

        fclose($file);
        $this->command->info("Cities imported successfully.");
    }
}
