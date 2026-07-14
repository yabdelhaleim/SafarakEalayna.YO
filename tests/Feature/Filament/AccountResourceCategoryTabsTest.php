<?php

namespace Tests\Feature\Filament;

use App\Enums\AccountType;
use App\Filament\Resources\Finance\AccountResource;
use App\Filament\Resources\Finance\AccountResource\Pages\ListAccounts;
use App\Models\Account;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 4 — AccountResource category tabs (Step 3: Feature test).
 *
 * Exercises the getTableQuery override on the ListAccounts page to
 * confirm the `?category=` URL parameter narrows the table to the
 * right AccountType group from AccountModuleContract.
 *
 * Also exercises the blade view that renders the tab strip so any
 * label/markup regression is caught early.
 *
 * @see \App\Filament\Resources\Finance\AccountResource\Pages\ListAccounts
 * @see \App\Support\Finance\AccountModuleContract
 */
class AccountResourceCategoryTabsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Tabs Tester',
            'email' => 'tabs-tester@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Seed 2 liquidity + 3 subject + 1 internal
        $seed = [
            ['liquidity', AccountType::Cashbox, 'office'],
            ['liquidity', AccountType::Bank,    'office'],
            ['subject',   AccountType::Customer, 'bus'],
            ['subject',   AccountType::Supplier, 'fawry'],
            ['subject',   AccountType::Customer, 'flights'],
            ['internal',  AccountType::Expense,  'office'],
        ];
        foreach ($seed as [$cat, $type, $moduleType]) {
            Account::create([
                'name' => "[TABS-TEST] {$cat} {$type->value}",
                'type' => $type,
                'currency' => 'EGP',
                'balance' => 0,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => $moduleType,
                'is_active' => true,
                'created_by' => $this->user->id,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Account::where('name', 'like', '[TABS-TEST]%')->delete();
        parent::tearDown();
    }

    /**
     * Run the ListAccounts page in-process and return the Eloquent query
     * that would be used to fetch the rows. We can't easily assert on the
     * rendered table, so we exercise the actual override directly.
     */
    private function getQueryFor(string $category): \Illuminate\Database\Eloquent\Builder
    {
        $page = new ListAccounts;
        $page->setUrl('/admin/finance/accounts' . ($category ? '?category=' . $category : ''));

        $reflection = new \ReflectionMethod($page, 'getTableQuery');
        $reflection->setAccessible(true);
        return $reflection->invoke($page);
    }

    public function test_no_category_returns_all_seeded_accounts(): void
    {
        $count = $this->getQueryFor('')->count();
        $this->assertSame(6, $count, 'No ?category= should return all 6 seeded accounts');
    }

    public function test_liquidity_category_filters_to_liquidity_types_only(): void
    {
        $rows = $this->getQueryFor('liquidity')->get();

        $this->assertCount(2, $rows, 'Liquidity tab should return exactly 2 rows');
        foreach ($rows as $row) {
            $this->assertContains(
                $row->type,
                AccountModuleContract::LIQUIDITY_TYPES,
                "Row '{$row->name}' has type '{$row->type}' which is not in LIQUIDITY_TYPES"
            );
        }
    }

    public function test_subject_category_filters_to_subject_types_only(): void
    {
        $rows = $this->getQueryFor('subject')->get();

        $this->assertCount(3, $rows, 'Subject tab should return exactly 3 rows');
        foreach ($rows as $row) {
            $this->assertContains(
                $row->type,
                AccountModuleContract::SUBJECT_TYPES,
                "Row '{$row->name}' has type '{$row->type}' which is not in SUBJECT_TYPES"
            );
        }
    }

    public function test_internal_category_filters_to_internal_types_only(): void
    {
        $rows = $this->getQueryFor('internal')->get();

        $this->assertCount(1, $rows, 'Internal tab should return exactly 1 row');
        foreach ($rows as $row) {
            $this->assertContains(
                $row->type,
                AccountModuleContract::INTERNAL_TYPES,
                "Row '{$row->name}' has type '{$row->type}' which is not in INTERNAL_TYPES"
            );
        }
    }

    public function test_unknown_category_falls_back_to_all(): void
    {
        // A bad URL like ?category=../etc/passwd must NOT return 0 rows or
        // throw — it must degrade to the "all" view.
        $count = $this->getQueryFor('unknown-category-xyz')->count();
        $this->assertSame(6, $count, 'Unknown category must fall back to the all-accounts view');
    }

    public function test_empty_string_category_is_treated_as_all(): void
    {
        $count = $this->getQueryFor('')->count();
        $this->assertSame(6, $count);
    }

    public function test_each_filtered_total_sums_to_all(): void
    {
        $all = $this->getQueryFor('')->count();
        $liq = $this->getQueryFor('liquidity')->count();
        $sub = $this->getQueryFor('subject')->count();
        $int = $this->getQueryFor('internal')->count();
        $this->assertSame($all, $liq + $sub + $int, 'Filtered totals must sum to the all-view total');
    }

    // ────────────────────────────────────────────────────────────
    // resolveCategoryFromRequest helper — direct unit test
    // ────────────────────────────────────────────────────────────

    public function test_resolve_category_helper_canonicalizes_input(): void
    {
        $page = new ListAccounts;
        $method = new \ReflectionMethod($page, 'resolveCategoryFromRequest');
        $method->setAccessible(true);

        $cases = [
            'liquidity'  => 'liquidity',
            'subject'    => 'subject',
            'internal'   => 'internal',
            ''           => '',
            'bogus'      => '',
            '../etc'     => '',
            'null-like'  => '',
        ];
        foreach ($cases as $input => $expected) {
            $request = \Illuminate\Http\Request::create('/', 'GET', $input === '' ? [] : ['category' => $input]);
            app()->instance('request', $request);
            $this->assertSame(
                $expected,
                $method->invoke($page),
                "Input '{$input}' should resolve to '{$expected}'"
            );
        }
    }

    // ────────────────────────────────────────────────────────────
    // Blade view smoke test
    // ────────────────────────────────────────────────────────────

    public function test_tab_strip_blade_view_renders_all_four_labels(): void
    {
        $html = view('filament.finance.account-tabs')->render();

        $this->assertStringContainsString('الكل', $html, 'View should contain "الكل" (All) label');
        $this->assertStringContainsString('تشغيلي', $html, 'View should contain "تشغيلي" (Liquidity) label');
        $this->assertStringContainsString('موضوعي', $html, 'View should contain "موضوعي" (Subject) label');
        $this->assertStringContainsString('إقفال', $html, 'View should contain "إقفال" (Internal) label');
    }

    public function test_tab_strip_view_has_accessibility_attributes(): void
    {
        $html = view('filament.finance.account-tabs')->render();

        $this->assertStringContainsString('aria-label="Account category"', $html);
        $this->assertStringContainsString('aria-current="page"', $html, 'The All tab should be marked as current by default');
    }

    public function test_tab_strip_has_four_anchor_links(): void
    {
        $html = view('filament.finance.account-tabs')->render();
        // The view emits exactly 4 <a ...>...</a> tags (one per tab)
        preg_match_all('/<a\s[^>]*>/', $html, $matches);
        $this->assertCount(4, $matches[0], 'View should render exactly 4 anchor tags (one per tab)');
    }
}
