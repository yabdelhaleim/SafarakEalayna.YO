<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links banks & customers to the accounts ledger, aligns treasury_transactions with Ledger + DB columns used in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table): void {
            if (! Schema::hasColumn('banks', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('id')->constrained('accounts')->nullOnDelete();
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('id')->constrained('accounts')->nullOnDelete();
            }
        });

        Schema::table('treasury_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('treasury_transactions', 'transaction_type')) {
                $table->string('transaction_type', 32)->nullable()->after('id');
            }
            if (! Schema::hasColumn('treasury_transactions', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('transaction_type')->constrained('accounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('treasury_transactions', 'balance_before')) {
                $table->decimal('balance_before', 15, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('treasury_transactions', 'balance_after')) {
                $table->decimal('balance_after', 15, 2)->nullable()->after('balance_before');
            }
            if (! Schema::hasColumn('treasury_transactions', 'reference_number')) {
                $table->string('reference_number')->nullable()->after('agent_name');
            }
            if (! Schema::hasColumn('treasury_transactions', 'ledger_transaction_id')) {
                $table->foreignId('ledger_transaction_id')->nullable()->after('reference_number')->constrained('transactions')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('treasury_transactions', function (Blueprint $table): void {
            foreach (['ledger_transaction_id', 'reference_number', 'balance_after', 'balance_before', 'account_id', 'transaction_type'] as $col) {
                if (! Schema::hasColumn('treasury_transactions', $col)) {
                    continue;
                }

                try {
                    if (str_contains($col, '_id')) {
                        $table->dropConstrainedForeignId($col);
                    } else {
                        $table->dropColumn($col);
                    }
                } catch (\Throwable) {
                    // tolerate partial state
                }
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'account_id')) {
                $table->dropConstrainedForeignId('account_id');
            }
        });

        Schema::table('banks', function (Blueprint $table): void {
            if (Schema::hasColumn('banks', 'account_id')) {
                $table->dropConstrainedForeignId('account_id');
            }
        });
    }
};
