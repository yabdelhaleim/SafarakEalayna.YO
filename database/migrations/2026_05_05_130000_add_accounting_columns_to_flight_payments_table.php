<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_payments', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('amount')->constrained('accounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('flight_payments', 'transaction_id')) {
                $table->foreignId('transaction_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('flight_payments', 'notes')) {
                $table->text('notes')->nullable()->after('transaction_id');
            }
            if (! Schema::hasColumn('flight_payments', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('flight_payments', function (Blueprint $table) {
            if (Schema::hasColumn('flight_payments', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            if (Schema::hasColumn('flight_payments', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('flight_payments', 'transaction_id')) {
                $table->dropConstrainedForeignId('transaction_id');
            }
            if (Schema::hasColumn('flight_payments', 'account_id')) {
                $table->dropConstrainedForeignId('account_id');
            }
        });
    }
};
