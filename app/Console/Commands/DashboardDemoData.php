<?php

namespace App\Console\Commands;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Services\Finance\TransactionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DashboardDemoData — ضخ بيانات تجريبية للوحات P&L على staging.
 *
 * المشكلة: كروت الداش بورد (صافي أرباح قطاع السياحة / صافي أرباح حسابات المكتب)
 * بتظهر 0.00 على staging لأن قاعدة البيانات فاضية من قيود income/expense حقيقية
 * (القيود الموجودة كلها transfer معكوس).
 *
 * الحل: شغّل `php artisan dashboard:demo-data` على staging — بيستخدم TransactionService
 * عشان يعمل قيود P&L مزدوجة متوازنة لكل موديول (طيران/حج/تأشيرات/باص/فوري/أونلاين/محفظة)
 * مع spread خلال آخر 30 يوم.
 *
 * كل القيود بادئتها "[DEMO]" في notes عشان تميزها من البيانات الحقيقية.
 *
 * @see \App\Services\Reports\ProfitLossReportService::moduleBreakdown() هو المستفيد
 */
class DashboardDemoData extends Command
{
    /**
     * اسم وسيناريو الأمر.
     */
    protected $signature = 'dashboard:demo-data
                            {--days=30 : عدد الأيام اللي هنوزّع فيها القيود (افتراضي 30 يوم)}
                            {--per-module=3 : عدد القيود لكل موديول/نوع (income/expense)}
                            {--dry-run : اعرض الخطة بس من غير ما تكتب على DB}';

    protected $description = 'يضخ بيانات تجريبية (income/expense) متوازنة لكل الموديولات عشان كروت الداش بورد تظهر أرقام.';

    /**
     * خريطة الموديولات → الأسماء الافتتاحية (تحط في الـ notes عشان تتعرف في التقارير).
     */
    private const MODULE_PLAN = [
        'flight' => [
            'label' => 'بيع تذكرة طيران',
            'min' => 4500,
            'max' => 15000,
            'count' => 4, // عدد قيود الـ income
        ],
        'hajj_umra' => [
            'label' => 'برنامج حج/عمرة',
            'min' => 35000,
            'max' => 75000,
            'count' => 3,
        ],
        'visa' => [
            'label' => 'رسوم تأشيرة',
            'min' => 800,
            'max' => 3500,
            'count' => 4,
        ],
        'bus' => [
            'label' => 'حجز باص',
            'min' => 150,
            'max' => 600,
            'count' => 5,
        ],
        'fawry' => [
            'label' => 'عمولة فوري',
            'min' => 80,
            'max' => 350,
            'count' => 4,
        ],
        'online' => [
            'label' => 'خدمة إلكترونية',
            'min' => 200,
            'max' => 900,
            'count' => 3,
        ],
        'wallet' => [
            'label' => 'تحويل محفظة',
            'min' => 500,
            'max' => 2500,
            'count' => 3,
        ],
    ];

    /**
     * نسب المصروفات لكل موديول (من قيمة الإيراد).
     */
    private const EXPENSE_RATIO = [
        'flight' => 0.65,
        'hajj_umra' => 0.78,
        'visa' => 0.30,
        'bus' => 0.55,
        'fawry' => 0.20,
        'online' => 0.40,
        'wallet' => 0.10,
    ];

    public function handle(TransactionService $txService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));
        $perModule = max(1, (int) $this->option('per-module'));

        $this->info('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  dashboard:demo-data — ضخ بيانات تجريبية للـ P&L');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info("  الفترة: آخر {$days} يوم");
        $this->info("  عدد القيود لكل موديول/نوع: {$perModule}");
        if ($dryRun) {
            $this->warn('  ⚠️  وضع المعاينة (dry-run) — لن تُكتب أي بيانات على DB');
        }
        $this->info('');

        // 1) اتأكد إن عندنا حسابات سيولة نقدر نستخدمها كـ to_account_id للـ income
        $liquidity = $this->pickLiquidityAccount();
        if (! $liquidity) {
            $this->error('  ❌ مفيش حسابات سيولة (cashbox/bank/wallet) نشطة في DB.');
            $this->error('     شغّل UnifiedVaultsSeeder أو أضف حساب خزينة الأول.');
            return self::FAILURE;
        }
        $this->info("  ✓ حساب الخزينة المستخدم: #{$liquidity->id} ({$liquidity->name}) — رصيد {$liquidity->balance} {$liquidity->currency}");

        // 2) Check for existing demo data (idempotency)
        $existingDemoCount = DB::table('transactions')
            ->where('notes', 'like', '[DEMO]%')
            ->count();
        if ($existingDemoCount > 0 && ! $this->confirm("  ⚠️  في {$existingDemoCount} قيد تجريبي موجود بالفعل. أضيف على الموجود؟", false)) {
            $this->info('  → تم الإلغاء بناءً على طلبك.');
            return self::SUCCESS;
        }

        // 3) ضخ القيود
        $created = 0;
        $failed = 0;
        $totalProfit = 0.0;
        $totalRevenue = 0.0;
        $totalExpense = 0.0;

        $bar = $this->output->createProgressBar(count(self::MODULE_PLAN) * ($perModule + 1));
        $bar->start();

        foreach (self::MODULE_PLAN as $moduleKey => $plan) {
            $moduleValue = TransactionModule::tryFrom($moduleKey)?->value;
            if (! $moduleValue) {
                $this->newLine();
                $this->warn("  ⚠️  موديول غير معروف: {$moduleKey} — تخطي");
                continue;
            }

            // 3a) قيود الإيراد
            $incomeCount = min($perModule, $plan['count']);
            for ($i = 0; $i < $incomeCount; $i++) {
                $amount = $this->randomAmount($plan['min'], $plan['max']);
                $createdAt = $this->randomCreatedAt($days);

                $notes = "[DEMO] {$plan['label']} #" . ($i + 1) . " — {$amount} EGP";

                if ($dryRun) {
                    $bar->advance();
                    continue;
                }

                try {
                    // recordIncome() بياخد to_account_id ويختار الـ contra (clearing)
                    // تلقائياً حسب الموديول، وبيعمل القيود المزدوجة والـ audit stamp.
                    $tx = $txService->recordIncome([
                        'amount' => $amount,
                        'to_account_id' => $liquidity->id,
                        'module' => $moduleValue,
                        'notes' => $notes,
                        'created_by' => 1,
                    ]);
                    // recordIncome مفيش created_at في الـ payload — نعدّله يدوياً عشان يتوزع
                    DB::table('transactions')
                        ->where('id', $tx->id)
                        ->update([
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ]);
                    $created++;
                    $totalRevenue += $amount;
                    $totalProfit += $amount;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->error("  ❌ فشل income {$moduleKey} #{$i}: " . $e->getMessage());
                }
                $bar->advance();
            }

            // 3b) قيد مصروف واحد لكل موديول
            $expenseRatio = self::EXPENSE_RATIO[$moduleKey] ?? 0.5;
            // إجمالي الإيرادات اللي اتحققت فعلاً للموديول ده (لحساب المصروف)
            $expectedRevenue = ($plan['min'] + $plan['max']) / 2 * $incomeCount;
            $expenseAmount = round($expectedRevenue * $expenseRatio, 2);
            if ($expenseAmount < 50) {
                $expenseAmount = 50.0;
            }
            $expenseCreatedAt = $this->randomCreatedAt($days);

            if ($dryRun) {
                $bar->advance();
                continue;
            }

            try {
                $tx = $txService->recordExpense([
                    'amount' => $expenseAmount,
                    'from_account_id' => $liquidity->id,
                    'module' => $moduleValue,
                    'notes' => "[DEMO] تكلفة {$plan['label']} (نظام) — {$expenseAmount} EGP",
                    'created_by' => 1,
                ]);
                DB::table('transactions')
                    ->where('id', $tx->id)
                    ->update([
                        'created_at' => $expenseCreatedAt,
                        'updated_at' => $expenseCreatedAt,
                    ]);
                $created++;
                $totalExpense += $expenseAmount;
                $totalProfit -= $expenseAmount;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("  ❌ فشل expense {$moduleKey}: " . $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // 4) ملخص
        $this->info('═══════════════════════════════════════════════════════════════');
        if ($dryRun) {
            $this->info('  ✅ معاينة مكتملة — مفيش بيانات اتكتبت');
        } else {
            $this->info("  ✅ تم إنشاء {$created} قيد (فشل: {$failed})");
            $this->info('');
            $this->info('  📊 ملخص الأرقام المتوقعة في كروت الداش بورد:');
            $this->info("     • إجمالي الإيرادات: " . number_format($totalRevenue, 2) . ' EGP');
            $this->info("     • إجمالي المصروفات: " . number_format($totalExpense, 2) . ' EGP');
            $this->info("     • صافي الربح:       " . number_format($totalProfit, 2) . ' EGP');
            $this->info('');
            $this->info('  🗑️  لو عايز تمسح البيانات: php artisan tinker');
            $this->info("     >>> \\DB::table('transactions')->where('notes', 'like', '[DEMO]%')->delete();");
            $this->info('');
            $this->info('  ⚠️  مهم: امسح كاش الداش بورد:');
            $this->info('     php artisan cache:clear && php artisan config:clear');
        }
        $this->info('═══════════════════════════════════════════════════════════════');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * اختار أول حساب سيولة نشط (cashbox المفضّل، بعده bank، بعده wallet).
     */
    private function pickLiquidityAccount(): ?Account
    {
        $preferred = ['cashbox', 'bank', 'wallet'];
        foreach ($preferred as $type) {
            $acc = Account::query()
                ->where('is_active', true)
                ->where('type', $type)
                ->orderBy('id')
                ->first();
            if ($acc) {
                return $acc;
            }
        }
        return null;
    }

    private function randomAmount(float $min, float $max): float
    {
        $v = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
        return round($v, 2);
    }

    private function randomCreatedAt(int $days): Carbon
    {
        $secondsAgo = mt_rand(0, $days * 24 * 60 * 60);
        return Carbon::now()->subSeconds($secondsAgo);
    }
}
