<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceType;

class ExistingServiceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $serviceTypes = [
            ['type' => 'Car Insurance'],
            ['type' => 'Health Insurance'],
            ['type' => 'Life Insurance'],
            ['type' => 'Home Insurance'],
            ['type' => 'Travel Insurance'],
            ['type' => 'Motor Insurance'],
            ['type' => 'Two Wheeler Insurance'],
            ['type' => 'Commercial Vehicle Insurance'],
            ['type' => 'Property Insurance'],
            ['type' => 'Personal Accident Insurance']
        ];

        foreach ($serviceTypes as $serviceType) {
            ServiceType::firstOrCreate($serviceType);
        }
    }
}
