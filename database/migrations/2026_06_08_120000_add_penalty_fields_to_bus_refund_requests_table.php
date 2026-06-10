<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_refund_requests', function (Blueprint $table) {
            $table->decimal('company_penalty', 15, 2)->default(0)->after('cancellation_fee');
            $table->decimal('office_penalty', 15, 2)->default(0)->after('company_penalty');
            $table->decimal('total_paid', 15, 2)->default(0)->after('office_penalty');
            $table->foreignId('account_id')->nullable()->after('treasury_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->after('account_id')->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bus_refund_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_id');
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn(['company_penalty', 'office_penalty', 'total_paid']);
        });
    }
};
