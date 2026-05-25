<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProgramSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('programs')->insert([
            [
                'program_name' => 'برنامج حج 2026',
                'program_type' => 'HAJJ',
                'season' => '2026',
                'total_nights' => 15,
                'accommodation_type' => 'SINGLE',
                'mecca_hotel_name' => 'فندق مكة',
                'mecca_nights' => 10,
                'medina_hotel_name' => 'فندق المدينة',
                'medina_nights' => 5,
                'departure_date' => '2026-06-01',
                'return_date' => '2026-06-16',
                'airline' => 'الخطوط السعودية',
                'trip_supervisor' => 'مشرف 1',
                'executing_company' => 'شركة المناسك',
                'departure_point' => 'القاهرة',
                'booking_status' => 'CONFIRMED',
                'program_price_tier' => 'standard',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'program_name' => 'برنامج عمرة 2026',
                'program_type' => 'UMRA',
                'season' => '2026',
                'total_nights' => 10,
                'accommodation_type' => 'DOUBLE',
                'mecca_hotel_name' => 'فندق مكة',
                'mecca_nights' => 7,
                'medina_hotel_name' => 'فندق المدينة',
                'medina_nights' => 3,
                'departure_date' => '2026-07-01',
                'return_date' => '2026-07-11',
                'airline' => 'الخطوط السعودية',
                'trip_supervisor' => 'مشرف 2',
                'executing_company' => 'شركة المناسك',
                'departure_point' => 'القاهرة',
                'booking_status' => 'CONFIRMED',
                'program_price_tier' => 'standard',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
