<?php
/**
 * DIAGNOSTIC: تشخيص أرصدة الناقلين/الأنظمة لتحديد سبب "رصيد مسبق غير كافٍ"
 *
 * ⚠️ للقراءة فقط — لا يُعدّل شيئاً.
 *
 * الاستخدام:
 *   php artisan tinker --execute='require "fix_carrier_balance_diag.php";'
 *
 * ما يفعله:
 *   1) يعرض كل الناقلين (flight_carriers) مع أرصدتهم المسبقة
 *   2) يعرض كل أنظمة الطيران (flight_systems) مع أرصدتها
 *   3) يحدد من منهم رصيده سالب أو أقل من حدّ معيّن
 *   4) يعرض آخر 10 قيود (transactions) لكل رصيد سالب لتحديد السبب
 *   5) يقترح حلاً (شحن، عكس قيد، أو استخدام ناقل آخر)
 */

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

echo "\n=========================================================\n";
echo "  DIAGNOSTIC: أرصدة الناقلين والأنظمة\n";
echo "=========================================================\n";

// ─────────────────────────────────────────────────────────────
// [1] الناقلون
// ─────────────────────────────────────────────────────────────
echo "\n[1] الناقلون (flight_carriers)\n";
echo "─────────────────────────────────────────────────────────\n";

$carriers = FlightCarrier::orderBy('id')->get();

if ($carriers->isEmpty()) {
    echo "  (لا يوجد ناقلون مسجلون)\n";
} else {
    printf("  %-4s | %-30s | %-15s | %-18s | %s\n", 'ID', 'الاسم', 'العملة', 'الرصيد المسبق', 'الحالة');
    echo "  " . str_repeat('─', 90) . "\n";

    foreach ($carriers as $c) {
        $bal = (float) $c->available_balance;
        $status = $bal < 0 ? '🔴 سالب'
                : ($bal < 1000 ? '🟡 منخفض' : '🟢 كافي');
        printf(
            "  %-4d | %-30s | %-15s | %18.2f | %s\n",
            $c->id,
            mb_substr((string) $c->name, 0, 30),
            $c->currency ?? 'EGP',
            $bal,
            $status
        );
    }
}

// ─────────────────────────────────────────────────────────────
// [2] أنظمة الطيران
// ─────────────────────────────────────────────────────────────
echo "\n[2] أنظمة الطيران (flight_systems)\n";
echo "─────────────────────────────────────────────────────────\n";

$systems = FlightSystem::orderBy('id')->get();

if ($systems->isEmpty()) {
    echo "  (لا يوجد أنظمة طيران مسجلة)\n";
} else {
    printf("  %-4s | %-30s | %-15s | %-18s | %s\n", 'ID', 'الاسم', 'العملة', 'الرصيد', 'الحالة');
    echo "  " . str_repeat('─', 90) . "\n";

    foreach ($systems as $s) {
        $bal = (float) $s->available_balance;
        $status = $bal < 0 ? '🔴 سالب'
                : ($bal < 1000 ? '🟡 منخفض' : '🟢 كافي');
        printf(
            "  %-4d | %-30s | %-15s | %18.2f | %s\n",
            $s->id,
            mb_substr((string) $s->name, 0, 30),
            $s->currency ?? 'EGP',
            $bal,
            $status
        );
    }
}

// ─────────────────────────────────────────────────────────────
// [2.5] الحسابات المسبقة (Prepaid GL Accounts) ← ده السبب الحقيقي للخطأ
// ─────────────────────────────────────────────────────────────
echo "\n[2.5] الحسابات المسبقة (Prepaid GL) — يُسحب منها عند إنشاء الحجز\n";
echo "─────────────────────────────────────────────────────────\n";

$prepaidNames = config('accounting.clearing.prepaid', []);
if (empty($prepaidNames)) {
    echo "  ⚠ لا يوجد إعداد prepaid في config/accounting.php\n";
} else {
    printf("  %-30s | %-20s | %-18s | %s\n", 'المفتاح', 'اسم الحساب', 'الرصيد', 'الحالة');
    echo "  " . str_repeat('─', 90) . "\n";

    foreach ($prepaidNames as $key => $name) {
        $acc = Account::where('name', $name)->first();
        if (! $acc) {
            printf("  %-30s | %-20s | %18s | ⚠ غير موجود\n",
                $key, mb_substr((string) $name, 0, 20), '-'
            );
            continue;
        }

        $bal = (float) $acc->balance;
        $status = $bal < 0 ? '🔴 سالب'
                : ($bal < 6291.70 ? '🟡 غير كافٍ للحجز' : '🟢 كافي');
        printf("  %-30s | %-20s | %18.2f | %s\n",
            $key,
            mb_substr((string) $acc->name, 0, 20),
            $bal,
            $status
        );
    }
}

// ─────────────────────────────────────────────────────────────
// [3] الأرصدة السالبة (المشكلة)
// ─────────────────────────────────────────────────────────────
echo "\n[3] الأرصدة السالبة (تحليل)\n";
echo "─────────────────────────────────────────────────────────\n";

$negativeCarriers = $carriers->filter(fn($c) => (float) $c->available_balance < 0);
$negativeSystems  = $systems->filter(fn($s) => (float) $s->available_balance < 0);

// البحث عن الحسابات المسبقة السالبة
$prepaidNames = config('accounting.clearing.prepaid', []);
$negativePrepaid = [];
foreach ($prepaidNames as $key => $name) {
    $acc = Account::where('name', $name)->first();
    if ($acc && (float) $acc->balance < 0) {
        $negativePrepaid[$key] = $acc;
    }
}

if ($negativeCarriers->isEmpty() && $negativeSystems->isEmpty() && empty($negativePrepaid)) {
    echo "  ✓ لا توجد أرصدة سالبة في carriers/systems\n";
    if (! empty($negativePrepaid)) {
        // لن يحدث لأن في حالة سالب في prepaid لن يدخل هنا
    }
} else {
    foreach ($negativeCarriers as $c) {
        echo "\n  🔴 الناقل #{$c->id} ({$c->name}) رصيده = " . number_format((float) $c->available_balance, 2) . " {$c->currency}\n";
        echo "  ── آخر 10 قيود مرتبطة به ──\n";

        // البحث عن account_id المرتبط بهذا الناقل
        $accountId = DB::table('accounts')
            ->where('accountable_type', 'App\\Models\\Flight\\FlightCarrier')
            ->where('accountable_id', $c->id)
            ->value('id');

        if (! $accountId) {
            echo "    (لا يوجد حساب محاسبي مرتبط بهذا الناقل)\n";
            continue;
        }

        $txs = DB::table('account_entries')
            ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
            ->where('account_entries.account_id', $accountId)
            ->orderByDesc('transactions.id')
            ->limit(10)
            ->get([
                'transactions.id as tx_id',
                'transactions.type',
                'transactions.amount',
                'account_entries.debit',
                'account_entries.credit',
                'account_entries.balance_after',
                'transactions.notes',
                'transactions.created_at',
            ]);

        if ($txs->isEmpty()) {
            echo "    (لا توجد قيود)\n";
            continue;
        }

        printf("    %-6s | %-12s | %10s | %10s | %10s | %s\n",
            'TxID', 'النوع', 'مدين', 'دائن', 'بعد', 'ملاحظات');
        echo "    " . str_repeat('─', 80) . "\n";

        foreach ($txs as $t) {
            printf(
                "    %-6d | %-12s | %10.2f | %10.2f | %10.2f | %s\n",
                $t->tx_id,
                mb_substr((string) $t->type, 0, 12),
                (float) $t->debit,
                (float) $t->credit,
                (float) $t->balance_after,
                mb_substr((string) ($t->notes ?? ''), 0, 30)
            );
        }
    }

    foreach ($negativeSystems as $s) {
        echo "\n  🔴 النظام #{$s->id} ({$s->name}) رصيده = " . number_format((float) $s->available_balance, 2) . " {$s->currency}\n";

        $accountId = DB::table('accounts')
            ->where('accountable_type', 'App\\Models\\Flight\\FlightSystem')
            ->where('accountable_id', $s->id)
            ->value('id');

        if (! $accountId) {
            echo "    (لا يوجد حساب محاسبي مرتبط بهذا النظام)\n";
            continue;
        }

        $txs = DB::table('account_entries')
            ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
            ->where('account_entries.account_id', $accountId)
            ->orderByDesc('transactions.id')
            ->limit(10)
            ->get([
                'transactions.id as tx_id',
                'transactions.type',
                'transactions.amount',
                'account_entries.debit',
                'account_entries.credit',
                'account_entries.balance_after',
                'transactions.notes',
                'transactions.created_at',
            ]);

        if ($txs->isEmpty()) {
            echo "    (لا توجد قيود)\n";
            continue;
        }

        printf("    %-6s | %-12s | %10s | %10s | %10s | %s\n",
            'TxID', 'النوع', 'مدين', 'دائن', 'بعد', 'ملاحظات');
        echo "    " . str_repeat('─', 80) . "\n";

        foreach ($txs as $t) {
            printf(
                "    %-6d | %-12s | %10.2f | %10.2f | %10.2f | %s\n",
                $t->tx_id,
                mb_substr((string) $t->type, 0, 12),
                (float) $t->debit,
                (float) $t->credit,
                (float) $t->balance_after,
                mb_substr((string) ($t->notes ?? ''), 0, 30)
            );
        }
    }
}

// ─────────────────────────────────────────────────────────────
// [3.5] تحليل الحسابات المسبقة السالبة (السبب الحقيقي للخطأ)
// ─────────────────────────────────────────────────────────────
echo "\n[3.5] تحليل الحسابات المسبقة السالبة\n";
echo "─────────────────────────────────────────────────────────\n";

if (empty($negativePrepaid)) {
    echo "  ✓ لا توجد حسابات مسبقة سالبة\n";
} else {
    foreach ($negativePrepaid as $key => $acc) {
        echo "\n  🔴 الحساب المسبق «{$key}» (#{$acc->id} \"{$acc->name}\") = " . number_format((float) $acc->balance, 2) . " {$acc->currency}\n";
        echo "  ── آخر 10 قيود مرتبطة به ──\n";

        $txs = DB::table('account_entries')
            ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
            ->where('account_entries.account_id', $acc->id)
            ->orderByDesc('transactions.id')
            ->limit(10)
            ->get([
                'transactions.id as tx_id',
                'transactions.type',
                'transactions.amount',
                'account_entries.debit',
                'account_entries.credit',
                'account_entries.balance_after',
                'transactions.notes',
                'transactions.created_at',
            ]);

        if ($txs->isEmpty()) {
            echo "    (لا توجد قيود)\n";
            continue;
        }

        printf("    %-6s | %-12s | %10s | %10s | %10s | %s\n",
            'TxID', 'النوع', 'مدين', 'دائن', 'بعد', 'ملاحظات');
        echo "    " . str_repeat('─', 80) . "\n";

        foreach ($txs as $t) {
            printf(
                "    %-6d | %-12s | %10.2f | %10.2f | %10.2f | %s\n",
                $t->tx_id,
                mb_substr((string) $t->type, 0, 12),
                (float) $t->debit,
                (float) $t->credit,
                (float) $t->balance_after,
                mb_substr((string) ($t->notes ?? ''), 0, 30)
            );
        }
    }
}

// ─────────────────────────────────────────────────────────────
// [4] التوصيات
// ─────────────────────────────────────────────────────────────
echo "\n[4] التوصيات\n";
echo "─────────────────────────────────────────────────────────\n";

if ($negativeCarriers->isNotEmpty()) {
    foreach ($negativeCarriers as $c) {
        $shortfall = abs((float) $c->available_balance) + 6291.70; // لتغطية العجز + حجز نموذجي
        echo "  • الناقل #{$c->id} ({$c->name}):\n";
        echo "      - شحن رصيد بمبلغ {$shortfall} {$c->currency} لتغطية العجز + حجز نموذجي.\n";
        echo "      - أو استخدم النظام #1 (لو رصيده موجب) كمصدر للحجز القادم.\n";
        echo "      - أو راجع القيود أعلاه لتحديد ما إذا كان السالب خطأ بيانات يحتاج عكس.\n";
    }
}

if ($negativeSystems->isNotEmpty()) {
    foreach ($negativeSystems as $s) {
        $shortfall = abs((float) $s->available_balance) + 6291.70;
        echo "  • النظام #{$s->id} ({$s->name}):\n";
        echo "      - شحن رصيد بمبلغ {$shortfall} {$s->currency} لتغطية العجز + حجز نموذجي.\n";
    }
}

if (! empty($negativePrepaid)) {
    echo "\n  🚨 الحل المطلوب: شحن الحساب المسبق الناقص (وليس الناقل نفسه).\n";
    foreach ($negativePrepaid as $key => $acc) {
        $shortfall = abs((float) $acc->balance) + 6291.70;
        echo "  • الحساب المسبق «{$key}» (#{$acc->id} \"{$acc->name}\"):\n";
        echo "      - شحن الحساب المسبق بمبلغ {$shortfall} {$acc->currency} لتغطية العجز + حجز نموذجي.\n";
        echo "      - استخدم: fix_prepaid_balance_apply.php (سأكتبه).\n";
        echo "      - أو من الواجهة: /admin → خزائن الطيران → شحن رصيد.\n";
    }
}

if ($negativeCarriers->isEmpty() && $negativeSystems->isEmpty() && empty($negativePrepaid)) {
    echo "  ✓ كل الأرصدة (الناقلين والأنظمة والمسبقة) موجبة — السبب الجذري للخطأ الأصلي في مكان آخر.\n";
    echo "  ✓ راجع المخرجات أعلاه وابحث عن الناقل/النظام اللي رصيده < 6291.70.\n";
}

echo "\n=========================================================\n";
echo "  ✓ انتهى التشخيص.\n";
echo "=========================================================\n";