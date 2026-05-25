<?php

namespace App\Services\Reports;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportFinanceService
{
    /**
     * Get overall financial summary across all accounts.
     *
     * @param  array  $filters  Keys: from_date, to_date
     */
    public function getFinancialSummary(array $filters): array
    {
        $query = DB::table('transactions');

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['module'])) {
            if (is_array($filters['module'])) {
                $query->whereIn('module', $filters['module']);
            } else {
                $query->where('module', $filters['module']);
            }
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        $totals = $query->selectRaw('
            SUM(CASE WHEN type = "income" THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) as total_expense,
            SUM(CASE WHEN type = "refund" THEN amount ELSE 0 END) as total_refunds,
            SUM(CASE WHEN type = "transfer" THEN amount ELSE 0 END) as total_transfers
        ')->first();

        $total_income = (float) ($totals->total_income ?? 0);
        $total_expense = (float) ($totals->total_expense ?? 0);
        $total_refunds = (float) ($totals->total_refunds ?? 0);
        $net_profit = $total_income - $total_expense - $total_refunds;

        return [
            'total_income' => round($total_income, 2),
            'total_expense' => round($total_expense, 2),
            'total_refunds' => round($total_refunds, 2),
            'total_transfers' => round((float) ($totals->total_transfers ?? 0), 2),
            'net_profit' => round($net_profit, 2),
            'period' => [
                'from' => $filters['from_date'] ?? null,
                'to' => $filters['to_date'] ?? null,
            ],
        ];
    }

    /**
     * Get balance summary for all accounts.
     * No date filter — shows current state.
     */
    public function getAccountsBalance(): array
    {
        $accounts = DB::table('accounts')
            ->select('id', 'name', 'type', 'currency', 'balance')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type,
                    'currency' => $account->currency,
                    'balance' => round((float) $account->balance, 2),
                ];
            });

        $totals = DB::table('accounts')->selectRaw('
            SUM(CASE WHEN type = "cashbox" THEN balance ELSE 0 END) as cashbox,
            SUM(CASE WHEN type = "wallet" THEN balance ELSE 0 END) as wallet,
            SUM(CASE WHEN type = "bank" THEN balance ELSE 0 END) as bank,
            SUM(balance) as grand_total
        ')->first();

        return [
            'accounts' => $accounts,
            'total_cashbox' => round((float) ($totals->cashbox ?? 0), 2),
            'total_wallet' => round((float) ($totals->wallet ?? 0), 2),
            'total_bank' => round((float) ($totals->bank ?? 0), 2),
            'grand_total' => round((float) ($totals->grand_total ?? 0), 2),
        ];
    }

    /**
     * Get income breakdown grouped by module.
     *
     * @param  array  $filters  Keys: from_date, to_date
     */
    public function getIncomeByModule(array $filters): array
    {
        $query = DB::table('transactions')->where('type', 'income');

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        $modules = $query->selectRaw('
            module,
            SUM(amount) as total
        ')->groupBy('module')->pluck('total', 'module');

        $result = [
            'flight' => round((float) ($modules['flight'] ?? 0), 2),
            'bus' => round((float) ($modules['bus'] ?? 0), 2),
            'service' => round((float) ($modules['service'] ?? 0), 2),
            'online' => round((float) ($modules['online'] ?? 0), 2),
            'general' => round((float) ($modules['general'] ?? 0), 2),
        ];

        $result['total'] = round(array_sum($result), 2);

        return $result;
    }

    /**
     * Get expense breakdown grouped by module.
     *
     * @param  array  $filters  Keys: from_date, to_date
     */
    public function getExpenseByModule(array $filters): array
    {
        $query = DB::table('transactions')->where('type', 'expense');

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        $modules = $query->selectRaw('
            module,
            SUM(amount) as total
        ')->groupBy('module')->pluck('total', 'module');

        $result = [
            'flight' => round((float) ($modules['flight'] ?? 0), 2),
            'bus' => round((float) ($modules['bus'] ?? 0), 2),
            'service' => round((float) ($modules['service'] ?? 0), 2),
            'online' => round((float) ($modules['online'] ?? 0), 2),
            'general' => round((float) ($modules['general'] ?? 0), 2),
        ];

        $result['total'] = round(array_sum($result), 2);

        return $result;
    }

    /**
     * Get daily income and expense totals for a date range.
     * Used for charts.
     *
     * @param  array  $filters  Keys: from_date (required), to_date (required)
     */
    public function getDailyFinancialChart(array $filters): Collection
    {
        $query = DB::table('transactions')
            ->selectRaw('
                DATE(created_at) as date,
                SUM(CASE WHEN type = "income" THEN amount ELSE 0 END) as total_income,
                SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) as total_expense
            ')
            ->whereDate('created_at', '>=', $filters['from_date'])
            ->whereDate('created_at', '<=', $filters['to_date'])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)');

        return $query->get()->map(function ($row) {
            $income = (float) ($row->total_income ?? 0);
            $expense = (float) ($row->total_expense ?? 0);

            return [
                'date' => $row->date,
                'total_income' => round($income, 2),
                'total_expense' => round($expense, 2),
                'net' => round($income - $expense, 2),
            ];
        });
    }

    /**
     * Get transaction history with filters for finance report.
     *
     * @param  array  $filters  Keys: type, module, account_id, from_date, to_date, per_page
     */
    public function getTransactionReport(array $filters): LengthAwarePaginator
    {
        $query = DB::table('transactions')
            ->leftJoin('users', 'transactions.created_by', '=', 'users.id')
            ->leftJoin('accounts as from_account', 'transactions.from_account_id', '=', 'from_account.id')
            ->leftJoin('accounts as to_account', 'transactions.to_account_id', '=', 'to_account.id')
            ->select(
                'transactions.id',
                'transactions.type',
                'transactions.amount',
                'transactions.module',
                'transactions.notes',
                'transactions.created_at',
                'transactions.from_account_id',
                'transactions.to_account_id',
                'users.name as created_by_name',
                'from_account.name as from_account_name',
                'from_account.type as from_account_type',
                'to_account.name as to_account_name',
                'to_account.type as to_account_type'
            );

        if (! empty($filters['type'])) {
            $query->where('transactions.type', $filters['type']);
        }

        if (! empty($filters['module'])) {
            if (is_array($filters['module'])) {
                $query->whereIn('transactions.module', $filters['module']);
            } else {
                $query->where('transactions.module', $filters['module']);
            }
        }

        if (! empty($filters['account_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('transactions.from_account_id', $filters['account_id'])
                    ->orWhere('transactions.to_account_id', $filters['account_id']);
            });
        }

        if (! empty($filters['account_type'])) {
            $t = $filters['account_type'];
            $query->where(function ($q) use ($t) {
                $q->where('from_account.type', $t)
                    ->orWhere('to_account.type', $t);
            });
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('transactions.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('transactions.created_at', '<=', $filters['to_date']);
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));

        return $query->orderBy('transactions.created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get a single transaction with related details.
     */
    public function getTransactionDetail(int $id): ?\stdClass
    {
        $txModel = \App\Models\Transaction::with([
            'createdBy',
            'fromAccount',
            'toAccount',
            'entries.account',
            'related' => function($morph) {
                $morph->morphWith([
                    \App\Models\FlightBooking::class => ['customer', 'passengers', 'airlineAccount', 'flightSystem', 'flightCarrier', 'flightGroup'],
                    \App\Models\VisaBooking::class => ['customer', 'visaDetail'],
                    \App\Models\HajjUmraBooking::class => ['customer', 'program'],
                    \App\Models\Bus\BusBooking::class => ['customer', 'inventory.company'],
                    \App\Models\Online\OnlineTransaction::class => ['customer', 'serviceType', 'provider'],
                ]);
            }
        ])->find($id);

        if (!$txModel) {
            return null;
        }

        // Convert to standard object to match original signature and fields
        $transaction = new \stdClass();
        $transaction->id = $txModel->id;
        $transaction->type = $txModel->type instanceof \App\Enums\TransactionType ? $txModel->type->value : (string)$txModel->type;
        $transaction->amount = (float)$txModel->amount;
        $transaction->module = $txModel->module instanceof \App\Enums\TransactionModule ? $txModel->module->value : (string)$txModel->module;
        $transaction->notes = $txModel->notes;
        $transaction->created_at = $txModel->created_at ? $txModel->created_at->toDateTimeString() : null;
        $transaction->from_account_id = $txModel->from_account_id;
        $transaction->to_account_id = $txModel->to_account_id;
        $transaction->created_by_name = $txModel->createdBy?->name;
        $transaction->from_account_name = $txModel->fromAccount?->name;
        $transaction->from_account_type = $txModel->fromAccount?->type;
        $transaction->from_account_currency = $txModel->fromAccount?->currency;
        $transaction->to_account_name = $txModel->toAccount?->name;
        $transaction->to_account_type = $txModel->toAccount?->type;
        $transaction->to_account_currency = $txModel->toAccount?->currency;

        // Map entries
        $transaction->entries = $txModel->entries->map(function($e) {
            $entry = new \stdClass();
            $entry->id = $e->id;
            $entry->account_id = $e->account_id;
            $entry->debit = (float)$e->debit;
            $entry->credit = (float)$e->credit;
            $entry->balance_after = (float)$e->balance_after;
            $entry->account_name = $e->account?->name ?? '—';
            $entry->account_type = $e->account?->type ?? '—';
            $entry->account_currency = $e->account?->currency ?? 'EGP';
            return $entry;
        })->toArray();

        // Build related_meta if related model exists
        $transaction->related_meta = null;
        if ($txModel->related) {
            $related = $txModel->related;
            $meta = new \stdClass();
            $meta->type = class_basename($related);
            
            // Extract person name and phone
            $meta->person_name = null;
            $meta->person_phone = null;
            
            if (isset($related->customer) && $related->customer) {
                $meta->person_name = $related->customer->full_name ?: $related->customer->name;
                $meta->person_phone = $related->customer->phone;
            } elseif (isset($related->customer_name)) {
                $meta->person_name = $related->customer_name;
                $meta->person_phone = $related->customer_phone ?? null;
            }
            
            // Extract financial data
            $meta->total_amount = 0.0;
            $meta->paid_amount = 0.0;
            
            if ($related instanceof \App\Models\FlightBooking) {
                $meta->total_amount = (float)$related->selling_price;
                $meta->paid_amount = (float)$related->payments()->sum('amount');
                $meta->details = "حجز طيران - PNR: " . ($related->pnr ?: '—') . " - خط الطيران: " . ($related->airline_name ?: '—') . " (" . ($related->origin ?: '—') . " -> " . ($related->destination ?: '—') . ")";
            } elseif ($related instanceof \App\Models\VisaBooking) {
                $meta->total_amount = (float)$related->selling_price + (float)($related->service_fee ?? 0);
                $meta->paid_amount = (float)$related->payments()->sum('amount');
                $meta->details = "حجز تأشيرة - النوع: " . ($related->visaDetail?->title ?: '—') . " - الوكيل: " . ($related->agent_name ?: '—');
            } elseif ($related instanceof \App\Models\HajjUmraBooking) {
                $meta->total_amount = (float)$related->selling_price;
                $meta->paid_amount = (float)$related->payments()->sum('amount');
                $meta->details = "حجز حج وعمرة - البرنامج: " . ($related->program?->name ?: '—') . " - الوكيل: " . ($related->agent_name ?: '—');
            } elseif ($related instanceof \App\Models\Bus\BusBooking) {
                $meta->total_amount = (float)$related->total_price;
                $meta->paid_amount = (float)$related->paid_amount;
                $meta->details = "حجز باص - الرحلة: " . ($related->inventory?->origin ?: '—') . " -> " . ($related->inventory?->destination ?: '—') . " - الشركة: " . ($related->inventory?->company?->name ?: '—');
            } elseif ($related instanceof \App\Models\Online\OnlineTransaction) {
                $meta->total_amount = (float)$related->selling_price;
                $meta->paid_amount = (float)$related->selling_price; // Online services are generally fully paid
                $meta->details = "خدمة إلكترونية - النوع: " . ($related->serviceType?->name ?: '—') . " - المزود: " . ($related->provider?->name ?: '—');
            }
            
            $meta->remaining_amount = max(0.0, $meta->total_amount - $meta->paid_amount);
            
            $transaction->related_meta = $meta;
        }

        return $transaction;
    }

    /**
     * Get bus company debt summary.
     * Shows which companies the office still owes money to.
     */
    public function getBusDebtSummary(): Collection
    {
        return DB::table('bus_inventories')
            ->join('bus_companies', 'bus_inventories.company_id', '=', 'bus_companies.id')
            ->where('bus_inventories.remaining_debt', '>', 0)
            ->selectRaw('
                bus_companies.id as company_id,
                bus_companies.name as company_name,
                SUM(bus_inventories.remaining_debt) as total_debt,
                COUNT(*) as inventory_count
            ')
            ->groupBy('bus_companies.id', 'bus_companies.name')
            ->orderBy('total_debt', 'desc')
            ->get()
            ->map(function ($row) {
                return [
                    'company_id' => $row->company_id,
                    'company_name' => $row->company_name,
                    'total_debt' => round((float) $row->total_debt, 2),
                    'inventory_count' => $row->inventory_count,
                ];
            });
    }

    public function getProfitLossReport(array $filters): array
    {
        $tourismModules = ['flight', 'hajj_umra', 'visa'];
        $officeModules = ['bus', 'fawry', 'online', 'wallet'];
        
        $query = DB::table('transactions');
        
        if (!empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }
        if (!empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }
        
        if (!empty($filters['module']) && $filters['module'] !== 'all') {
            $query->where('module', $filters['module']);
        } elseif (!empty($filters['category']) && $filters['category'] !== 'all') {
            if ($filters['category'] === 'tourism') {
                $query->whereIn('module', $tourismModules);
            } elseif ($filters['category'] === 'office') {
                $query->whereIn('module', $officeModules);
            }
        }
        
        $transactions = $query->select('type', 'module', DB::raw('SUM(amount) as total'))
            ->whereIn('type', ['income', 'expense'])
            ->groupBy('type', 'module')
            ->get();
            
        $revenuesList = [];
        $expensesList = [];
        $cogsList = []; // Assuming no explicit COGS yet, we keep it empty or map it if you have specific COGS accounts.
        
        $totalRevenues = 0;
        $totalExpenses = 0;
        
        foreach ($transactions as $tx) {
            $moduleName = $this->getModuleName($tx->module);
            $amount = (float) $tx->total;
            
            if ($tx->type === 'income') {
                $revenuesList[] = ['name' => "إيرادات " . $moduleName, 'amount' => $amount];
                $totalRevenues += $amount;
            } else if ($tx->type === 'expense') {
                $expensesList[] = ['name' => "مصروفات " . $moduleName, 'amount' => $amount];
                $totalExpenses += $amount;
            }
        }
        
        return [
            'totalRevenues' => round($totalRevenues, 2),
            'totalCogs' => 0,
            'totalExpenses' => round($totalExpenses, 2),
            'revenuesList' => $revenuesList,
            'cogsList' => $cogsList,
            'expensesList' => $expensesList
        ];
    }
    
    private function getModuleName(?string $module): string
    {
        return match($module) {
            'flight' => 'وحدة الطيران',
            'hajj_umra' => 'الحج والعمرة',
            'visa' => 'التأشيرات',
            'bus' => 'وحدة الباص',
            'fawry' => 'فوري',
            'online' => 'الخدمات الإلكترونية',
            'wallet' => 'المحافظ الإلكترونية',
            'general' => 'عام',
            default => 'أخرى (' . ($module ?? 'غير محدد') . ')',
        };
    }
}
