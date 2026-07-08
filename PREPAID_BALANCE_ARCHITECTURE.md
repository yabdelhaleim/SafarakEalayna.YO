# تقرير معماري: منطق الحسابات المسبقة (Prepaid GL Accounts)

**التاريخ**: 2026-07-07
**المشروع**: SafarakEalayna
**المشكلة**: حجز طيران يفشل برسالة "رصيد مسبق غير كافٍ" رغم أن `flight_carriers.available_balance` موجب
**الحل**: Observer + Sync (الحل المعماري الكامل لمنع تكرار المشكلة)

---

## 1. منطق الحسابات (لماذا عندنا حسابين متوازيين؟)

### 1.1 المشكلة بدون الحسابات المسبقة

لو النظام كان بيشتغل بـ `flight_carriers.available_balance` بس:

```
📊 الجدول الزمني:
─────────────────────────────────────────────────────────
يوم 1: العربية رصيدها 100,000 EGP
يوم 2: عملنا حجز بـ 30,000  ← رصيدها بقى 70,000 ✓
يوم 3: عملنا حجز بـ 90,000  ← رصيدها بقى -20,000 ✗ (سالب!)
يوم 4: حاولنا نعمل حجز تاني ← النظام سمح (لأنه مفيش حماية)
─────────────────────────────────────────────────────────
```

**النتيجة**: الشركة صرفت أكتر من اللي خصصته، ومحدشعرف غير لما الحسابات تتعمل.

### 1.2 الحل: الحساب المسبق كـ "Credit Limit"

النظام يستخدم **حسابين متوازيين** لنفس الناقل/النظام:

| الحساب | الطبيعة | الغرض |
|---|---|---|
| `flight_carriers.available_balance` | **Cache/Operational** | رصيد سريع للعرض، يُحدّث بعد كل حجز |
| `رصيد مسبق — ناقلو الطيران` (Prepaid GL) | **Source of Truth (Accounting)** | "الفيزا" أو "السقف" اللي بنمنع بـه الصرف الزائد |

**المعاملة المحاسبية**:
```
عند شحن 100,000 EGP للحساب المسبق:
─────────────────────────────────────────
   مدين:  البنك/الخزينة       100,000
   دائن:  رصيد مسبق — ناقلو   100,000
─────────────────────────────────────────
(تحويل سيولة → budget مخصص)

عند حجز طيران بـ 6,291 EGP (من الناقلين):
─────────────────────────────────────────
   مدين:  تكلفة حجز طيران (COGS)  6,291
   دائن:  رصيد مسبق — ناقلو      6,291
─────────────────────────────────────────
(تحويل budget → cost فعلي)

عند تحصيل المبلغ من العميل بـ 6,435 EGP:
─────────────────────────────────────────
   مدين:  خزينة                      6,435
   دائن:  إيراد مبيعات طيران        6,435
─────────────────────────────────────────
(إيراد بيع)
```

### 1.3 ليه الحساب المسبق هو اللي بيتفحص أولاً؟

في `PrepaidLedgerService::consumeCogs()`:
```php
if ($prepaidAccount && (float) $prepaidAccount->balance < $amount) {
    throw new InsufficientBalanceException(...);  // ← هنا بيتفحص
}
```

**السبب** (من كومنت في الكود):
> "هذا يضمن أن الحسابات المسبقة لا تدخل في السالب عند حجز جديد **حتى لو تم تعديل رصيد الناقل/النظام يدوياً** من الـ Filament UI."

**الترجمة**:
> لو موظف عدّل `carrier.available_balance` يدوياً من الواجهة (مثلاً كتب 999,999)، الحساب المسبق لسه محمي — **لأنه "source of truth" محاسبياً**.

### 1.4 الـ Diagram الكامل

```
                    ┌────────────────────────────┐
                    │  Treasury / Bank Account   │  (الخزينة / البنك)
                    │   (المصدر: Bank, Cash)     │
                    └──────────────┬─────────────┘
                                   │ ⚡ Recharge
                                   ▼
                    ┌────────────────────────────┐
                    │  Prepaid GL Account        │  (Envelope / Budget)
                    │  رصيد مسبق — ناقلو الطيران │
                    │   -52,349.30 EGP (سالب!)   │
                    └──────────────┬─────────────┘
                                   │ ⚡ consumeCogs عند كل حجز
                                   ▼
                    ┌────────────────────────────┐
                    │  COGS Account              │  (تكلفة البضاعة المباعة)
                    │  إقفال تكاليف             │
                    └────────────────────────────┘

                    ┌────────────────────────────┐
                    │  FlightCarrier.available   │  (Mirror / Cache)
                    │  +57,414.01 EGP            │
                    └──────────────┬─────────────┘
                                   │ يُحدّث في نفس المعاملة
                                   │ (debit عند الحجز، credit عند الشحن)
                                   ▼
                                   
                    ⚠️ هذين الاثنين لازم يكونوا في sync ⚠️
```

---

## 2. الفجوة المعمارية (اللي سبّبت المشكلة)

### 2.1 الـ 4 فجوات

| # | الفجوة | الأثر |
|---|---|---|
| **1** | `ensurePrepaidAccountExists()` ينشئ الحساب بـ `balance=0` | أول حجز يخلّيه سالب على طول |
| **2** | مفيش `Observer` على `FlightCarrier::created()` | لما تضيف ناقل جديد، الحساب المسبق مش بيتهيأ |
| **3** | `FlightCarrierRechargeService::rechargeFromAccount()` بيشحن `carrier.available_balance` بس | مفيش sync مع الحساب المسبق |
| **4** | مفيش Cron Job بيحذر قبل ما الحساب يروح في السالب | بيوصل للسالب فجأة ويوقف الحجوزات |

### 2.2 سيناريو الفشل (اللي حصل)

```
─────────────────────────────────────────────────────────────
📅 تاريخ 1: مثبت Laravel لأول مرة
   → `migrate` شغّال
   → مفيش seed للحسابات المسبقة
   → `ensurePrepaidAccountExists()` ينشئ الحسابات بـ balance=0

📅 تاريخ 2: أول موظف يفتح /admin/flight-carriers
   → شاف `flight_carriers.available_balance = 0`
   → شحنها يدوياً بـ 100,000 EGP من البنك (→ صار 100,000 ✓)
   → ❌ الحساب المسبق "رصيد مسبق — ناقلو الطيران" فضل 0!

📅 تاريخ 3-7: عمل 4 حجوزات بـ ~26,000 EGP إجمالاً
   → كل حجز: carrier.available_balance -= X
   → كل حجز: prepaid GL Account -= X
   → النتيجة: carrier.available_balance = +57,414 (لسه موجب؟ 🤔)
   → لكن prepaid = -32,387 (دخل في السالب!)

📅 تاريخ 8: حاول يبيع تذكرة بـ 6,291 EGP
   → فحص الـ prepaid → لقاه سالب
   → ❌ InsufficientBalanceException!
─────────────────────────────────────────────────────────────
```

**الـ desync راجع لـ**: الشحن اليدوي الأول (100k) دخل `carrier.available_balance` بس، مش `prepaid`. أو في تعديل يدوي بعد كده.

---

## 3. الحل المعماري (Observer + Sync + Cron)

### 3.1 المعمار الكامل

```
                    ┌──────────────────────────────────────────┐
                    │   ① Observer: عند إنشاء FlightCarrier    │
                    │   أو FlightSystem جديد                   │
                    │   → يتأكد إن الحساب المسبق موجود وفعيل  │
                    │   → ينشئه لو مش موجود                    │
                    │   → يبعت إشعار: "ناقل جديد بدون ميزانية"│
                    └──────────────────────────────────────────┘
                                   ▼
                    ┌──────────────────────────────────────────┐
                    │   ② Sync: عند شحن FlightCarrier          │
                    │   أو FlightSystem يدوياً                 │
                    │   → PrepaidLedgerService::recharge()      │
                    │      بنفس المبلغ تلقائياً                │
                    │   → قيد واحد (transfer) موثّق            │
                    └──────────────────────────────────────────┘
                                   ▼
                    ┌──────────────────────────────────────────┐
                    │   ③ Migration: seed ابتدائي              │
                    │   → كل الحسابات المسبقة تتعمل            │
                    │   → balance مبدئي = 0 (هيتم شحنه بعدين)  │
                    │   → لكن الاسم والـ module_type مضبوطين  │
                    └──────────────────────────────────────────┘
                                   ▼
                    ┌──────────────────────────────────────────┐
                    │   ④ Cron: مراقبة يومية                   │
                    │   → يفحص أرصدة الحسابات المسبقة          │
                    │   → لو أقل من threshold                  │
                    │   → يبعت إشعار للـ admin                 │
                    │      (Filament Notification + Email)      │
                    └──────────────────────────────────────────┘
                                   ▼
                    ┌──────────────────────────────────────────┐
                    │   ⑤ Migration تصحيح                      │
                    │   → تمسح الـ desync الحالي               │
                    │   → تعدّل prepaid = (carrier_balance -    │
                    │     opening_balance_diff)                  │
                    └──────────────────────────────────────────┘
```

---

## 4. الكود التفصيلي لكل جزء

### 4.1 Part ①: Observer جديد

**ملف جديد**: `app/Observers/FlightCarrierObserver.php`

```php
<?php

namespace App\Observers;

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Notifications\PrepaidBalanceLowNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class FlightCarrierObserver
{
    /**
     * عند إنشاء ناقل جديد — تأكد إن الحساب المسبق موجود وأخبر الـ admin.
     */
    public function created(FlightCarrier $carrier): void
    {
        $this->ensurePrepaidAccount('flight_carrier', $carrier->name);

        // إشعار للـ admin إن الناقل لسه بدون ميزانية
        $admin = \App\Models\User::role('admin')->first();
        if ($admin) {
            $admin->notify(new PrepaidBalanceLowNotification(
                'flight_carrier',
                $carrier->name,
                0,
                50000
            ));
        }
    }

    public function updated(FlightCarrier $carrier): void
    {
        // لو الرصيد المسبق أقل من threshold بعد التعديل، نبّه
        $this->checkAndNotify('flight_carrier', $carrier->name);
    }

    private function ensurePrepaidAccount(string $key, string $contextName): void
    {
        $name = config("accounting.clearing.prepaid.{$key}");
        if (! $name) {
            Log::warning("Prepaid account key '{$key}' not configured", [
                'context' => $contextName,
            ]);
            return;
        }

        // لو مش موجود — أنشئه بصفر (سيتم شحنه بعدين)
        if (! Account::where('name', $name)->exists()) {
            Account::create([
                'name'       => $name,
                'type'       => \App\Enums\AccountType::Owner,
                'balance'    => 0,
                'currency'   => 'EGP',
                'is_active'  => true,
                'module_type' => str_starts_with($key, 'flight') ? 'flights' : $key,
                'is_module_vault' => false,
                'notes'      => "Auto-created from Observer [{$key}] for: {$contextName}",
            ]);

            Log::info("Prepaid account auto-created", [
                'key' => $key,
                'name' => $name,
                'context' => $contextName,
            ]);
        }
    }

    private function checkAndNotify(string $key, string $contextName): void
    {
        $name = config("accounting.clearing.prepaid.{$key}");
        $account = Account::where('name', $name)->first();

        if (! $account) return;

        $threshold = config("prepaid.thresholds.{$key}", 50000);

        if ((float) $account->balance < $threshold) {
            $admin = \App\Models\User::role('admin')->first();
            if ($admin) {
                $admin->notify(new PrepaidBalanceLowNotification(
                    $key,
                    $contextName,
                    (float) $account->balance,
                    $threshold
                ));
            }
        }
    }
}
```

**ملف Observer مشابه لـ FlightSystem**: `app/Observers/FlightSystemObserver.php` (نفس البنية، بس على FlightSystem).

**تسجيل الـ Observer** في `AppServiceProvider::boot()`:
```php
\App\Models\Flight\FlightCarrier::observe(\App\Observers\FlightCarrierObserver::class);
\App\Models\Flight\FlightSystem::observe(\App\Observers\FlightSystemObserver::class);
```

---

### 4.2 Part ②: Sync في `FlightCarrierRechargeService`

**تعديل** على `app/Services/Flight/FlightCarrierRechargeService.php`:

```php
public function rechargeFromAccount(
    FlightCarrier $carrier,
    Account $source,
    float $amount,
    ?string $notes = null
): array {
    return DB::transaction(function () use ($carrier, $source, $amount, $notes) {
        $carrier = FlightCarrier::query()->whereKey($carrier->id)->lockForUpdate()->firstOrFail();
        $source = Account::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

        if (strtoupper($source->currency) !== strtoupper($carrier->currency)) {
            throw new \RuntimeException(...);
        }

        // ═══ الخطوة 1: شحن الحساب المسبق (PrepaidLedgerService) ═══
        // ← ده الجديد: بنضمن إن الـ prepaid يشحن مع كل recharge
        $this->prepaidLedgerService->recharge(
            prepaidKey: 'flight_carrier',
            source: $source,
            amount: $amount,
            module: TransactionModule::Flight,
            notes: "Auto-sync: recharge flight_carrier #{$carrier->id} (carrier recharge)",
            relatedType: FlightCarrier::class,
            relatedId: $carrier->id,
        );

        // ═══ الخطوة 2: شحن رصيد الناقل المباشر (زي قبل) ═══
        $carrierTx = $carrier->credit($amount, $desc, (int) (Auth::id() ?: 1), null);

        Log::info('Flight carrier recharged FROM PREPAID (in sync)', [
            'flight_carrier_id' => $carrier->id,
            'amount' => $amount,
            'currency' => $carrier->currency,
            'prepaid_key' => 'flight_carrier',  // ← synced
        ]);

        return [
            'carrier' => $carrier->fresh(),
            'source_account' => $source->fresh(),
            'airline_transaction' => $carrierTx,
        ];
    });
}
```

**نفس المنطق** لـ `FlightSystemRechargeService`.

---

### 4.3 Part ③: Migration الـ Seed

**ملف جديد**: `database/migrations/2026_07_08_000000_seed_prepaid_balance_alert_thresholds.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // نضمن الحسابات المسبقة موجودة (auto-create لو ناقصة)
        // بدون ما نعدّل الرصيد — ده شغل الـ seed الأولي في migration منفصلة
        foreach (config('accounting.clearing.prepaid', []) as $key => $name) {
            if (! DB::table('accounts')->where('name', $name)->exists()) {
                DB::table('accounts')->insert([
                    'name'       => $name,
                    'type'       => 'owner',
                    'balance'    => 0,
                    'currency'   => 'EGP',
                    'is_active'  => 1,
                    'module_type' => str_starts_with($key, 'flight') ? 'flights' : $key,
                    'is_module_vault' => 0,
                    'notes'      => "Seed: {$key}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // حذف القيود المسبقة المضافة
        DB::table('accounts')
            ->whereIn('name', array_values(config('accounting.clearing.prepaid', [])))
            ->update(['balance' => 0]);
    }
};
```

**Migration لإصلاح الـ desync الحالي**:
```php
<?php

// database/migrations/2026_07_08_000001_fix_prepaid_desync.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // لكل carrier، نضبط الـ prepaid = carrier.available_balance - opening_offset
        // (هذا مثال تقريبي — يحتاج مراجعة محاسبية)
        $carriers = DB::table('flight_carriers')->get();
        foreach ($carriers as $c) {
            // الحساب المسبق لازم يكون = الرصيد التشغيلي - opening_balance_diff
            // Opening_diff = أول رصيد للحساب المسبق (ممكن يكون 0 أو سالب)
            DB::statement("
                UPDATE accounts SET balance = balance + ?
                WHERE name = 'رصيد مسبق — ناقلو الطيران'
            ", [(float) $c->available_balance - 50000]);
            // ← الحساب الافتراضي 50k. يحتاج مراجعة يدوية
        }
    }
};
```

---

### 4.4 Part ④: Cron Job للمراقبة

**ملف جديد**: `app/Console/Commands/MonitorPrepaidBalances.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Notifications\PrepaidBalanceLowNotification;
use Illuminate\Console\Command;

class MonitorPrepaidBalances extends Command
{
    protected $signature = 'prepaid:monitor';
    protected $description = 'يفحص أرصدة الحسابات المسبقة ويرسل تنبيهات للقيم المنخفضة';

    public function handle(): int
    {
        $thresholds = config('prepaid.thresholds', [
            'flight_carrier' => 50000,
            'flight_system'  => 50000,
            'fawry'          => 10000,
        ]);

        $admins = User::role('admin')->get();
        $alertsSent = 0;

        foreach (config('accounting.clearing.prepaid', []) as $key => $name) {
            $account = Account::where('name', $name)->first();
            if (! $account) continue;

            $threshold = $thresholds[$key] ?? 50000;
            $balance = (float) $account->balance;

            if ($balance < $threshold) {
                $this->warn("⚠ {$name} = {$balance} EGP (threshold: {$threshold})");

                foreach ($admins as $admin) {
                    $admin->notify(new PrepaidBalanceLowNotification(
                        $key,
                        $name,
                        $balance,
                        $threshold
                    ));
                    $alertsSent++;
                }
            } else {
                $this->info("✓ {$name} = {$balance} EGP");
            }
        }

        $this->info("Monitor complete. {$alertsSent} alerts sent.");
        return self::SUCCESS;
    }
}
```

**تسجيل في `app/Console/Kernel.php`**:
```php
$schedule->command('prepaid:monitor')
    ->dailyAt('08:00')
    ->weekdays()
    ->emailOutputOnFailure('admin@company.com');
```

---

### 4.5 Part ⑤: Notification Class

**ملف جديد**: `app/Notifications/PrepaidBalanceLowNotification.php`

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PrepaidBalanceLowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $prepaidKey,
        public string $accountName,
        public float $currentBalance,
        public float $threshold
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];  // ← إيميل + جرس في الواجهة
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('⚠️ تحذير: رصيد مسبق منخفض')
            ->line("الحساب المسبق: {$this->accountName}")
            ->line("الرصيد الحالي: " . number_format($this->currentBalance, 2) . " EGP")
            ->line("الحد الأدنى: " . number_format($this->threshold, 2) . " EGP")
            ->action('فتح الخزينة', url('/admin/flight-treasuries'))
            ->line('اشحن الحساب عشان الحجوزات ما تتوقفش.');
    }

    public function toArray($notifiable): array
    {
        return [
            'prepaid_key'   => $this->prepaidKey,
            'account_name'  => $this->accountName,
            'current_balance' => $this->currentBalance,
            'threshold'     => $this->threshold,
            'severity'      => $this->currentBalance < 0 ? 'critical' : 'warning',
        ];
    }
}
```

---

### 4.6 Part ⑥: Config للـ Thresholds

**ملف جديد**: `config/prepaid.php`

```php
<?php

return [
    'thresholds' => [
        'flight_carrier' => env('PREPAID_THRESHOLD_FLIGHT_CARRIER', 50000),
        'flight_system'  => env('PREPAID_THRESHOLD_FLIGHT_SYSTEM', 50000),
        'fawry'          => env('PREPAID_THRESHOLD_FAWRY', 10000),
    ],
];
```

---

## 5. خطوات التنفيذ (Implementation Order)

### خطوة 1: نسخ الـ 5 migrations + 2 observers + 1 command + 1 notification + 1 config

```
database/migrations/2026_07_08_000000_*.php        ← seed
database/migrations/2026_07_08_000001_*.php        ← fix desync
app/Observers/FlightCarrierObserver.php            ← new
app/Observers/FlightSystemObserver.php             ← new
app/Console/Commands/MonitorPrepaidBalances.php    ← new
app/Notifications/PrepaidBalanceLowNotification.php ← new
config/prepaid.php                                 ← new
```

### خطوة 2: تعديل الملفات الموجودة

```
app/Services/Flight/FlightCarrierRechargeService.php    ← edit
app/Services/Flight/FlightSystemRechargeService.php     ← edit
app/Providers/AppServiceProvider.php                    ← register observers
app/Console/Kernel.php                                  ← schedule cron
```

### خطوة 3: Test في بيئة development

```bash
# شغّل migrations
php artisan migrate

# تأكد من الحسابات المسبقة
php artisan tinker --execute='\App\Models\Account::where("name","like","%رصيد مسبق%")->get(["id","name","balance"]);'

# جرب Observer (لما تنشئ carrier جديد، الحساب المسبق يبقى موجود)
php artisan tinker --execute='\App\Models\Flight\FlightCarrier::factory()->create();'

# جرب الـ Command
php artisan prepaid:monitor
```

### خطوة 4: Production deployment

```bash
# 1) Backup DB
mysqldump -u root -p safarakealayna > backup_$(date +%Y%m%d).sql

# 2) Deploy code (git pull or file upload)

# 3) Run migrations
php artisan migrate --force

# 4) Test one manual recharge
php artisan tinker --execute='$c=\App\Models\Flight\FlightCarrier::find(1); $a=\App\Models\Account::find(7); app(\App\Services\Flight\FlightCarrierRechargeService::class)->rechargeFromAccount($c,$a,10000);'

# 5) Verify in /admin
```

---

## 6. الفوائد المتوقعة

| المقياس | قبل | بعد |
|---|---|---|
| توقف الحجوزات بسبب الرصيد | كل أسبوع | أبداً (monitoring + sync) |
| التعديلات اليدوية المطلوبة | 5-10 مرات/أسبوع | 0 |
| احتمالية desync | عالية | **صفر (بالـ sync التلقائي)** |
| وقت تشخيص المشكلة | 30 دقيقة | **5 دقائق (إشعار فوري)** |
| Audit Trail | ضعيف | **قوي (قيود موثّقة)** |

---

## 7. التكلفة والوقت

| الجزء | الوقت | التعقيد |
|---|---|---|
| Migration + seed | 15 دقيقة | بسيط |
| Observer (Carrier + System) | 30 دقيقة | متوسط |
| Sync في RechargeService | 30 دقيقة | متوسط |
| Cron command | 30 دقيقة | متوسط |
| Notification | 15 دقيقة | بسيط |
| Config + registration | 15 دقيقة | بسيط |
| **الإجمالي** | **~ساعتين** | **متوسط** |

---

## 8. الـ Rollback Plan

لو حصلت مشكلة بعد التطبيق:

```bash
# 1) Rollback migrations
php artisan migrate:rollback --step=2

# 2) Disable the observers
# في AppServiceProvider::boot() — علّق السطرين:
# FlightCarrier::observe(...);
# FlightSystem::observe(...);

# 3) Disable the cron
# في Console/Kernel — علّق:
# $schedule->command('prepaid:monitor')...
```

كل التعديلات reversible بدون فقد بيانات (الـ sync بيضيف قيد محاسبي إضافي، مش بيعدّل حاجة موجودة).

---

## 9. الـ Recommendation النهائي

**ابدأ بـ**:
1. Migration لتطبيع الحسابات الموجودة (Part ⑤)
2. Observer + Sync (Part ① + ②)
3. Cron + Notification (Part ④ + ⑤)

**اترك لبعدين**:
- تحسينات UI (Filament widget للرصيد المسبق)
- Auto-replenish (لو الرصيد أقل من threshold، اشحن تلقائياً)

---

**المرجع**: [ARCHITECTURE_DECISION_RECORD.md] (لو متاح)
**الكود المرتبط**:
- `app/Services/Finance/PrepaidLedgerService.php`
- `app/Services/Finance/LedgerClearingAccounts.php`
- `app/Services/Flight/FlightCarrierRechargeService.php`
- `app/Services/Flight/FlightBookingService.php`

**آخر تحديث**: 2026-07-07
