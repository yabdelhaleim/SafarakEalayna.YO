<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Account Statement — XLSX export endpoint coverage.
 *
 * GET /api/v1/finance/accounts/{id}/statement/export
 *
 * The export endpoint streams a real .xlsx file generated server-side via
 * PhpSpreadsheet, applying the same filter set the on-screen statement uses
 * (search, from_date, to_date, type, module). This is the fix for the legacy
 * client-side CSV builder that only iterated the first 20 visible rows.
 */
class AccountStatementExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@export.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'TEST_EXPORT Cashbox',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $overrides)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function seedEntries(Account $account, array $rows): void
    {
        LedgerBalanceMutationGuard::run(function () use ($account, $rows) {
            foreach ($rows as $r) {
                $debit = (float) ($r['debit'] ?? 0.00);
                $credit = (float) ($r['credit'] ?? 0.00);
                $amount = $r['amount'] ?? max($debit, $credit);
                $at = $r['at'] ?? now();
                $notes = $r['notes'] ?? 'TEST_EXPORT entry';

                $tx = new Transaction();
                $tx->timestamps = false;
                $tx->forceFill([
                    'type' => $r['type'] ?? TransactionType::Transfer->value,
                    'amount' => $amount,
                    'currency' => $account->currency,
                    'module' => $r['module'] ?? TransactionModule::General->value,
                    'from_account_id' => $account->id,
                    'to_account_id' => null,
                    'created_by' => $this->admin->id,
                    'notes' => $notes,
                    'created_at' => $at,
                    'updated_at' => $at,
                ])->save();

                $entry = new AccountEntry();
                $entry->timestamps = false;
                $entry->forceFill([
                    'account_id' => $account->id,
                    'transaction_id' => $tx->id,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance_after' => $r['balance_after'] ?? 0.00,
                    'notes' => $notes,
                    'is_opening' => false,
                    'created_at' => $at,
                    'updated_at' => $at,
                ])->save();
            }
        });
    }

    public function test_EXPORT_01_returns_xlsx_with_correct_mime_and_filename(): void
    {
        $acc = $this->seedAccount(['name' => 'Cash Test 01']);
        $this->seedEntries($acc, [
            ['credit' => 100.0, 'balance_after' => 100.0, 'at' => now()->subMinutes(2)],
        ]);

        $response = $this->get("/api/v1/finance/accounts/{$acc->id}/statement/export");

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type')
        );

        $cd = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $cd);
        $this->assertStringContainsString('.xlsx', $cd);
        $this->assertStringContainsString('Cash_Test_01', $cd);
    }

    public function test_EXPORT_02_workbook_is_a_valid_xlsx_with_seven_column_header(): void
    {
        $acc = $this->seedAccount();
        // Seed entries in chronological order (oldest first). The export
        // surfaces them DESC (newest first), so the body rows end up
        // reversed: row 10 = C (latest), row 12 = A (earliest).
        $this->seedEntries($acc, [
            ['credit' => 100.0, 'balance_after' => 100.0, 'at' => now()->subMinutes(5), 'notes' => 'TEST row A'],
            ['credit' => 50.0, 'balance_after' => 150.0, 'at' => now()->subMinutes(4), 'notes' => 'TEST row B'],
            ['debit' => 30.0, 'balance_after' => 120.0, 'at' => now()->subMinutes(3), 'notes' => 'TEST row C'],
        ]);

        $response = $this->get("/api/v1/finance/accounts/{$acc->id}/statement/export");
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());

        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        // 7-column header on row 9 (after title block + summary card).
        $headerRow = 9;
        $expected = [
            'A' => 'التاريخ',
            'B' => 'القسم / الموظف',
            'C' => 'المرجع / PNR',
            'D' => 'البيان والوصف',
            'E' => 'مدين (-)',
            'F' => 'دائن (+)',
            'G' => 'الرصيد بعد الحركة',
        ];
        foreach ($expected as $col => $label) {
            $this->assertSame(
                $label,
                (string) $sheet->getCell($col.$headerRow)->getValue(),
                "Header column {$col} mismatch"
            );
        }

        // Body rows start at row 10 (DESC: latest = C at top).
        $this->assertStringContainsString('TEST row C', (string) $sheet->getCell('D10')->getValue());
        $this->assertStringContainsString('TEST row B', (string) $sheet->getCell('D11')->getValue());
        $this->assertStringContainsString('TEST row A', (string) $sheet->getCell('D12')->getValue());

        @unlink($tmp);
    }

    public function test_EXPORT_03_exports_full_filtered_set_not_just_first_page(): void
    {
        $acc = $this->seedAccount();
        // Seed in chronological order — i=1 is oldest, i=30 is the newest.
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [
                'credit' => 10.0,
                'balance_after' => $i * 10.0,
                'notes' => "TEST export row $i",
                'at' => now()->subMinutes(30 - $i),
            ];
        }
        $this->seedEntries($acc, $rows);

        $response = $this->get("/api/v1/finance/accounts/{$acc->id}/statement/export");
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());

        $sheet = IOFactory::load($tmp)->getActiveSheet();

        // Header = row 9, body = rows 10..39, totals = row 41 (gap at 40).
        // Verify the highest row index is exactly what we expect so we
        // catch regressions that drop body rows.
        $lastBodyRow = 39;
        $expectedHighest = $lastBodyRow + 2;
        $this->assertSame($expectedHighest, $sheet->getHighestRow(), 'Expected 30 body rows + totals');

        // DESC: row 10 is row-30 (latest), row 39 is row-1 (earliest).
        $this->assertStringContainsString('TEST export row 30', (string) $sheet->getCell('D10')->getValue());
        $this->assertStringContainsString('TEST export row 1', (string) $sheet->getCell('D'.$lastBodyRow)->getValue());

        @unlink($tmp);
    }

    public function test_EXPORT_04_respects_type_credit_filter(): void
    {
        $acc = $this->seedAccount();
        // 3 entries; only 2 are credits. The export must drop the debit row.
        $this->seedEntries($acc, [
            ['credit' => 100.0, 'balance_after' => 100.0, 'at' => now()->subMinutes(5)],
            ['credit' => 0.0, 'debit' => 50.0, 'balance_after' => 50.0, 'at' => now()->subMinutes(4)],
            ['credit' => 75.0, 'balance_after' => 125.0, 'at' => now()->subMinutes(3)],
        ]);

        $response = $this->get("/api/v1/finance/accounts/{$acc->id}/statement/export?type=credit");
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());

        $sheet = IOFactory::load($tmp)->getActiveSheet();

        // Iterate the body range (rows 10..11) — skip the totals row at
        // row 13 which contains SUM formulas that read as non-zero strings.
        $bodyStart = 10;
        $bodyEnd = 11; // 2 credit-only rows
        $debitValues = [];
        $creditValues = [];
        for ($r = $bodyStart; $r <= $bodyEnd; $r++) {
            $debit = $sheet->getCell('E'.$r)->getValue();
            $credit = $sheet->getCell('F'.$r)->getValue();
            if ((float) $debit > 0) {
                $debitValues[] = ['row' => $r, 'debit' => $debit];
            }
            if ((float) $credit > 0) {
                $creditValues[] = ['row' => $r, 'credit' => $credit];
            }
        }

        $this->assertSame([], $debitValues, 'No debit values should appear when type=credit');
        $this->assertCount(2, $creditValues, 'Exactly 2 credit rows should appear when type=credit');

        @unlink($tmp);
    }

    public function test_EXPORT_05_respects_date_range_filter(): void
    {
        $acc = $this->seedAccount();
        $this->seedEntries($acc, [
            ['credit' => 100.0, 'balance_after' => 100.0, 'at' => now()->subDays(20)],
            ['credit' => 200.0, 'balance_after' => 300.0, 'at' => now()->subDays(5)],
            ['credit' => 300.0, 'balance_after' => 600.0, 'at' => now()->subDays(1)],
        ]);

        // 10-day window excludes the 20-day-old row and the 1-day-old row.
        // Only the 5-day-old entry should be exported.
        $response = $this->get(
            "/api/v1/finance/accounts/{$acc->id}/statement/export"
            .'?from_date='.now()->subDays(10)->toDateString()
            .'&to_date='.now()->subDays(2)->toDateString()
        );
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());

        $sheet = IOFactory::load($tmp)->getActiveSheet();

        // Exactly 1 body row should match (the 5-days-ago entry). Iterate
        // the body range only — skip the totals row which contains SUM
        // formulas that read as non-zero strings via getValue().
        $bodyStart = 10;
        $bodyEnd = 10; // exactly 1 in-window row
        $creditCount = 0;
        for ($r = $bodyStart; $r <= $bodyEnd; $r++) {
            $credit = $sheet->getCell('F'.$r)->getValue();
            if ((float) $credit > 0) {
                $creditCount++;
            }
        }
        $this->assertSame(1, $creditCount, 'Only the in-window credit row should appear');

        @unlink($tmp);
    }

    public function test_EXPORT_06_404_for_nonexistent_account(): void
    {
        $response = $this->get('/api/v1/finance/accounts/999999/statement/export');
        $response->assertStatus(404);
    }

    public function test_EXPORT_07_non_admin_gets_403(): void
    {
        $emp = User::query()->create([
            'name' => 'Emp', 'email' => 'emp@export.test',
            'password' => Hash::make('password'),
            'role' => 'employee', 'is_active' => true,
        ]);
        $acc = $this->seedAccount();

        auth()->forgetGuards();
        Sanctum::actingAs($emp, ['*']);

        $this->get("/api/v1/finance/accounts/{$acc->id}/statement/export")
            ->assertStatus(403);
    }

    public function test_EXPORT_08_empty_statement_returns_workbook_with_notice_row(): void
    {
        $acc = $this->seedAccount();
        // no entries

        $response = $this->get("/api/v1/finance/accounts/{$acc->id}/statement/export");
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());

        $sheet = IOFactory::load($tmp)->getActiveSheet();
        $this->assertStringContainsString(
            'لا توجد حركات',
            (string) $sheet->getCell('A10')->getValue()
        );

        @unlink($tmp);
    }
}
