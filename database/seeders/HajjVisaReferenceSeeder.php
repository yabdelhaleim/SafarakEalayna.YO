<?php

namespace Database\Seeders;

use App\Models\HajjUmra\AccommodationType;
use App\Models\HajjUmra\VisaDuration;
use Illuminate\Database\Seeder;

class HajjVisaReferenceSeeder extends Seeder
{
    public function run(): void
    {
        // أنواع التسكين الأساسية
        $accommodations = [
            ['code' => 'single', 'name_ar' => 'فردي', 'name_en' => 'Single', 'capacity' => 1, 'sort_order' => 10],
            ['code' => 'double', 'name_ar' => 'مزدوج', 'name_en' => 'Double', 'capacity' => 2, 'sort_order' => 20],
            ['code' => 'triple', 'name_ar' => 'ثلاثي', 'name_en' => 'Triple', 'capacity' => 3, 'sort_order' => 30],
            ['code' => 'quad', 'name_ar' => 'رباعي', 'name_en' => 'Quad', 'capacity' => 4, 'sort_order' => 40],
            ['code' => 'quintuple', 'name_ar' => 'خماسي', 'name_en' => 'Quintuple', 'capacity' => 5, 'sort_order' => 50],
        ];

        foreach ($accommodations as $row) {
            AccommodationType::updateOrCreate(['code' => $row['code']], $row + ['is_active' => true]);
        }

        // مدد التأشيرة الشائعة
        $durations = [
            ['code' => '1m_single', 'label_ar' => 'شهر واحد - دخول واحد', 'months' => 1, 'entry_type' => 'single', 'sort_order' => 10],
            ['code' => '3m_single', 'label_ar' => '٣ شهور - دخول واحد', 'months' => 3, 'entry_type' => 'single', 'sort_order' => 20],
            ['code' => '3m_multiple', 'label_ar' => '٣ شهور - دخول متعدد', 'months' => 3, 'entry_type' => 'multiple', 'sort_order' => 25],
            ['code' => '6m_single', 'label_ar' => '٦ شهور - دخول واحد', 'months' => 6, 'entry_type' => 'single', 'sort_order' => 30],
            ['code' => '6m_multiple', 'label_ar' => '٦ شهور - دخول متعدد', 'months' => 6, 'entry_type' => 'multiple', 'sort_order' => 35],
            ['code' => '1y_single', 'label_ar' => 'سنة - دخول واحد', 'months' => 12, 'entry_type' => 'single', 'sort_order' => 40],
            ['code' => '1y_multiple', 'label_ar' => 'سنة - دخول متعدد', 'months' => 12, 'entry_type' => 'multiple', 'sort_order' => 45],
            ['code' => '2y_multiple', 'label_ar' => 'سنتان - دخول متعدد', 'months' => 24, 'entry_type' => 'multiple', 'sort_order' => 50],
            ['code' => '5y_multiple', 'label_ar' => '٥ سنوات - دخول متعدد', 'months' => 60, 'entry_type' => 'multiple', 'sort_order' => 60],
            ['code' => 'umrah', 'label_ar' => 'تأشيرة عمرة', 'months' => null, 'entry_type' => 'single', 'sort_order' => 70],
            ['code' => 'hajj', 'label_ar' => 'تأشيرة حج', 'months' => null, 'entry_type' => 'single', 'sort_order' => 80],
        ];

        foreach ($durations as $row) {
            VisaDuration::updateOrCreate(['code' => $row['code']], $row + ['is_active' => true]);
        }
    }
}
