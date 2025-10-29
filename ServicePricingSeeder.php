<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServicePricing;

class ServicePricingSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [];
        $seed = function ($service, $package, $unitsList, $base) use (&$rows) {
            foreach ($unitsList as $i => $u) {
                $rows[] = [
                    'service_key' => $service,
                    'package' => $package,
                    'units' => $u,
                    'price_rupees' => $base + ($i * 500), // dummy tiering
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        };

        // Training
        $seed('Training', 'hourly', [1,2,3,4,5], 1000);
        $seed('Training', 'monthly', [1,3,5,7,9], 2500);
        $seed('Training', 'yearly', [1,2,3], 12000);

        // Daily Dog Walking
        $seed('Daily Dog Walking', 'hourly', [1,2,3,4,5], 300);
        $seed('Daily Dog Walking', 'monthly', [1,3,5,7,9], 1200);
        $seed('Daily Dog Walking', 'yearly', [1,2,3], 10000);

        foreach ($rows as $row) {
            ServicePricing::updateOrCreate(
                [
                    'service_key' => $row['service_key'],
                    'package' => $row['package'],
                    'units' => $row['units']
                ],
                ['price_rupees' => $row['price_rupees'], 'updated_at' => now()]
            );
        }
    }
}


