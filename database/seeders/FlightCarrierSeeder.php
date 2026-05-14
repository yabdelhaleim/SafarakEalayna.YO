<?php

namespace Database\Seeders;

use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use Illuminate\Database\Seeder;

class FlightCarrierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $amadeus = FlightSystem::where('code', 'AMA')->first();
        $ndc = FlightSystem::where('code', 'NDC')->first();

        $carriers = [
            [
                'name' => 'الجزيرة',
                'code' => 'JZ',
                'iata_code' => 'KU',
                'flight_system_id' => $amadeus?->id,
                'currency' => 'KWD',
                'balance' => 17500,
                'credit_limit' => 50000,
                'is_active' => true,
                'notes' => 'شركة طيران الكويتية',
            ],
            [
                'name' => 'العربية للطيران',
                'code' => 'SV',
                'iata_code' => 'SV',
                'flight_system_id' => $amadeus?->id,
                'currency' => 'SAR',
                'balance' => 100000,
                'credit_limit' => 200000,
                'is_active' => true,
                'notes' => 'الخطوط الجوية السعودية',
            ],
            [
                'name' => 'نسما للطيران',
                'code' => 'NS',
                'iata_code' => 'NE',
                'flight_system_id' => $ndc?->id,
                'currency' => 'SAR',
                'balance' => 50000,
                'credit_limit' => 100000,
                'is_active' => true,
                'notes' => 'شركة طيران سعودية',
            ],
            [
                'name' => 'اير كايرو',
                'code' => 'MS',
                'iata_code' => 'MS',
                'flight_system_id' => $amadeus?->id,
                'currency' => 'EGP',
                'balance' => 500000,
                'credit_limit' => 1000000,
                'is_active' => true,
                'notes' => 'مصر للطيران',
            ],
            [
                'name' => 'أبو كابيرو',
                'code' => 'ABK',
                'iata_code' => 'AB',
                'flight_system_id' => $amadeus?->id,
                'currency' => 'EGP',
                'balance' => 200000,
                'credit_limit' => 500000,
                'is_active' => true,
                'notes' => 'شركة طيران مصرية',
            ],
        ];

        foreach ($carriers as $carrier) {
            FlightCarrier::updateOrCreate(
                ['code' => $carrier['code']],
                $carrier
            );
        }

        $this->command->info('✅ Flight carriers seeded successfully!');
    }
}
