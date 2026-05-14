<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$mappings = [
    'flight' => ['طيران', 'flight'],
    'bus' => ['باص', 'bus'],
    'visa' => ['تاشيرة', 'تأشيرة', 'visa'],
    'hajj_umra' => ['حج', 'عمرة', 'hajj', 'umra'],
    'wallet' => ['محفظة', 'محفظه', 'wallet', 'instapay', 'vodafone'],
    'fawry' => ['فوري', 'fawry'],
    'online' => ['الكترونية', 'إلكترونية', 'online'],
];

$count = 0;
foreach ($mappings as $module => $keywords) {
    foreach ($keywords as $keyword) {
        $updated = \App\Models\Account::where('name', 'like', '%' . $keyword . '%')
            ->where(function($q) {
                $q->whereNull('module')->orWhere('module', 'general')->orWhere('module', '');
            })
            ->update(['module' => $module]);
        $count += $updated;
    }
}

echo "Updated $count accounts.\n";
