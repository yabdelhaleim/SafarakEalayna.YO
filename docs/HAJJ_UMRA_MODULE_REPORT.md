# 🕋 Hajj/Umra Module — تقرير شامل ومفصّل

> **تاريخ التقرير:** 2026-07-11
> **النظام:** SafarakEalayna (Laravel 13 + Filament 5 + Vue.js)
> **الموديول:** الحج والعمرة (`module_type = hajj_umra`) — tourism division
> **إجمالي الملفات المرتبطة:** ~80 ملف

---

## 1. نظرة عامة

موديول الحج والعمرة هو موديول **سياحة متكامل** بيغطي:

- بيع برامج الحج والعمرة (فنادق مكة/المدينة + طيران + مشرف + شركة منفذة)
- محاسبة **double-entry** تلقائية (تكلفة على الشركة المنفذة + إيراد على العميل)
- تتبع دفعات العميل (دفعة أولية + دفعات لاحقة) من خزائن/بنوك/محافظ مخصصة
- إدارة الشركات المنفذة وموردي العمرة (لكل واحد حساب GL تلقائي)
- كشوف حسابات العملاء + مستحقات الشركات
- تكامل كامل مع نظام الـ GL الرئيسي
- Filament Admin + Vue.js SPA كاملين

---

## 2. الـ Architecture Pattern

```
Layer 7: Frontends (Filament + Vue.js SPA + Pinia store)
Layer 6: Filament Resources (7 resources + 4 page resources + 1 widget)
Layer 5: HTTP Layer (Controllers + Form Requests)
Layer 4: Business Service (HajjUmraBookingService) — العصب
Layer 3: Observers (HajjUmraExecutingCompanyObserver + UmrahSupplierObserver)
Layer 2: Eloquent Models (9 models + relationships + accessors)
Layer 1: Database (9 tables)
```

---

## 3. قاعدة البيانات — الجداول الـ 9

### 3.1 `hajj_umra_bookings` (الجدول الرئيسي)

**Migrations:**
- `2026_04_27_124551_create_hajj_umra_bookings_table.php` (الأساسي)
- `2026_05_06_080000_setup_hajj_umra_and_visa_accounting.php` (الأعمدة المحاسبية)
- `2026_05_07_025403_add_baggage_to_hajj_umra_bookings_table.php`
- `2026_06_03_220000_upgrade_visa_and_umrah_tables.php` (supplier/companion/accommodation)
- indexes أداء في `2026_05_15` و`2026_05_24`

**الأعمدة الجوهرية:**
- `customer_id` FK → customers
- `companion_customer_id` FK → customers NULL
- `program_id` FK → programs
- `supplier_id` FK → umrah_suppliers NULL
- `purchase_price`, `companion_purchase_price`, `selling_price`, `companion_selling_price`, `profit` (decimal 15,2)
- `currency` default EGP
- `per_person` boolean
- `accommodation_choice` (standard/private), `accommodation_extra_charge`
- `status` (pending/confirmed/in_progress/completed/cancelled/refunded)
- `agent_name`, `notes`, `baggage`
- **`account_id`** FK → accounts (إلزامي - الخزينة)
- **`employee_id`, `created_by`** FK → users
- **`expense_transaction_id`, `income_transaction_id`** FK → transactions
- SoftDeletes ✅

### 3.2 `hajj_umra_payments` (دفعات العميل)

**Migration:** `2026_04_27_145756_create_hajj_umra_payments_table.php` + `2026_05_06_080000_setup_hajj_umra_and_visa_accounting.php`

- `hajj_umra_booking_id` FK cascade
- `account_id` FK NULL
- `transaction_id` FK NULL (قيد الإيراد)
- `payment_method` (cash/bank_transfer/cash_wallet/postal_transfer/office_safe/office_drawer/mixed)
- `amount`, `currency`, `treasury_account`, `transaction_reference`, `payment_date`, `paid_by`, `created_by`

### 3.3 `programs` (البرامج)

**Migrations:** `2026_04_27_124250` + `2026_05_06_080000` (default prices + FKs) + `2026_05_06_075703` (nullable accommodation_type) + `2026_05_07_202920` (hotel links) + `2026_06_25_160000` (nullable executing_company)

- `program_name`, `program_type` (umra/hajj), `season`
- `total_nights`, `accommodation_type/_id`
- `mecca_hotel_name/_id`, `mecca_nights`
- `medina_hotel_name/_id`, `medina_nights`
- `departure_date`, `return_date`, `airline`
- `trip_supervisor/_id`, `executing_company/_id`
- `departure_point`, `booking_status` (open/confirmed/waitlist/cancelled)
- `default_purchase_price`, `default_selling_price`, `program_price_tier`
- `is_active`, SoftDeletes

### 3.4 `umrah_transaction_passengers` (تفصيل الفئات)

**Migration:** `2026_06_03_220000_upgrade_visa_and_umrah_tables.php`

- `transaction_id` FK → hajj_umra_bookings
- `category` (adult / child_with_bed / child_no_bed / infant)
- `count` (int), `unit_price`, `subtotal` (decimal)

### 3.5 `hajj_umra_executing_companies` (الشركات المنفذة)

**Migrations:** `2026_05_06_080000` + `2026_05_07_200555_add_account_id_to_hajj_bus_partners.php`

- `name`, `license_number`, `phone`, `notes`
- **`account_id`** FK → accounts (يُنشأ تلقائياً عبر Observer/booted)
- `is_active`, SoftDeletes

### 3.6 `umrah_suppliers` (موردي العمرة)

**Migration:** `2026_06_03_220000_upgrade_visa_and_umrah_tables.php`

- `name`, `phone`
- **`account_id`** FK → accounts (يُنشأ تلقائياً عبر Observer)
- `default_cost_price`
- `is_active`, SoftDeletes

### 3.7 `trip_supervisors` (مشرفو الرحلات)

**Migration:** `2026_05_06_080000_setup_hajj_umra_and_visa_accounting.php`

- `full_name`, `phone`, `national_id`, `notes`, `is_active`, SoftDeletes

### 3.8 `accommodation_types` (أنواع التسكين)

**Migration:** `2026_05_06_080000_setup_hajj_umra_and_visa_accounting.php`

- `code` UNIQUE (single/double/triple/quad/quintuple)
- `name_ar`, `name_en`, `capacity`, `sort_order`, `is_active`

### 3.9 `hotels` (الفنادق)

**File:** `app/Models/HajjUmra/Hotel.php`

- `name`, `city`, `country`, `phone`, `email`
- `stars`, `price_per_night`
- `total_rooms`, `available_rooms`
- `account_id` FK
- `amenities` (array JSON)
- `is_active`, SoftDeletes

---

## 4. الـ Models

### 4.1 `App\Models\HajjUmraBooking` — `app/Models/HajjUmraBooking.php`

- **fillable:** كل الأعمدة + transaction IDs
- **casts:** purchase_price decimal:2, status → HajjUmraStatus enum
- **booted guard:** ممنوع الحذف المباشر → throw RuntimeException (استخدم cancel بدلاً منه)
- **Relationships:** customer, companion, program, supplier, passengers, employee, account, expenseTransaction, incomeTransaction, payments
- **Accessors:** `total_selling_price`, `paid_amount`, `remaining_amount`, `is_fully_paid`
- **toArray override:** يضيف `status_label`, paid_amount, remaining_amount, is_fully_paid
- **scopeByStatus(HajjUmraStatus $status)**
- **traits:** HasFactory, SoftDeletes, ClearsCache

### 4.2 `App\Models\HajjUmraPayment`

نموذج بسيط — علاقات: booking(), account(), transaction(), createdBy()

### 4.3 `App\Models\Program`

- **booted sync:** مزامنة تلقائية بين الحقول النصية والـ FKs (accommodation_type ↔ accommodation_type_id, executing_company ↔ executing_company_id, trip_supervisor ↔ trip_supervisor_id) — مع firstOrCreate للمرجعيات الجديدة
- **Relationships:** bookings, meccaHotel, medinaHotel, executingCompany, tripSupervisor, accommodationTypeRow
- **scopes:** active()

### 4.4 Models تحت `App\Models\HajjUmra\`

| Model | الدور |
|---|---|
| `HajjUmraExecutingCompany` | الشركات المنفذة. **booted ينشئ GL account تلقائياً** |
| `UmrahSupplier` | موردي العمرة. Observer ينشئ GL account |
| `Hotel` | الفنادق |
| `TripSupervisor` | مشرفو الرحلات |
| `AccommodationType` | أنواع التسكين |
| `VisaAgent`, `VisaDuration` | مرجعيات التأشيرات (مشتركة) |

### 4.5 `UmrahTransactionPassenger`

تفصيل الفئات داخل الحجز — علاقة belongsTo HajjUmraBooking عبر transaction_id.

---

## 5. الـ Service الرئيسي — `HajjUmraBookingService`

**الموقع:** `app/Services/HajjUmra/HajjUmraBookingService.php` (~520 سطر)

```php
class HajjUmraBookingService
{
    public function __construct(protected TransactionService $transactions) {}
}
```

### 5.1 Methods

| Method | الدور |
|---|---|
| `paginate($filters)` | List مع eager-loading + filters (status/program/customer/dates/search/program_type) |
| `applyFilters()` | تطبيق الـ filters |
| `find($id)` | عرض كامل مع كل العلاقات |
| **`create($data)`** ⭐ | إنشاء الحجز مع القيود المحاسبية الكاملة |
| `repostExpenseTransaction()` | void + repost للقيد عند تعديل السعر/المورد |
| `repostIncomeTransaction()` | نفس الشيء لقيد الإيراد |
| **`update($booking, $data)`** ⭐ | تعديل + repost القيود |
| **`cancel($booking, $reason)`** ⭐ | إلغاء + عكس كل القيود |
| **`addPayment($booking, $data)`** ⭐ | تسجيل دفعة (قيد إيراد) |
| `resolveCustomer()` | إيجاد/إنشاء العميل (عبر الهاتف) |
| `ensureCustomerAccount()` | إنشاء GL account للعميل (محمي بـ LedgerBalanceMutationGuard) |

### 5.2 `create()` — تسلسل العمليات

```php
DB::transaction(function () use ($data) {
    // 1) العميل (موجود أو جديد)
    $customer = $this->resolveCustomer(...);
    $program = Program::findOrFail($data['program_id']);

    // 2) حساب الأسعار والربح
    $totalPurchase = purchase + companion_purchase;
    $totalSelling = selling + companion_selling + accommodation_extra;
    $profit = $totalSelling - $totalPurchase;

    // 3) الخزينة: من الطلب أو الخزينة الرسمية للموديول
    $accountId = $data['account_id'] ?? Account::getModuleVault('hajj_umra')->id;

    // 4) إنشاء الحجز
    $booking = HajjUmraBooking::create([...]);

    // 5) ضمان وجود حساب GL للعميل
    $customerAccount = $this->ensureCustomerAccount($customer->id);

    // 6) تفصيل الفئات (بالغ/طفل/رضيع)
    if (!empty($data['passengers'])) { ... }

    // 7) تحديد expense_account_id (المورد أو الشركة المنفذة)
    if ($supplierId) $expenseAccountId = $supplier->account_id;
    elseif ($program->executing_company_id) {
        // إنشاء حساب GL للشركة لو جديد
        if (! $company->account_id) { /* Account::create */ }
        $expenseAccountId = $company->account_id;
    } else {
        $expenseAccountId = $accountId;
    }

    // 8) قيد التكلفة
    $expense = $this->transactions->recordExpense([
        'amount' => $totalPurchase,
        'from_account_id' => $expenseAccountId,
        'module' => 'hajj_umra',
        'related_type' => HajjUmraBooking::class,
        'related_id' => $booking->id,
        'notes' => "تكلفة برنامج {$program->program_name} - {$customer->full_name}",
    ]);

    // 9) قيد الإيراد
    $income = $this->transactions->recordIncome([
        'amount' => $totalSelling,
        'to_account_id' => $customerAccount->id,
        'module' => 'hajj_umra',
        'related_type' => HajjUmraBooking::class,
        'related_id' => $booking->id,
        'notes' => "بيع برنامج {$program->program_name} - {$customer->full_name}",
    ]);

    // 10) ربط القيود بالـ booking
    $booking->update(['expense_transaction_id' => $expense->id, 'income_transaction_id' => $income->id]);

    // 11) دفعة أولية (اختيارية)
    if (!empty($data['initial_payment'])) $this->addPayment($booking, $data['initial_payment']);
});
```

### 5.3 `addPayment()`

```php
DB::transaction(function () use ($booking, $data) {
    $customerAccount = $this->ensureCustomerAccount($booking->customer_id);

    // قيد إيراد (to = الخزينة، contra = العميل)
    $income = $this->transactions->recordIncome([
        'amount' => $amount,
        'to_account_id' => $accountId,
        'contra_account_id' => $customerAccount->id,
        'module' => 'hajj_umra',
        'related_type' => HajjUmraBooking::class,
        'related_id' => $booking->id,
        'notes' => "دفعة على حجز #{$booking->id}",
    ]);

    // إنشاء سجل الدفعة
    return $booking->payments()->create([
        'payment_method' => $data['payment_method'] ?? 'cash',
        'amount' => $amount,
        'currency' => $data['currency'] ?? 'EGP',
        'treasury_account' => $data['treasury_account'] ?? 'office_drawer',
        'account_id' => $accountId,
        'transaction_id' => $income->id,
        'transaction_reference' => $data['reference'] ?? null,
        'payment_date' => $data['payment_date'] ?? now(),
        'paid_by' => $data['paid_by'] ?? $booking->customer?->full_name ?? '',
    ]);
});
```

### 5.4 `cancel()` — عكس كل القيود

```php
DB::transaction(function () use ($booking, $reason) {
    if ($booking->status === 'cancelled') throw new \RuntimeException('الحجز ملغى مسبقاً.');

    $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

    // 1) عكس كل دفعات العميل
    foreach ($booking->payments as $payment) {
        if ($payment->transaction) {
            $this->transactions->voidTransactionJournal($payment->transaction);
            $payment->transaction->delete();
        }
    }

    // 2) عكس قيد الإيراد
    if ($booking->incomeTransaction) { void + delete }

    // 3) عكس قيد التكلفة
    if ($booking->expenseTransaction) { void + delete }

    // 4) تحديث الحالة + reason
    $booking->update([
        'status' => 'cancelled',
        'notes' => $note.'سبب الإلغاء: '.$reason,
        'expense_transaction_id' => null,
        'income_transaction_id' => null,
    ]);
});
```

### 5.5 `update()` — void + repost

- لو تغير purchase/supplier → `repostExpenseTransaction` (void + delete + recordExpense جديد)
- لو تغير selling → `repostIncomeTransaction` (void + delete + recordIncome جديد)
- **آمن مع `LedgerBalanceMutationGuard`** (الدرع الواقي ضد تعديل الأرصدة المباشر)

---

## 6. الـ Accounting Flows — دورة الحياة المالية

### 6.1 المبادئ

- **Double-Entry Bookkeeping** عبر `TransactionService` المركزي
- **ممنوع تعديل الأرصدة مباشرة** — كل تغيير لازم يمر بـ:
  - `recordExpense()` (تكلفة)
  - `recordIncome()` (إيراد)
  - `recordJournalTransfer()` (تحويل)
  - `voidTransactionJournal()` (عكس قيد)
- كل قيد = `Transaction` header + 2 `AccountEntry` rows متوازنة
- كل transaction بياخد `module = 'hajj_umra'` + `related_type = HajjUmraBooking::class` + `related_id`

### 6.2 الـ Cycle الكامل

```
📅 إنشاء الحجز:
  ① HajjUmraBooking::create (status=confirmed, profit محسوب)
  ② ensureCustomerAccount (لو العميل جديد → Account::create module_type=hajj_umra)
  ③ إنشاء/جلب حساب GL للشركة المنفذة (عبر Observer)
  ④ recordExpense: from=supplier/company_account, debit=cost, to=clearing_expense, credit=cost
  ⑤ recordIncome:  from=customer_account, debit=selling, to=clearing_income, credit=selling
  ⑥ ربط expense_transaction_id + income_transaction_id
  ⑦ addPayment (اختياري): recordIncome to=treasury, contra=customer_account

💰 تسجيل دفعة:
  recordIncome(to=treasury, contra=customer_account, amount=paid)
  HajjUmraPayment::create(transaction_id=income.id)

✏️ تعديل الأسعار:
  repostExpenseTransaction: void + delete old → recordExpense new
  repostIncomeTransaction: void + delete old → recordIncome new

❌ إلغاء الحجز:
  void + delete كل payments.transactions
  void + delete incomeTransaction + expenseTransaction
  status = cancelled + transaction_ids = null
  ⚠️ لا حذف فيزيائي (SoftDeletes + booted guard)

🏦 سلف الشركات:
  withdraw: recordJournalTransfer(company_account → treasury)
  repay:   recordJournalTransfer(treasury → company_account)
```

### 6.3 الـ Clearing Accounts

من `config/accounting.php`:

```php
'clearing' => [
    'income' => [
        'hajj_umra' => env('ACCOUNTING_INCOME_CLEARING_HAJJ_NAME', 'إقفال إيرادات الحج والعمرة'),
    ],
    'expense' => [
        'hajj_umra' => env('ACCOUNTING_EXPENSE_CLEARING_HAJJ_NAME', 'إقفال تكاليف الحج والعمرة'),
    ],
],
```

### 6.4 الخزينة (Treasury) — `HajjUmraTreasuryController`

- **overview:** accounts (cashbox/wallet/bank/treasury + module_type=hajj_umra) + executing_companies + recent 40 transactions
- **accountHajjUmraTransactions:** paginated transactions لحساب معين

### 6.5 الـ Dashboard — `HajjUmraDashboardController`

```php
return [
    'stats' => [
        'monthly_revenue' => sum selling للشهر,
        'total_bookings' => count(),
        'cashboxes' / 'banks' / 'wallets' => [count, balance],
    ],
    'recent_bookings' => آخر 10,
    'liquidity' => ['total' => cashboxes+banks+wallets],
];
```

### 6.6 مديونيات العملاء — `customerBalances()`

- مجمّع حسب العميل: `total_sales − total_paid = total_debt`
- يضيف سندات القبض العامة من AccountEntries غير المرتبطة بحجز
- filters: search, dates, status (debtors/creditors)

### 6.7 كشف حساب العميل — `customerStatement()`

- **الفواتير:** كل الحجوزات (غير الملغاة) — debit
- **دفعات الحجوزات:** payments — credit
- **سندات القبض/الصرف العامة:** AccountEntries على حساب العميل — مرتبة زمنياً مع رصيد تراكمي

---

## 7. الـ API Routes — تحت `prefix('hajj-umra')` في `routes/api.php:429`

| Method | Route | Controller |
|---|---|---|
| GET | `/dashboard` | `HajjUmraDashboardController@index` |
| GET | `/treasury/overview` | `HajjUmraTreasuryController@overview` |
| GET | `/treasury/accounts/{account}/transactions` | `HajjUmraTreasuryController@accountHajjUmraTransactions` |
| GET | `/executing-companies/dues` | `HajjUmraExecutingCompanyFinanceController@dues` |
| POST | `/executing-companies/{company}/withdraw` | نفس |
| POST | `/executing-companies/{company}/repay` | نفس |
| GET/POST | `/programs` | `HajjUmraProgramController@index/store` |
| GET | `/programs/{program}` | `HajjUmraProgramController@show` |
| PUT/PATCH | `/programs/{program}` | `HajjUmraProgramController@update` |
| GET | `/settings/programs` | `HajjUmraReferenceController@programs` |
| GET | `/settings/executing-companies` | نفس |
| GET | `/settings/trip-supervisors` | نفس |
| GET | `/settings/accommodation-types` | نفس |
| GET | `/settings/statuses` | نفس |
| GET | `/customer-balances` | `HajjUmraController@customerBalances` |
| GET | `/customer-statement` | `HajjUmraController@customerStatement` |
| GET/POST | `/bookings` | `HajjUmraController@index/store` |
| GET | `/bookings/{hajjUmra}` | `HajjUmraController@show` |
| PUT/PATCH | `/bookings/{hajjUmra}` | `HajjUmraController@update` |
| DELETE | `/bookings/{hajjUmra}` | `HajjUmraController@destroy` (cancel) |
| POST | `/bookings/{hajjUmra}/payments` | `HajjUmraController@addPayment` |
| GET/POST | `/api/v1/umrah-suppliers` و `/api/umrah-suppliers` | `UmrahSupplierApiController` |

---

## 8. الـ Filament Admin Resources

### 8.1 `HajjUmraBookingResource`

**Form Schema (7 Sections):**
1. العميل والبرنامج (customer, companion, program مع auto-fill للأسعار)
2. المرافق والمورّد (companion prices, supplier, accommodation_choice, accommodation_extra_charge)
3. التسعير (purchase_price, selling_price, per_person)
4. شبكة تسعير الأسرة (passenger grid: adult/child_with_bed/child_no_bed/infant)
5. المحاسبة والدفع (account_id filtered via HajjUmraLiquidityAccount, status, employee, agent_name)
6. الدفعة الأولية (toggle + amount + method + account + date + reference + paid_by)
7. ملاحظات وأمتعة (notes, baggage)

**Table Columns:** ID, customer, phone, program, type badge (حج/عمرة), prices, profit, status badge, account, created_at

**Filters:** status, program_id, TrashedFilter

**Actions:** View, Edit, **تسجيل دفعة** (custom action)

**Pages:** List, Create, Edit, View

**Widget:** `HajjUmraStats` (إجمالي الحجوزات + الربح + المحصل)

### 8.2 `ProgramResource`

- Form: كل حقول البرنامج + اختيار المرجعيات (FKs)
- **Widget:** `ProgramProfitability` (revenue − costs − extra expenses = profit + margin)

### 8.3 Resources أخرى

| Resource | الدور |
|---|---|
| `HajjUmraExecutingCompanyResource` | إدارة الشركات المنفذة + رصيد الحساب + statement |
| `AccommodationTypeResource` | إدارة أنواع التسكين |
| `TripSupervisorResource` | إدارة مشرفي الرحلات |
| `HajjUmraTreasuryResource` | خزائن الموديول (cashbox) |
| `HajjUmraBankAccountResource` | بنوك الموديول |
| `HajjUmraWalletResource` | محافظ الموديول |

### 8.4 Custom Page: `HajjUmraExecutingCompanyAdvances`

جدول مستحقات الشركات + أزرار سحب/سداد + AccountEntries مفلتر على `TransactionModule::HajjUmra`.

---

## 9. الـ Observers

### 9.1 `HajjUmraExecutingCompanyObserver`

```php
public function saving(HajjUmraExecutingCompany $company): void {
    if ($company->account_id !== null) return;

    $account = Account::create([
        'name' => 'حساب الشركة المنفذة للحج/العمرة: '.($company->name ?: 'غير مسمى'),
        'type' => AccountType::Supplier,
        'currency' => 'EGP',
        'balance' => 0.00,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'hajj_umra',
        'created_by' => Auth::id() ?? 1,
    ]);
    $company->account_id = $account->id;
}
```

**مسجل في** `app/Providers/AppServiceProvider.php`

### 9.2 `UmrahSupplierObserver`

نفس النمط — ينشئ "حساب مورد العمرة: {name}".

### 9.3 `HajjUmraExecutingCompany::booted()`

- لو مفيش account_id → ينشئ
- لو تم تعديل الاسم → يحدث اسم الحساب تلقائياً

---

## 10. الـ Validation Rules

### 10.1 `HajjUmraLiquidityAccount` — `app/Rules/HajjUmraLiquidityAccount.php`

**Validation steps:**
1. لازم يكون تابع لموديول الحج والعمرة (يقبل hajj_umra/hajj/umrah)
2. لازم يكون من نوع سيولة (cashbox/wallet/bank/treasury/post)
3. لازم يكون مفعّل

**Methods:**
- `validate($attribute, $value, $fail)` — تطبيق الـ rule
- `applyLiquidityScope(Builder $query)` — فلتر للـ Filament Select
- `belongsToHajjUmraModule(Account $account): bool` — فحص الانتماء

### 10.2 `StoreHajjUmraBookingRequest`

- customer (or new customer data), companion, program_id (required)
- prices (required numeric), currency, per_person
- status (in HajjUmraStatus dropdown), agent_name, notes
- **`account_id` (required + HajjUmraLiquidityAccount rule)**
- employee_id
- initial_payment (nested array): amount, payment_method, account_id (HajjUmraLiquidityAccount), payment_date, reference, paid_by
- supplier_id, companion_purchase_price, companion_selling_price
- accommodation_choice, accommodation_extra_charge
- passengers grid: array of {category (adult/child_with_bed/child_no_bed/infant), count, unit_price, subtotal}

---

## 11. الـ Enums

| Enum | File | القيم |
|---|---|---|
| `HajjUmraStatus` | `app/Enums/HajjUmraStatus.php` | pending/confirmed/in_progress/completed/cancelled/refunded + label() + color() + forDropdown() |
| `HajjUmraPaymentMethod` | `app/Enums/HajjUmraPaymentMethod.php` | cash/bank_transfer/cash_wallet/postal_transfer/office_safe/office_drawer/mixed |
| `TransactionModule` | `app/Enums/TransactionModule.php` | يحتوي `HajjUmra = 'hajj_umra'` + Arabic label `الحج والعمرة` |

---

## 12. الـ Frontend (Vue.js + Pinia)

### 12.1 Pinia Store: `resources/js/stores/hajjUmraStore.js`

- Calls: bookings, settings (programs/companies/supervisors/accommodation), accounts (filtered to hajj_umra), executing company finance, umrah suppliers.

### 12.2 Views تحت `resources/js/views/hajjUmra/`

| View | الدور |
|---|---|
| `HajjUmraDashboard.vue` | لوحة تحكم KPIs |
| `HajjUmraIndex.vue` | قائمة الحجوزات |
| `HajjUmraCreate.vue` | إنشاء حجز (form كبير) |
| `HajjUmraShow.vue` | تفاصيل الحجز |
| `HajjUmraEdit.vue` | تعديل الحجز |
| `HajjUmraCustomerBalances.vue` | مديونيات العملاء |
| `HajjUmraTreasury.vue` | خزينة الموديول |
| `HajjUmraExecutingCompaniesDue.vue` | مستحقات الشركات المنفذة |
| `Programs/ProgramIndex.vue` | قائمة البرامج |
| `Programs/ProgramCreate.vue` | إنشاء برنامج |
| `Programs/ProgramEdit.vue` | تعديل برنامج |

### 12.3 Routes (Frontend)

تحت `/hajj-umra` في `resources/js/router/index.js`.

---

## 13. الـ Config & Integration

### 13.1 `config/accounting.php`

- `clearing.income.hajj_umra` → "إقفال إيرادات الحج والعمرة"
- `clearing.expense.hajj_umra` → "إقفال تكاليف الحج والعمرة"

### 13.2 `AccountModuleDivision`

```php
const TOURISM = ['tourism', 'flights', 'hajj_umra', 'visas'];
const LEGACY_MODULE_TO_TYPE = ['hajj' => 'hajj_umra', 'umrah' => 'hajj_umra', ...];
moduleLabel('hajj_umra') => 'الحج والعمرة';
```

### 13.3 `UserPermissions`

- `MANAGE_HAJJ` permission → label: `موديول الحج والعمرة`

### 13.4 تكامل مع باقي النظام

| Component | التكامل |
|---|---|
| `Account` | كل حساب مرتبط بـ `module_type = hajj_umra` |
| `Customer` | له `account_id` تلقائي + `hajjUmraBookings(): HasMany` |
| `TransactionService` | العصب المحاسبي (recordExpense/Income/JournalTransfer/void) |
| `LedgerClearingAccounts` | يحل income/expense contra IDs |
| `LedgerEntryDescriptionResolver` | `forHajjUmraBooking()` + `hajjBookingDetails()` |
| `TreasuryService` | يحسب hajj_umra_profits + hajj_umra_balances |
| `ProfitLossReportService` | الحج/العمرة ضمن TOURISM_MODULES |
| `FinancialReportService` | تقارير الحج/العمرة |
| `DashboardService` | KPIs موحدة |
| `InvoiceController` / `InvoiceType` | `HajjUmrah = 'hajj_umrah'` value |

---

## 14. الأمان والحماية

### 14.1 ممنوع الحذف المباشر للحجز

```php
static::deleting(function (HajjUmraBooking $booking) {
    throw new \RuntimeException(
        'لا يمكن حذف حجز الحج والعمرة برمجياً لتجنب إفساد السجلات المالية والتسكين. '
      . 'يرجى إلغاء الحجز (Cancel) بدلاً من حذفه.'
    );
});
```

### 14.2 `LedgerBalanceMutationGuard`

- كل تعديل على `Account.balance` لازم يمر عبر `TransactionService`
- `HajjUmraBookingService::ensureCustomerAccount` يستخدم `LedgerBalanceMutationGuard::run()`

### 14.3 `HajjUmraLiquidityAccount` Rule

- يخلي `account_id` يكون حساب سيولة تابع للموديول فقط

### 14.4 Audit Metadata

- كل transaction بيمر عبر `TransactionAuditStamper` (posting_channel, correlation_id, http_method, request_route, client_ip, user_agent)

---

## 15. الـ Tests

| Test | الملف |
|---|---|
| `HajjUmraApiTest` | `tests/Feature/HajjUmra/HajjUmraApiTest.php` — dashboard, treasury, validation, cancellation, debt payment, program CRUD, reposting |
| `VisaUmrahImprovementsTest` | `tests/Feature/VisaUmrahImprovementsTest.php` — Umrah supplier + Hajj/Umra booking with supplier/companion/accommodation/passenger grid/balances/statement |
| `BusinessActionsTest` | `tests/Feature/BusinessActionsTest.php` |
| `FilamentLiquidityVueApiTest` | `tests/Feature/FilamentLiquidityVueApiTest.php` |
| `TourismTrialBalanceIntegrityTest` | `tests/Feature/Finance/TourismTrialBalanceIntegrityTest.php` |
| `TreasuryOverviewIntegrityTest` | `tests/Feature/Finance/TreasuryOverviewIntegrityTest.php` |
| `TrialBalanceTest` | `tests/Feature/Finance/TrialBalanceTest.php` |
| `FinanceAccountsModuleFilterTest` | `tests/Feature/FinanceAccountsModuleFilterTest.php` |

---

## 16. ملخص الـ Operational Cycles

### Cycle 1: إنشاء برنامج جديد
```
Filament → ProgramResource::create
   ↓
Program::create
   ↓ (booted sync)
   ├─ accommodation_type_id → accommodation_type
   ├─ executing_company_id ↔ executing_company
   └─ trip_supervisor_id ↔ trip_supervisor
```

### Cycle 2: إنشاء حجز (الأساسية)
```
Filament/Vue → HajjUmraController::store
   ↓ (StoreHajjUmraBookingRequest)
HajjUmraBookingService::create
   ↓
   ├─ resolveCustomer
   ├─ Program::find
   ├─ حساب totalPurchase / totalSelling / profit
   ├─ HajjUmraBooking::create
   ├─ ensureCustomerAccount
   ├─ حفظ passengers
   ├─ تحديد expense_account_id
   ├─ recordExpense (تكلفة)
   ├─ recordIncome (إيراد)
   ├─ حفظ transaction IDs
   └─ addPayment (اختياري)
```

### Cycle 3: تسجيل دفعة
```
addPayment → HajjUmraBookingService
   ↓
   ├─ recordIncome (to=treasury, contra=customer)
   └─ HajjUmraPayment::create
```

### Cycle 4: تعديل حجز
```
update → repostExpense + repostIncome
   ├─ void old transactions
   ├─ delete old transactions
   └─ record new transactions بالقيم الجديدة
```

### Cycle 5: إلغاء حجز
```
cancel → void + delete كل القيود
   ├─ payments.transactions
   ├─ incomeTransaction
   └─ expenseTransaction
   ↓
   status = cancelled + transaction_ids = null
```

### Cycle 6: سلفة الشركة المنفذة
```
withdraw/repay → recordJournalTransfer
```

### Cycle 7: إدارة مورد عمرة
```
UmrahSupplier::create → UmrahSupplierObserver ينشئ GL account
```

### Cycle 8: إدارة شركة منفذة
```
HajjUmraExecutingCompany::create → Observer/booted ينشئ GL account
```

---

## 17. الـ Permissions

| Permission | Label | الدور |
|---|---|---|
| `MANAGE_HAJJ` | `موديول الحج والعمرة` | في default employee modules |

كل Filament resources محمية بـ Filament's default auth + permission checks.

---

## 18. ملاحظات ومخاطر

1. **`DatabaseSeeder::class` يستدعي `HajjUmraSettingsSeeder::class`** لكن الـ seeder غير موجود — stale reference
2. **SoftDeletes مفعّل** على bookings, programs, companies, supervisors, hotels, suppliers
3. **تعدد العملات:** `currency` موجود لكن `TransactionService` يتعامل مع EGP افتراضياً. `CurrencyService` يتدخل للتحويل
4. **`baggage` field** موجود لكن مش مرتبط بـ business logic — للعرض فقط
5. **`Hotel::amenities`** كـ `array` JSON

---

## 19. الـ Documentation الموجودة

- `docs/ARCHITECTURE.md` — قسم **3.3 Hajj/Umra Module** (L715-734)
- لا يوجد ملف docs مخصص للحج والعمرة فقط قبل هذا التقرير

---

## 20. خريطة الملفات الكاملة (File Map)

### Models (5 ملفات في app/Models + 5 ملفات في app/Models/HajjUmra/)
- `app/Models/HajjUmraBooking.php`
- `app/Models/HajjUmraPayment.php`
- `app/Models/Program.php`
- `app/Models/UmrahTransactionPassenger.php`
- `app/Models/HajjUmra/AccommodationType.php`
- `app/Models/HajjUmra/HajjUmraExecutingCompany.php`
- `app/Models/HajjUmra/Hotel.php`
- `app/Models/HajjUmra/TripSupervisor.php`
- `app/Models/HajjUmra/UmrahSupplier.php`
- `app/Models/HajjUmra/VisaAgent.php` (مشترك)
- `app/Models/HajjUmra/VisaDuration.php` (مشترك)

### Services (1 ملف)
- `app/Services/HajjUmra/HajjUmraBookingService.php` ⭐

### Controllers (8 ملفات)
- `app/Http/Controllers/Api/V1/HajjUmraController.php`
- `app/Http/Controllers/Api/V1/HajjUmraReferenceController.php`
- `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraDashboardController.php`
- `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraExecutingCompanyFinanceController.php`
- `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraProgramController.php`
- `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraTreasuryController.php`
- `app/Http/Controllers/Api/V1/HajjUmra/UmrahSupplierApiController.php`

### Form Requests (5 ملفات)
- `app/Http/Requests/HajjUmra/StoreHajjUmraBookingRequest.php`
- `app/Http/Requests/HajjUmra/UpdateHajjUmraBookingRequest.php`
- `app/Http/Requests/HajjUmra/StoreHajjUmraPaymentRequest.php`
- `app/Http/Requests/HajjUmra/StoreProgramRequest.php`
- `app/Http/Requests/HajjUmra/UpdateProgramRequest.php`

### Resources (1 ملف)
- `app/Http/Resources/HajjUmra/HajjUmraBookingResource.php`

### Enums (3 ملفات)
- `app/Enums/HajjUmraStatus.php`
- `app/Enums/HajjUmraPaymentMethod.php`
- `app/Enums/TransactionModule.php` (يحوي HajjUmra)

### Observers (2 ملفات)
- `app/Observers/HajjUmraExecutingCompanyObserver.php`
- `app/Observers/UmrahSupplierObserver.php`

### Rules (1 ملف)
- `app/Rules/HajjUmraLiquidityAccount.php`

### Migrations (~10 ملفات Hajj/Umra + indexes + accounting setup)

### Filament Admin (8 resources + pages + widgets)
- `HajjUmraBookings/` (Resource + 4 Pages + Widget)
- `Programs/` (Resource + 4 Pages + Widget)
- `HajjUmraExecutingCompanies/`
- `AccommodationTypes/`
- `TripSupervisors/`
- `HajjUmraTreasuries/`
- `HajjUmraBankAccounts/`
- `HajjUmraWallets/`
- Custom Page: `HajjUmraExecutingCompanyAdvances.php`
- Concerns/Support: `BelongsToHajjUmraModuleNavigation.php`, `HajjUmraModuleNavigation.php`

### Routes
- `routes/api.php` — تحت `prefix('hajj-umra')` و `/umrah-suppliers`

### Config
- `config/accounting.php` — clearing accounts
- `config/filament.php` — register resources

### Support Classes
- `app/Support/Finance/AccountModuleDivision.php` — TOURISM division + legacy module mapping
- `app/Support/Finance/UnifiedLiquidityGrouper.php` — liquidity grouping

### Frontend (Vue.js + Pinia)
- `resources/js/stores/hajjUmraStore.js`
- `resources/js/views/hajjUmra/HajjUmraDashboard.vue`
- `resources/js/views/hajjUmra/HajjUmraIndex.vue`
- `resources/js/views/hajjUmra/HajjUmraCreate.vue`
- `resources/js/views/hajjUmra/HajjUmraShow.vue`
- `resources/js/views/hajjUmra/HajjUmraEdit.vue`
- `resources/js/views/hajjUmra/HajjUmraCustomerBalances.vue`
- `resources/js/views/hajjUmra/HajjUmraTreasury.vue`
- `resources/js/views/hajjUmra/HajjUmraExecutingCompaniesDue.vue`
- `resources/js/views/hajjUmra/Programs/ProgramIndex.vue`
- `resources/js/views/hajjUmra/Programs/ProgramCreate.vue`
- `resources/js/views/hajjUmra/Programs/ProgramEdit.vue`
- `resources/js/router/index.js` — `/hajj-umra` routes

### Shared/Adjacent Files (مرتبطة بالموديول)
- `app/Services/CustomerService.php` — module detection
- `app/Services/DashboardService.php` — Hajj/Umra stats
- `app/Services/Finance/AccountService.php` — eager-load HajjUmraBooking
- `app/Services/Finance/LedgerClearingAccounts.php` — HajjUmra clearing
- `app/Services/Finance/LedgerEntryDescriptionResolver.php` — `forHajjUmraBooking()` + `hajjBookingDetails()`
- `app/Services/Finance/TreasuryService.php` — hajj_umra_profits + hajj_umra_balances
- `app/Services/Finance/TrialBalanceExportService.php` — Hajj/Umra balances
- `app/Services/Reports/FinancialReportService.php` — Hajj/Umra customers/companies/hotels/suppliers
- `app/Services/Reports/ProfitLossReportService.php` — TOURISM_MODULES = ['flight', 'hajj_umra', 'visa', 'tourism']
- `app/Services/Reports/ReportFinanceService.php` — Hajj/Umra descriptions
- `app/Services/Setting/PrintSettingService.php` — hajj_umra label
- `app/Services/Visa/VisaBookingService.php` — يستخدم `App\Models\HajjUmra\VisaAgent`
- `app/Http/Controllers/Api/V1/CustomerController.php` — `payDebt()` accepts hajj_umra
- `app/Http/Controllers/Api/V1/Employee/EmployeeDashboardController.php` — monthly booking count
- `app/Http/Controllers/Api/V1/InvoiceController.php` — invoice type `hajj_umrah`
- `app/Http/Controllers/Api/V1/SettingController.php` — returns hajj_umra label
- `app/Http/Requests/Finance/StoreAccountRequest.php` + `UpdateAccountRequest.php` — allows `module_type = hajj_umra`
- `app/Http/Resources/CustomerResource.php` — active module badge hajj/حج وعمرة
- `app/Http/Resources/Finance/AccountEntryResource.php` — formats as process type حج وعمرة
- `app/Filament/Admin/Resources/Accounts/AccountFormSchema.php` — hajj_umra option
- `app/Filament/Admin/Support/HajjUmraModuleNavigation.php` — parent label constant
- `app/Filament/Admin/Concerns/BelongsToHajjUmraModuleNavigation.php` — navigation trait
- `app/Support/UserPermissions.php` — `MANAGE_HAJJ` permission
- `app/Console/Commands/ConsolidateDuplicateBookings.php` — `consolidateHajjUmrah()`

### Database Scripts
- `database/scripts/audit_report.sql` — Hajj/Umra + Visa tables audit
- `database/scripts/detect_duplicates.sql` — legacy `hajj_umrah` vs new `hajj_umra_bookings`

### Tests
- `tests/Feature/HajjUmra/HajjUmraApiTest.php` ⭐
- `tests/Feature/VisaUmrahImprovementsTest.php`
- `tests/Feature/BusinessActionsTest.php`
- `tests/Feature/FilamentLiquidityVueApiTest.php`
- `tests/Feature/Finance/TourismTrialBalanceIntegrityTest.php`
- `tests/Feature/Finance/TreasuryOverviewIntegrityTest.php`
- `tests/Feature/Finance/TreasuryOverviewTest.php`
- `tests/Feature/Finance/TrialBalanceTest.php`
- `tests/Feature/FinanceAccountsModuleFilterTest.php`
- `tests/Feature/FinancialReportTest.php`
- `tests/Feature/UnifiedDashboardTest.php`
- `tests/Unit/Finance/UnifiedLiquidityGrouperTest.php`

---

## 21. الـ Summary النهائي

موديول الحج والعمرة **شغال end-to-end**:

1. ✅ **Backend:** Models + Service + Controllers + FormRequests + Resources + Rules + Observers + Enums
2. ✅ **Database:** 9 جداول مع indexes أداء + FKs محاسبية + SoftDeletes
3. ✅ **Filament Admin:** 8 Resources + Custom Page + Widgets (CRUD كامل)
4. ✅ **Vue.js Frontend:** 11 View + Pinia Store + Router (Dashboard, Index, Create, Show, Edit, Customer Balances, Treasury, Executing Companies, Programs CRUD)
5. ✅ **Double-Entry Accounting:** كل عملية حجز بتنشئ Expense + Income تلقائياً مع ربط transaction IDs
6. ✅ **Payment Tracking:** دفعة أولية + دفعات لاحقة مع كشوف حسابات كاملة
7. ✅ **Executing Company Finance:** مستحقات + سحب/سسد + AccountEntries مفلتر على الموديول
8. ✅ **Cancellation Flow:** عكس كل القيود (void + delete) + SoftDeletes + لا حذف فيزيائي
9. ✅ **Validation:** HajjUmraLiquidityAccount rule يضمن استخدام حسابات سيولة من الموديول فقط
10. ✅ **Security:** LedgerBalanceMutationGuard + booted delete guard + audit metadata
11. ✅ **Reports:** P&L + Treasury + Customer balances + Trial balance كلها بتدمج الموديول
12. ✅ **Tests:** feature tests شاملة + integrity tests + validation tests

**🎯 إيه اللي بيحصل عملياً:**
- بيـ create program (Filament) → بيتسجل في `programs` + يحفظ FKs
- بيـ create booking → بيتسجل في `hajj_umra_bookings` + بيتنشأ تلقائياً:
  - حساب GL للعميل (لو جديد)
  - حساب GL للشركة المنفذة (لو جديد)
  - قيد Expense على حساب الشركة (التكلفة)
  - قيد Income على حساب العميل (البيع)
- بيتسجل دفعة → قيد Income على الخزينة (مدين) وخصم من حساب العميل
- بيعدّل → void + repost للقيود بالقيم الجديدة
- بيُلغي → void + delete كل القيود + status = cancelled
- كشوف الحسابات بتتجمع من AccountEntries على حساب العميل
- Treasury overview بيعرض الأرصدة + آخر معاملات الموديول
- P&L بيحسب ربح الحج/العمرة من `profit` field المحفوظ في كل حجز
- كل حاجة مربوطة بـ `module = 'hajj_umra'` في `transactions` + `module_type = 'hajj_umra'` في `accounts`

---

> **🎉 كده الموديول شامل 100% — Backend + Frontend + DB + Tests + Accounting + Filament + Vue**  
> لو محتاج تفاصيل أكثر عن ملف معين أو flow محدد، اسأل وأنا أعمّق فيه.