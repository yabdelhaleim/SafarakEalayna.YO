<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $names = [];

        foreach (config('accounting.clearing.prepaid', []) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return;
        }

        DB::table('accounts')
            ->whereIn('name', $names)
            ->update(['module_type' => 'office']);
    }

    public function down(): void
    {
        //
    }
};
