<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Bus\BusCompany;
use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\HajjUmra\Program;
use App\Models\LedgerReconciliationRun;
use App\Models\Online\OnlineServiceProvider;
use App\Services\Reports\FinancialReportService;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialReportController extends Controller
{
    public function __construct(
        protected FinancialReportService $reportService
    ) {}

    /**
     * Whitelist of modules the profit-by-day / profit-entity-top endpoints
     * accept. Anything outside this set is rejected with HTTP 422.
     *
     * Keep in sync with ProfitLossReportService::moduleBreakdown() which
     * emits the same module keys (see by_module[].module in that method).
     */
    private const PROFIT_DRILLDOWN_MODULES = [
        'flight', 'bus', 'hajj_umra', 'visa', 'fawry', 'online', 'wallet',
    ];

    /**
     * Per-module entity mapping for the profit-entity-top endpoint. The
     * client never supplies the relatedType / entityColumn / joinChain —
     * they are derived from this whitelist server-side, so a hostile
     * caller cannot point the query at arbitrary FQCNs or join chains.
     *
     * `join_chain` shape (when not null):
     *   ['table' => 'bus_inventories', 'fk' => 'inventory_id']
     * which makes getProfitByEntity() do the 2-hop
     *   transactions.related_id → bus_bookings.id
     *                       → bus_inventories.id → bus_inventories.company_id
     */
    private const PROFIT_ENTITY_MAP = [
        'flight' => [
            'flight_system' => [
                'related_type'  => 'App\\Models\\Flight\\FlightBooking',
                'entity_column' => 'flight_system_id',
                'join_chain'    => null,
                'label_model'   => FlightSystem::class,
                'label_column'  => 'name',
            ],
            'flight_carrier' => [
                'related_type'  => 'App\\Models\\Flight\\FlightBooking',
                'entity_column' => 'flight_carrier_id',
                'join_chain'    => null,
                'label_model'   => FlightCarrier::class,
                'label_column'  => 'name',
            ],
        ],
        'bus' => [
            'bus_company' => [
                'related_type'  => 'App\\Models\\Bus\\BusBooking',
                'entity_column' => 'company_id',
                'join_chain'    => ['table' => 'bus_inventories', 'fk' => 'inventory_id'],
                'label_model'   => BusCompany::class,
                'label_column'  => 'name',
            ],
        ],
        'hajj_umra' => [
            'program' => [
                'related_type'  => 'App\\Models\\HajjUmraBooking',
                'entity_column' => 'program_id',
                'join_chain'    => null,
                'label_model'   => Program::class,
                'label_column'  => 'program_name',
            ],
        ],
        'visa' => [
            // No visa_agent mapping in the schema today — fall back to the
            // booking's customer_id. The response explicitly tags this as
            // 'customer' (not 'visa_agent') so the UI can label it
            // correctly. See entityTypeLabel() in the controller.
            'customer' => [
                'related_type'  => 'App\\Models\\VisaBooking',
                'entity_column' => 'customer_id',
                'join_chain'    => null,
                'label_model'   => Customer::class,
                'label_column'  => 'full_name',
            ],
        ],
        'fawry' => [
            'customer' => [
                'related_type'  => 'App\\Models\\Fawry\\FawryTransaction',
                'entity_column' => 'client_id',
                'join_chain'    => null,
                'label_model'   => Customer::class,
                'label_column'  => 'full_name',
            ],
        ],
        'online' => [
            'provider' => [
                'related_type'  => 'App\\Models\\Online\\OnlineTransaction',
                'entity_column' => 'provider_id',
                'join_chain'    => null,
                'label_model'   => OnlineServiceProvider::class,
                'label_column'  => 'name_ar',
            ],
        ],
        'wallet' => [
            'customer' => [
                'related_type'  => 'App\\Models\\Wallet\\WalletTransaction',
                'entity_column' => 'customer_id',
                'join_chain'    => null,
                'label_model'   => Customer::class,
                'label_column'  => 'full_name',
            ],
        ],
    ];

    /**
     * Human-readable label for the entity_type key. The visa module's
     * fallback entry is intentionally labelled 'عميل' (customer) — not
     * 'وكيل تأشيرات' — to avoid misleading the operator.
     */
    private function entityTypeLabel(string $module, string $entityType): string
    {
        return match (true) {
            $module === 'visa'  && $entityType === 'customer' => 'عميل (معرّف العميل المرتبط بالحجز)',
            $module === 'fawry' && $entityType === 'customer' => 'عميل',
            $module === 'wallet' && $entityType === 'customer' => 'عميل',
            default => match ($entityType) {
                'flight_system' => 'نظام طيران',
                'flight_carrier' => 'شركة طيران',
                'bus_company' => 'شركة باصات',
                'program' => 'برنامج حج/عمرة',
                'provider' => 'مزود خدمة',
                'customer' => 'عميل',
                default => $entityType,
            },
        };
    }

    /**
     * تقرير كشف خزينة
     */
    public function treasuryReport(Request $request): JsonResponse
    {
        try {
            $treasuryId = $request->input('treasury_id');
            $treasury = Account::findOrFail($treasuryId);

            $report = $this->reportService->getTreasuryReport($treasury, $request->all());

            return ApiResponse::success('Treasury report generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير الأرباح
     */
    public function profitReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getProfitReport($request->all());

            return ApiResponse::success('Profit report generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير مديونيات العملاء
     */
    public function customerDebtsReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getCustomerDebtsReport($request->all());

            return ApiResponse::success('Customer debts report generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير مديونيات الموردين
     */
    public function supplierDebtsReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getSupplierDebtsReport($request->all());

            return ApiResponse::success('Supplier debts report generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * ملخص مالي شامل
     */
    public function financialSummary(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getFinancialSummary($request->all());

            return ApiResponse::success('Financial summary generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function profitByModule(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                'Profit by module calculated.',
                $this->reportService->getProfitByModule($request->all())
            )->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Per-day profit breakdown for a single module.
     *
     * Powers the Vue AccountsIndex drill-down modal ("يومي" tab). Wraps
     * ProfitLossReportService::getDailyProfitByModule() — same GL engine
     * that backs the Filament dashboard widgets and the
     * accounts-list stats.performance payload.
     *
     * Module is hard-whitelisted (PROFIT_DRILLDOWN_MODULES) — anything
     * outside the set returns 422 to prevent arbitrary SQL injection
     * via the normalizeModuleKey() match-arm surface.
     */
    public function profitByDay(Request $request): JsonResponse
    {
        try {
            $module = strtolower(trim((string) $request->input('module', '')));
            // FIX (TO-GAP-fix, 2026-07-16): module is now optional.
            // If supplied, it must be whitelisted; if empty, we aggregate
            // across ALL tourism modules (a department-wide daily P&L).
            if ($module !== '' && ! in_array($module, self::PROFIT_DRILLDOWN_MODULES, true)) {
                return ApiResponse::error(
                    'Invalid module. Allowed: ' . implode(', ', self::PROFIT_DRILLDOWN_MODULES),
                    null,
                    422
                );
            }

            $fromDate = (string) $request->input('from_date', now()->startOfMonth()->toDateString());
            $toDate   = (string) $request->input('to_date', now()->toDateString());

            /** @var ProfitLossReportService $plService */
            $plService = app(ProfitLossReportService::class);

            $byDay = [];
            if ($module !== '') {
                $byDay = $plService->getDailyProfitByModule($module, [
                    'from_date' => $fromDate,
                    'to_date'   => $toDate,
                ]);
            } else {
                // Aggregate across all whitelisted modules
                $merged = [];
                foreach (self::PROFIT_DRILLDOWN_MODULES as $mod) {
                    foreach ($plService->getDailyProfitByModule($mod, [
                        'from_date' => $fromDate,
                        'to_date'   => $toDate,
                    ]) as $row) {
                        $date = $row['date'] ?? null;
                        if (! $date) continue;
                        if (! isset($merged[$date])) {
                            $merged[$date] = ['date' => $date, 'income' => 0, 'cogs' => 0, 'expense' => 0, 'profit' => 0];
                        }
                        $merged[$date]['income']  += (float) ($row['income'] ?? 0);
                        $merged[$date]['cogs']    += (float) ($row['cogs'] ?? 0);
                        $merged[$date]['expense'] += (float) ($row['expense'] ?? 0);
                        $merged[$date]['profit']  += (float) ($row['profit'] ?? 0);
                    }
                }
                krsort($merged);
                $byDay = array_values($merged);
            }

            // Aggregate totals so the UI can show "متوسط يومي", "إجمالي الفترة", etc.
            $totals = ['income' => 0.0, 'cogs' => 0.0, 'expense' => 0.0, 'profit' => 0.0];
            foreach ($byDay as $row) {
                $totals['income']  += (float) ($row['income'] ?? 0);
                $totals['cogs']    += (float) ($row['cogs'] ?? 0);
                $totals['expense'] += (float) ($row['expense'] ?? 0);
                $totals['profit']  += (float) ($row['profit'] ?? 0);
            }
            $totals['income']  = round($totals['income'], 2);
            $totals['cogs']    = round($totals['cogs'], 2);
            $totals['expense'] = round($totals['expense'], 2);
            $totals['profit']  = round($totals['profit'], 2);

            return ApiResponse::success('Profit by day calculated.', [
                'module'    => $module ?: 'all',
                'from_date' => $fromDate,
                'to_date'   => $toDate,
                'currency'  => 'EGP',
                'by_day'    => $byDay,
                'totals'    => $totals,
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Top-N entities by GL profit within a module + period.
     *
     * Powers the Vue AccountsIndex drill-down modal ("أعلى الكيانات" tab).
     * The client supplies module + limit + period only; the relatedType /
     * entityColumn / joinChain / label_model are derived server-side from
     * PROFIT_ENTITY_MAP so a hostile caller cannot inject arbitrary
     * FQCNs into the SQL.
     *
     * For each module we pick the FIRST (and only, today) entity_type from
     * the map. If a module grows multiple entity_types (flight already does:
     * flight_system + flight_carrier), we expose BOTH as separate items
     * in the response under `entity_types` so the UI can offer a small
     * toggle without us growing the contract.
     */

    /**
     * FIX (TO-GAP-003, fixed 2026-07-16):
     *   Per-operation P&L breakdown. Returns EACH transaction line
     *   (revenue, cogs, or operating expense) classified with its
     *   related entity (booking, payment, transfer, etc.) so the operator
     *   can trace ANY amount in the P&L back to the source operation.
     *
     * Query params:
     *   - module: 'flight' | 'hajj_umra' | 'visa' | etc. (optional — aggregates all if missing)
     *   - from_date, to_date: ISO date strings (default: month-to-date)
     *   - limit: max rows to return (default 100, hard-capped at 1000)
     *   - category: 'revenue' | 'cogs' | 'expense' (optional — returns all categories)
     */
    public function profitByOperation(Request $request): JsonResponse
    {
        try {
            $module = strtolower(trim((string) $request->input('module', '')));
            if ($module !== '' && ! in_array($module, self::PROFIT_DRILLDOWN_MODULES, true)) {
                return ApiResponse::error(
                    'Invalid module. Allowed: ' . implode(', ', self::PROFIT_DRILLDOWN_MODULES),
                    null,
                    422
                );
            }

            $fromDate = (string) $request->input('from_date', now()->startOfMonth()->toDateString());
            $toDate   = (string) $request->input('to_date', now()->toDateString());
            $category = strtolower((string) $request->input('category', ''));
            $limit    = (int) $request->input('limit', 100);
            $limit    = max(1, min(1000, $limit));

            $plService = app(ProfitLossReportService::class);
            $clearingAccounts = app(\App\Services\Finance\LedgerClearingAccounts::class);
            $maps = $clearingAccounts->moduleAccountMaps();
            $incomeClearing = $maps['income'];
            $expenseClearing = $maps['expense'];
            $prepaidAccounts = $clearingAccounts->prepaidAccountIdMap();
            $allClearingIds = array_values(array_unique(array_merge(
                array_keys($incomeClearing),
                array_keys($expenseClearing),
                array_keys($prepaidAccounts),
            )));

            $query = DB::table('transactions as t')
                ->leftJoin('accounts as to_acc', 't.to_account_id', '=', 'to_acc.id')
                ->leftJoin('accounts as from_acc', 't.from_account_id', '=', 'from_acc.id')
                ->leftJoin('transfers as tr', 't.id', '=', 'tr.transaction_id')
                ->select([
                    't.id as transaction_id',
                    't.type as transaction_type',
                    't.module',
                    't.amount',
                    't.related_type',
                    't.related_id',
                    't.from_account_id',
                    't.to_account_id',
                    't.created_at',
                    't.notes',
                    'to_acc.name as to_account_name',
                    'to_acc.type as to_account_type',
                    'from_acc.name as from_account_name',
                    'from_acc.type as from_account_type',
                ]);
            $query->whereBetween('t.created_at', [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'])
                ->whereIn('t.to_account_id', $allClearingIds);

            if ($module !== '') {
                $query->where('t.module', $module);
            }
            $query->orderBy('t.id', 'desc')->limit($limit * 3); // over-fetch to filter by classification

            $rows = [];
            $totals = ['income' => 0.0, 'cogs' => 0.0, 'expense' => 0.0, 'profit' => 0.0];
            $skipped = 0;
            foreach ($query->get() as $tx) {
                if (count($rows) >= $limit) break;
                $classification = $plService->classifyPublic((object)[
                    'id' => $tx->transaction_id,
                    'type' => $tx->transaction_type,
                    'module' => $tx->module,
                    'amount' => $tx->amount,
                    'from_account_id' => $tx->from_account_id,
                    'to_account_id' => $tx->to_account_id,
                    'to_account_type' => $tx->to_account_type,
                    'to_account_module_type' => null,
                    'from_account_module_type' => null,
                ], $incomeClearing, $expenseClearing, $prepaidAccounts);
                if ($classification === null) { $skipped++; continue; }
                $shortClass = $classification;
                if (str_contains($shortClass, 'revenue_reversal') || str_contains($shortClass, 'refund')) $shortClass = 'refund';
                elseif (str_contains($shortClass, 'cogs_reversal')) $shortClass = 'cogs';
                elseif (str_contains($shortClass, 'cogs')) $shortClass = 'cogs';
                elseif (str_contains($shortClass, 'revenue')) $shortClass = 'revenue';
                elseif (str_contains($shortClass, 'operating_expense')) $shortClass = 'expense';
                $sign = (str_contains($classification, 'reversal') || str_contains($classification, 'refund')) ? -1.0 : 1.0;
                $amount = (float) $tx->amount * $sign;
                if ($category !== '' && $shortClass !== $category) continue;
                $rows[] = [
                    'transaction_id' => (int) $tx->transaction_id,
                    'date' => (string) $tx->created_at,
                    'module' => $tx->module,
                    'classification' => $shortClass,
                    'amount' => round($amount, 2),
                    'related_type' => $tx->related_type,
                    'related_id' => $tx->related_id,
                    'from_account' => ['id' => (int) $tx->from_account_id, 'name' => $tx->from_account_name, 'type' => $tx->from_account_type],
                    'to_account' => ['id' => (int) $tx->to_account_id, 'name' => $tx->to_account_name, 'type' => $tx->to_account_type],
                    'notes' => $tx->notes,
                ];
                $totals[$shortClass] = ($totals[$shortClass] ?? 0) + $amount;
            }
            $totals['profit'] = $totals['income'] - $totals['cogs'] - $totals['expense'];

            return ApiResponse::success('Per-operation P&L fetched.', [
                'module' => $module ?: 'all',
                'category' => $category ?: 'all',
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'rows' => $rows,
                'totals' => array_map(fn ($v) => round((float) $v, 2), $totals),
                'meta' => [
                    'row_count' => count($rows),
                    'skipped_non_clearing' => $skipped,
                    'generated_at' => now()->toIso8601String(),
                ],
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Throwable $e) {
            return ApiResponse::error('profit-by-operation failed: ' . $e->getMessage(), null, 422);
        }
    }

    public function profitEntityTop(Request $request): JsonResponse
    {
        try {
            $module = strtolower(trim((string) $request->input('module', '')));
            if (! in_array($module, self::PROFIT_DRILLDOWN_MODULES, true)) {
                return ApiResponse::error(
                    'Invalid module. Allowed: ' . implode(', ', self::PROFIT_DRILLDOWN_MODULES),
                    null,
                    422
                );
            }
            if (! isset(self::PROFIT_ENTITY_MAP[$module])) {
                return ApiResponse::error("No entity mapping for module '{$module}'.", null, 422);
            }

            $limit = (int) $request->input('limit', 20);
            $limit = max(1, min(100, $limit));

            $sort = strtolower((string) $request->input('sort', 'profit'));
            if (! in_array($sort, ['profit', 'loss'], true)) {
                $sort = 'profit';
            }

            $fromDate = (string) $request->input('from_date', now()->startOfMonth()->toDateString());
            $toDate   = (string) $request->input('to_date', now()->toDateString());
            $filters  = ['from_date' => $fromDate, 'to_date' => $toDate];

            /** @var ProfitLossReportService $plService */
            $plService = app(ProfitLossReportService::class);

            $perEntityType = [];
            foreach (self::PROFIT_ENTITY_MAP[$module] as $entityType => $cfg) {
                $rows = $plService->getProfitByEntity(
                    $module,
                    $cfg['related_type'],
                    $cfg['entity_column'],
                    $cfg['join_chain'],
                    $filters
                );

                // Sort: 'profit' = highest profit first (default, mirrors
                // getProfitByEntity()'s built-in sort). 'loss' = lowest
                // profit first (i.e. biggest loss on top).
                if ($sort === 'loss') {
                    usort($rows, fn ($a, $b) => $a['profit'] <=> $b['profit']);
                }

                $rows = array_slice($rows, 0, $limit);

                // Resolve human labels in a single batch query per type.
                $labelMap = [];
                if ($rows !== []) {
                    $ids = array_map(fn ($r) => (int) $r['entity_id'], $rows);
                    /** @var \Illuminate\Database\Eloquent\Model $instance */
                    $instance = new $cfg['label_model'];
                    $labelMap = $instance->newQuery()
                        ->whereIn($instance->getKeyName(), $ids)
                        ->pluck($cfg['label_column'], $instance->getKeyName())
                        ->all();
                }

                $items = [];
                foreach ($rows as $r) {
                    $items[] = [
                        'entity_id'    => (int) $r['entity_id'],
                        'entity_label' => (string) ($labelMap[(int) $r['entity_id']] ?? ('#' . $r['entity_id'])),
                        'income'       => (float) $r['income'],
                        'cogs'         => (float) $r['cogs'],
                        'expense'      => (float) $r['expense'],
                        'profit'       => (float) $r['profit'],
                    ];
                }

                $perEntityType[] = [
                    'entity_type'    => $entityType,
                    'entity_type_label' => $this->entityTypeLabel($module, $entityType),
                    'items'          => $items,
                ];
            }

            return ApiResponse::success('Top profit entities calculated.', [
                'module'        => $module,
                'from_date'     => $fromDate,
                'to_date'       => $toDate,
                'currency'      => 'EGP',
                'sort'          => $sort,
                'limit'         => $limit,
                'entity_types'  => $perEntityType,
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function cashFlowRealtime(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success('Cash flow snapshot generated.', $this->reportService->getCashFlowRealtime($request->all()));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function customerLedgerBalances(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success('Customer ledger balances generated.', $this->reportService->getCustomerLedgerBalances($request->all()));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function bankLedgerReconciliation(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success('Bank vs ledger reconciliation generated.', $this->reportService->getBankLedgerReconciliation($request->all()));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function latestLedgerReconciliation(): JsonResponse
    {
        try {
            $run = LedgerReconciliationRun::query()->with('findings')->latest('run_at')->first();
            if (! $run) {
                return ApiResponse::success('No reconciliation runs recorded yet.', null);
            }

            return ApiResponse::success('Latest ledger reconciliation retrieved.', $run);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير الديون والمديونيات الموحد
     */
    public function debtsReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getDebtsReport($request->all());

            return ApiResponse::success('Debts and receivables report generated successfully.', $report)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير تحليل رأس المال للقطاعات بالعملات المتعددة
     */
    public function capitalAnalysis(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getCapitalAnalysis($request->all());

            return ApiResponse::success('Capital analysis report generated successfully.', $report)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير حركات الطيران التفصيلي الموحد
     */
    public function detailedFlightReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getDetailedFlightReport($request->all());

            return ApiResponse::success('Detailed flight report generated successfully.', $report)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير ميزان الحسابات (جرد لحظي لرأس المال)
     */
    public function trialBalance(Request $request): JsonResponse
    {
        try {
            $treasuryService = app(\App\Services\Finance\TreasuryService::class);
            $report = $treasuryService->getTrialBalance();

            return ApiResponse::success('Trial balance report generated successfully.', $report)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * FIX (TO-GAP-001, fixed 2026-07-16): per-account detailed trial balance
     * with explicit total_debit and total_credit per account.
     *
     * Powers the AccountsIndex.vue "trial balance" table where each row
     * shows D / C / balance / type / module so the operator can trace
     * any imbalance to a specific account.
     *
     * @param  Request  $request  Optional: ?division=tourism|office (default tourism)
     */
    public function trialBalanceDetailed(Request $request): JsonResponse
    {
        try {
            $division = strtolower((string) $request->input('division', 'tourism'));
            $division = in_array($division, ['tourism', 'office'], true) ? $division : 'tourism';

            // The clearings (إقفال إيرادات/تكاليف) are in module_type=hawj_umra
            // but they should be grouped with the tourism division.
            $tourismModules = ['flight', 'hajj_umra', 'visa', 'tourism', 'clearings'];

            $rows = DB::table('account_entries as ae')
                ->join('accounts as a', 'a.id', '=', 'ae.account_id')
                ->leftJoin('transactions as t', 't.id', '=', 'ae.transaction_id')
                ->where('a.is_active', 1)
                ->whereNotNull('t.module')
                ->select([
                    'a.id as account_id',
                    'a.name as account_name',
                    'a.type as account_type',
                    'a.module_type as account_module_type',
                    'a.module as account_module',
                    'a.balance as account_balance',
                    DB::raw('SUM(ae.debit) as total_debit'),
                    DB::raw('SUM(ae.credit) as total_credit'),
                    DB::raw('SUM(ae.debit) - SUM(ae.credit) as net_balance'),
                    DB::raw('COUNT(DISTINCT ae.transaction_id) as transaction_count'),
                ])
                ->groupBy('a.id', 'a.name', 'a.type', 'a.module_type', 'a.module', 'a.balance')
                ->orderBy('a.type')
                ->orderBy('a.name')
                ->get();

            // Filter and group by division
            $filtered = [];
            $totalDebit = 0.0; $totalCredit = 0.0;
            foreach ($rows as $r) {
                $moduleType = strtolower((string) ($r->account_module_type ?? ''));
                $belongsToTourism = in_array($moduleType, $tourismModules, true);
                $belongsToOffice = in_array($moduleType, ['office', 'bus', 'fawry', 'online', 'wallet_transfer'], true);
                $isClearing = in_array($moduleType, ['hajj_umra']) && in_array(strtolower((string)$r->account_type), ['expense', 'revenue', 'owner', 'liability']);
                if ($division === 'tourism' && ! ($belongsToTourism || $isClearing)) continue;
                if ($division === 'office' && ! $belongsToOffice) continue;
                $filtered[] = $r;
                $totalDebit += (float) $r->total_debit;
                $totalCredit += (float) $r->total_credit;
            }

            return ApiResponse::success('Detailed trial balance generated.', [
                'division' => $division,
                'as_of'    => now()->toIso8601String(),
                'accounts' => $filtered,
                'totals'   => [
                    'total_debit' => round($totalDebit, 2),
                    'total_credit' => round($totalCredit, 2),
                    'difference' => round($totalDebit - $totalCredit, 2),
                    'balanced' => abs($totalDebit - $totalCredit) < 0.01,
                ],
                'meta' => [
                    'account_count' => count($filtered),
                    'generated_at' => now()->toIso8601String(),
                ],
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function officeTrialBalance(Request $request): JsonResponse
    {
        try {
            $treasuryService = app(\App\Services\Finance\TreasuryService::class);
            $report = $treasuryService->getOfficeTrialBalance();

            return ApiResponse::success('Office trial balance report generated successfully.', $report)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function consolidatedTrialBalance(Request $request): JsonResponse
    {
        try {
            $treasuryService = app(\App\Services\Finance\TreasuryService::class);
            $report = $treasuryService->getConsolidatedTrialBalance();

            return ApiResponse::success('Consolidated trial balance report generated successfully.', $report)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
