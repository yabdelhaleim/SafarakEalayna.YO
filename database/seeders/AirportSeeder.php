<?php

namespace Database\Seeders;

use App\Models\Airport;
use Illuminate\Database\Seeder;

class AirportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $airports = [
            // مصر
            ['iata_code' => 'CAI', 'icao_code' => 'HECA', 'city_name_ar' => 'القاهرة', 'city_name_en' => 'Cairo', 'airport_name_ar' => 'مطار القاهرة الدولي', 'airport_name_en' => 'Cairo International Airport', 'country_code' => 'EG', 'country_name_ar' => 'مصر', 'country_name_en' => 'Egypt', 'latitude' => 30.121943, 'longitude' => 31.405553, 'timezone' => 'Africa/Cairo', 'is_active' => true],
            ['iata_code' => 'HRG', 'icao_code' => 'HEGN', 'city_name_ar' => 'الغردقة', 'city_name_en' => 'Hurghada', 'airport_name_ar' => 'مطار الغردقة الدولي', 'airport_name_en' => 'Hurghada International Airport', 'country_code' => 'EG', 'country_name_ar' => 'مصر', 'country_name_en' => 'Egypt', 'latitude' => 27.178424, 'longitude' => 33.799423, 'timezone' => 'Africa/Cairo', 'is_active' => true],
            ['iata_code' => 'SSH', 'icao_code' => 'HESH', 'city_name_ar' => 'شرم الشيخ', 'city_name_en' => 'Sharm El Sheikh', 'airport_name_ar' => 'مطار شرم الشيخ الدولي', 'airport_name_en' => 'Sharm El Sheikh International Airport', 'country_code' => 'EG', 'country_name_ar' => 'مصر', 'country_name_en' => 'Egypt', 'latitude' => 27.977288, 'longitude' => 34.395016, 'timezone' => 'Africa/Cairo', 'is_active' => true],
            ['iata_code' => 'ALE', 'icao_code' => 'HEAX', 'city_name_ar' => 'الإسكندرية', 'city_name_en' => 'Alexandria', 'airport_name_ar' => 'مطار الإسكندرية الدولي', 'airport_name_en' => 'Alexandria International Airport', 'country_code' => 'EG', 'country_name_ar' => 'مصر', 'country_name_en' => 'Egypt', 'latitude' => 31.199673, 'longitude' => 29.839402, 'timezone' => 'Africa/Cairo', 'is_active' => true],

            // السعودية
            ['iata_code' => 'JED', 'icao_code' => 'OEJN', 'city_name_ar' => 'جدة', 'city_name_en' => 'Jeddah', 'airport_name_ar' => 'مطار الملك عبدالعزيز الدولي', 'airport_name_en' => 'King Abdulaziz International Airport', 'country_code' => 'SA', 'country_name_ar' => 'السعودية', 'country_name_en' => 'Saudi Arabia', 'latitude' => 21.679644, 'longitude' => 39.156547, 'timezone' => 'Asia/Riyadh', 'is_active' => true],
            ['iata_code' => 'RUH', 'icao_code' => 'OERK', 'city_name_ar' => 'الرياض', 'city_name_en' => 'Riyadh', 'airport_name_ar' => 'مطار الملك خالد الدولي', 'airport_name_en' => 'King Khalid International Airport', 'country_code' => 'SA', 'country_name_ar' => 'السعودية', 'country_name_en' => 'Saudi Arabia', 'latitude' => 24.957641, 'longitude' => 46.698874, 'timezone' => 'Asia/Riyadh', 'is_active' => true],
            ['iata_code' => 'DMM', 'icao_code' => 'OEDF', 'city_name_ar' => 'الدمام', 'city_name_en' => 'Dammam', 'airport_name_ar' => 'مطار الملك فهد الدولي', 'airport_name_en' => 'King Fahd International Airport', 'country_code' => 'SA', 'country_name_ar' => 'السعودية', 'country_name_en' => 'Saudi Arabia', 'latitude' => 26.471173, 'longitude' => 49.797887, 'timezone' => 'Asia/Riyadh', 'is_active' => true],
            ['iata_code' => 'MED', 'icao_code' => 'OEMA', 'city_name_ar' => 'المدينة المنورة', 'city_name_en' => 'Medina', 'airport_name_ar' => 'مطار الأمير محمد بن عبدالعزيز الدولي', 'airport_name_en' => 'Prince Mohammad bin Abdulaziz International Airport', 'country_code' => 'SA', 'country_name_ar' => 'السعودية', 'country_name_en' => 'Saudi Arabia', 'latitude' => 24.553423, 'longitude' => 39.705105, 'timezone' => 'Asia/Riyadh', 'is_active' => true],

            // الكويت
            ['iata_code' => 'KWI', 'icao_code' => 'OKBK', 'city_name_ar' => 'الكويت', 'city_name_en' => 'Kuwait', 'airport_name_ar' => 'مطار الكويت الدولي', 'airport_name_en' => 'Kuwait International Airport', 'country_code' => 'KW', 'country_name_ar' => 'الكويت', 'country_name_en' => 'Kuwait', 'latitude' => 29.226557, 'longitude' => 47.968877, 'timezone' => 'Asia/Kuwait', 'is_active' => true],

            // الإمارات
            ['iata_code' => 'DXB', 'icao_code' => 'OMDB', 'city_name_ar' => 'دبي', 'city_name_en' => 'Dubai', 'airport_name_ar' => 'مطار دبي الدولي', 'airport_name_en' => 'Dubai International Airport', 'country_code' => 'AE', 'country_name_ar' => 'الإمارات', 'country_name_en' => 'United Arab Emirates', 'latitude' => 25.253175, 'longitude' => 55.365673, 'timezone' => 'Asia/Dubai', 'is_active' => true],
            ['iata_code' => 'AUH', 'icao_code' => 'OMAA', 'city_name_ar' => 'أبوظبي', 'city_name_en' => 'Abu Dhabi', 'airport_name_ar' => 'مطار أبوظبي الدولي', 'airport_name_en' => 'Abu Dhabi International Airport', 'country_code' => 'AE', 'country_name_ar' => 'الإمارات', 'country_name_en' => 'United Arab Emirates', 'latitude' => 24.433030, 'longitude' => 54.651148, 'timezone' => 'Asia/Dubai', 'is_active' => true],

            // قطر
            ['iata_code' => 'DOH', 'icao_code' => 'OTHH', 'city_name_ar' => 'الدوحة', 'city_name_en' => 'Doha', 'airport_name_ar' => 'مطار حمد الدولي', 'airport_name_en' => 'Hamad International Airport', 'country_code' => 'QA', 'country_name_ar' => 'قطر', 'country_name_en' => 'Qatar', 'latitude' => 25.260919, 'longitude' => 51.613757, 'timezone' => 'Asia/Qatar', 'is_active' => true],

            // الأردن
            ['iata_code' => 'AMM', 'icao_code' => 'OJAI', 'city_name_ar' => 'عمان', 'city_name_en' => 'Amman', 'airport_name_ar' => 'مطار الملكة علياء الدولي', 'airport_name_en' => 'Queen Alia International Airport', 'country_code' => 'JO', 'country_name_ar' => 'الأردن', 'country_name_en' => 'Jordan', 'latitude' => 31.722614, 'longitude' => 35.993197, 'timezone' => 'Asia/Amman', 'is_active' => true],

            // تركيا
            ['iata_code' => 'IST', 'icao_code' => 'LTFM', 'city_name_ar' => 'إسطنبول', 'city_name_en' => 'Istanbul', 'airport_name_ar' => 'مطار إسطنبول', 'airport_name_en' => 'Istanbul Airport', 'country_code' => 'TR', 'country_name_ar' => 'تركيا', 'country_name_en' => 'Turkey', 'latitude' => 41.275341, 'longitude' => 28.751959, 'timezone' => 'Europe/Istanbul', 'is_active' => true],

            // بريطانيا
            ['iata_code' => 'LHR', 'icao_code' => 'EGLL', 'city_name_ar' => 'لندن', 'city_name_en' => 'London', 'airport_name_ar' => 'مطار هيثرو', 'airport_name_en' => 'London Heathrow Airport', 'country_code' => 'GB', 'country_name_ar' => 'بريطانيا', 'country_name_en' => 'United Kingdom', 'latitude' => 51.470022, 'longitude' => -0.454295, 'timezone' => 'Europe/London', 'is_active' => true],
        ];

        foreach ($airports as $airport) {
            Airport::updateOrCreate(
                ['iata_code' => $airport['iata_code']],
                $airport
            );
        }

        $this->command->info('✅ Airports seeded successfully! (' . count($airports) . ' airports)');
    }
}
