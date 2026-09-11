<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Deep verification — opens the generated .xlsx and inspects its structure
 * (sheet name, title, headers, body rows, formulas, formatting).
 *
 * This is the "did the export actually work end-to-end" sanity check that
 * complements the lightweight assertions in AccountStatementExportTest.
 */
class AccountStatementExportStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::query()->create([
            'name' => 'Structure Admin',
            'email' => 'structure@export.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'TEST_STRUCTURE Cashbox',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 500.0,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => User::query()->first()->id,
        ], $overrides)));
    }

    public function test_xlsx_opens_and_has_correct_sheet_structure(): void
    {
        $acc = $this->seedAccount();

        $response = $this->get("/api/v1/finance/accounts/{$acc->id}/statement/export");
        $response->assertOk();

        // Save the streamed content to disk so PhpSpreadsheet can read it
        // back via IOFactory::load() (which expects a real file path).
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_structure_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());

        // Real XLSX files start with the PK ZIP magic bytes. If this
        // assertion fails, the export is writing the wrong format.
        $magic = file_get_contents($tmp, false, null, 0, 4);
        $this->assertSame('PK', substr($magic, 0, 2), 'File must be a real ZIP/XLSX (PK magic bytes)');

        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        // ─────────── sheet metadata ───────────
        $this->assertSame('كشف حساب', $sheet->getTitle());
        $this->assertTrue($sheet->getRightToLeft(), 'Sheet must be RTL for Arabic content');

        // ─────────── title block (rows 1-2) ───────────
        $title = (string) $sheet->getCell('A1')->getValue();
        $this->assertStringContainsString($acc->name, $title);
        $this->assertStringContainsString($acc->currency, $title);

        // Merged across A1:G2
        $merged = $sheet->getMergeCells();
        $this->assertContains('A1:G2', $merged);

        // ─────────── summary card (rows 5-7) ───────────
        $this->assertStringContainsString('ملخص الفترة', (string) $sheet->getCell('A5')->getValue());
        $this->assertSame('رصيد أول المدة', (string) $sheet->getCell('A6')->getValue());
        $this->assertSame('إجمالي الإيداعات', (string) $sheet->getCell('C6')->getValue());
        $this->assertSame('إجمالي المسحوبات', (string) $sheet->getCell('E6')->getValue());
        $this->assertSame('رصيد آخر المدة', (string) $sheet->getCell('G6')->getValue());

        // ─────────── header row (row 9) — all 7 columns ───────────
        $headers = [
            'A9' => 'التاريخ',
            'B9' => 'القسم / الموظف',
            'C9' => 'المرجع / PNR',
            'D9' => 'البيان والوصف',
            'E9' => 'مدين (-)',
            'F9' => 'دائن (+)',
            'G9' => 'الرصيد بعد الحركة',
        ];
        foreach ($headers as $cell => $expected) {
            $this->assertSame(
                $expected,
                (string) $sheet->getCell($cell)->getValue(),
                "Header mismatch at {$cell}"
            );
        }

        // ─────────── totals row has SUM formulas ───────────
        // The closing balance on G7 should equal the account balance
        // when there are no entries in the period.
        $this->assertSame(500.0, (float) $sheet->getCell('B7')->getValue(), 'opening balance cell');
        $this->assertSame(500.0, (float) $sheet->getCell('G7')->getValue(), 'closing balance cell');

        // ─────────── column widths are non-default ───────────
        $this->assertGreaterThan(15, (int) $sheet->getColumnDimension('D')->getWidth());
        $this->assertGreaterThan(10, (int) $sheet->getColumnDimension('E')->getWidth());

        // ─────────── empty body should show the notice ───────────
        $this->assertStringContainsString(
            'لا توجد حركات',
            (string) $sheet->getCell('A10')->getValue()
        );

        @unlink($tmp);
    }

    public function test_xlsx_with_entries_has_body_and_totals_row(): void
    {
        $acc = $this->seedAccount();
        // Add 5 transactions to make sure the body+totals are populated.
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($acc) {
            for ($i = 1; $i <= 5; $i++) {
                $tx = new \App\Models\Transaction();
                $tx->timestamps = false;
                $tx->forceFill([
                    'type' => \App\Enums\TransactionType::Income->value,
                    'amount' => 100.0,
                    'currency' => $acc->currency,
                    'module' => \App\Enums\TransactionModule::General->value,
                    'from_account_id' => $acc->id,
                    'to_account_id' => null,
                    'created_by' => User::query()->first()->id,
                    'notes' => "STRUCTURE row $i",
                    'created_at' => now()->subMinutes(5 - $i),
                    'updated_at' => now()->subMinutes(5 - $i),
                ])->save();

                $entry = new \App\Models\AccountEntry();
                $entry->timestamps = false;
                $entry->forceFill([
                    'account_id' => $acc->id,
                    'transaction_id' => $tx->id,
                    'debit' => 0.0,
                    'credit' => 100.0,
                    'balance_after' => 500.0 + ($i * 100.0),
                    'notes' => "STRUCTURE row $i",
                    'is_opening' => false,
                    'created_at' => now()->subMinutes(5 - $i),
                    'updated_at' => now()->subMinutes(5 - $i),
                ])->save();
            }
        });

        $response = $this->get("/api/v1/finance/accounts/{$acc->id}/statement/export");
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_with_data_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());

        $sheet = IOFactory::load($tmp)->getActiveSheet();

        // 5 body rows (rows 10-14), totals row at row 16 (gap at 15).
        $highestRow = $sheet->getHighestRow();
        $this->assertSame(16, $highestRow, 'Expected 5 body rows + totals');

        // The totals row SUM formulas should be present (we set
        // preCalculateFormulas(false), so they remain as strings).
        $totalDebit = (string) $sheet->getCell('E16')->getValue();
        $totalCredit = (string) $sheet->getCell('F16')->getValue();
        $this->assertStringStartsWith('=SUM', $totalDebit);
        $this->assertStringStartsWith('=SUM', $totalCredit);

        // The closing balance cell should reflect stats['closing_balance'].
        // Account was seeded with balance=500 (no auto-opening entry because
        // the test bypasses AccountService::createAccount). All 5 new credits
        // contribute. So:
        //   initialBalance = 500 - (500 - 0) = 0
        //   period_credit  = 500 (only the 5 new credits)
        //   closing        = 0 + 500 - 0 = 500
        $closingBalance = (float) $sheet->getCell('G16')->getValue();
        $this->assertSame(500.0, $closingBalance, 'closing balance = opening 0 + period credit 500');

        // Sanity check the summary card matches: B7 opening = 0, D7 credit = 500.
        $this->assertSame(0.0, (float) $sheet->getCell('B7')->getValue(), 'opening balance');
        $this->assertSame(500.0, (float) $sheet->getCell('D7')->getValue(), 'period credit');

        @unlink($tmp);
    }
}
