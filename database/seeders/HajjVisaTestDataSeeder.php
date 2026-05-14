<?php

namespace Database\Seeders;

use App\Models\HajjUmra\AccommodationType;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\TripSupervisor;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Program;
use Illuminate\Database\Seeder;

class HajjVisaTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $company = HajjUmraExecutingCompany::updateOrCreate(
            ['name' => 'شركة الفجر للسياحة'],
            ['license_number' => 'LIC-1001', 'phone' => '01000000001', 'is_active' => true],
        );

        $supervisor = TripSupervisor::updateOrCreate(
            ['full_name' => 'الشيخ أحمد محمد'],
            ['phone' => '01100000001', 'national_id' => '29501010100001', 'is_active' => true],
        );

        $accommodation = AccommodationType::where('code', 'quad')->first();

        Program::updateOrCreate(
            ['program_name' => 'عمرة شعبان ١٤٤٧'],
            [
                'program_type' => 'umra',
                'season' => '1447H',
                'total_nights' => 10,
                'mecca_hotel_name' => 'فندق دار التوحيد',
                'mecca_nights' => 6,
                'medina_hotel_name' => 'فندق روتانا المدينة',
                'medina_nights' => 4,
                'departure_date' => now()->addDays(30)->toDateString(),
                'return_date' => now()->addDays(40)->toDateString(),
                'airline' => 'مصر للطيران',
                'departure_point' => 'القاهرة',
                'accommodation_type' => $accommodation?->name_ar,
                'accommodation_type_id' => $accommodation?->id,
                'trip_supervisor' => $supervisor->full_name,
                'trip_supervisor_id' => $supervisor->id,
                'executing_company' => $company->name,
                'executing_company_id' => $company->id,
                'booking_status' => 'open',
                'default_purchase_price' => 28000,
                'default_selling_price' => 32000,
                'is_active' => true,
            ],
        );

        VisaAgent::updateOrCreate(
            ['company_name' => 'مكتب البركة للتأشيرات'],
            [
                'contact_person' => 'محمد علي',
                'phone' => '01200000001',
                'email' => 'baraka@example.com',
                'country' => 'السعودية',
                'is_active' => true,
            ],
        );
    }
}
