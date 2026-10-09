<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Converts Office-division customer debt repayment transactions from
     * type='income' to type='transfer'.
     *
     * Context:
     * Office modules (Fawry, Bus, Online, Wallet) recognize revenues upon operation
     * creation (accrual via income-clearing accounts). Customer debt repayments (سند قبض)
     * through customers.pay_debt were recorded with type='income', causing double-counting
     * of revenue in P&L and creating false deficits in the office trial balance.
     */
    public function up(): void
    {
        $officeModules = ['fawry', 'bus', 'online', 'wallet', 'wallet_transfer', 'office'];

        $count = DB::table('transactions')
            ->whereIn('module', $officeModules)
            ->where('type', 'income')
            ->where(function ($query) {
                $query->where('notes', 'like', '%سند قبض - تسديد مديونية عميل%')
                    ->orWhere('notes', 'like', '%تسديد مديونية عميل%');
            })
            ->update([
                'type' => 'transfer',
            ]);

        Log::info("Fixed {$count} office customer debt repayment transactions from type 'income' to 'transfer'.");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $officeModules = ['fawry', 'bus', 'online', 'wallet', 'wallet_transfer', 'office'];

        DB::table('transactions')
            ->whereIn('module', $officeModules)
            ->where('type', 'transfer')
            ->where(function ($query) {
                $query->where('notes', 'like', '%سند قبض - تسديد مديونية عميل%')
                    ->orWhere('notes', 'like', '%تسديد مديونية عميل%');
            })
            ->update([
                'type' => 'income',
            ]);
    }
};
