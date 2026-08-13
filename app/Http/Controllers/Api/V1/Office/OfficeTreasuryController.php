<?php

namespace App\Http\Controllers\Api\V1\Office;

use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Office-division treasury endpoints.
 *
 * Sister controller to {@see \App\Http\Controllers\Api\V1\Bus\BusTreasuryController}.
 *
 * Why a separate controller instead of reusing the bus one?
 *
 *  - The bus treasury endpoint filters transactions by `module=Bus`, so any
 *    FAWRY / ONLINE / GENERAL operation touching the same office vault is
 *    hidden when viewing a single account statement from `/bus/treasury`.
 *  - The Office division is unified: one vault (`module_type='office'`) often
 *    services bus, fawry, online, and wallet_transfer simultaneously.
 *  - Users auditing office liquidity need the FULL ledger across modules,
 *    not just the bus slice.
 *
 * This controller returns the complete set of transactions on an
 * office-division account, with optional `module` / `type` / date filters
 * for drill-down.
 *
 * ─── SECURITY ──────────────────────────────────────────────────────────
 * This route inherits the parent v1 group middleware
 * (`auth:sanctum`, `active`, `CaptureFinancialPostingContext`,
 * `RejectBannedFinancialBypassMarkers`) from
 * {@see \routes\api.php}. Unauthenticated / banned users get 401 before
 * reaching this controller. The application-level
 * {@see belongsToOfficeDivision()} gate then ensures the requested
 * account actually belongs to the office division.
 */
class OfficeTreasuryController extends Controller
{
    /**
     * Per-page ceiling. Mirrors the bus endpoint and other treasury
     * controllers in the project. Kept in sync with the bus controller
     * so both endpoints have identical pagination behaviour.
     */
    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 30;

    /**
     * List transactions on an office-division liquidity account.
     *
     * Unlike {@see BusTreasuryController::accountBusTransactions()},
     * this does NOT filter by `module`. The query is across the full
     * ledger of the account so the user can see every entry that
     * contributed to its current balance.
     *
     * Query parameters (all optional):
     *   - module      : restrict to a single office-division module
     *                   (office | bus | fawry | online | general).
     *                   Unknown values produce a 422 validation error.
     *   - type        : restrict by transaction type
     *                   (income | expense | transfer | refund | writeoff).
     *                   Unknown values produce a 422 validation error.
     *   - from_date   : YYYY-MM-DD inclusive lower bound on `created_at`.
     *                   Invalid format → 422.
     *   - to_date     : YYYY-MM-DD inclusive upper bound on `created_at`.
     *                   Invalid format → 422.
     *   - page        : pagination page (default 1, must be >= 1).
     *   - per_page    : items per page (default 30, clamped to 1..100).
     *
     * Response: `ApiResponse::success` envelope → `{success, message,
     * data: <LengthAwarePaginator>, errors: null}`. The paginator
     * serialises to `{data: [...], current_page, last_page, per_page,
     * total, from, to, first_page_url, ...}`.
     */
    public function accountTransactions(Request $request, Account $account): JsonResponse
    {
        if (! self::belongsToOfficeDivision($account)) {
            return ApiResponse::error(
                'الحساب المحدد ليس تابعاً لقسم المكتب.',
                null,
                422
            );
        }

        $validated = $this->validateQuery($request);

        $query = Transaction::query()
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->with(['fromAccount:id,name,type,currency', 'toAccount:id,name,type,currency']);

        if ($validated['module'] !== null) {
            $query->where('module', $validated['module']);
        }
        if ($validated['type'] !== null) {
            $query->where('type', $validated['type']);
        }
        if ($validated['from_date'] !== null) {
            $query->whereDate('created_at', '>=', $validated['from_date']);
        }
        if ($validated['to_date'] !== null) {
            $query->whereDate('created_at', '<=', $validated['to_date']);
        }

        $paginator = $query->latest()->paginate(
            perPage: $validated['per_page'],
            page: $validated['page'],
        );

        return ApiResponse::success('معاملات الحساب في قسم المكتب.', $paginator);
    }

    /**
     * Validate every query string parameter explicitly. Surfaces invalid
     * input as 422 (matches the project's standard validation behaviour
     * via the global exception handler in {@see \bootstrap\app.php}).
     *
     * @return array{module: string|null, type: string|null, from_date: string|null, to_date: string|null, page: int, per_page: int}
     */
    private function validateQuery(Request $request): array
    {
        $allowedModules = array_merge(
            AccountModuleContract::OFFICE_DIVISION_MODULES,
            ['general']
        );

        $allowedTypes = [
            'income', 'expense', 'transfer', 'refund', 'writeoff',
        ];

        $rules = [
            // 'all' is a sentinel meaning "no filter" — accepted but
            // resolved to a null filter, not a DB literal.
            'module' => ['nullable', 'string', Rule::in(array_merge($allowedModules, ['all']))],
            'type' => ['nullable', 'string', Rule::in($allowedTypes)],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'page' => ['nullable', 'integer', 'min:1'],
            // per_page: reject pathological negatives / non-integers,
            // but CLAMP values > MAX_PER_PAGE down to MAX_PER_PAGE so the
            // behaviour matches BusTreasuryController::accountBusTransactions
            // (which uses `min((int) $perPage, 100)`). 422 is reserved
            // for genuinely invalid input, not for "too many".
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];

        $validated = $request->validate($rules);

        $moduleFilter = $validated['module'] ?? null;
        if ($moduleFilter === 'all' || $moduleFilter === '') {
            $moduleFilter = null;
        }

        $typeFilter = $validated['type'] ?? null;
        if ($typeFilter === '') {
            $typeFilter = null;
        }

        // Clamp per_page to the safe range. Mirrors the bus endpoint.
        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $perPage = min($perPage, self::MAX_PER_PAGE);

        $page = (int) ($validated['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        return [
            'module' => $moduleFilter,
            'type' => $typeFilter,
            'from_date' => $validated['from_date'] ?? null,
            'to_date' => $validated['to_date'] ?? null,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * True iff the account belongs to the Office division AND is a usable
     * liquidity account (cashbox/wallet/bank) AND is currently active.
     *
     * Accepts:
     *  - module_type='office' (unified vault)
     *  - module_type IN ('bus', 'fawry', 'online', 'wallet_transfer') (per-module vault)
     *  - legacy `module` alias falling back to one of the above values.
     *
     * REJECTS:
     *  - tourism-division accounts
     *  - subject / internal account types (only liquidity accounts are useful here)
     *  - inactive accounts
     *
     * Mirrors the acceptance matrix used in
     * {@see \App\Rules\BusLiquidityAccount} but widened to all office
     * modules and made stricter on the is_active check.
     */
    public static function belongsToOfficeDivision(Account $account): bool
    {
        $moduleType = $account->module_type instanceof \BackedEnum
            ? $account->module_type->value
            : (string) ($account->module_type ?? '');

        if (in_array($moduleType, AccountModuleContract::OFFICE_DIVISION_MODULES, true)) {
            return self::isUsableLiquidity($account);
        }

        // Legacy alias column fallback.
        $module = $account->module instanceof \BackedEnum
            ? $account->module->value
            : (string) ($account->module ?? '');

        if (in_array($module, AccountModuleContract::OFFICE_DIVISION_MODULES, true)) {
            return self::isUsableLiquidity($account);
        }

        return false;
    }

    /**
     * Liquidity-only + active-only sanity check. Mirrors the contract
     * used by every other treasury rule so behaviour stays consistent.
     */
    private static function isUsableLiquidity(Account $account): bool
    {
        if (! $account->is_active) {
            return false;
        }

        $type = $account->type instanceof \BackedEnum
            ? $account->type->value
            : (string) ($account->type ?? '');

        return in_array($type, AccountModuleContract::LIQUIDITY_TYPES, true);
    }
}
