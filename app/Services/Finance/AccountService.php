<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bus\BusBooking;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\HajjUmraBooking;
use App\Models\Online\OnlineTransaction;
use App\Models\VisaBooking;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function buildAccountsQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = Account::query();

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('wallet_number', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['type']) && empty($filters['account_type'])) {
            $filters['account_type'] = $filters['type'];
        }

        if (isset($filters['account_type'])) {
            if ($filters['account_type'] === 'collection') {
                $query->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES);
            } else {
                $query->where('type', $filters['account_type']);
            }
        }

        if (! empty($filters['types'])) {
            $raw = $filters['types'];
            $types = is_array($raw) ? $raw : explode(',', (string) $raw);
            $types = array_values(array_filter(array_map('trim', $types)));
            if ($types !== []) {
                $query->whereIn('type', $types);
            }
        }

        // Finance accounts page lists treasury liquidity only; expense/customer accounts use explicit type filters.
        if (! isset($filters['account_type']) && empty($filters['types'])) {
            $query->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        AccountModuleDivision::applyLiquidityTreasuryScope($query);

        if (isset($filters['currency']) && ! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (isset($filters['owner_type']) && ! empty($filters['owner_type'])) {
            if (is_array($filters['owner_type'])) {
                $query->whereIn('owner_type', $filters['owner_type']);
            } else {
                $query->where('owner_type', $filters['owner_type']);
            }
        }

        if (isset($filters['module_type']) && $filters['module_type'] !== '') {
            AccountModuleDivision::applyModuleTypeFilter($query, (string) $filters['module_type']);
        }

        if (isset($filters['module']) && $filters['module'] !== '') {
            AccountModuleDivision::applyModuleFilter($query, (string) $filters['module']);
        }

        if (isset($filters['payment_status']) && $filters['payment_status'] !== '') {
            if ($filters['payment_status'] === 'paid') {
                $query->where('balance', '<=', 0);
            }
            if ($filters['payment_status'] === 'unpaid') {
                $query->where('balance', '>', 1000);
            }
            if ($filters['payment_status'] === 'partial') {
                $query->whereBetween('balance', [1, 1000]);
            }
        }

        return $query;
    }

    public function getAllAccounts(array $filters): LengthAwarePaginator
    {
        $query = $this->buildAccountsQuery($filters);

        $perPage = min($filters['per_page'] ?? 15, 100);
        $accounts = $query
            ->orderBy('module_type')
            ->orderBy('name')
            ->paginate($perPage);

        $accounts->load('createdBy');

        return $accounts;
    }

    public function createAccount(array $data): Account
    {
        return DB::transaction(function () use ($data) {
            $type = $data['type'] instanceof AccountType
                ? $data['type']
                : AccountType::tryFrom((string) $data['type']);

            $attributes = [
                'name' => $data['name'],
                'type' => $data['type'],
                'balance' => $data['balance'] ?? 0.00,
                'currency' => $data['currency'] ?? 'EGP',
                'is_active' => $data['is_active'] ?? true,
                'module_type' => $data['module_type'] ?? 'tourism',
                'module' => $data['module'] ?? null,
                'owner_type' => $data['owner_type'] ?? Account::OWNER_TYPE_OWNER,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ];

            if ($type === AccountType::Wallet) {
                $attributes['wallet_provider'] = $data['wallet_provider'] ?? null;
                $attributes['wallet_number'] = $data['wallet_number'] ?? null;
            }

            $account = Account::create($attributes);

            if ($account->balance != 0) {
                // Record opening balance in entries
                AccountEntry::create([
                    'account_id' => $account->id,
                    'transaction_id' => null, // null for opening balance if no transaction exists
                    'debit' => $account->balance < 0 ? abs($account->balance) : 0,
                    'credit' => $account->balance > 0 ? $account->balance : 0,
                    'balance_after' => $account->balance,
                    'notes' => 'رصيد افتتاحي',
                ]);
            }

            Log::info('Account created', [
                'account_id' => $account->getKey(),
                'user_id' => auth()->id(),
                'data' => $data,
            ]);

            return $account;
        });
    }

    public function updateAccount(Account $account, array $data): Account
    {
        return DB::transaction(function () use ($account, $data) {
            $account->fill([
                'name' => $data['name'] ?? $account->name,
                'type' => $data['type'] ?? $account->type,
                'currency' => $data['currency'] ?? $account->currency,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : $account->is_active,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $account->notes,
                'module_type' => $data['module_type'] ?? $account->module_type,
                'module' => array_key_exists('module', $data) ? $data['module'] : $account->module,
                'owner_type' => $data['owner_type'] ?? $account->owner_type,
                'wallet_provider' => array_key_exists('wallet_provider', $data) ? $data['wallet_provider'] : $account->wallet_provider,
                'wallet_number' => array_key_exists('wallet_number', $data) ? $data['wallet_number'] : $account->wallet_number,
            ]);
            $account->save();

            Log::info('Account updated', [
                'account_id' => $account->getKey(),
                'user_id' => auth()->id(),
            ]);

            return $account->fresh();
        });
    }

    public function deactivateAccount(Account $account): bool
    {
        return DB::transaction(function () use ($account) {
            if ($account->balance != 0.00) {
                throw new ValidationException(
                    Validator::make([], []),
                    new MessageBag(['account' => 'Cannot deactivate an account with non-zero balance.'])
                );
            }

            $account->fill(['is_active' => false]);
            $account->save();

            Log::info('Account deactivated', [
                'account_id' => $account->getKey(),
                'user_id' => auth()->id(),
            ]);

            return true;
        });
    }

    public function getAccountById(int $id): Account
    {
        return Account::with('createdBy')->findOrFail($id);
    }

    public function getAccountStatement(Account $account, array $filters): array
    {
        $flightGroup = FlightGroup::where('account_id', $account->id)->first();
        if ($flightGroup) {
            return $this->getFlightGroupStatementForAccount($flightGroup, $account, $filters);
        }

        $query = $account->entries()
            ->with([
                'transaction.createdBy',
                'transaction.fromAccount',
                'transaction.toAccount',
                'transaction.related' => function ($morph) {
                    $morph->morphWith([
                        FlightBooking::class => ['customer', 'passengers', 'fromAirport', 'toAirport'],
                        BusBooking::class => ['customer', 'inventory.company'],
                        OnlineTransaction::class => ['serviceType', 'provider'],
                        VisaBooking::class => ['customer', 'visaDetail'],
                        HajjUmraBooking::class => ['customer', 'program'],
                    ]);
                },
            ]);

        // Base query for stats (without eager loading and pagination)
        $statsQuery = $account->entries();

        // Apply filters to both queries
        $this->applyStatementFilters($query, $filters);
        $this->applyStatementFilters($statsQuery, $filters);

        // Calculate Period Totals
        $periodTotals = (clone $statsQuery)->selectRaw('SUM(credit) as total_credit, SUM(debit) as total_debit')->first();

        // Calculate Opening Balance (balance before from_date)
        $openingBalance = 0;
        if (! empty($filters['from_date'])) {
            $openingBalance = $account->entries()
                ->where('created_at', '<', $filters['from_date'])
                ->selectRaw('SUM(credit) - SUM(debit) as balance')
                ->value('balance') ?? 0;
        }

        $perPage = min($filters['per_page'] ?? 20, 100);
        $paginator = $query->orderBy('account_entries.created_at', 'desc')
            ->orderBy('account_entries.id', 'desc')
            ->paginate($perPage);

        return [
            'items' => $paginator->getCollection(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'stats' => [
                'opening_balance' => (float) $openingBalance,
                'period_credit' => (float) ($periodTotals->total_credit ?? 0),
                'period_debit' => (float) ($periodTotals->total_debit ?? 0),
                'closing_balance' => (float) ($openingBalance + ($periodTotals->total_credit ?? 0) - ($periodTotals->total_debit ?? 0)),
                'account_balance' => (float) $account->balance,
            ],
        ];
    }

    protected function applyStatementFilters($query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('account_entries.notes', 'like', "%{$search}%")
                    ->orWhereHas('transaction', function ($tq) use ($search) {
                        $tq->where('notes', 'like', "%{$search}%")
                            ->orWhere('id', 'like', "%{$search}%")
                            ->orWhereHasMorph('related', [
                                FlightBooking::class,
                                BusBooking::class,
                                OnlineTransaction::class,
                                VisaBooking::class,
                                HajjUmraBooking::class,
                            ], function ($rq, $type) use ($search) {
                                if ($type === FlightBooking::class) {
                                    $rq->where('booking_reference', 'like', "%{$search}%")
                                        ->orWhere('pnr', 'like', "%{$search}%")
                                        ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$search}%"));
                                } elseif ($type === BusBooking::class) {
                                    $rq->where('booking_number', 'like', "%{$search}%")
                                        ->orWhere('passenger_name', 'like', "%{$search}%");
                                } elseif ($type === OnlineTransaction::class) {
                                    $rq->where('reference_number', 'like', "%{$search}%")
                                        ->orWhere('customer_name', 'like', "%{$search}%");
                                } elseif ($type === VisaBooking::class || $type === HajjUmraBooking::class) {
                                    $rq->whereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$search}%"));
                                }
                            });
                    });
            });
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('account_entries.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('account_entries.created_at', '<=', $filters['to_date']);
        }

        if (! empty($filters['type'])) {
            $query->where(function ($q) use ($filters) {
                if ($filters['type'] === 'credit') {
                    $q->where('credit', '>', 0);
                } elseif ($filters['type'] === 'debit') {
                    $q->where('debit', '>', 0);
                }
            });
        }

        if (! empty($filters['module'])) {
            $query->whereHas('transaction', function ($tq) use ($filters) {
                $tq->where('module', $filters['module']);
            });
        }

        if (! empty($filters['user_id'])) {
            $query->whereHas('transaction', function ($tq) use ($filters) {
                $tq->where('created_by', $filters['user_id']);
            });
        }
    }

    protected function creditAccount(Account $account, float $amount, int $transactionId): AccountEntry
    {
        return $account->getConnection()->transaction(function () use ($account, $amount, $transactionId) {
            $lockedAccount = Account::where('id', $account->id)
                ->lockForUpdate()
                ->first();

            $lockedAccount->balance += $amount;
            $lockedAccount->save();

            $entry = AccountEntry::create([
                'account_id' => $lockedAccount->getKey(),
                'transaction_id' => $transactionId,
                'debit' => 0.00,
                'credit' => $amount,
                'balance_after' => $lockedAccount->balance,
            ]);

            Log::info('Account credited', [
                'account_id' => $account->getKey(),
                'amount' => $amount,
                'transaction_id' => $transactionId,
            ]);

            return $entry;
        });
    }

    protected function debitAccount(Account $account, float $amount, int $transactionId): AccountEntry
    {
        return $account->getConnection()->transaction(function () use ($account, $amount, $transactionId) {
            $lockedAccount = Account::where('id', $account->id)
                ->lockForUpdate()
                ->first();

            if ($lockedAccount->balance < $amount) {
                throw new \Exception("Insufficient balance in account: {$lockedAccount->name}");
            }

            $lockedAccount->balance -= $amount;
            $lockedAccount->save();

            $entry = AccountEntry::create([
                'account_id' => $lockedAccount->getKey(),
                'transaction_id' => $transactionId,
                'debit' => $amount,
                'credit' => 0.00,
                'balance_after' => $lockedAccount->balance,
            ]);

            Log::info('Account debited', [
                'account_id' => $account->getKey(),
                'amount' => $amount,
                'transaction_id' => $transactionId,
            ]);

            return $entry;
        });
    }

    public function credit(Account $account, float $amount, int $transactionId): AccountEntry
    {
        return LedgerBalanceMutationGuard::run(fn () => $this->creditAccount($account, $amount, $transactionId));
    }

    public function debit(Account $account, float $amount, int $transactionId): AccountEntry
    {
        return LedgerBalanceMutationGuard::run(fn () => $this->debitAccount($account, $amount, $transactionId));
    }

    public function getFlightGroupStatementForAccount(FlightGroup $flightGroup, Account $account, array $filters): array
    {
        // 1. Fetch all transactions for this flight group to calculate running balances chronologically
        $allTxQuery = $flightGroup->groupTransactions()
            ->with([
                'booking.customer',
                'booking.passengers',
                'booking.fromAirport',
                'booking.toAirport',
                'createdBy:id,name',
            ])
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');

        $allTransactions = $allTxQuery->get();

        $running = 0.0;
        
        // Find if there's any opening balance entry in account_entries.
        $firstEntry = $account->entries()->where('notes', 'رصيد افتتاحي')->first();
        if ($firstEntry) {
            $running += ($firstEntry->credit - $firstEntry->debit);
        }

        foreach ($allTransactions as $tx) {
            if ($tx->type === 'payment') {
                $running += (float) $tx->amount;
            } else {
                $running -= (float) $tx->amount;
            }
            $tx->balance_after = $running;
        }

        // 2. Filter the transactions based on filters
        $filteredTransactions = $allTransactions;

        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $resolver = app(\App\Services\Finance\LedgerEntryDescriptionResolver::class);
            $filteredTransactions = $filteredTransactions->filter(function ($tx) use ($search, $resolver) {
                if (str_contains(mb_strtolower((string) $tx->notes), $search)) {
                    return true;
                }
                if ($tx->createdBy && str_contains(mb_strtolower($tx->createdBy->name), $search)) {
                    return true;
                }
                if ($tx->booking) {
                    $bookingRef = $tx->booking->booking_reference ?: $tx->booking->booking_number ?: $tx->booking->pnr;
                    if (str_contains(mb_strtolower((string) $bookingRef), $search)) {
                        return true;
                    }
                    if ($tx->booking->customer && str_contains(mb_strtolower($tx->booking->customer->full_name), $search)) {
                        return true;
                    }
                    $desc = $resolver->forFlightBooking($tx->booking);
                    if (str_contains(mb_strtolower($desc), $search)) {
                        return true;
                    }
                }
                return false;
            });
        }

        $fromDate = ! empty($filters['from_date']) ? \Carbon\Carbon::parse($filters['from_date'])->startOfDay() : null;
        $toDate = ! empty($filters['to_date']) ? \Carbon\Carbon::parse($filters['to_date'])->endOfDay() : null;

        if ($fromDate) {
            $filteredTransactions = $filteredTransactions->filter(function ($tx) use ($fromDate) {
                return \Carbon\Carbon::parse($tx->created_at)->gte($fromDate);
            });
        }

        if ($toDate) {
            $filteredTransactions = $filteredTransactions->filter(function ($tx) use ($toDate) {
                return \Carbon\Carbon::parse($tx->created_at)->lte($toDate);
            });
        }

        if (! empty($filters['type'])) {
            $filteredTransactions = $filteredTransactions->filter(function ($tx) use ($filters) {
                if ($filters['type'] === 'credit') {
                    return $tx->type === 'payment';
                } elseif ($filters['type'] === 'debit') {
                    return $tx->type === 'debt';
                }
                return true;
            });
        }

        // Stats calculations
        $openingBalance = 0.0;
        if ($firstEntry) {
            $openingBalance += ($firstEntry->credit - $firstEntry->debit);
        }

        if ($fromDate) {
            $lastTxBefore = $allTransactions->filter(function ($tx) use ($fromDate) {
                return \Carbon\Carbon::parse($tx->created_at)->lt($fromDate);
            })->last();

            if ($lastTxBefore) {
                $openingBalance = $lastTxBefore->balance_after;
            }
        }

        $periodCredit = 0.0;
        $periodDebit = 0.0;
        foreach ($filteredTransactions as $tx) {
            if ($tx->type === 'payment') {
                $periodCredit += (float) $tx->amount;
            } else {
                $periodDebit += (float) $tx->amount;
            }
        }

        $closingBalance = $openingBalance + $periodCredit - $periodDebit;

        // Sort items by created_at desc, id desc (matching standard ledger entries)
        $sortedTransactions = $filteredTransactions->sortByDesc(function ($tx) {
            return \Carbon\Carbon::parse($tx->created_at)->timestamp . '_' . sprintf('%010d', $tx->id);
        });

        // Map flight group transactions to mock AccountEntry instances
        $mockEntries = $sortedTransactions->map(function ($tx) use ($account) {
            return $this->mapFlightGroupTransactionToAccountEntry($tx, $account);
        });

        $perPage = min($filters['per_page'] ?? 20, 100);
        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage();
        $currentPageItems = $mockEntries->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $currentPageItems,
            $mockEntries->count(),
            $perPage,
            $currentPage,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );

        return [
            'items' => $paginator->getCollection(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'stats' => [
                'opening_balance' => (float) $openingBalance,
                'period_credit' => (float) $periodCredit,
                'period_debit' => (float) $periodDebit,
                'closing_balance' => (float) $closingBalance,
                'account_balance' => (float) $account->balance,
            ],
        ];
    }

    protected function mapFlightGroupTransactionToAccountEntry($tx, Account $account): AccountEntry
    {
        $entry = new AccountEntry();
        $entry->id = $tx->id;
        $entry->account_id = $account->id;
        $entry->debit = $tx->type === 'debt' ? (float) $tx->amount : 0.0;
        $entry->credit = $tx->type === 'payment' ? (float) $tx->amount : 0.0;
        $entry->balance_after = (float) $tx->balance_after;
        $entry->notes = $tx->notes;
        $entry->created_at = $tx->created_at;
        $entry->updated_at = $tx->updated_at;

        // Try to find a real Transaction model for this payment/debt
        $realTransaction = \App\Models\Transaction::where('related_type', FlightGroupTransaction::class)
            ->where('related_id', $tx->id)
            ->with(['createdBy', 'fromAccount', 'toAccount'])
            ->first();

        if ($realTransaction) {
            $entry->transaction_id = $realTransaction->id;
            if ($tx->booking) {
                $realTransaction->related_type = get_class($tx->booking);
                $realTransaction->related_id = $tx->booking->id;
                $realTransaction->setRelation('related', $tx->booking);
            }
            $entry->setRelation('transaction', $realTransaction);
        } else {
            $transaction = new \App\Models\Transaction();
            $transaction->id = $tx->id;
            $transaction->type = $tx->type === 'payment' 
                ? TransactionType::Income 
                : TransactionType::Expense;
            $transaction->module = TransactionModule::Flight;
            $transaction->notes = $tx->notes;
            $transaction->created_by = $tx->created_by;
            
            $transaction->setRelation('createdBy', $tx->createdBy);

            if ($tx->booking) {
                $transaction->related_type = get_class($tx->booking);
                $transaction->related_id = $tx->booking->id;
                $transaction->setRelation('related', $tx->booking);
            } else {
                $transaction->related_type = null;
                $transaction->related_id = null;
                $transaction->setRelation('related', null);
            }

            $entry->transaction_id = null;
            $entry->setRelation('transaction', $transaction);
        }

        return $entry;
    }
}

