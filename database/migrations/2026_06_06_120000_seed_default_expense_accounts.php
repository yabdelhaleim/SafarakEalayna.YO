<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // لا بيانات افتراضية — حسابات المصروفات تُنشأ من داخل التطبيق فقط.
    }

    public function down(): void
    {
        //
    }
};
