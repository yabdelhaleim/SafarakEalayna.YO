<?php

namespace Database\Seeders;

use App\Models\Flight\FlightSystem;
use Illuminate\Database\Seeder;

class FlightSystemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $systems = [
            [
                'name' => 'Amadeus',
                'code' => 'AMA',
                'type' => 'gds',
                'description' => 'نظام الحجز العالمي Amadeus',
                'is_active' => true,
            ],
            [
                'name' => 'NDC',
                'code' => 'NDC',
                'type' => 'ndc',
                'description' => 'New Distribution Capability',
                'is_active' => true,
            ],
            [
                'name' => 'NDC X',
                'code' => 'NDCX',
                'type' => 'ndc',
                'description' => 'NDC X Platform',
                'is_active' => true,
            ],
            [
                'name' => '3TP',
                'code' => 'TP3',
                'type' => 'gds',
                'description' => 'Third Party Platform',
                'is_active' => true,
            ],
            [
                'name' => 'Sabre',
                'code' => 'SAB',
                'type' => 'gds',
                'description' => 'نظام الحجز العالمي Sabre',
                'is_active' => true,
            ],
            [
                'name' => 'Galileo',
                'code' => 'GAL',
                'type' => 'gds',
                'description' => 'نظام الحجز العالمي Galileo',
                'is_active' => true,
            ],
        ];

        foreach ($systems as $system) {
            FlightSystem::updateOrCreate(
                ['code' => $system['code']],
                $system
            );
        }

        $this->command->info('✅ Flight systems seeded successfully!');
    }
}
