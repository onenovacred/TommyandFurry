<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceType;

class ServiceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $serviceTypes = [
            [
                'name' => 'Car Insurance',
                'description' => 'Comprehensive car insurance coverage including third-party liability and own damage protection.',
                'base_price' => 5000.00,
                'category' => 'Insurance',
                'is_active' => true
            ],
            [
                'name' => 'Health Insurance',
                'description' => 'Medical insurance coverage for hospitalization, surgeries, and medical expenses.',
                'base_price' => 8000.00,
                'category' => 'Insurance',
                'is_active' => true
            ],
            [
                'name' => 'Life Insurance',
                'description' => 'Term life insurance providing financial protection for your family.',
                'base_price' => 12000.00,
                'category' => 'Insurance',
                'is_active' => true
            ],
            [
                'name' => 'Home Insurance',
                'description' => 'Property insurance covering your home and belongings against damage and theft.',
                'base_price' => 3000.00,
                'category' => 'Insurance',
                'is_active' => true
            ],
            [
                'name' => 'Travel Insurance',
                'description' => 'Travel protection covering medical emergencies, trip cancellation, and baggage loss.',
                'base_price' => 1500.00,
                'category' => 'Insurance',
                'is_active' => true
            ],
            [
                'name' => 'Pet Insurance',
                'description' => 'Veterinary care coverage for your pets including medical treatments and surgeries.',
                'base_price' => 2500.00,
                'category' => 'Insurance',
                'is_active' => true
            ],
            [
                'name' => 'Motorcycle Insurance',
                'description' => 'Two-wheeler insurance covering third-party liability and own damage.',
                'base_price' => 2000.00,
                'category' => 'Insurance',
                'is_active' => true
            ],
            [
                'name' => 'Business Insurance',
                'description' => 'Commercial insurance protecting your business assets and operations.',
                'base_price' => 15000.00,
                'category' => 'Insurance',
                'is_active' => true
            ],
            [
                'name' => 'Consultation Service',
                'description' => 'Insurance consultation and advisory services for policy selection.',
                'base_price' => 500.00,
                'category' => 'Service',
                'is_active' => true
            ],
            [
                'name' => 'Policy Renewal',
                'description' => 'Assistance with insurance policy renewal and documentation.',
                'base_price' => 200.00,
                'category' => 'Service',
                'is_active' => true
            ]
        ];

        foreach ($serviceTypes as $serviceType) {
            ServiceType::create($serviceType);
        }
    }
}
