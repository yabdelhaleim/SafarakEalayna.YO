# خطة تنفيذ: إضافة محافظ قسم المكتب لموديول المحافظ والتحويلات

## 🎯 الهدف
في صفحة `/wallet/create`، المستخدم يقدر يختار **محفظة رسمية للموديول** (`module='wallet_transfer'`) **أو** **محفظة عامة لقسم المكتب** (`module_type='office'` العام) — مع تسجيل العملية في الـ Audit Log لتمييز النطاق المستخدم، **بدون أي تأثير على المنطق المحاسبي أو على أي جزء آخر من النظام**.

---

## ✅ تأكيد السلامة (Production Safety Audit)

بعد استكشاف الكود، لقيت إن **الباك إند جاهز فعلاً** لاستيعاب التوسعة من غير أي تعديل:

| فحص | النتيجة |
|---|---|
| `TransferLiquidityAccount` rule يقبل `module_type='office'`؟ | ✅ **نعم** (سطر 92-94: يقبل الـ division كلها) |
| `TransferTreasuryController::overview` يرجع محافظ office؟ | ✅ **نعم** (سطر 33-37: `whereIn(['office', 'wallet_transfer'])`) |
| `WalletTransactionService::createTransaction` يستخدم wallet_account_id مباشرة؟ | ✅ **نعم** (سطر 446) — من غير أي تحقق إضافي على module_type |
| المنطق المحاسبي (القيود، الأرصدة)؟ | ✅ **لم يتأثر** — `wallet_account_id` بيتسجل كما هو في `Transaction.from_account_id` |
| `Account::booted()` saving guard؟ | ✅ **ما يمنعش** — الـ wallet_account_id بس reference لـ Account ID موجود |
| `LedgerBalanceMutationGuard`؟ | ✅ **ما يتأثرش** — التغيير على مستوى UI/API فقط |

**الاستنتاج:** التغيير كله في الـ **Frontend** (Vue)، مع إضافة **Audit Log entries**. **صفر migration، صفر breaking change، صفر تأثير على الحسابات.**

---

## 📋 خطوات التنفيذ

### 1) Frontend — `resources/js/views/wallet/WalletCreate.vue` (التعديل الأساسي)

#### أ) تعديل فلتر المحافظ الحالي (سطر 727-738)

**قبل:**
```js
const baseList = walletAccounts.value.filter(
  (a) => a.module_type === 'wallet_transfer' || a.module === 'wallet_transfer'
);
```

**بعد:**
```js
// ✅ توسعة: نقبل (1) محافظ wallet_transfer الرسمية + (2) أي محفظة قسم مكتب (office)
// كلاهما liquidity صالحة — الباك إند في TransferLiquidityAccount rule و TreasuryController مغطّيهم
const baseList = walletAccounts.value.filter((a) => {
  if (a.module === 'wallet_transfer' || a.module_type === 'wallet_transfer') {
    return true; // المحفظة الرسمية للموديول
  }
  if (a.module_type === 'office') {
    return true; // محفظة عامة لقسم المكتب
  }
  return false;
});
```

#### ب) إضافة computed للتجميع البصري

```js
// جديد: تقسيم بطاقات المحفظ لمجموعتين بصرياً
const groupedWalletAccounts = computed(() => {
  const type = selectedWalletType.value;
  const matched = (a) => type ? accountMatchesWalletType(a, type) : true;

  const official = filteredWalletAccounts.value.filter(matched).filter(
    (a) => a.module === 'wallet_transfer' || a.module_type === 'wallet_transfer'
  );
  const officeWide = filteredWalletAccounts.value.filter(matched).filter(
    (a) => a.module_type === 'office' && a.module !== 'wallet_transfer' && a.module_type !== 'wallet_transfer'
  );
  return { official, officeWide };
});
```

#### ج) تعديل الـ template (سطر 224-266)

استبدال البلوك الحالي للـ Wallet Cards بـ:
- **header صغير** فوق كل مجموعة (مع عدد المحافظ)
- **badge** على كل كارد: "رسمية" أو "قسم المكتب"
- نفس الـ UX (نفس الكروت، نفس الضغط، نفس الانيميشن)

#### د) فلتر تشغيلي اختياري (top tabs) — "الكل / الرسمية / المكتب"

ثلاث أزرار tabs صغيرة فوق القوائم:
- 🟦 **الكل** (افتراضي)
- 🟧 **الرسمية للموديول فقط**
- 🟨 **قسم المكتب فقط**

#### هـ) اختصار لإضافة محفظة مكتب جديدة (Filament)

لأن المستخدم محتاج يضيف محافظ جديدة، نضيف زر "إضافة محفظة جديدة" يفتح Filament في تبويب جديد:
```vue
<a href="/admin/accounts/create?type=wallet&module_type=office" target="_blank" class="...">
  + إضافة محفظة جديدة لقسم المكتب
</a>
```

---

### 2) Backend — Audit Log Integration

#### أ) تعديل `app/Services/Wallet/WalletTransactionService.php`

بعد إنشاء `WalletTransaction` بنجاح (بعد سطر 153)، نضيف:

```php
// Audit Log: نسجل نوع العملية والنطاق المستخدم ( رسمي موديول / قسم مكتب )
\Illuminate\Support\Facades\DB::afterCommit(function () use ($record, $type, $createdBy) {
    try {
        $walletAccount = Account::find($record->wallet_account_id);
        $scope = $walletAccount && (
            $walletAccount->module === 'wallet_transfer' ||
            $walletAccount->module_type === 'wallet_transfer'
        ) ? 'official_module' : 'office_department';

        \App\Models\AuditLog::create([
            'user_id' => $createdBy,
            'action' => 'wallet_transaction.created',
            'model_type' => \App\Models\Wallet\WalletTransaction::class,
            'model_id' => $record->id,
            'old_values' => null,
            'new_values' => [
                'type' => $type->value,
                'wallet_account_id' => $record->wallet_account_id,
                'wallet_account_scope' => $scope,        // ⭐ المفتاح
                'wallet_account_name' => $walletAccount?->name,
                'cash_account_id' => $record->cash_account_id,
                'amount' => (float) $record->amount,
                'service_fee' => (float) $record->service_fee,
                'total_amount' => (float) $record->total_amount,
                'amount_paid' => (float) $record->amount_paid,
                'customer_id' => $record->customer_id,
                'customer_name' => $record->customer_name,
                'wallet_number' => $record->wallet_number,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'notes' => "عملية محفظة ({$type->value}) باستخدام محفظة {$scope}: {$walletAccount?->name}",
        ]);
    } catch (\Throwable $e) {
        // Audit failure must NEVER break the operation
        \Illuminate\Support\Facades\Log::warning('WalletTransaction audit log failed', [
            'id' => $record->id, 'error' => $e->getMessage(),
        ]);
    }
});
```

**نفس النمط** لـ `updateTransaction` (سطر 217) و `deleteTransaction` (سطر 689).

**ضمانات:**
- `DB::afterCommit` — الـ Audit يتسجل بعد commit الـ transaction الأساسي → لو فشل audit، الـ accounting state يبقى سليم
- `try/catch` حول الـ AuditLog::create — صفر تأثير على الـ flow الأساسي
- الـ AuditLog model مغطّى بـ `audit_logs` table موجودة من 2026-04-27 (لا migration جديد)

---

### 3) Feature Test — `tests/Feature/Wallet/UseOfficeDepartmentWalletsTest.php`

تغطية اختبارية كاملة:

```php
public function test_user_can_use_office_division_wallet_in_wallet_transfer_module(): void
{
    // 1. أنشئ محفظة module_type='office' (قسم المكتب) عامة
    $officeWallet = Account::factory()->create([
        'type' => AccountType::Wallet,
        'module_type' => 'office',
        'wallet_provider' => 'vodafone_cash',
        'is_active' => true,
    ]);

    // 2. أرسل POST /api/v1/wallet/transactions مع wallet_account_id بتاعها
    $response = $this->actingAs($this->admin)->postJson('/api/v1/wallet/transactions', [
        'type' => 'send',
        'wallet_type_id' => $this->walletType->id,
        'wallet_account_id' => $officeWallet->id,
        'cash_account_id' => $this->cashbox->id,
        'amount' => 100,
        'service_fee' => 5,
        'customer_name' => 'عميل اختبار',
        'wallet_number' => '01012345678',
    ]);

    // 3. اتأكد إنها اتسجلت + القيود اتحرّكت + الـ AuditLog اتكتب بـ scope='office_department'
    $response->assertStatus(201);
    $this->assertDatabaseHas('audit_logs', [
        'model_id' => $response->json('data.id'),
        'action' => 'wallet_transaction.created',
    ]);
    $this->assertEquals('office_department', AuditLog::where('model_id', $response->json('data.id'))->first()->new_values['wallet_account_scope']);
}

public function test_existing_wallet_transfer_scope_still_works(): void
{
    // backward compatibility: السلوك القديم ما يتأثرش
}

public function test_audit_log_failure_does_not_break_transaction(): void
{
    // لو الـ audit_logs table معطّلة، العملية لازم تنجح برضه
}
```

---

## 📂 ملخص الملفات اللي هتتعدل

| # | الملف | نوع التعديل | حجم التعديل |
|---|---|---|---|
| 1 | `resources/js/views/wallet/WalletCreate.vue` | تعديل `<template>` (عرض المجموعات) + `<script>` (فلتر + computed) | ~80 سطر |
| 2 | `app/Services/Wallet/WalletTransactionService.php` | إضافة AuditLog::create بعد الـ create/update/delete | ~30 سطر |
| 3 | `tests/Feature/Wallet/UseOfficeDepartmentWalletsTest.php` | **جديد** — 3 سيناريوهات | ~120 سطر |

**صفر migrations. صفر تغيير في API responses (الحقول الجديدة في `new_values` للـ AuditLog فقط، ما تأثرش على الـ wallet transaction). صفر تعديل على أي controller أو rule أو migration.**

---

## 🛡️ خطة الـ Rollback Safety

لو حصل أي مشكلة بعد التطبيق:

1. **Frontend:** التغيير في الـ Vue filter ممكن يترجع بـ git revert في دقيقة واحدة (revert commit واحد)
2. **Backend AuditLog:** صفر تأثير على الباك إند الأساسي — الـ AuditLog entries زيادة في جدول `audit_logs` فقط
3. **Validation rule:** ما اتغيرش — الباك إند كان بيقبل النطاقين من زمان

---

## 📊 معايير النجاح (Acceptance Criteria)

- [ ] في `/wallet/create`، المستخدم يشوف **مجموعتين منفصلتين**: رسمية + قسم المكتب
- [ ] كل كارد عليه badge يوضح النطاق
- [ ] في `audit_logs` يظهر action `wallet_transaction.created` مع `wallet_account_scope` صحيح
- [ ] كل الـ tests الموجودة في `tests/Feature/Wallet/` و `tests/Feature/Finance/` تنجح
- [ ] الـ feature tests الـ 3 الجديدة تنجح
- [ ] صفر تأثير على رصيد أي حساب محاسبي
- [ ] صفر أخطاء في الـ console / logs
- [ ] Build / `npm run build` ينجح
- [ ] `php artisan test --filter=Wallet` ينجح 100%

---

## 🚦 ترتيب التنفيذ

1. **Backend Audit Log** (آمن 100% — إضافة فقط، صفر breaking)
2. **Feature Tests** (نتأكد إن الباك إند شغال + نضيف الـ 3 tests الجديدة)
3. **Frontend Vue** (بعد ما نتأكد إن الباك إند مستقر)
4. **Manual Smoke Test** في بيئة الـ production بعد deploy
5. **اختياري:** Vue tabs filter (لو الـ UI بدونه مقبول)

---

## 💡 ملاحظات إضافية

- **الـ `owner_type` field** (موجود لكن مش مستخدم في الـ treasury controllers): لم نلمسه — الـ `owner_type` خاص بنظام آخر (حسابات المالكين ككل)، مش النطاق اللي بيتكلم عنه المستخدم
- **الـ `employee_id` column:** ما أضفناش — الـ user ليس له employee record بالضرورة، والـ scope "office department" هو مجموعة حسابات وليس ربط بموظف
- **CacheHelper::flushTags:** ما احتجناش نلمسه — الـ `TransferTreasuryController::overview` ما بيستخدمش cache tags (الـ wallets دايماً fresh query)
- **القيد المحاسبي:** منطق الـ `TransactionService::recordIncome/recordExpense/recordJournalTransfer` بياخد `account_id` خام — ما عنده أي تحقق على `module_type`، فالعملية بتعدي بسلاسة على أي Account ID سليم
