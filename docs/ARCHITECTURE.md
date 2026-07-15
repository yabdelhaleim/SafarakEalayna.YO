# 🏗️ SafarakEalayna — Master Architecture Reference

> **الـ Bible** — مرجع شامل لكل حاجة في المشروع (كود، معمارية، حسابات)
> **آخر تحديث:** 2026-07-10
> **الـ Status:** 🟢 Live Reference

---

## 📖 جدول المحتويات (TOC)

| الجزء | الموضوع |
|---|---|
| **الجزء 1** | [Quick Start & Navigation](#الجزء-1-quick-start--navigation) |
| **الجزء 2** | [Core Architecture (المحاسبة / دفتر الأستاذ)](#الجزء-2-core-architecture-المحاسبة--دفتر-الأستاذ) |
| **الجزء 3** | [Module Map (كل module فين)](#الجزء-3-module-map-كل-module-فين) |
| **الجزء 4** | [Layered Architecture (طبقات النظام)](#الجزء-4-layered-architecture-طبقات-النظام) |
| **الجزء 5** | [Critical Files Index (فهرس الملفات الحرجة)](#الجزء-5-critical-files-index-فهرس-الملفات-الحرجة) |
| **الجزء 6** | [🗺️ Impact Maps (خرائط العمليات)](#الجزء-6--impact-maps-خرائط-العمليات) — **الأهم** |
| **الجزء 7** | [🔧 Recipes (وصفات جاهزة)](#الجزء-7--recipes-وصفات-جاهزة) |
| **الجزء 8** | [⚠️ Known Issues & Status](#الجزء-8--known-issues--status) |
| **الجزء 9** | [🔍 Search Index (فهرس البحث)](#الجزء-9--search-index-فهرس-البحث) |

---

# الجزء 1: Quick Start & Navigation

## 1.1 إيه هو سفارك إليّنا؟

**سفارك إليّنا** (Safa Rak Ealayna) — نظام إدارة وكالة سفر متكاملة بيجمع بين:
- **منصة حجز** لوحدات متعددة: طيران، باص، حج/عمرة، تأشيرات، خدمات إلكترونية، فوري
- **محاسبة double-entry (دفتر الأستاذ)** في القلب — كل عملية حجز بتنشئ قيود متوازنة تلقائياً
- **Filament Admin Panel** للإدارة على `/admin`
- **REST API** (Sanctum-authenticated) للـ Vue.js SPA على `/api/v1/*`
- **Multi-currency** (EGP أساسي + USD/SAR/KWD)

## 1.2 الـ Tech Stack

| الـ Component | الإصدار | ملاحظات |
|---|---|---|
| **PHP** | `^8.3` | ⚠️ في PHP 8.3.31 الـ `(string)$enum` مش شغّال — استخدم `->value` |
| **Laravel** | `^13.0` | الـ base framework |
| **Filament** | `^5.6` | الـ admin panel (بهوية وبـ navigation مخصصة) |
| **Laravel Sanctum** | `^4.0` | API token authentication |
| **PHPSpreadsheet** | `^5.8` | Excel import/export (trial balance, invoices) |
| **PHPUnit** | `^12.5.12` | الـ testing framework |
| **Laravel Dusk** | `^8.6` | Browser tests |
| **Laravel Telescope** | `^5.20` | Debug toolbar (في dev/local فقط) |
| **MySQL** | 127.0.0.1:3306 / db=`safarakealayna` | قاعدة البيانات الرئيسية |
| **SQLite in-memory** | — | Cache للـ tests فقط |
| **Multi-process dev** | `composer dev` | server + 2 queue workers + Vite via `concurrently` |
| **Session driver** | `database` | — |
| **Localization** | `ar` primary, `en` fallback | `APP_LOCALE=ar` |

## 1.3 خريطة الـ Directories (الـ bird's-eye view)

```
safarakealayna/
├── app/
│   ├── Console/Commands/                  ⌨️ Artisan commands (4 ملفات)
│   ├── Enums/                             🏷️ 41 enum (AccountType, Currency, etc.)
│   ├── Exceptions/                        ⚠️ InsufficientBalanceException + others
│   ├── Filament/
│   │   ├── Admin/
│   │   │   ├── Resources/                 🎨 63 Filament Resources (مقسمة بـ modules)
│   │   │   ├── Pages/                     📄 9 custom pages (Dashboards, Statements)
│   │   │   ├── Widgets/                   📊 7 widgets
│   │   │   ├── Clusters/                  🗂️ VisaCluster, EmployeeCluster, Finance
│   │   │   ├── Concerns/                  🔧 7 traits (per-module navigation)
│   │   │   └── Support/                   🎨 6 support classes
│   │   └── ...
│   ├── Http/
│   │   ├── Controllers/                   🌐 40+ controllers (Api/V1/{Module}/)
│   │   ├── Requests/                      📋 49 form-request validators
│   │   └── Middleware/                    🔧 7 middlewares (الأمان + audit)
│   ├── Jobs/                              ⚡ Background jobs
│   ├── Listeners/                         👂 Event listeners
│   ├── Models/                            🗄️ Eloquent models
│   │   ├── (root)                         Core: User, Customer, Supplier, Account, Transaction, AccountEntry
│   │   ├── Flight/                        ✈️ 14 files
│   │   ├── Bus/                           🚌 8 files
│   │   ├── HajjUmra/                      🕋 8 files
│   │   ├── Fawry/                         💳 4 files
│   │   ├── Wallet/                        👛 3 files
│   │   ├── Employee/                      👥 6 files
│   │   └── Setting/                       ⚙️ Settings
│   ├── Notifications/                     🔔 1 (PrepaidBalanceLowNotification)
│   ├── Observers/                         👁️ Model observers
│   ├── Policies/                          🛡️ CustomerPolicy (فيها TODO)
│   ├── Providers/                         ⚙️ Service providers (AppServiceProvider, FilamentServiceProvider, ...)
│   ├── Services/                          💼 70+ service classes
│   │   ├── (root)                         Top-level (DashboardService, BusTicketService, ...)
│   │   ├── Airports/                      🛫
│   │   ├── Bus/                           🚌
│   │   ├── Employee/                      👥
│   │   ├── Fawry/                         💳
│   │   ├── Finance/                       💰 18 files (العصب المحاسبي)
│   │   ├── Flight/                        ✈️ 7 files (الأكبر والأعقد)
│   │   ├── HajjUmra/, Online/, Reports/, Setting/, System/, Treasury/, Visa/, Wallet/
│   ├── Support/Finance/                   🛡️ 6 finance support classes
│   │   └── LedgerBalanceMutationGuard.php ← الكنز 💎
│   └── Traits/                            🔁 Helper traits
├── bootstrap/                             🚀 Laravel bootstrap
├── config/                                ⚙️ app.php, database.php, filament.php, accounting.php, ...
├── database/
│   ├── migrations/                        🗄️ Schema migrations
│   ├── seeders/                           🌱 Data seeders
│   └── factories/                         🏭 Model factories (للـ tests)
├── docs/                                  📚 Documentation (هذا الملف)
├── public/                                🌐 Public assets
├── resources/
│   ├── views/                             🎨 Blade views (Filament)
│   ├── js/ (ممكن يكون في مشروع منفصل)   ⚛️ Vue.js SPA
│   └── css/
├── routes/
│   ├── api.php                            🌐 277 route definitions (v1/*)
│   ├── web.php                            🌐 Minimal web routes + Filament
│   ├── finance.php                        💰 Finance-specific routes
│   └── console.php                        ⌨️ Console routes
├── storage/                               💾 Logs, cache, uploads
├── tests/
│   ├── Feature/                           ✅ 50+ feature tests (الـ flows المهمة)
│   ├── Unit/                              🔬 Unit tests (services, models)
│   ├── Filament/                          🎨 Filament-specific tests
│   └── Browser/                           🌐 Dusk browser tests
└── (root scripts)                         ⚠️ 20+ diagnostic/phase scripts (انظر الجزء 8)
```

## 1.4 ازاي تقرأ الملف ده

| لو عايز تعمل إيه | روح لـ |
|---|---|
| 🐛 **إصلاح مشكلة موجودة** | الجزء 6 (Impact Maps) → الجزء 8 (Known Issues) |
| 🆕 **إضافة feature جديد** | الجزء 7 (Recipes) → الجزء 5 (Critical Files Index) |
| 🔍 **البحث عن ملف/class** | الجزء 9 (Search Index) — ابحث بـ message أو exception |
| 📐 **فهم الـ accounting flow** | الجزء 2 (Core Architecture) |
| 🧩 **فهم module معين** | الجزء 3 (Module Map) |
| 🛡️ **فهم الـ balance protection** | الجزء 2.4 + `BALANCE_TOUCHPOINTS_MAP.md` |
| 🆘 **حالة حادثة (incident)** | `INCIDENT_ROOT_CAUSE_REPORT.md` + `ACCOUNTING_HANDOFF_2026-07-08.md` |

> **🔗 الـ docs المكملة (موجودة في الـ root):**
> - `ACCOUNTING_HANDOFF_2026-07-08.md` — تقرير حادثة الـ desync Phases 1-3b
> - `BALANCE_TOUCHPOINTS_MAP.md` — كل الأرصدة فين + مستوى الحماية
> - `PREPAID_BALANCE_ARCHITECTURE.md` — شرح الحسابات المسبقة
> - `INCIDENT_ROOT_CAUSE_REPORT.md` — الـ root cause analysis لحادثة 2026-07-07

---

# الجزء 2: Core Architecture (المحاسبة / دفتر الأستاذ)

> **ده القلب.** كل حاجة في النظام مبنية حوالين الـ double-entry ledger. لازم تفهمه قبل أي حاجة تانية.

## 2.1 The Ledger System — الجداول الـ 3 الأساسية

النظام بيستخدم **3 جداول أساسية** للـ double-entry bookkeeping:

```
┌─────────────────────────────────────────────────────────────────┐
│                      The Double-Entry System                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   ┌──────────────┐                                               │
│   │   accounts   │  ← General Ledger (دفتر الأستاذ)             │
│   ├──────────────┤     - id, name, type, balance, currency       │
│   │ id           │     - balance محمي بـ LedgerBalanceMutationGuard│
│   │ name         │     - 10 types (cashbox, bank, customer, ...) │
│   │ type         │                                               │
│   │ balance      │ ← 🛡️ لا تتعدّل مباشرة!                      │
│   │ currency     │                                               │
│   └──────┬───────┘                                               │
│          │                                                       │
│          │ 1:N                                                   │
│          ▼                                                       │
│   ┌──────────────────┐                                           │
│   │ account_entries  │ ← سطور القيد (debit/credit)              │
│   ├──────────────────┤   - account_id, transaction_id             │
│   │ account_id       │   - debit, credit, balance_after          │
│   │ transaction_id   │   - notes (added in Phase 3b v3)           │
│   │ debit            │                                           │
│   │ credit           │                                           │
│   │ balance_after    │                                           │
│   └──────┬───────────┘                                           │
│          │                                                       │
│          │ N:1                                                   │
│          ▼                                                       │
│   ┌────────────────────┐                                         │
│   │   transactions     │ ← رأس القيد (header)                   │
│   ├────────────────────┤   - type, amount, from/to_account_id     │
│   │ id                 │   - module, related_type/id             │
│   │ type               │   - posting_channel (للـ audit)         │
│   │ amount             │   - correlation_id, http_method         │
│   │ from_account_id    │   - request_route, client_ip            │
│   │ to_account_id      │   - user_agent (HTTP-traceable)        │
│   │ module             │                                         │
│   │ created_by         │                                         │
│   │ posting_channel    │                                         │
│   └────────────────────┘                                         │
└─────────────────────────────────────────────────────────────────┘
```

### الـ 3 Models الأساسية

| الـ Model | الملف | الغرض |
|---|---|---|
| `App\Models\Account` | [`app/Models/Account.php:16-224`](#) | الحسابات المحاسبية (العملاء، الخزائن، البنوك، المصروفات، الإيرادات...) + الـ **balance guard** |
| `App\Models\Transaction` | [`app/Models/Transaction.php:13-102`](#) | رأس القيد — بيحمل الـ metadata (who, when, where) |
| `App\Models\AccountEntry` | [`app/Models/AccountEntry.php:8-34`](#) | سطور القيد (debit/credit) — الـ N entry rows لكل transaction |

### الفلسفة

- **ما تعملش `Account::find($id)->update(['balance' => 100])` أبداً!** 🛑
- كل تعديل على الرصيد لازم يمر بـ:
  1. **`TransactionService::recordJournalTransfer()`** (للتحويلات)
  2. **`TransactionService::recordExpense()`** (للمصروفات)
  3. **`TransactionService::recordIncome()`** (للإيرادات)
  4. **`PrepaidLedgerService::recharge()`** (للحسابات المسبقة)
  5. **`PrepaidLedgerService::consumeCogs()`** (لاستهلاك COGS)
  6. **`Model::debit()` / `Model::credit()`** (للـ FlightCarrier/System بس)

## 2.2 Account Types & Their Roles

**الـ enum:** [`app/Enums/AccountType.php`](#) — 10 أنواع:

| # | الـ Value | الاسم | الاستخدام |
|---|---|---|---|
| 1 | `cashbox` | خزينة نقدية | الكاش في المكاتب |
| 2 | `bank` | بنك | الحسابات البنكية |
| 3 | `wallet` | محفظة إلكترونية | Vodafone Cash, Etisalat Cash, etc. |
| 4 | `treasury` | خزينة رئيسية | الخزينة المركزية |
| 5 | `customer` | حساب عميل | لكل عميل حساب على الـ GL |
| 6 | `supplier` | حساب مورد | لكل مورد حساب |
| 7 | `expense` | حساب مصروف | مصروفات تشغيلية |
| 8 | `revenue` | حساب إيراد | إيرادات |
| 9 | `owner` | حساب المالك | رأس المال / خلاصة الأرباح |
| 10 | `wallet_provider` | مزود محفظة | الـ wallet provider entity |

**ملاحظة:** الـ "prepaid" مش enum منفصل — هو account من نوع `owner` بـ `module_type = flights/fawry` و `notes = "رصيد مسبق — ..."`.

### AccountScopes المتاحة

في [`app/Models/Account.php:151-196`](#):

```php
scopeActive()       // only is_active = true
scopeByType()       // filter by AccountType
scopeOwner()        // filter by owner_type
scopeCurrency()     // filter by currency
scopeForOwner()     // owner_type = ?
scopeForSupplier()  // has supplier relation
scopeTourism()      // module_type in TOURISM division
scopeOffice()       // module_type in OFFICE division
scopeModule()       // module = ?
```

## 2.3 The Double-Entry Flow — ازاي القيود بتتكتب

### الـ Canonical Posting Method

**[`app/Services/Finance/TransactionService.php:477-592`](#)** — `recordJournalTransfer()`:

```php
public function recordJournalTransfer(array $data): Transaction
{
    // 1) Validation
    $amount > 0  AND  $from_id !== $to_id
    
    // 2) Persist Transaction row (type = Transfer)
    $transaction = Transaction::create([...]);
    TransactionAuditStamper->stamp(); // ← audit metadata
    
    // 3) Lock both accounts in ascending ID order
    Account::whereIn('id', [$from, $to])->orderBy('id')->lockForUpdate()->get();
    
    // 4) Fund-account check (unless allow_from_negative)
    if (is_cash_account AND $from->balance < $amount) throw ValidationException;
    
    // 5) Cross-currency conversion (if applicable)
    if ($from->currency !== $to->currency) convert via CurrencyService;
    
    // 6) Apply balances
    $from->balance -= $amount;
    $to->balance   += $converted_amount;
    
    // 7) Write 2 AccountEntry rows (one debit, one credit)
    AccountEntry::create(['account_id' => $from, 'debit' => $amount, 'credit' => 0, 'balance_after' => ...]);
    AccountEntry::create(['account_id' => $to,   'debit' => 0, 'credit' => $amount, 'balance_after' => ...]);
    
    return $transaction;
}
```

**🔑 القواعد الذهبية:**

1. **ترتيب الـ locking** بالـ ID تصاعدي — `WHERE id IN (...) ORDER BY id ASC FOR UPDATE` (لمنع deadlock)
2. **الـ conversion بيحصل في الـ to account فقط** (الـ from بياخد amount الأصلي)
3. **كل transaction لازم ليه entries متوازنة** (sum(debit) == sum(credit))
4. **`TransactionAuditStamper::stamp()`** بيتستدعى من `persistTransaction()` على كل transaction

### أنواع الـ Transactions (الـ TransactionType enum)

[`app/Enums/TransactionType.php`](#):

| Type | الاستخدام |
|---|---|
| `Income` | إيراد (من بيع خدمة) |
| `Expense` | مصروف |
| `Transfer` | تحويل بين حسابين |
| `Refund` | استرداد |
| `Writeoff` | **شطب** (أضيف في Phase 3b v3 — لتصفير desyncs قديمة) |

## 2.4 🛡️ Balance Protection: `LedgerBalanceMutationGuard`

> **ده أهم file في المشروع كله.** هو اللي بيحمي الـ GL من الـ desync اللي حصل في 2026-07.

**[`app/Support/Finance/LedgerBalanceMutationGuard.php`](#)** — 32 سطر بس، لكنه **الدرع الواقي**:

```php
final class LedgerBalanceMutationGuard
{
    private static int $depth = 0;
    
    public static function run(callable $callback): mixed
    {
        ++self::$depth;       // ← "افتح البوابة"
        try {
            return $callback();
        } finally {
            --self::$depth;   // ← "اقف البوابة"
        }
    }
    
    public static function isAllowed(): bool
    {
        return self::$depth > 0;
    }
}
```

### ازاي بيشتغل؟

**١) في الـ Account Model** [`app/Models/Account.php:50-70`](#):

```php
static::updating(function (Account $account): void {
    if (! $account->isDirty('balance')) return;
    
    if (! config('accounting.balance_guard.block_unauthorized_updates', true)) return;
    
    if (config('accounting.balance_guard.disable_intesting', false) 
        && app()->runningUnitTests()) return;
    
    if (LedgerBalanceMutationGuard::isAllowed()) return;  // ← البوابة
    
    throw new \RuntimeException(
        'تعديل رصيد الحساب مباشرةً غير مسموح. '
        .'استخدم مسار الدفتر (قيود المعاملة) عبر خدمات المالية المعتمدة.'
    );
});
```

**الترجمة:** لو عملت `Account::find(1)->update(['balance' => 100])` خارج الـ Guard → **🛑 exception**.

**٢) استخدم الـ Guard عند الحاجة:**

```php
// ✅ الطريقة الصحيحة:
LedgerBalanceMutationGuard::run(function () use ($account, $newBalance) {
    $account->balance = $newBalance;
    $account->save();
});

// ❌ الطريقة الممنوعة:
$account->balance = $newBalance;
$account->save();  // → RuntimeException
```

**٣) ليه الـ depth counter؟**

عشان لو عندك nested calls:
```php
LedgerBalanceMutationGuard::run(function () {
    // outer call depth=1
    
    LedgerBalanceMutationGuard::run(function () {
        // inner call depth=2 — لسه مسموح
        $account->balance = 100;
        $account->save();
    });
    // inner depth=1 — لسه مسموح
});
// depth=0 — البوابة اتقفلت
```

## 2.5 Currency Logic

### الـ Currency Enum

[`app/Enums/Currency.php`](#) — 4 عملات:

| الـ Value | الاسم | الرمز |
|---|---|---|
| `EGP` | الجنيه المصري | ج.م |
| `USD` | دولار أمريكي | $ |
| `SAR` | ريال سعودي | ر.س |
| `KWD` | دينار كويتي | د.ك |

> **EGP = default** (السوق المصري، فوري، إلخ)

### الـ CurrencyService

[`app/Services/Finance/CurrencyService.php`](#):

```php
convert($amount, $fromCurrency, $toCurrency): array  // ['to_amount' => ..., 'rate' => ...]
```

### ازاي الـ multi-currency بيتم في الـ postings؟

في `recordJournalTransfer()` [`app/Services/Finance/TransactionService.php:539-558`](#):

```php
if ($sameCurrency) {
    $toAmount = $amount;  // 1:1
} else {
    // Cross-currency conversion
    $toAmount = $data['converted_amount'] ?? ($amount / $rate);  // depends on currency pair
}
```

### الحسابات الـ Foreign Currency

[`app/Models/Account.php:24-38`](#) — `$fillable` فيه `currency`. كل account ليه عملته.

> **ملحوظة:** الـ `FlightBooking` عنده **dual currency** بشكل خاص:
> - `currency` (العملة الأصلية للحجز)
> - `foreign_currency` + `purchase_price_foreign` + `exchange_rate`
> - `purchase_price_egp` + `original_amount` + `original_currency`
> - **`base_currency_amount`** (المحوّل للـ EGP)

## 2.6 The Prepaid System (الحسابات المسبقة)

> **مش كل account عادي.** الحسابات المسبقة (Prepaid GL) هي اللي بتسمح للـ Flight booking بإن يكون له credit limit.

### الفلسفة

بدل الحسابات المسبقة:

```
📅 يوم 1: العربية رصيدها 100,000 EGP
📅 يوم 2: حجز 30,000 → رصيدها 70,000 ✓
📅 يوم 3: حجز 90,000 → رصيدها -20,000 ✗ (سالب!)
```

**لازم يكون فيه Credit Limit:**

- `flight_carriers.balance` ← cache تشغيلي (للعرض)
- "رصيد مسبق — ناقلو الطيران" (`account.type = owner`) ← **Source of Truth** (credit limit)

### الـ PrepaidLedgerService

[`app/Services/Finance/PrepaidLedgerService.php:15-224`](#):

```php
class PrepaidLedgerService
{
    // ① recharge: من source → prepaid account (لما بشحن رصيد)
    recharge(string $prepaidKey, Account $source, float $amount, 
             TransactionModule $module, ?string $notes, ?string $relatedType, ?int $relatedId): Transaction;
    
    // ② consumeCogs: من prepaid → expense contra (لما بحجز)
    consumeCogs(string $prepaidKey, TransactionModule $module, float $amount, 
                ?string $notes, ?string $relatedType, ?int $relatedId): ?Transaction;
    
    // ③ refundCogs: من expense contra → prepaid (لما بعمل refund)
    refundCogs(string $prepaidKey, TransactionModule $module, float $amount, ...): ?Transaction;
}
```

### الـ "رصيد مسبق" Checking

[`app/Services/Finance/PrepaidLedgerService.php:128-159`](#):

```php
$prepaidAccount = Account::query()->find($prepaidId);
if ($prepaidAccount && (float) $prepaidAccount->balance < $amount) {
    throw new InsufficientBalanceException(
        'رصيد مسبق غير كافٍ على حساب "' . $prepaidAccount->name . '"...'
    );
}
```

> **🔑 القاعدة:** الـ prepaid check بيمشي أولاً قبل debit الـ carrier/system — حتى لو الـ carrier.balance = موجب، الـ prepaid لو سالب → **الحجز يتقفل**.

### الـ Config الـ Prep aid

ملف: `config/accounting.php`:

```php
return [
    'clearing' => [
        'prepaid' => [
            'flight_carrier' => 'رصيد مسبق — ناقلو الطيران',
            'flight_system'  => 'رصيد مسبق — أنظمة حجز الطيران',
            'fawry'          => 'رصيد مسبق — ماكينات فوري',
        ],
    ],
    ...
];
```

> ⚠️ **الحل المعماري الكامل** موجود في `PREPAID_BALANCE_ARCHITECTURE.md` (Observer + Sync + Cron).

## 2.7 Write-offs (شطب الأرصدة)

### المشكلة

لو حصلت desync قديمة (pre-2025 migration، مثلاً) ومفيش ledger history لإثبات الحقيقة — الحل هو **`TransactionType::Writeoff`**.

### الـ Flow

```php
// في الـ Phase 3b v3
Transaction::create([
    'type'           => TransactionType::Writeoff->value,
    'amount'         => $desyncAmount,
    'from_account_id' => $writeoffExpenseAccountId,
    'to_account_id'   => $prepaidAccountId,
    'module'         => 'flight',
    'notes'          => 'Phase 3b correction',
]);
// ثم entry debit على expense، credit على prepaid
```

### الملفات المرتبطة

- `app/Enums/TransactionType.php` (الـ Writeoff value)
- `app/Models/AccountEntry.php:11-17` (`notes` field أضيف في Phase 3b v3)
- الـ script اللي نفذ التصحيح: `phase3b_v3_writeoff_7desyncs.php` (في الـ root)

> **⚠️ انظر `BALANCE_TOUCHPOINTS_MAP.md` للـ decision matrix** (Manual Edit vs Correction Entry vs Write-off).

---

# الجزء 3: Module Map (كل module فين)

> **كل business module في النظام له نفس الـ pattern:** Models → Services → Controllers → Filament → API. الجزء ده بيوضح in في كل واحد.

## 3.1 ✈️ Flight Module (الأكبر والأعقد)

> **أكتر module في النظام.** 14 model + 7 service + 13 controller + 7 Filament resources. الـ recent incident كله كان هنا.

### الـ Models (`app/Models/Flight/`)

| الملف | الغرض | Key columns |
|---|---|---|
| `FlightBooking.php` | الحجز الرئيسي (الأكتر schema تعقيداً) | purchase_price, selling_price, profit, currency, purchase_price_egp, booking_exchange_rate, sale_gl_transaction_id, status |
| `FlightCarrier.php` | ناقلي الطيران (EgyptAir, Jazeera, etc.) | balance (🛡️ Phase 1 protected), credit_limit, available_balance |
| `FlightSystem.php` | أنظمة الحجز (NDC_WONDR, Amadeus, etc.) | balance (🛡️ Phase 1 protected), credit_limit |
| `AirlineAccount.php` | ⚠️ **Legacy** — predecessor لـ FlightCarrier | balance (❌ NOT protected — GAP كبير) |
| `AirlineCredit.php` | رصيد دائن للعملاء (voucher) | amount, expiry_date |
| `AirlineTransaction.php` | معاملات carrier | debit, credit, balance_after |
| `FlightSystemTransaction.php` | معاملات system | نفس النمط |
| `FlightPayment.php` | دفعات العميل للحجز | payment_method, amount, currency |
| `FlightPassenger.php` | ركاب الحجز | name, passport, type |
| `FlightSegment.php` | قطاعات الرحلة (مثال: GUC-CAI) | origin, destination, departure_at |
| `FlightGroup.php` | مجموعات الدفع للحجز (حجز بـ group) | balance, name |
| `FlightGroupTransaction.php` | معاملات المجموعة | debit, credit |
| `FlightRefund.php` | استردادات الحجز | amount, refund_type, status |
| `FlightTicket.php` | التذكرة الإلكترونية | ticket_number, status |
| `RefundRequest.php` | طلب استرداد (الـ workflow) | destination (treasury/airline_credit), status, currency |
| `TicketModification.php` | تعديل تذكرة (date change) | airline_change_fee, agency_commission, status |

### الـ Services (`app/Services/Flight/`)

| الملف | الأسطر | الغرض | Key methods |
|---|---:|---|---|
| `FlightBookingService.php` | **2297** ⭐ | كل lifecycle الـ booking | createBooking (L210), cancelBooking (L1690), updateBooking (L1292), addPayment (L1546), confirmBooking (L1496), updatePrices (L1441), backfillMissingCustomerSaleLedgers (L1039) |
| `RefundService.php` | 273 | Refund request workflow | createRefundRequest (L29), processRefundRequest (L83) |
| `ModificationService.php` | 160 | Ticket modification | createRequest, updateStatus, confirmModification (L89) |
| `FlightCarrierRechargeService.php` | 175 | شحن رصيد carrier | rechargeFromAccount (L38) — Phase 1 protected |
| `FlightSystemRechargeService.php` | 138 | شحن رصيد system | rechargeFromAccount (L32) — Phase 1 protected |
| `AirlineAccountDebitService.php` | ~80 | خصم من AirlineAccount (Legacy) | debitForModification |
| `AviationService.php` | ~200 | Legacy booking entry point | calculateProfit, validatePassengers, createBooking |

**⭐ FlightBookingService — أهم method (الـ booking flow):**

[`app/Services/Flight/FlightBookingService.php:210-411`](#):

```php
public function createBooking(array $data): FlightBooking
{
    DB::transaction(function () {
        // 1) Validate & normalize
        // 2) Compute prices (purchasePriceEGP, sellingPriceEGP, profit)
        // 3) Resolve purchase_balance_source (carrier | system | group)
        // 4) Snapshot settlement (currency_used, exchange_rate_used)
        // 5) Generate booking_number (FLT-20260710-XXXXX)
        // 6) Create booking row (FlightBooking::create)
        // 7) Debit exactly one purchase pool:
        if ($source === 'carrier') debitFlightCarrier(...);
        elseif ($source === 'system') debitFlightSystem(...);
        elseif ($source === 'group') recordPurchaseFromGroup(...);
        // 8) Create passengers
        // 9) Record sale on customer ledger (recordSaleToCustomer)
        // 10) Create flight tickets
        // 11) Create segments
        // 12) Process initial payment (addPayment)
        // 13) Eager-load full booking graph and return
    });
}
```

**⭐ FlightBookingService — method بيعمل الـ balance debit:**

[`app/Services/Flight/FlightBookingService.php:801-908`](#):

```php
protected function debitFlightCarrier(...) {
    // 1) Lock carrier row (lockForUpdate)
    // 2) Check available_balance (throws if insufficient)
    // 3) Call FlightCarrier::debit()  ← safe via mutateBalanceInternal
    // 4) Call PrepaidLedgerService::consumeCogs('flight_carrier', ...)  ← GL entry
}
```

نفس النمط في `debitFlightSystem()` [`:859-908`](#).

### الـ API Controllers (`app/Http/Controllers/Api/V1/Flight/`)

13 controller:

| Controller | الـ endpoints |
|---|---|
| `FlightController.php` | CRUD الحجز + prices/confirm/payments/sendEmail/cancel |
| `FlightBookingController.php` | Legacy stub |
| `FlightCarrierController.php` | CRUD carriers + balance + recharge |
| `FlightSystemController.php` | CRUD systems |
| `FlightDashboardController.php` | KPIs |
| `FlightTreasuryController.php` | Treasury overview + per-account tx + system recharge |
| `FlightGroupController.php` | Groups + statement + pay-debt |
| `AirlineAccountController.php` | ⚠️ Legacy CRUD — **GAP** (لا observer على الـ balance) |
| `RefundController.php` | Refund workflow (treasuries/airlineCredits/store/process) |
| `ModificationController.php` | Ticket modification |
| `PassengerController.php` | Passenger directory + alerts + mark-traveled |
| `AirportController.php` | Airports list/search/popular/by-iata/grouped |
| `AviationController.php` | Aviation bookings CRUD + next booking number |

### الـ Filament Resources (`app/Filament/Admin/Resources/Flight*`)

| الـ Resource | الملف | الـ Key Actions |
|---|---|---|
| `FlightBookingResource.php` | bookings CRUD | — |
| `FlightCarrierResource.php` | carriers CRUD | **`rechargeBalance()` action (L246)** — استخدام `FlightCarrierRechargeService` |
| `FlightSystemResource.php` | systems CRUD | `rechargeBalance()` action (L262) — `FlightSystemRechargeService` |
| `FlightGroupResource.php` | groups CRUD | view transactions |
| `FlightGeneralTreasuryResource.php` | treasuries | view |
| `FlightTreasuryResource.php` | treasuries | view |
| `FlightWalletResource.php` | wallets | view |
| `TicketModificationResource.php` | modifications | view/approve |

### الـ Filament Custom Pages & Widgets

| الصفحة | الملف | الوظيفة |
|---|---|---|
| **FlightDashboard** | `Pages/FlightDashboard.php` | لوحة تحكم الطيران |
| **FlightSystemsBalancesPage** | `Pages/FlightSystemsBalancesPage.php` ⭐ | جدول أرصدة الأنظمة + زر recharge |
| **CurrencyTreasuryExchangePage** | `Pages/CurrencyTreasuryExchangePage.php` | صرف عملات من الخزينة |

**Widgets:** `FlightStatsWidget` (sum balance)، `RecentFlightBookingsWidget` (آخر الحجوزات).

### الـ Tests

- `FlightBookingFlowTest.php` (الـ booking flow)
- `FlightBookingApiCrudTest.php` (API CRUD)
- `FlightBookingPhase2Test.php` (Phase 2 refactor)
- `FlightCreditBookingTest.php` (AirlineCredit flow)
- `FlightGroupPayDebtTest.php`
- `FlightRemainingCrudTest.php`
- `FlightSystemRechargeTest.php`
- `FlightBookingDisplayConsistencyTest.php`

---

## 3.2 🚌 Bus Module

### Models (`app/Models/Bus/`)
- `BusBooking` — الحجز
- `BusCompany` — شركة الباص
- `BusCompanyPayment` — دفعات للشركة
- `BusGovernorate` — محافظة
- `BusInventory` — مخزون الباصات (seats/routes)
- `BusPayment` — دفعات العميل
- `BusRefundRequest` — طلب استرداد
- (BusTicket في الـ root)

### Services (`app/Services/Bus/`)
- `BusBookingService.php` — booking lifecycle (create, pay, cancel, delete)
- `BusCompanyService.php` — شركة CRUD + `ensureCompanyAccount` (يُنشئ GL account)
- `BusInventoryService.php` — إدارة المخزون + `payInventoryDebt`
- `BusRefundService.php` — refund requests (create + process, idempotent)

### Filament Resources (10 ملفات)
`BusBanks`, `BusBookings`, `BusCompanies`, `BusCompanyPayments`, `BusGeneralTreasuries`, `BusGovernorates`, `BusInventories`, `BusTickets`, `BusTreasuries`, `BusWallets`.

### Custom Pages
`BusCompanyDebtStatement.php` — جدول ديون الشركات + pay-debt action.

### API Controllers (`Api/V1/Bus/`)
`BusBookingController`, `BusCompanyController`, `BusCustomerController` (العملاء اللي عليهم bus debt)، `BusDashboardController`, `BusInventoryController`, `BusRefundController`, `BusTreasuryController`.

### Tests
`BusApiCrudTest`, `BusBookingFlowTest`, `tests/Feature/Bus/BusApiTest.php`.

---

## 3.3 🕋 Hajj/Umra Module

### Models (`app/Models/HajjUmra/`)
- `AccommodationType`, `HajjUmraExecutingCompany`, `Hotel`, `TripSupervisor`, `UmrahSupplier`, `VisaAgent`, `VisaDuration`

### Services
- `app/Services/HajjUmra/HajjUmraBookingService.php` — booking create/update/cancel + payment
- (الأخيرة like HajjUmra and Visa)

### Filament Resources (6 ملفات)
`HajjUmraBankAccounts`, `HajjUmraBookings`, `HajjUmraExecutingCompanies`, `HajjUmraTreasuries`, `HajjUmraWallets`, `Programs`.

### Custom Pages
`HajjUmraExecutingCompanyAdvances.php` — جدول سلف الشركات.

### API Controllers
`HajjUmraController` (root level), `HajjUmraDashboardController`, `HajjUmraExecutingCompanyFinanceController`, `HajjUmraProgramController`, `HajjUmraTreasuryController`, `HajjUmraReferenceController`.

### Tests
`tests/Feature/HajjUmra/HajjUmraApiTest.php`.

---

## 3.4 🛂 Visa Module

### Models (في الـ root level)
- `VisaBooking`, `VisaDetail`, `VisaPayment`

### Services
- `app/Services/Visa/VisaBookingService.php` — booking lifecycle

### Filament Resources (5 ملفات)
`VisaAgents`, `VisaBanks`, `VisaBookings`, `VisaDurations`, `VisaTreasuries`, `VisaWallets`.

### Custom Pages
`VisaAgentDebtStatement.php` — ديون وكلاء التأشيرات + pay-debt.

### API Controllers (`Api/V1/Visa/`)
`VisaController` (root), `VisaAgentApiController`, `VisaAgentFinanceController`, `VisaTreasuryController`.

### Enums مهمة
- `VisaStatus`, `VisaType`, `VisaEntryType`, `VisaPaymentMethod`

---

## 3.5 💻 Online Services Module (خدمات إلكترونية)

### Models
- `app/Models/Online/OnlineServiceProvider` — مزود الخدمة
- `OnlineServiceType` — نوع الخدمة
- `OnlineTransaction` — المعاملة

### Services
- `OnlineServiceProviderService.php`
- `OnlineServiceTypeService.php`
- `OnlineTransactionService.php` — transactions + daily summary

### Filament Resources (5 ملفات)
`OnlineBankAccounts`, `OnlineGeneralTreasuries`, `OnlineServiceProviders`, `OnlineServiceTypes`, `OnlineTransactions`, `OnlineTreasuries`, `OnlineWallets`.

### API Controllers (`Api/V1/Online/`)
`OnlineServiceProviderController`, `OnlineServiceTypeController`, `OnlineSettingsController`, `OnlineTransactionController`, `OnlineTreasuryController`.

### Tests
`OnlineServicesApiCrudTest`.

---

## 3.6 💳 Fawry Module (ماكينات فوري المصرية)

### Models (`app/Models/Fawry/`)
- `FawryMachine`, `FawryTransaction`, `FawryOperationType`, `FawryPaymentMethod`

### Services (`app/Services/Fawry/`)
- `FawryMachineRechargeService.php` — شحن ماكينة فوري
- `FawryTransactionService.php` — transactions + daily summary

### Filament Resources (10 ملفات)
`FawryBanks`, `FawryCashboxes`, `FawryCurrencies`, `FawryMachines`, `FawryOperationTypes`, `FawryPaymentMethods`, `FawryTransactions`, `FawryTreasuries`, `FawryWallets`.

### الـ Prepaid Account
- "رصيد مسبق — ماكينات فوري" (account type: owner)
- الـ key في config: `accounting.clearing.prepaid.fawry`

### API Controllers (`Api/V1/Fawry/`)
`FawryDashboardController`, `FawryMachineApiController`, `FawrySettingsController`, `FawryTransactionController`, `FawryTreasuryController`.

### Tests
`FawryMachineServiceTest`, `FawryTransactionServiceTest`, `FawryTransactionControllerTest`, `FawryModuleIntegrationTest`, `FawryWalletFilamentTest`.

---

## 3.7 👛 Wallet Module (المحافظ الإلكترونية)

> **لاحظ:** "Wallet" هنا = "Transfer module" (Vodafone Cash, Etisalat Cash, إلخ) — مختلف عن الـ `account.type = wallet`.

### Models (`app/Models/Wallet/`)
- `Wallet`, `WalletType`, `WalletTransaction`

### Services (`app/Services/Wallet/`)
- `WalletTransactionService.php` — wallet deposit/withdraw + daily summary

### Filament Resources (3 ملفات + 4 TransferAccounts)
`WalletAccounts`, `WalletTransactions`, `WalletTypes` + `TransferAccounts` cluster (4: Bank, Cashbox, Treasury, Wallet).

### API Controllers (`Api/V1/Wallet/`)
`TransferDashboardController`, `TransferTreasuryController`, `WalletTransactionController`, `WalletTypeController`.

### Tests
`WalletTransactionCrudTest`.

---

## 3.8 👥 Employee / HR Module

### Models (`app/Models/Employee/`)
- `Employee`, `EmployeeAttendance`, `EmployeeBonus`, `Payroll`, `Leave`, `Loan`

### Services (`app/Services/Employee/`)
- `EmployeeService.php` — CRUD + stats
- `EmployeeAttendanceService.php` — حضور/انصراف + rollups
- `EmployeeBonusService.php` — bonuses + deductions + draws (uses `TransactionService`)
- `EmployeeReportService.php` — تقارير أداء

### Filament Resources (3 ملفات)
`EmployeeBonuses`, `Payrolls`, `TripSupervisors`.

### Custom Pages
`MaintenanceModePage.php` (مش employees فعلياً — الـ toggle للـ maintenance mode).

### API Controllers (`Api/V1/Employee/`)
`AttendanceController`, `EmployeeBonusController`, `EmployeeController`, `EmployeeDashboardController`, `EmployeeReportController`.

### Tests
`EmployeeReportServiceTest` (unit)، `UserManagementTest`.

---

## 3.9 💰 Finance Core (العصب المحاسبي — مش module بل infrastructure)

> **ده الـ infrastructure المحاسبي** اللي كل modules بتستخدمها.

### الـ Services (`app/Services/Finance/`)

| الملف | الـ Purpose |
|---|---|
| **`TransactionService.php`** ⭐ | **Canonical posting engine** — `recordJournalTransfer`, `recordExpense`, `recordIncome`, `recordTransfer`, `reverseTransaction`, `voidTransactionJournal` |
| **`PrepaidLedgerService.php`** ⭐ | `recharge`, `consumeCogs`, `refundCogs` — للحسابات المسبقة |
| **`LedgerClearingAccounts.php`** ⭐ | Resolves/auto-creates GL accounts — `incomeContraIdForModule`, `expenseContraIdForModule`, `prepaidAccountId`, `prepaidAccountIdMap` |
| `AccountingService.php` | High-level `postBalancedJournal` + cash receipts/disbursements |
| `AccountService.php` | Account list/create/update/deactivate + statement builder |
| `AccountRechargeService.php` | Recharge أي Account من source |
| `ApprovalService.php` | Approval workflows (تحويلات، تغيير عملة، حجوزات) — **فيها 2 TODO** |
| `AuditService.php` | Audit log writer + retrieval |
| `CurrencyService.php` | FX convert + admin rates |
| `LedgerEntryDescriptionResolver.php` | Generates Arabic descriptions per booking type |
| `LedgerReconciliationService.php` | Daily reconciliation + integrity scans |
| `LedgerRepairService.php` | Backfill legacy postings + sync balances |
| `SupplierAccountService.php` | Recharge/debit/credit supplier accounts |
| `TransactionAuditStamper.php` | Stamps every Transaction with audit metadata |
| `TreasuryAccountResolver.php` | Resolves module-vault/office/owner treasuries |
| `TreasuryLedgerMirror.php` | Mirrors GL legs to treasury_transactions |
| `TreasuryService.php` | Treasury stats + balances + drawer close + trial balances |
| `TrialBalanceExportService.php` | Spreadsheet export |

### الـ Support Classes (`app/Support/Finance/`)

| الملف | الـ Purpose |
|---|---|
| **`LedgerBalanceMutationGuard.php`** ⭐⭐ | الـ balance protection — بيمنع تعديل مباشر |
| `AccountModuleDivision.php` | تصنيف الـ accounts (TOURISM vs OFFICE) |
| `LiquidityAccountGroups.php` | تصنيف accounts للـ liquidity grouping |
| `PostingContext.php` | HTTP context wrapper |
| `PostingContextRegistry.php` | الـ registry للـ context |
| `UnifiedLiquidityGrouper.php` | Unification logic |

### الـ API Routes (`routes/finance.php`)

```
Route::prefix('finance')->middleware(['auth:sanctum'])->group(function () {
    Route::get   ('/'                          → AccountController@index)
    Route::post  ('/'                          → AccountController@store)
    Route::get   ('/{id}'                      → AccountController@show)
    Route::put   ('/{id}'                      → AccountController@update)
    Route::patch ('/{id}/deactivate'           → AccountController@deactivate)
    Route::get   ('/{id}/statement'            → AccountController@statement)
    Route::post  ('/transfers'                 → AccountController@transfer)
});

Route::prefix('suppliers')->middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get ('/{supplier}/account/recharge'   → SupplierAccountController@recharge)
    Route::post('/{supplier}/account/recharge'   → SupplierAccountController@recharge)
    Route::get ('/{supplier}/account/statement'  → SupplierAccountController@statement)
    Route::get ('/{supplier}/account/balance'    → SupplierAccountController@balance)
});
```

---

## 3.10 📊 Reports & Invoicing

### Reports (`app/Services/Reports/`)
- `FinancialReportService.php` (95KB!) — Treasury، P&L، customer/supplier debt، cash-flow، capital analysis
- `FinanceOperationsReportService.php`
- `ProfitLossReportService.php` — P&L + module breakdown
- `ReportCustomerService.php` — customer balances + top customers
- `ReportEmployeeService.php` — employee performance + bonus
- `ReportFinanceService.php` — financial summary + accounts balance + module breakdown
- `ReportOperationsService.php` — profit summary + per-module ops reports

### Invoice (`app/Services/InvoiceService.php`)
- Lifecycle: create، send، addPayment، cancel، overdue ticker، recalculation
- Models: `Invoice`, `InvoiceItem`, `InvoicePayment`

### Filament Resources
`ExchangeRates`, `ExpenseAccounts` (ليست resource حقيقي لكن مفيد).

### Tests
`FinancialReportTest`, `ProfitLossReportTest`, `OperationsLedgerTest`, `ReportsHubTest`، `FinanceDashboardDataTest`.

---

# الجزء 4: Layered Architecture (طبقات النظام)

> **النظام 7 طبقات** (طبقات الـ Laravel الكلاسيكية + services + observers).

```
┌──────────────────────────────────────────────────────────────────┐
│ Layer 7: Frontends                                                │
│   • Filament Admin Panel (/admin)                                 │
│   • Vue.js SPA (/api/v1/*)                                        │
│   • Tenant Portal (if any)                                        │
├──────────────────────────────────────────────────────────────────┤
│ Layer 6: Filament Resources + Custom Pages                        │
│   • app/Filament/Admin/Resources/  (63 resource)                  │
│   • app/Filament/Admin/Pages/      (9 custom page)                │
│   • app/Filament/Admin/Widgets/    (7 widget)                     │
├──────────────────────────────────────────────────────────────────┤
│ Layer 5: HTTP Layer (Controllers + Form Requests)                 │
│   • app/Http/Controllers/Api/V1/   (40+ controller)               │
│   • app/Http/Requests/             (49 FormRequest)               │
│   • app/Http/Middleware/           (7 middleware)                │
├──────────────────────────────────────────────────────────────────┤
│ Layer 4: Business Services (الـ logic)                            │
│   • app/Services/Finance/          (18) — الـ accounting          │
│   • app/Services/Flight/           (7) — booking lifecycle        │
│   • app/Services/{Module}/         — لكل module                  │
├──────────────────────────────────────────────────────────────────┤
│ Layer 3: Observers / Listeners (cross-cutting)                    │
│   • app/Observers/                 — على الـ models               │
│   • app/Listeners/                 — على الـ events               │
│   • app/Notifications/             — 1 (PrepaidBalanceLow)        │
├──────────────────────────────────────────────────────────────────┤
│ Layer 2: Eloquent Models (الـ data + basic behavior)              │
│   • app/Models/Account             — الـ booted() = Phase 1 guard│
│   • app/Models/Transaction         — header                       │
│   • app/Models/AccountEntry        — entry rows                   │
│   • app/Models/{Module}/*          — per-module                   │
├──────────────────────────────────────────────────────────────────┤
│ Layer 1: Database (MySQL)                                         │
│   • accounts / transactions / account_entries (الـ GL)             │
│   • {module}_bookings, {module}_payments, ...                     │
│   • flight_carriers, flight_systems, flight_bookings, ...         │
└──────────────────────────────────────────────────────────────────┘
```

## 4.1 الـ Middleware الـ 7

[`app/Http/Middleware/`](#):

| الـ Middleware | الملف | الـ Purpose | ملاحظات |
|---|---|---|---|
| `AuthenticateWithApiToken` | `AuthenticateWithApiToken.php` | SSO via `?token=` or `Authorization: Bearer` | logs in admin/owner into session |
| **`CaptureFinancialPostingContext`** ⭐ | `CaptureFinancialPostingContext.php` | بتاعة الـ audit | Sets `PostingContext` على الـ registry (HTTP context لكل finance posting) |
| `CheckPermission` | `CheckPermission.php` | Per-route permission check | admin/manager/employee matrix |
| `EnsureIsActive` | `EnsureIsActive.php` | 401 if `user.is_active = false` | — |
| `EnsureIsAdmin` | `EnsureIsAdmin.php` | 403 unless admin/owner | — |
| **`RejectBannedFinancialBypassMarkers`** ⭐ | `RejectBannedFinancialBypassMarkers.php` | **Security guard** | Aborts 403 if request فيه `direct_financial_write` query param أو `X-Allow-Direct-Ledger` header — **ده bypass marker ممنوع!** |
| `StandardizeApiResponse` | `StandardizeApiResponse.php` | Response wrapper | يضع responses في envelope `{success, message, data, errors}` |

**🔗 الـ Kernel:** [`app/Http/Kernel.php`](#) بيعرّف aliases: `auth`, `auth.api`, `admin`, `permission`, `active`.

**⚠️ الـ Route Group للـ protected v1:**

```php
Route::prefix('v1')->middleware([
    'auth:sanctum',
    'active',
    'admin',         // (لو endpoint يحتاج admin)
    CaptureFinancialPostingContext::class,  // ← الـ audit
    RejectBannedFinancialBypassMarkers::class,  // ← الـ security
])->group(function () { ... });
```

## 4.2 الـ Console Commands (`app/Console/Commands/`)

[البحث بـ `find app/Console/Commands`](#) — 4 ملف (تقريباً). الـ architecture الأهم:

- `MaintenanceModeService` (في `app/Services/System/`) — يدير `php artisan down/up`
- الـ Filament `MaintenanceModePage` — يعرض toggle للـ maintenance mode بـ secret token + retry + redirect + IP allowlist

## 4.3 الـ Events / Listeners

[`app/Listeners/ProcessTicketModificationAccounting.php`](#) — **GAP!** ده الـ listener اللي بيخصم من `AirlineAccount.balance` بدون تسجيل GL entry. (انظر الجزء 8.5).

---

# الجزء 5: Critical Files Index (فهرس الملفات الحرجة)

> **جدول البحث السريع.** لو محتاج ملف معين → ابحث هنا.

## 5.1 🗄️ الـ Models الأساسية

| الـ Model | الملف | الـ Key Lines | ملاحظات |
|---|---|---|---|
| `Account` | `app/Models/Account.php` | 16-44 (fillable)، 48-104 (booted guard)، 106-109 (canBeDeleted)، 151-196 (scopes) | 🛡️ الـ `updating` hook على L50-70 |
| `Transaction` | `app/Models/Transaction.php` | 16-35 (fillable)، 37-41 (casts)، 43-76 (relations)، 78-101 (scopes) | الـ posting_channel للـ audit |
| `AccountEntry` | `app/Models/AccountEntry.php` | 10-23 (fillable + casts)، 25-33 (relations) | `notes` field added Phase 3b v3 |
| `Customer` | `app/Models/Customer.php` | — | SoftDeletes؛ belongsTo Account |
| `Supplier` | `app/Models/Supplier.php` | — | belongsTo Account |
| `User` | `app/Models/User.php` | — | Filament auth + Sanctum |
| `ExchangeRate` | `app/Models/ExchangeRate.php` | — | للـ FX rates |

## 5.2 ✈️ الـ Flight Models

| الـ Model | الملف | Key columns / ملاحظات |
|---|---|---|
| `FlightBooking` | `app/Models/Flight/FlightBooking.php` | `sale_gl_transaction_id`, `purchase_price_egp`, `original_currency`, dual currency |
| `FlightCarrier` | `app/Models/Flight/FlightCarrier.php` | 🛡️ `mutateBalanceInternal` (L88) + `debit` (L174) + `credit` (L198) |
| `FlightSystem` | `app/Models/Flight/FlightSystem.php` | نفس النمط |
| `AirlineAccount` | `app/Models/Flight/AirlineAccount.php` | ⚠️ **LEGACY** — `balance` في fillable، بدون observer |
| `FlightPassenger` | `app/Models/Flight/FlightPassenger.php` | النوع، الباسبورت |
| `FlightSegment` | `app/Models/Flight/FlightSegment.php` | origin, destination, departure_at |
| `FlightPayment` | `app/Models/Flight/FlightPayment.php` | payment_method, amount, currency |
| `FlightRefund` | `app/Models/Flight/FlightRefund.php` | refund amount, status |
| `RefundRequest` | `app/Models/Flight/RefundRequest.php` | destination (treasury/airline_credit), status |
| `TicketModification` | `app/Models/Flight/TicketModification.php` | airline_change_fee, agency_commission, status state machine |

## 5.3 💰 الـ Finance Services (العصب)

| الـ Service | الملف | الـ Key Methods + Lines | الـ Purpose |
|---|---|---|---|
| **`TransactionService`** ⭐ | `app/Services/Finance/TransactionService.php` | `recordJournalTransfer` (L477-592) ⭐<br>`recordExpense` (L46-120)<br>`recordIncome` (L132-210)<br>`recordTransfer` (L290+)<br>`reverseTransaction` (L217+)<br>`voidTransactionJournal` (L597+)<br>`persistTransaction` (L28-34) | الـ canonical posting engine |
| **`PrepaidLedgerService`** ⭐ | `app/Services/Finance/PrepaidLedgerService.php` | `recharge` (L29-100) ⭐<br>`consumeCogs` (L109-180) ⭐<br>`refundCogs` (L185-224) | عمليات الحسابات المسبقة |
| **`LedgerClearingAccounts`** ⭐ | `app/Services/Finance/LedgerClearingAccounts.php` | `incomeContraIdForModule` (L21-32)<br>`expenseContraIdForModule` (L47-61)<br>`prepaidAccountId` (L63-76) ⭐<br>`prepaidAccountIdMap` (L78-117)<br>`ensurePrepaidAccountExists` (L196-239) | Resolver لـ GL accounts |
| `AccountingService` | `app/Services/Finance/AccountingService.php` | `postBalancedJournal` | High-level posting |
| `AccountService` | `app/Services/Finance/AccountService.php` | `buildAccountsQuery` (L28)<br>`createAccount` (L122)<br>`getAccountStatement` (L224) — **special cases flight groups**<br>`credit`/`debit` (L423, L428) guarded by Guard | Account CRUD + statements |
| `AccountRechargeService` | `app/Services/Finance/AccountRechargeService.php` | `recharge` | Recharge any account |
| `ApprovalService` | `app/Services/Finance/ApprovalService.php` | create/approve/reject | ⚠️ **فيها 2 TODO** (L185, L193) |
| `CurrencyService` | `app/Services/Finance/CurrencyService.php` | `convert` (amount, from, to) | FX |
| `TransactionAuditStamper` | `app/Services/Finance/TransactionAuditStamper.php` | `stamp(Transaction)` | Audit metadata |
| `TreasuryService` | `app/Services/Finance/TreasuryService.php` | stats, balances per currency, drawer close | Treasury aggregation |
| `TreasuryLedgerMirror` | `app/Services/Finance/TreasuryLedgerMirror.php` | `mirrorFlightInboundReceipt` | Mirrors GL legs |
| `LedgerReconciliationService` | `app/Services/Finance/LedgerReconciliationService.php` | daily runs + integrity scans | Reconciliation |
| `LedgerRepairService` | `app/Services/Finance/LedgerRepairService.php` | backfill legacy postings | Repair |
| `LedgerEntryDescriptionResolver` | `app/Services/Finance/LedgerEntryDescriptionResolver.php` | generates Arabic descriptions | Descriptions |

## 5.4 ✈️ الـ Flight Services

| الـ Service | الملف | الـ Key Methods + Lines | ملاحظات |
|---|---|---|---|
| **`FlightBookingService`** ⭐ | `app/Services/Flight/FlightBookingService.php` | `createBooking` (L210-411) ⭐⭐<br>`cancelBooking` (L1690-1842)<br>`updateBooking` (L1292+)<br>`addPayment` (L1546+)<br>`confirmBooking` (L1496+)<br>`updatePrices` (L1441+)<br>`backfillMissingCustomerSaleLedgers` (L1039+)<br>**`debitFlightCarrier`** (L801-857) ⭐<br>**`debitFlightSystem`** (L859-908) ⭐<br>`creditBackFlightCarrier` (L1847+)<br>`recordSaleToCustomer` (L2106+)<br>`recordPurchaseFromGroup` (L2144+) | **2297 سطر — أكبر file.** الـ booking flow كامل هنا |
| `FlightCarrierRechargeService` | `app/Services/Flight/FlightCarrierRechargeService.php` | `rechargeFromAccount` (L38) | 🛡️ **Phase 1** — ID-asc locks + retry loop |
| `FlightSystemRechargeService` | `app/Services/Flight/FlightSystemRechargeService.php` | `rechargeFromAccount` (L32) | 🛡️ **Phase 1** — نفس النمط |
| `RefundService` | `app/Services/Flight/RefundService.php` | `createRefundRequest` (L29)<br>`processRefundRequest` (L83) ⭐ | Refund workflow |
| `ModificationService` | `app/Services/Flight/ModificationService.php` | `createRequest` (L16)<br>`updateStatus` (L56)<br>`confirmModification` (L89) ⭐ | Ticket modifications |
| `AirlineAccountDebitService` | `app/Services/Flight/AirlineAccountDebitService.php` | `debitForModification` | ⚠️ **Legacy** — بيخصم بدون GL |
| `AviationService` | `app/Services/Flight/AviationService.php` | `calculateProfit`, `validatePassengers`, `createBooking` | Legacy booking path |

## 5.5 🛡️ الـ Support Classes (الأمان)

| الملف | الـ Purpose | ملف:سطر |
|---|---|---|
| **`LedgerBalanceMutationGuard`** ⭐⭐ | Balance protection للـ accounts | `app/Support/Finance/LedgerBalanceMutationGuard.php` (32 سطر) |
| `PostingContext` | HTTP context wrapper | `app/Support/Finance/PostingContext.php` |
| `PostingContextRegistry` | الـ registry | `app/Support/Finance/PostingContextRegistry.php` |
| `AccountModuleDivision` | TOURISM vs OFFICE classification | `app/Support/Finance/AccountModuleDivision.php` |
| `LiquidityAccountGroups` | Liquidity grouping | `app/Support/Finance/LiquidityAccountGroups.php` |
| `UnifiedLiquidityGrouper` | Unification logic | `app/Support/Finance/UnifiedLiquidityGrouper.php` |

## 5.6 🌐 الـ API Controllers (الأكثر استخداماً)

| الـ Controller | الملف | الـ Endpoints المهمة |
|---|---|---|
| `FlightController` | `app/Http/Controllers/Api/V1/Flight/FlightController.php` | bookings CRUD + prices/confirm/payments/cancel |
| `FlightCarrierController` | نفس المجلد | carriers CRUD + balance + recharge |
| `FlightSystemController` | نفس المجلد | systems CRUD |
| `FlightTreasuryController` | نفس المجلد | treasury overview + per-account tx + system recharge |
| `RefundController` | نفس المجلد | refund workflow (treasuries/airlineCredits/store/process) |
| `ModificationController` | نفس المجلد | ticket modification |
| `BusBookingController` | `app/Http/Controllers/Api/V1/Bus/BusBookingController.php` | bus booking CRUD, stats, pay, cancel |
| `BusInventoryController` | نفس المجلد | bus inventory + available + pay-debt |
| `FawryMachineApiController` | `app/Http/Controllers/Api/V1/Fawry/FawryMachineApiController.php` | machines + recharge + transactions |
| `AccountController` | `app/Http/Controllers/Api/V1/Finance/AccountController.php` | accounts list/show/update + transfer |
| `SupplierAccountController` | نفس المجلد | supplier account recharge/statement/balance |
| `DashboardController` | `app/Http/Controllers/Api/V1/DashboardController.php` | unified dashboard (cache-tagged, admin only) |
| `FinancialReportController` | `app/Http/Controllers/Api/V1/Reports/FinancialReportController.php` | كل التقارير المالية |

## 5.7 🔐 الـ Middleware

| الـ Middleware | الملف | ملاحظات |
|---|---|---|
| `AuthenticateWithApiToken` | `app/Http/Middleware/AuthenticateWithApiToken.php` | SSO via `?token=` or Bearer |
| **`CaptureFinancialPostingContext`** ⭐ | `app/Http/Middleware/CaptureFinancialPostingContext.php` | Audit HTTP context (auto-cleared in `finally`) |
| `CheckPermission` | `app/Http/Middleware/CheckPermission.php` | role-based wildcard matrix |
| `EnsureIsActive` | `app/Http/Middleware/EnsureIsActive.php` | 401 if not active |
| `EnsureIsAdmin` | `app/Http/Middleware/EnsureIsAdmin.php` | 403 unless admin/owner |
| **`RejectBannedFinancialBypassMarkers`** ⭐ | `app/Http/Middleware/RejectBannedFinancialBypassMarkers.php` | 🛡️ Security guard — banned bypass markers |
| `StandardizeApiResponse` | `app/Http/Middleware/StandardizeApiResponse.php` | wraps in `{success, message, data, errors}` |

## 5.8 🏷️ الـ Enums (الأكثر استخداماً)

| الـ Enum | الملف | الـ Values |
|---|---|---|
| `AccountType` | `app/Enums/AccountType.php` | cashbox, bank, wallet, treasury, customer, supplier, expense, revenue, owner |
| `Currency` | `app/Enums/Currency.php` | EGP, USD, SAR, KWD |
| `TransactionType` | `app/Enums/TransactionType.php` | Income, Expense, Transfer, Refund, **Writeoff** (Phase 3b v3) |
| `TransactionModule` | `app/Enums/TransactionModule.php` | General, Flight, Bus, HajjUmra, Visa, Online, Fawry, Wallet, Employee |
| `FlightBookingStatus` | `app/Enums/FlightBookingStatus.php` | pending, confirmed, partial, refund, cancelled |
| `BusBookingStatus` | `app/Enums/BusBookingStatus.php` | — |
| `HajjUmraStatus` | `app/Enums/HajjUmraStatus.php` | — |
| `VisaStatus` / `VisaType` / `VisaEntryType` | الـ 3 ملفات | — |
| `OnlineTransactionStatus` | `app/Enums/OnlineTransactionStatus.php` | — |
| `PaymentMethod` | `app/Enums/PaymentMethod.php` | — |
| `WalletProvider` | `app/Enums/WalletProvider.php` | — |
| `FawryOperationType`, `FawryPaymentMethod` | `app/Enums/Fawry*.php` | — |

## 5.9 ✅ أهم الـ Tests

| الـ Test | الملف | الـ يختبر |
|---|---|---|
| `FlightBookingFlowTest` | `tests/Feature/FlightBookingFlowTest.php` | الـ booking flow الكامل |
| `FlightSystemRechargeTest` | `tests/Feature/FlightSystemRechargeTest.php` | الـ recharge flow |
| `FlightBookingPhase2Test` | `tests/Feature/FlightBookingPhase2Test.php` | Phase 2 refactor |
| `PrepaidCogsTest` (موجود ضمني) | — | الـ prepaid COGS guard |
| `FinancialIntegrityTest` | `tests/Feature/FinancialIntegrityTest.php` | 🛡️ الـ integrity test (فيها **`.bak` backup**) |
| `CurrencyExchangeTransferTest` | `tests/Feature/CurrencyExchangeTransferTest.php` | Multi-currency |
| `FinanceTransferTest` | `tests/Feature/FinanceTransferTest.php` | Account transfers |
| `BusBookingFlowTest` | `tests/Feature/BusBookingFlowTest.php` | Bus module |
| `ConcurrentSessionTest` | `tests/Feature/ConcurrentSessionTest.php` | SSO behavior |
| `FawryModuleIntegrationTest` | `tests/Feature/Fawry/FawryModuleIntegrationTest.php` | Fawry end-to-end |
| `ReportsHubTest` | `tests/Feature/Reports/ReportsHubTest.php` | Reports |
| `DashboardFinancialStatsTest` | `tests/Feature/DashboardFinancialStatsTest.php` | Dashboard |
| `Phase 6 booking cycle script` | `phase6_test_booking_cycle.php` (الـ root) | ⚠️ لازم يتحول لـ PHPUnit test |

---

# الجزء 6: 🗺️ Impact Maps (خرائط العمليات)

> **🎯 الجزء الأهم.** الوقت اللي تبقى فيه المشكلة — روح هنا.

## 6.1 إنشاء حجز طيران

```
User action (Filament أو API)
    │
    ▼
FlightController::store()      [app/Http/Controllers/Api/V1/Flight/FlightController.php]
    │ POST /v1/flight/bookings
    │ Validates with StoreFlightBookingRequest
    ▼
FlightBookingService::createBooking()    [app/Services/Flight/FlightBookingService.php:210-411]
    │ DB::transaction { ... }
    │ 
    ├─── Step 1: prepareFlightBookingPayload()        [L427]
    ├─── Step 2: Compute prices (purchasePriceEGP, profit)
    ├─── Step 3: resolvePurchaseBalanceSource()       [L661]
    ├─── Step 4: persistedSettlementSnapshot()        [L604]
    ├─── Step 5: generateBookingNumber()              [L416]
    ├─── Step 6: FlightBooking::create()              [model::create]
    │
    ├─── Step 7: Debit purchase pool ─────────────────────────────────────┐
    │   ├── if 'carrier':                                                    │
    │   │   debitFlightCarrier()                    [L801-857]               │
    │   │       ├── FlightCarrier::debit() ← safe via mutateBalanceInternal │
    │   │       └── PrepaidLedgerService::consumeCogs('flight_carrier')     │
    │   │               └── TransactionService::recordJournalTransfer()    │
    │   │                   └── AccountEntry::create × 2                   │
    │   ├── if 'system':                                                     │
    │   │   debitFlightSystem()                     [L859-908]               │
    │   │       ├── FlightSystem::debit() ← safe via mutateBalanceInternal  │
    │   │       └── PrepaidLedgerService::consumeCogs('flight_system')      │
    │   └── if 'group':                                                      │
    │       recordPurchaseFromGroup()               [L2144+]                 │
    │           ├── FlightGroupTransaction::create                          │
    │           └── TransactionService::recordJournalTransfer()             │
    │
    ├─── Step 8: createPassengers()                  [L1163]
    ├─── Step 9: recordSaleToCustomer()              [L2106]                  │
    │       └── TransactionService::recordJournalTransfer()                 │
    │           └── customer.balance += sellingPrice                        │
    ├─── Step 10: createFlightTickets()              [L1123]
    ├─── Step 11: createSegments()                   [L1193]
    └─── Step 12: addPayment()                        [L1546]
            └── TransactionService::recordIncome()
                └── TreasuryLedgerMirror::mirrorFlightInboundReceipt()

📁 الملفات اللي هتلمسها لو عندك مشكلة هنا:
   - app/Services/Flight/FlightBookingService.php (الـ orchestrator)
   - app/Http/Controllers/Api/V1/Flight/FlightController.php
   - app/Http/Requests/Flight/StoreFlightBookingRequest.php
   - app/Models/Flight/FlightBooking.php
   - app/Models/Flight/FlightCarrier.php (لو source=carrier)
   - app/Models/Flight/FlightSystem.php (لو source=system)
   - app/Services/Finance/PrepaidLedgerService.php
   - app/Services/Finance/TransactionService.php
```

## 6.2 إلغاء حجز طيران

```
FlightController::cancel()  → FlightBookingController → FlightBookingService::cancelBooking() [L1690-1842]
    │
    ├─── 1) Validate (reject if already CANCELLED/REFUNDED)
    ├─── 2) Calculate refundAmount = totalPaid - airlinePenalty - officePenalty
    ├─── 3) Mark issued tickets as cancelled         [FlightTicket::update]
    │
    ├─── 4) Credit back purchase pool
    │   ├── if 'carrier': creditBackFlightCarrier() [L1847+]
    │   │       ├── FlightCarrier::credit() 
    │   │       └── PrepaidLedgerService::refundCogs('flight_carrier')
    │   ├── if 'system': creditBackFlightSystem()   [L1904+]
    │   ├── if 'group': reverseGroupPurchase()      [L2213+]
    │   └── legacy 'both' / null paths              [L1731-1758]
    │
    ├─── 5) Reverse GL sale journal
    │       └── if sale_gl_transaction_id set:
    │           TransactionService::recordJournalTransfer()
    │           ← customer ← clearing for selling_price - totalPenalties
    │
    ├─── 6) Refund cash from treasury → refundTreasuryAccount()  [L1958]
    │       └── TransactionService::recordJournalTransfer()
    │           └── TreasuryLedgerMirror::mirrorFlightOutboundFromCash()
    │
    ├─── 7) Create FlightRefund row                  [FlightRefund::create]
    └─── 8) Update booking status (REFUNDED or CANCELLED)

📁 نفس الـ files زي 6.1 + إضافة:
   - app/Models/Flight/FlightRefund.php
   - app/Services/Finance/TreasuryLedgerMirror.php (في الخطوات 4, 6)
```

## 6.3 Refund to Treasury (RefundRequest workflow)

```
RefundController::process()  → RefundService::processRefundRequest()  [app/Services/Flight/RefundService.php:83-272]
    │
    DB::transaction { ... }
    │
    ├─── 1) Lock RefundRequest row (idempotency check — bail if processed)
    ├─── 2) Lock FlightBooking
    │
    ├─── 3) Branch by destination:
    │   ├── if 'airline_credit' [L95-117]:
    │   │       └── AirlineCredit::create (1-year expiry)
    │   │           — مفيش GL posting — voucher only
    │   │
    │   └── if 'agency_treasury' [L119-258]:
    │       ├─── a) Lock Treasury
    │       ├─── b) Validate currency match
    │       ├─── c) Treasury::credit()  + TreasuryTransaction::create (receipt)
    │       ├─── d) Resolve destination Account (Cashbox fallback)
    │       ├─── e) **Debit purchase pool** (flight_carrier / flight_system)
    │       │       for the same refund amount (EGP-adjusted)
    │       ├─── f) Resolve prepaid GL account via LedgerClearingAccounts
    │       └─── g) TransactionService::recordJournalTransfer()
    │               from prepaid → treasury (converted if needed)
    │
    ├─── 4) Update booking status (REFUNDED or PARTIALLY_REFUNDED)
    └─── 5) Mark RefundRequest as processed

📁 الملفات:
   - app/Services/Flight/RefundService.php
   - app/Http/Controllers/Api/V1/Flight/RefundController.php
   - app/Http/Requests/Flight/StoreFlightRefundRequest.php
   - app/Models/Flight/RefundRequest.php
   - app/Models/Flight/AirlineCredit.php (لو airline_credit destination)
   - app/Models/Treasury.php
   - app/Services/Finance/TransactionService.php
   - app/Services/Finance/LedgerClearingAccounts.php
```

## 6.4 Ticket Modification

```
ModificationController::confirm()  → ModificationService::confirmModification()  [app/Services/Flight/ModificationService.php:89-140]
    │
    ├─── 1) Lock TicketModification + FlightBooking (idempotency: bail if already confirmed)
    ├─── 2) Validate: require booking.airline_account_id (fixed financial rule)
    ├─── 3) Snapshots: airline_change_fee_snapshot, commission_snapshot, exchange_rate_snapshot
    ├─── 4) Set status = 'confirmed'
    ├─── 5) Update FlightBooking (departure_date, destination, mod_count)
    │
    └─── 6) event(new TicketModified($modification))
            ↓
        ProcessTicketModificationAccounting listener
            ↓ ⚠️ THE GAP
            AirlineAccountDebitService::debitForModification()
                ├── Locks airline_account
                ├── Decrements balance (NO GL entry!)
                └── Returns AirlineTransaction
```

**⚠️ الـ GAP:** الـ listener بيخصم من `AirlineAccount.balance` بدون تسجيل GL entry — **ده اللي عمل الـ desync في الـ 2 flight_systems.** (انظر الجزء 8.5)

📁 الملفات:
- `app/Services/Flight/ModificationService.php`
- `app/Http/Controllers/Api/V1/Flight/ModificationController.php`
- `app/Events/TicketModified.php`
- `app/Listeners/ProcessTicketModificationAccounting.php` ⚠️
- `app/Services/Flight/AirlineAccountDebitService.php` ⚠️
- `app/Models/Flight/TicketModification.php`

## 6.5 Recharge Carrier / System (الأمان العالي)

```
Filament Resource action (FlightCarrierResource::rechargeBalance → L246)
    │
    ▼
FlightCarrierRechargeService::rechargeFromAccount()  [app/Services/Flight/FlightCarrierRechargeService.php:38]
    │
    Retry loop on PDOException 1020/1213 (max 3 attempts)
    │
    ▼
    executeRechargeTransaction()  [L85-173] (in DB::transaction)
    │
    ├─── 1) Currency match pre-check
    ├─── 2) Resolve prepaid account_id via LedgerClearingAccounts
    ├─── 3) **Lock all rows in ascending ID order** (carrier → source → prepaid) 🛡️ Phase 1
    ├─── 4) Re-fetch carrier + source after locks
    ├─── 5) Compose description
    ├─── 6) PrepaidLedgerService::recharge(prepaidKey='flight_carrier', ...)
    │       └── TransactionService::recordJournalTransfer()
    │           └── AccountEntry × 2 (debit source, credit prepaid)
    ├─── 7) FlightCarrier::credit(amount, desc, userId, null)
    │       ├── mutates static::$internalBalanceUpdate = true
    │       ├── Find carrier → lock → balance += amount → save
    │       └── returns AirlineTransaction
    └─── 8) Log + return

📁 الملفات:
   - app/Services/Flight/FlightCarrierRechargeService.php (carrier) أو FlightSystemRechargeService.php (system)
   - app/Filament/Admin/Resources/FlightCarriers/FlightCarrierResource.php (الـ action UI)
   - app/Models/Flight/FlightCarrier.php (mutateBalanceInternal)
   - app/Services/Finance/PrepaidLedgerService.php (recharge)
   - app/Services/Finance/LedgerClearingAccounts.php
   - app/Services/Finance/TransactionService.php
```

## 6.6 Recharge Account (Generic — AccountRechargeService)

```
Filament action (AccountFormSchema::rechargeAccount → L333) أو AccountController API
    │
    ▼
AccountRechargeService::recharge(Account $account, Account $source, float $amount, ?string $notes)
    │
    ▼
TransactionService::recordJournalTransfer()
    ├─── Lock both accounts (ascending ID)
    ├─── Convert if needed (cross-currency)
    ├─── Apply: $source->balance -= amount; $account->balance += converted
    ├─── AccountEntry × 2 (debit source, credit account)
    └─── Audit stamp
```

## 6.7 Transfer بين Account API

```
AccountController::transfer() [routes/finance.php:Route::post('transfers')]
    │
    ▼
TransactionService::recordTransfer() (different from recordJournalTransfer — stores Transfer row أيضاً)
    │
    ├─── recordJournalTransfer (نفس الـ logic)
    ├─── Transfer::create() (extra link)
    ├─── Reuse transfer_id if provided (approval workflows)
    └─── Return Transfer

📁 الملفات:
   - app/Http/Controllers/Api/V1/Finance/AccountController.php
   - routes/finance.php
   - app/Services/Finance/TransactionService.php
   - app/Models/Transfer.php
```

## 6.8 Bus Booking

```
BusBookingController::store() → StoreBusBookingRequest validates
    │
    ▼
BusBookingService::createBooking() [app/Services/Bus/BusBookingService.php]
    │
    ├─── Validate inputs
    ├─── applies company credit (decreases BusCompany account balance)
    ├─── applies customer-sale-debt reversal (decreases Customer account)
    ├─── Update BusBooking + BusInventory
    ├─── TransactionService::recordExpense / recordIncome
    │       └── AccountEntry × 2
    └─── Return BusBooking

📁 الملفات:
   - app/Services/Bus/BusBookingService.php
   - app/Http/Controllers/Api/V1/Bus/BusBookingController.php
   - app/Http/Requests/Bus/StoreBusBookingRequest.php
   - app/Models/Bus/BusBooking.php
   - app/Models/Bus/BusInventory.php
   - app/Models/Bus/BusCompany.php
   - app/Services/Finance/TransactionService.php
   - app/Services/Bus/BusCompanyService.php (ensureCompanyAccount)
```

## 6.9 Fawry Transaction

```
FawryTransactionController::store() → StoreFawryTransactionRequest
    │
    ▼
FawryTransactionService::createTransaction() [app/Services/Fawry/FawryTransactionService.php]
    │
    ├─── Validate
    ├─── FawryMachine.balance -= amount (via ??? — verify — does it use Guard?)
    ├─── Record transaction in FawryTransaction
    └─── TransactionService::recordIncome
        └── AccountEntry × 2

📁 الملفات:
   - app/Services/Fawry/FawryTransactionService.php
   - app/Http/Controllers/Api/V1/Fawry/FawryTransactionController.php
   - app/Http/Requests/Fawry/StoreFawryTransactionRequest.php
   - app/Models/Fawry/FawryTransaction.php
   - app/Models/Fawry/FawryMachine.php
   - app/Services/Finance/TransactionService.php
```

## 6.10 Employee Bonus

```
EmployeeBonusController::store() → StoreEmployeeBonusRequest
    │
    ▼
EmployeeBonusService::createBonus/deduction/draw() [app/Services/Employee/EmployeeBonusService.php]
    │
    ├─── Validate type (bonus / deduction / draw)
    ├─── Look up employee + bonus account
    └─── TransactionService::recordExpense()
        └── AccountEntry × 2

📁 الملفات:
   - app/Services/Employee/EmployeeBonusService.php
   - app/Http/Controllers/Api/V1/Employee/EmployeeBonusController.php
   - app/Http/Requests/Employee/StoreEmployeeBonusRequest.php
   - app/Models/Employee/EmployeeBonus.php
   - app/Services/Finance/TransactionService.php
```

## 6.11 Write-off Balance Desync

```
External decision (المحاسبة بتقرر الـ entity X لازم يتعمله write-off)
    │
    ▼
Phase 3b v3 script أو manual via Filament (لا يوجد حالياً dedicated endpoint)
    │
    ▼
Transaction::create([
    'type' => TransactionType::Writeoff,
    'amount' => $desyncAmount,
    'from_account_id' => $writeoffExpenseAccountId,
    'to_account_id' => $prepaidAccountId,
    'module' => 'flight',
    'notes' => 'Write-off: unreconcilable balance, approved by [name]',
])
    │
    AccountEntry::create(['account_id' => $writeoffExpenseAccount, 'transaction_id' => $tx_id, 'debit' => $desyncAmount, 'credit' => 0, 'notes' => 'writeoff expense'])
    AccountEntry::create(['account_id' => $prepaidAccountId, 'transaction_id' => $tx_id, 'debit' => 0, 'credit' => $desyncAmount, 'notes' => 'prepaid reversed'])

📁 الملفات:
   - app/Enums/TransactionType.php (الـ Writeoff value)
   - app/Models/AccountEntry.php (الـ notes field)
   - scripts/phase3b_v3_writeoff_7desyncs.php (السكربت اللي استخدمه)
   - BALANCE_TOUCHPOINTS_MAP.md (الـ decision matrix)
```

**⚠️ ملاحظة:** في الحالة المثالية، لازم يكون فيه endpoint في الـ Filament أو API للـ write-off (بس لسه مش موجود).

---

# الجزء 7: 🔧 Recipes (وصفات جاهزة)

> **"عشان أعمل X، إيه الخطوات؟"** — خطوة بخطوة لكل عملية شائعة.

## 7.1 إصلاح Balance Desync (المرجع الشامل: `BALANCE_TOUCHPOINTS_MAP.md`)

### الـ Decision Tree

```
اكتشف desync (carrier.balance ≠ prepaid.balance)
   │
   ├─ هل فيه ledger history؟ (account_entries موجودة)
   │   ├─ نعم → 🅱️ Correction Entry (إنشاء قيد تصحيح متوازن)
   │   └─ لا → مفيش evidence
   │           ├─ هل فيه audit_log؟ → 🅰️ Manual Edit (مع توثيق)
   │           └─ لا → 🔴 قديم (>6 شهور) → 🅲️ Write-off
   │
   └─ الـ decision في الـ ACCOUNTING_HANDOFF_2026-07-08.md (Section 5)
```

### الخطوات الفعلية

1. **حضّر الـ diagnostic:**
   ```bash
   # تأكيد الـ desync
   cd /c/travile/SafarakEalayna
   php artisan tinker --execute='
   $c = App\Models\Flight\FlightCarrier::find($id);
   $a = App\Models\Account::find(24); // prepaid GL
   echo "Carrier: ".$c->balance." vs Prepaid share: ".($a->balance/8)."\n";
   '
   ```

2. **لو correction entry:**
   ```php
   // Script reference: phase3b_v3_writeoff_7desyncs.php في الـ root
   DB::transaction(function () {
       $tx = Transaction::create([
           'type' => TransactionType::Writeoff->value,
           'amount' => $absDesync,
           'from_account_id' => $writeoffExpenseId,
           'to_account_id' => $prepaidId,
           'module' => 'flight',
           'notes' => 'Phase 3b correction — [entity]',
       ]);
       AccountEntry::create(['account_id' => $prepaidId, 'transaction_id' => $tx->id, 'debit' => 0, 'credit' => $absDesync, 'balance_after' => ..., 'notes' => '...']);
   });
   ```

3. **Verify:**
   ```bash
   # بعد الـ fix، اتأكد إن الـ GL والإجمالي matched
   php artisan tinker --execute='
   $a = App\Models\Account::find(24);
   $sum = App\Models\AccountEntry::where("account_id", 24)->sum("credit") - App\Models\AccountEntry::where("account_id", 24)->sum("debit");
   echo "Prepaid GL: ".$a->balance." | Entries sum: ".$sum." | Delta: ".($a->balance - $sum)."\n";
   '
   ```

## 7.2 إضافة Account Type جديد

لو محتاج account type جديد (غير الـ 10 الموجودة):

1. **عدّل الـ enum:** `app/Enums/AccountType.php`
   ```php
   case NewType = 'new_type';  // lowercase
   ```

2. **عدّل الـ Account model:** (لو محتاج في `$fillable` أو `booted()` logic)
   - `app/Models/Account.php:24-38` (fillable)
   - `app/Models/Account.php:40-46` (casts)

3. **عدّل الـ `canBeDeleted()`** لو محتاج:
   - `app/Models/Account.php:106-109`

4. **عدّل الـ scopes** لو محتاج:
   - `app/Models/Account.php:151-196`

5. **حدّث الـ Prepaid config** (لو الـ type ده prepaid):
   - `config/accounting.php` — `clearing.prepaid.*`

6. **حدّث الـ Filament AccountFormSchema** لو محتاج UI:
   - `app/Filament/Admin/Resources/Accounts/AccountFormSchema.php`
   - ده **بيُستخدم في 27 Resource مختلف!**

7. **Tests:**
   ```php
   // tests/Feature/Accounts/{Type}Test.php — أنشئ test جديد
   ```

## 7.3 إضافة Module جديد (نسخة HajjUmra Template)

> **HajjUmra هو الـ simplest module** — استخدمه كقالب.

### الملفات المطلوبة

```
app/Models/{Module}/
├── {MainEntity}.php              # الـ model الرئيسي
├── {SupportingEntity1}.php       # entities مساعدة
└── ...

app/Services/{Module}/
└── {Module}BookingService.php    # business logic

app/Http/Controllers/Api/V1/{Module}/
├── {Module}Controller.php        # CRUD
└── ...

app/Http/Requests/{Module}/
├── Store{Entity}Request.php
├── Update{Entity}Request.php
└── StorePaymentRequest.php

app/Filament/Admin/Resources/{Module}/
├── {Entity}s/{Entity}Resource.php
└── ...

app/Filament/Admin/Pages/
├── {Module}Dashboard.php
└── {Entity}DebtStatement.php (اختياري)

database/migrations/
└── XXXX_XX_XX_create_{table}.php
```

### الـ Checks:

- [ ] كل الـ Models في `app/Models/{Module}/`
- [ ] الـ main service يبني في `app/Services/{Module}/` ويستخدم `TransactionService`
- [ ] Filament Resource تم تسجيله في `FilamentServiceProvider` (أو auto-discovery)
- [ ] API routes في `routes/api.php` بـ `Route::prefix('{module}')`
- [ ] Form Requests منفصلة عن الـ controllers (للتحقق)
- [ ] Migration يبدأ بـ `balance = 0` ما عدا الـ opening balances
- [ ] `php artisan migrate` شغّال بدون أخطاء
- [ ] Feature test موجود: `tests/Feature/{Module}/{Module}ApiTest.php`

## 7.4 إضافة Booking Type جديد (مثال: Hajj)

1. **الـ Model:** أنشئ `app/Models/HajjUmra/HajjUmraBooking.php` (موجود — استخدمه كقالب)
2. **الـ Service:** `app/Services/HajjUmra/HajjUmraBookingService.php` — استخدم نفس الـ pattern:
   - `createBooking(data)` → DB::transaction → debit supplier + recordSaleToCustomer
   - `cancelBooking()` → reverse all entries
3. **الـ Form Requests:** `StoreHajjUmraBookingRequest`, `UpdateHajjUmraBookingRequest`, `StoreHajjUmraPaymentRequest`
4. **الـ Controller:** `HajjUmraController` مع endpoints CRUD
5. **الـ Filament Resource:** `HajjUmraBookings/HajjUmraBookingResource`
6. **الـ Tests:** `HajjUmraApiTest`

📁 **Files to copy/edit:** HajjUmra module كامل هو القالب.

## 7.5 Debug Transaction فاشلة

### الخطوات

1. **شوف الـ exception message** — ابحث في الـ Search Index (الجزء 9)
2. **شوف الـ stack trace** — افتح الـ file عند السطر المذكور
3. **تحقق من الـ transactions الـ recent:**
   ```bash
   php artisan tinker --execute='
   $tx = App\Models\Transaction::latest()->limit(5)->get();
   echo $tx->toJson(JSON_PRETTY_PRINT);
   '
   ```
4. **تحقق من الـ balances:**
   ```bash
   php artisan tinker --execute='
   $a = App\Models\Account::find($id);
   $entries = App\Models\AccountEntry::where("account_id", $id)->orderBy("id", "desc")->limit(5)->get();
   echo "Balance: ".$a->balance." | Last 5 entries:\n".$entries->toJson();
   '
   ```
5. **شوف الـ PostFlight:** هل في `LedgerBalanceMutationGuard` failed؟ هل في `LockConflict`؟ هل في `InsufficientBalance`؟

## 7.6 تشغيل Reconciliation

1. **الـ Service:** `app/Services/Finance/LedgerReconciliationService.php`
2. **الـ Run:**
   ```bash
   php artisan tinker --execute='
   $svc = app(App\Services\Finance\LedgerReconciliationService::class);
   $report = $svc->runDailyReconciliation();
   print_r($report);
   '
   ```
3. **شوف الـ `LedgerReconciliationRun` و `LedgerReconciliationFinding` rows** — دول بيحفظوا الـ findings.

## 7.7 Deploy Schema Change بأمان

### Pre-deployment Checklist

```bash
# 1) Backup
mysqldump -u root -p safarakealayna > backup_$(date +%Y%m%d_%H%M%S).sql

# 2) Run in staging first
php artisan migrate --force  # على staging

# 3) Run the feature tests
php artisan test --filter={YourChange}

# 4) Verify the integrity test still passes
php artisan test --filter=FinancialIntegrityTest
```

### Post-deployment

```bash
# 5) Check any log errors
tail -f storage/logs/laravel.log

# 6) Run a quick reconciliation
php artisan tinker --execute='
   $svc = app(App\Services\Finance\LedgerReconciliationService::class);
   $svc->runDailyReconciliation();
'
```

### الـ Migration Template

```php
<?php
// database/migrations/YYYY_MM_DD_HHMMSS_description.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('new_field')->nullable()->after('balance');
        });
    }
    
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('new_field');
        });
    }
};
```

## 7.8 إضافة Filament Resource لـ entity جديد

**من الصفر:**

1. **أنشئ الـ Model** (لو مش موجود)
2. **أنشئ الـ Migration:**
   ```bash
   php artisan make:migration create_things_table
   php artisan make:model Thing
   ```
3. **أنشئ الـ Filament Resource:**
   ```bash
   php artisan make:filament-resource Thing --generate
   ```
4. **حدّد الـ `navigation group`** باستخدام `app/Filament/Admin/Concerns/` traits (مثل `BelongsToBusModuleNavigation`)
5. **عدّل الـ form schema** — اقرأ `app/Filament/Admin/Resources/Accounts/AccountFormSchema.php` كقالب
6. **اربطه بالـ Service** لو الـ operations محتاجة business logic
7. **اربطه بالـ Routes** (لو محتاج API):
   ```php
   // routes/api.php
   Route::apiResource('things', ThingController::class);
   ```

---

# الجزء 8: ⚠️ Known Issues & Status

> **كل حاجة لسه مش مظبوطة** — معرفة المشاكل دي بتوفر ساعات debug.

## 8.1 الـ 3 TODOs في الكود

| # | الملف:السطر | الـ Status | الـ Effort |
|---|---|---|---|
| 1 | `app/Policies/CustomerPolicy.php:16` | 🔴 **AMAZON TO DO** — مفيش authorization logic | 30 دقيقة |
| 2 | `app/Services/Finance/ApprovalService.php:185` | 🟡 `// TODO: تنفيذ تحويل العملة` (currency conversion في approval flow) | 2 ساعة |
| 3 | `app/Services/Finance/ApprovalService.php:193` | 🟡 `// TODO: تأكيد الحجز` (booking confirmation في approval flow) | 1 ساعة |

**الخطورة:** منخفضة (مش في الـ critical path) — لكن لو عايز توثيق authorization للـ customers لازم تنفذ 1.

## 8.2 الـ Pending Scripts في الـ root

> **⚠️ مهم:** الـ scripts دي واحد one-off، لازم تتنقل لـ `scripts/archive/` لما نخلص.

### الـ Phase Scripts (مهمة — في الإعادة ممكن تحتاجها)

| الـ Script | الـ Status | الـ Purpose |
|---|---|---|
| `phase3b_v3_rollback.sql` | ⚠️ متاح | Rollback لـ Phase 3b v3 (لو احتجت) |
| `phase3b_v3_rollback_staging.sql` | ⚠️ متاح | Rollback للـ staging |
| `phase3b_v4_emergency_rollback.sql` | ⚠️ متاح | Emergency rollback |
| `phase3b_v3_writeoff_7desyncs.php` | ✅ استخدم | الـ write-off الرئيسي |
| `phase3b_v2_correct-fix.php` | ✅ استخدم | الـ correction script |
| `phase3b_v1_revert_and_fix.php` | ❌ OUTDATED | v1 — فيه bug (credit بدل debit) |
| `phase6_test_booking_cycle.php` | ✅ **لازم يتحول لـ PHPUnit test!** | EGP + USD booking cycle |
| `phase7_cleanup_reverse_recharge.php` | ✅ استخدم | Reverse a test recharge |

### الـ Diagnostic Scripts (للاستخدام مرة واحدة بس)

```
diag_balance_gap_phase2.php
diag_create_flight_booking.php
diag_office.php
diagnose_tourism_gap.php
diag_prod.php
del_apply*.php             # 4 ملفات
del_corp_apply*.php        # 4 ملفات
del_corp_compensate.php
del_corp_diag.php
fix_carrier_balance_apply.php
fix_carrier_balance_diag.php
fix_cash_booking_deficit.php
audit_tinker.php
check_models.php
check.cjs
scratch_*.php              # 3 ملفات
test_account_isolation.php
test_string_cast.php
seed_wallets.php
scenario_test.php
quick_crud_test.php
```

**العدد:** ~20+ ملف في الـ root، منه:
- `phase*` (7 ملفات) → `scripts/rollback/` + `scripts/test/`
- `diag_*` (5 ملفات) → `scripts/diagnostics/`
- `del_*`, `fix_*` (8 ملفات) → `scripts/fixes/`
- الباقي → `scripts/misc/`

**🎯 خطة التنظيف (Phase 8):**
1. نقل لكل المجلدات الفرعية
2. حذف الـ `phase3b_v1_*` (deprecated)
3. حذف `tests/Feature/FinancialIntegrityTest.php.bak` (أو استبدل بـ backup policy)
4. تحديث `composer.json` scripts (لو نضيف alias للـ scripts المهمة)

## 8.3 الـ Backup Files

- `tests/Feature/FinancialIntegrityTest.php.bak` — **ممكن يكون مهم!** اتأكد إن الـ .php الجديد شغّال قبل ما تحذف الـ .bak
- `archive/2026-07-08_balance-incident/` — كل ملفات الـ incident response (محفوظة كمرجع تاريخي)

## 8.4 ملخص الـ Incident History (Phases 1-7)

| الـ Phase | الـ Status | الغرض | الـ Commit |
|---|---|---|---|
| **Phase 1** | ✅ Complete | 4 layers defense على `flight_carriers.balance` + `flight_systems.balance` (observer + non-fillable + UI disabled + DB::listen) | `d9b8787` (الأحدث، Phase 7) |
| **Phase 1v2** | ⏳ Recommended | حماية `AirlineAccount.balance` (الـ GAP!) — Phase 1v2 plan موجود في `BALANCE_TOUCHPOINTS_MAP.md` § 10 | — |
| **Phase 3a** | ✅ Complete | investigation: مفيش audit_logs، migration من `AirlineAccount` كان السبب | — |
| **Phase 3b v1** | ❌ Reverted | كان فيه bug — credit بدل debit — اتعمل rollback | — |
| **Phase 3b v2** | ✅ Used | النسخة المصححة من v1 (Manual Edit) | — |
| **Phase 3b v3** | ✅ Complete | أضاف `TransactionType::Writeoff` + `AccountEntry.notes` + الـ enum ->value fix | `3b2b351` |
| **Phase 3b v4** | ✅ Complete | emergency rollback script + ALL-OR-NOTHING transaction + atomic cleanup | `28455ae`, `0701230` |
| **Phase 4** | ⏳ Recommended | Hardening: improve error wrapping, refactor `ProcessTicketModificationAccounting` | — |
| **Phase 6** | ✅ Complete | booking cycle test في EGP + USD (لسه script، مش test) | `b4a5431` |
| **Phase 7** | ✅ Complete | atomic cleanup script — reverse a test recharge على production | `d9b8787` |

**📌 الحالة الحالية:** flight carriers + flight systems محميّة بـ 4 layers. AirlineAccount لسه فيه GAP. باقي الـ Hardening tasks (Phase 4) معلقة.

## 8.5 الـ 2 Gaps الكبيرة

### 🔴 GAP #1: `AirlineAccount.balance` غير محمي

**المشكلة:** [`app/Models/Flight/AirlineAccount.php:14-26`](#) فيه `'balance'` في `[Fillable]`، مفيش `booted()` hook، مفيش `Observer`.

**النتيجة:** أي حد يقدر يعمل `AirlineAccount::find(1)->update(['balance' => 100])` بدون exception → **desync محتمل.**

**الحل المقترح (Phase 1v2 — Effort: 2 ساعة):**

```php
// app/Observers/AirlineAccountObserver.php (أنشئ)
class AirlineAccountObserver
{
    public static function booted(): void
    {
        static::updating(function (AirlineAccount $account) {
            if (! $account->isDirty('balance')) return;
            if (LedgerBalanceMutationGuard::isAllowed()) return;
            throw new \RuntimeException('تعديل رصيد AirlineAccount مباشرةً غير مسموح...');
        });
    }
}

// app/Models/Flight/AirlineAccount.php
// 1) Remove 'balance' from #[Fillable]
// 2) Register Observer in AppServiceProvider::boot()

// 3) Add internal update method (like FlightCarrier::mutateBalanceInternal)
public function mutateBalanceInternal(float $delta, callable $mutator): self
{
    LedgerBalanceMutationGuard::run(function () use ($delta, $mutator) {
        $this->balance = (float) $this->balance + $delta;
        $mutator($this);
        $this->save();
    });
    return $this;
}
```

### 🔴 GAP #2: `ProcessTicketModificationAccounting` Listener

**المشكلة:** [`app/Listeners/ProcessTicketModificationAccounting.php:56`](#) بيستدعي `AirlineAccount.debit()` بدون تسجيل GL entry.

**النتيجة:** خصومات Ticket modifications بتأثر على `AirlineAccount.balance` لكن مفيش `transactions` row، مفيش `account_entries` row → **desync مع الـ GL.**

**الحل المقترح (Phase 4 — Effort: 4 ساعة):**

```php
// 1) تعديل الـ listener ليستدعي service بدلاً من الـ model مباشرة
// 2) Service لازم يعمل:
//    - Lock airline_account
//    - Use PrepaidLedgerService::recharge('flight_carrier', ...) أو consumeCogs
//    - Or create a dedicated "airline_account_adjustment" service
// 3) لازم يكتب في airline_transactions (موجود)
// 4) لازم يعمل AccountEntry rows على الـ GL
```

## 8.6 الـ Hard-coded Constants (ليست في الـ DB)

في [`app/Services/Flight/FlightBookingService.php:49-55`](#) — `FALLBACK_EGP_PER_UNIT`:

```php
const FALLBACK_EGP_PER_UNIT = [
    'USD' => 50.0,   // 1 USD = 50 EGP (fallback)
    'SAR' => 13.33,  // 1 SAR ≈ 13.33 EGP (fallback)
    'KWD' => 165.0,  // 1 KWD ≈ 165 EGP (fallback)
];
```

**التحذير:** لو الـ `Currency` row مش موجود في الـ DB → بيستخدم الـ fallback → **بيدي warnings**. الحل: تأكد إن كل عملة مسجلة في `/admin/currencies`.

## 8.7 الـ Currency Exchange Module (TODO في ApprovalService)

**المشكلة:** [`app/Services/Finance/ApprovalService.php:185`](#) فيه `// TODO: تنفيذ تحويل العملة` — ده بيأثر على الـ approval workflow لو فيه تحويل عملة.

**الـ Impact:** لو موظف طلب تحويل عملة والـ approval workflow باعت للـ manager → الـ currency conversion مش بتتنفذ.

**الحل:** ربط الـ `CurrencyService::convert()` في الـ approval step.

## 8.8 الـ Booking Confirmation Flow (TODO في ApprovalService)

**المشكلة:** [`app/Services/Finance/ApprovalService.php:193`](#) فيه `// TODO: تأكيد الحجز` — ده بيأثر على approval-based bookings.

**الـ Impact:** بعض الحجوزات محتاجة approval قبل التنفيذ (مثل الحجوزات الكبيرة > threshold) — الـ flow مش كامل.

## 8.9 الـ Stale Documentation Files

في الـ root، فيه ~20 ملف `.md` تاريخي. معظمها (ACCOUNTING_HANDOFF_, BALANCE_TOUCHPOINTS_MAP, PREPAID_BALANCE_ARCHITECTURE, INCIDENT_ROOT_CAUSE_REPORT) — مفيدة كمرجع، **بس لازم نوضح الـ "current state" في ARCHITECTURE.md (هذا الملف)** عشان ما تتلخبطش.

الـ `AUDIT_REPORT.md` و `FLIGHT_MODULE_ANALYSIS.md` و `CRUD_TEST_REPORT_*.md` و `FIX_*.md` و `INTEGRATION_*.md` — معظمها outdated. اقتراح: نقلهم لـ `docs/archive/` وضيف `ARCHITECTURE.md` كـ single source of truth.

## 8.10 الـ Open Questions (محتاج قرار)

1. **هل ننقل الـ scripts من الـ root لمجلد `scripts/`?** — نعم، Phase 8 من الخطة.
2. **هل نضيف `Approval Workflow` للحسابات الكبيرة؟** — TODO #3، Effort 1ساعة
3. **هل نطبق Phase 1v2 (AirlineAccount protection)؟** — أيوه 🔴 high priority
4. **هل نطبق Phase 4 (Listener refactor)؟** — أيوه 🔴 high priority
5. **هل نحول `phase6_test_booking_cycle.php` لـ PHPUnit test؟** — نعم، Phase 8 من الخطة

---

# الجزء 9: 🔍 Search Index (فهرس البحث)

> **لو ظهر error message مشهور → روح هنا.**

## 9.1 Exception Messages (الرسائل الشائعة)

| الـ Message (أو جزء منه) | الملف:السطر | الـ Service / الـ Method |
|---|---|---|
| `"رصيد مسبق غير كافٍ على حساب"` | [`app/Services/Finance/PrepaidLedgerService.php:149-157`](#) | `consumeCogs()` — الـ prepaid guard |
| `"Insufficient balance in account"` | [`app/Services/Finance/TransactionService.php:96-97`](#) | `recordExpense()` legacy path |
| `"تعديل رصيد الحساب مباشرةً غير مسموح"` | [`app/Models/Account.php:67-69`](#) | `Account::updating` boot — الـ **balance guard** |
| `"لا يمكن حذف حساب مالي"` | [`app/Models/Account.php:99-103`](#) | `Account::canBeDeleted` |
| `"رصيد الحساب غير كافٍ"` | [`app/Services/Finance/TransactionService.php:531-537`](#) | `recordJournalTransfer()` fund-account check |
| `"مبلغ الشحن يجب أن يكون أكبر من صفر"` | [`app/Services/Finance/PrepaidLedgerService.php:39`](#) | `recharge()` |
| `"حساب المصدر يطابق حساب الرصيد المسبق"` | [`app/Services/Finance/PrepaidLedgerService.php:43`](#) | `recharge()` |
| `"قيد المصروف يتطلب حساب إقفال تكاليف"` | [`app/Services/Finance/TransactionService.php:73-77`](#) | `recordExpense()` strict mode |
| `"تعذر تحديد حسابات استهلاك الرصيد المسبق"` | [`app/Services/Finance/PrepaidLedgerService.php:124-126`](#) | `consumeCogs()` — missing clearing account |
| `"Insufficient balance in account"` (في `withdrawal`) | [`app/Services/Treasury/TreasuryService.php`](#) | الـ Treasury service |
| `"Foreign currency mismatch"` | [`app/Http/Controllers/Api/V1/Flight/RefundController.php`](#) | Refund processing |
| `"Direct financial write not allowed"` | [`app/Http/Middleware/RejectBannedFinancialBypassMarkers.php`](#) | الـ bypass marker guard |
| `"User not active"` | `app/Http/Middleware/EnsureIsActive.php` | الـ auth guard |
| `"Insufficient permission"` | `app/Http/Middleware/CheckPermission.php` | الـ RBAC |

## 9.2 الـ Method Names (الأكثر استخداماً في الـ searches)

| الـ Method | الـ File | الـ Function |
|---|---|---|
| `createBooking` | `app/Services/Flight/FlightBookingService.php:210` | إنشاء حجز طيران |
| `cancelBooking` | `app/Services/Flight/FlightBookingService.php:1690` | إلغاء حجز طيران |
| `rechargeFromAccount` | `app/Services/Flight/FlightCarrierRechargeService.php:38` | شحن carrier |
| `processRefundRequest` | `app/Services/Flight/RefundService.php:83` | معالجة refund |
| `confirmModification` | `app/Services/Flight/ModificationService.php:89` | تأكيد تعديل تذكرة |
| `recordJournalTransfer` | `app/Services/Finance/TransactionService.php:477` | قيد متوازن |
| `recordExpense` | `app/Services/Finance/TransactionService.php:46` | قيد مصروف |
| `recordIncome` | `app/Services/Finance/TransactionService.php:132` | قيد إيراد |
| `reverseTransaction` | `app/Services/Finance/TransactionService.php:217` | عكس قيد |
| `recharge` | `app/Services/Finance/PrepaidLedgerService.php:29` | شحن prepaid |
| `consumeCogs` | `app/Services/Finance/PrepaidLedgerService.php:109` | استهلاك COGS |
| `refundCogs` | `app/Services/Finance/PrepaidLedgerService.php:185` | عكس COGS |
| `credit` / `debit` | `app/Models/Flight/FlightCarrier.php:174, 198` | تعديل رصيد carrier |
| `mutateBalanceInternal` | `app/Models/Flight/FlightCarrier.php:88` | balance guard helper |
| `run` / `isAllowed` | `app/Support/Finance/LedgerBalanceMutationGuard.php:17, 27` | الـ Guard |
| `ensurePrepaidAccountExists` | `app/Services/Finance/LedgerClearingAccounts.php:196` | إنشاء prepaid |
| `prepaidAccountId` | `app/Services/Finance/LedgerClearingAccounts.php:63` | resolver لـ prepaid |
| `ensureCompanyAccount` | `app/Services/Bus/BusCompanyService.php`](#) | bus company onboarding |
| `ensureCustomerAccount` | `app/Services/Flight/FlightBookingService.php:2066` | customer GL creation |
| `ensureFlightIncomeClearingAccount` | `app/Services/Flight/FlightBookingService.php:994` | flight income clearing |
| `recordSaleToCustomer` | `app/Services/Flight/FlightBookingService.php:2106` | sale على customer GL |
| `debitFlightCarrier` / `debitFlightSystem` | `app/Services/Flight/FlightBookingService.php:801, 859` | debit pools |
| `creditBackFlightCarrier` / `creditBackFlightSystem` | `app/Services/Flight/FlightBookingService.php:1847, 1904` | reverse debit |
| `recordPurchaseFromGroup` | `app/Services/Flight/FlightBookingService.php:2144` | flight group purchase |
| `reverseGroupPurchase` | `app/Services/Flight/FlightBookingService.php:2213` | reverse group purchase |

## 9.3 الـ Tables (DB Columns)

| الـ Table | الـ Column | الـ Use |
|---|---|---|
| `accounts` | `balance` | 🛡️ guard-protected — الـ GL balance |
| `accounts` | `currency` | الحساب فرعي بعملة واحدة |
| `accounts` | `type` | enum AccountType |
| `accounts` | `module_type` | Tourism/Office classification |
| `accounts` | `is_module_vault` | الـ module treasury flag |
| `accounts` | `wallet_provider` | enum WalletProvider |
| `transactions` | `type` | Income/Expense/Transfer/Refund/Writeoff |
| `transactions` | `module` | enum TransactionModule |
| `transactions` | `posting_channel` | enum/field للـ audit |
| `transactions` | `correlation_id` | للـ HTTP request tracking |
| `account_entries` | `debit` | المدين |
| `account_entries` | `credit` | الدائن |
| `account_entries` | `balance_after` | الرصيد بعد الـ entry |
| `account_entries` | `notes` | الـ description |
| `account_entries` | `transaction_id` | FK للـ transaction |
| `flight_bookings` | `sale_gl_transaction_id` | FK للـ GL sale entry |
| `flight_bookings` | `purchase_price_egp` | price بالـ EGP |
| `flight_bookings` | `original_currency` | الـ source currency |
| `flight_bookings` | `booking_exchange_rate` | الـ rate locked |
| `flight_carriers` | `balance` | 🛡️ Phase 1 protected |
| `flight_carriers` | `credit_limit` | الـ max debt |
| `flight_carriers` | `available_balance` | computed |
| `flight_systems` | `balance` | 🛡️ Phase 1 protected |
| `airline_accounts` | `balance` | ⚠️ **NOT protected** — الـ GAP |

## 9.4 الـ Forms + Filament Pages

| الـ Form | الـ Usage |
|---|---|
| `FlightCarrierResource` | carriers CRUD + `rechargeBalance` action |
| `FlightSystemResource` | systems CRUD + `rechargeBalance` action |
| `FlightSystemsBalancesPage` | bulk جدول + `rechargeFlightSystem` |
| `AccountFormSchema` | مستخدم في **27 Filament Resource!** |
| `FlightBookingResource` | booking flow |
| `BusBookingResource` | bus flow |
| `HajjUmraBookingResource` | Hajj flow |
| `VisaBookingResource` | visa flow |
| `FawryTransactionResource` | Fawry flow |
| `MaintenanceModePage` | maintenance toggle |

## 9.5 الـ Config Keys

| الـ Key | الملف | الـ Use |
|---|---|---|
| `accounting.clearing.prepaid.*` | `config/accounting.php` | Prepaid account names |
| `accounting.clearing.income.*` | نفس الملف | Income clearing accounts |
| `accounting.clearing.expense.*` | نفس الملف | Expense clearing accounts |
| `accounting.strict_double_entry` | نفس الملف | Enforce balanced journal? |
| `accounting.allow_legacy_single_leg_fallback` | نفس الملف | Allow single-leg postings? |
| `accounting.balance_guard.block_unauthorized_updates` | نفس الملف | Enable LedgerBalanceMutationGuard? |
| `accounting.balance_guard.disable_in_testing` | نفس الملف | Disable guard in tests? |
| `accounting.strict_test_guards` | نفس الملف | Enable strict prepaid COGS check in tests? |
| `accounting.audit.capture_http` | نفس الملف | Capture HTTP context? |
| `flight_accounting.ledger_clearing_account_name` | `config/flight_accounting.php` | Flight income clearing account name |
| `prepaid.thresholds.{key}` | `config/prepaid.php` | Prepaid low-balance alert thresholds |

## 9.6 الـ Routes (Cheat Sheet)

| الـ Route | الـ Controller | الـ Module |
|---|---|---|
| `POST /v1/flight/bookings` | `FlightController@store` | Flight |
| `GET /v1/flight/bookings` | `FlightController@index` | Flight |
| `POST /v1/flight/carriers/{id}/recharge` | `FlightCarrierController@recharge` | Flight |
| `POST /v1/flight/refunds/{id}/process` | `RefundController@process` | Flight |
| `POST /v1/flight/modifications/{id}/confirm` | `ModificationController@confirm` | Flight |
| `GET /v1/finance/{id}/statement` | `AccountController@statement` | Finance |
| `POST /v1/finance/transfers` | `AccountController@transfer` | Finance |
| `GET /v1/suppliers/{id}/account/balance` | `SupplierAccountController@balance` | Finance |
| `POST /v1/bus/bookings` | `BusBookingController@store` | Bus |
| `POST /v1/fawry/transactions` | `FawryTransactionController@store` | Fawry |
| `GET /v1/dashboard` | `DashboardController` | Dashboard |

---

# 📌 ملخص نهائي

**ده الـ master reference** لكل حاجة في المشروع. لو عندك مشكلة أو عايز تضيف feature:

1. **🔍 ابحث** في الجزء 9 (Search Index) بالـ message/error/method name
2. **🗺️ روح** للجزء 6 (Impact Maps) للـ operation المطلوب
3. **📖 اقرأ** الجزء 5 (Critical Files Index) للـ file:line refs
4. **🔧 شوف** الجزء 7 (Recipes) لو محتاج step-by-step
5. **⚠️ تحقق** من الجزء 8 (Known Issues) لو المشكلة مألوفة

**لو الملف ده مش كافي أو فيه خطأ** — قولي وأحدثه فوراً.

**📅 آخر تحديث:** 2026-07-10
**✍️ المؤلف:** ZCode + Youssef Abd Elhaleim
**🎯 الـ Mission:** كل حاجة في النظام في مكان واحد، واضح ومحدد.

> **🚀 يلا نبدأ نشتغل!**

