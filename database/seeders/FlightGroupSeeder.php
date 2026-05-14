<?php

namespace Database\Seeders;

use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use Illuminate\Database\Seeder;

class FlightGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jazeera = FlightCarrier::where('code', 'JZ')->first();
        $saudi = FlightCarrier::where('code', 'SV')->first();
        $nesma = FlightCarrier::where('code', 'NS')->first();
        $airCairo = FlightCarrier::where('code', 'MS')->first();
        $abuKabiro = FlightCarrier::where('code', 'ABK')->first();

        $groups = [
            [
                'name' => 'الشعلة',
                'code' => 'SHA',
                'flight_carrier_id' => $jazeera?->id,
                'contact_person' => 'أحمد محمد',
                'contact_phone' => '+965 1234 5678',
                'contact_email' => 'ahmad@alshalla.com',
                'commission_rate' => 5.00,
                'is_active' => true,
                'notes' => 'وكيل سفر الشعلة',
            ],
            [
                'name' => 'فوياج',
                'code' => 'VOY',
                'flight_carrier_id' => $jazeera?->id,
                'contact_person' => 'سارة أحمد',
                'contact_phone' => '+965 2345 6789',
                'contact_email' => 'sara@voyage.com',
                'commission_rate' => 4.50,
                'is_active' => true,
                'notes' => 'وكيل سفر فوياج',
            ],
            [
                'name' => 'العلا',
                'code' => 'ALA',
                'flight_carrier_id' => $saudi?->id,
                'contact_person' => 'محمد علي',
                'contact_phone' => '+966 5432 1098',
                'contact_email' => 'mohammed@alala.com',
                'commission_rate' => 6.00,
                'is_active' => true,
                'notes' => 'وكيل سفر العلا للعمرة والسياحة',
            ],
            [
                'name' => 'المهاجر',
                'code' => 'MUH',
                'flight_carrier_id' => $nesma?->id,
                'contact_person' => 'فاطمة خالد',
                'contact_phone' => '+966 9876 5432',
                'contact_email' => 'fatma@almuhajir.com',
                'commission_rate' => 5.50,
                'is_active' => true,
                'notes' => 'وكيل سفر المهاجر',
            ],
            [
                'name' => 'لوجانو',
                'code' => 'LUG',
                'flight_carrier_id' => $airCairo?->id,
                'contact_person' => 'عمر حسن',
                'contact_phone' => '+20 100 123 4567',
                'contact_email' => 'omar@lugano.com',
                'commission_rate' => 7.00,
                'is_active' => true,
                'notes' => 'وكيل سفر لوجانو',
            ],
            [
                'name' => 'السفر الميسر',
                'code' => 'SAF',
                'flight_carrier_id' => $abuKabiro?->id,
                'contact_person' => 'خالد مصطفى',
                'contact_phone' => '+20 122 987 6543',
                'contact_email' => 'khaled@alsafar.com',
                'commission_rate' => 6.50,
                'is_active' => true,
                'notes' => 'وكيل سفر السفر الميسر',
            ],
        ];

        foreach ($groups as $group) {
            FlightGroup::updateOrCreate(
                ['code' => $group['code']],
                $group
            );
        }

        $this->command->info('✅ Flight groups seeded successfully!');
    }
}
