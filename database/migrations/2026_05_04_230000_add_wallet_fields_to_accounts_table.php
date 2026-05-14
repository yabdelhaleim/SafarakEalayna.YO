<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * حقول محددة لحسابات المحافظ (type = wallet): نوع المزود + رقم المحفظة/الهاتف.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('wallet_provider', 40)->nullable()->after('notes');
            $table->string('wallet_number', 100)->nullable()->after('wallet_provider');
            $table->index(['type', 'wallet_provider']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['type', 'wallet_provider']);
            $table->dropColumn(['wallet_provider', 'wallet_number']);
        });
    }
};
