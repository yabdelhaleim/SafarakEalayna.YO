<?php

namespace App\Filament\Resources\Finance\AccountResource\Pages;

use App\Filament\Resources\Finance\AccountResource;
use App\Support\Finance\AccountModuleContract;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Phase 4 — Account Unification.
 *
 * Adds a `?category=` URL-parameter switch that scopes the table query to
 * one of the three AccountType groups defined in {@see AccountModuleContract}:
 *
 *   - (none / no param) → all accounts, no filter
 *   - ?category=liquidity → accounts where type ∈ LIQUIDITY_TYPES
 *   - ?category=subject   → accounts where type ∈ SUBJECT_TYPES
 *   - ?category=internal  → accounts where type ∈ INTERNAL_TYPES
 *
 * The category switch is visualised by a tab strip rendered in
 * `getHeader()` (see resources/views/filament/finance/account-tabs.blade.php).
 *
 * IMPORTANT: nothing is hidden. Each tab is just a query scope. The full set
 * of accounts remains reachable via the default `الكل — All` tab.
 */
class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    /**
     * Scope the table to one of the four category views based on the
     * `?category=` URL parameter. Unknown values fall back to "all".
     */
    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        $category = $this->resolveCategoryFromRequest();

        return match ($category) {
            'liquidity' => $query->whereIn('type', AccountModuleContract::LIQUIDITY_TYPES),
            'subject'   => $query->whereIn('type', AccountModuleContract::SUBJECT_TYPES),
            'internal'  => $query->whereIn('type', AccountModuleContract::INTERNAL_TYPES),
            default     => $query,
        };
    }

    /**
     * Render the tab strip above the table. The tab set lives in
     * {@see resources/views/filament/finance/account-tabs.blade.php} so the
     * markup can be iterated / styled independently of the PHP class.
     *
     * Must be PUBLIC (not protected) — matches {@see Page::getHeader()} signature.
     */
    public function getHeader(): View
    {
        return view('filament.finance.account-tabs');
    }

    /**
     * Map the `?category=` request value to one of the canonical keys
     * (or empty string = "all"). Unknown values degrade gracefully to "all"
     * — the user always sees the full chart-of-accounts even if the URL
     * is stale.
     */
    private function resolveCategoryFromRequest(): string
    {
        /** @var Request $request */
        $request = app(Request::class);
        $raw = (string) $request->query('category', '');

        return in_array($raw, ['', 'liquidity', 'subject', 'internal'], true)
            ? $raw
            : '';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}