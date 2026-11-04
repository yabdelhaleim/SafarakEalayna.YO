<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CustomerCreditLedger extends Model
{
    use HasFactory;

    protected $table = 'customer_credit_ledger';

    public const SOURCE_REFUND = 'refund';
    public const SOURCE_OVERPAYMENT = 'overpayment';
    public const SOURCE_DEPOSIT = 'deposit';
    public const SOURCE_BOOKING_SETTLEMENT = 'booking_settlement';
    public const SOURCE_DISBURSEMENT = 'disbursement';
    public const SOURCE_REVERSAL = 'reversal';

    protected $fillable = [
        'customer_id',
        'company_id',
        'source_type',
        'source_id',
        'amount',
        'balance_after',
        'currency',
        'notes',
        'created_by',
        'reversed_at',
        'reversal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * حساب الرصيد الدائن المتاح الحالي (مجموع الحركات غير المعكوسة).
     *
     * ⚠️ قراءة غير قفلية (non-locking read): تُستخدم للعرض والتقارير فقط.
     * أي مسار يستهلك الرصيد الدائن يجب أن يستخدم
     * {@see static::lockedAvailableCredit()} بدلها — لأن هذه القراءة قد
     * ترى snapshot قديماً تحت عزل REPEATABLE READ.
     */
    public static function getAvailableCredit(?int $customerId, ?int $companyId = null, string $currency = 'EGP'): float
    {
        $query = static::query()
            ->whereNull('reversed_at')
            ->where('currency', $currency);

        if ($companyId) {
            $query->where('company_id', $companyId);
        } elseif ($customerId) {
            $query->where('customer_id', $customerId);
        } else {
            return 0.0;
        }

        return round((float) $query->sum('amount'), 2);
    }

    /**
     * قراءة قفلية (SELECT ... FOR UPDATE) للرصيد الدائن المتاح.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     * لماذا القراءة القفلية ضرورية (Phase 8 — Concurrency & Cash Safety):
     *
     * الرصيد الدائن المتاح (L) *تجميع* (SUM) على عدة صفوف — فقفل صف
     * العميل وحده لا يكفي: تحت عزل REPEATABLE READ يقرأ المجموع من
     * snapshot المعاملة، فقد يقرأ رصيداً قديماً لو التزمت معاملة أخرى
     * حركة صرف بين قفلنا وقراءتنا ⇒ صرف مزدوج (رصيد دائن سالب + عجز
     * نقدي في الخزينة).
     *
     * القراءة القفلية تحسم الأمرين:
     *   ① ترى آخر حالة مُلتزَمة (latest committed) لأنها locking read
     *      تتجاوز snapshot المعاملة — فالرصيد المقروء حديث دائماً.
     *   ② تفرض gap-locks على نطاق العميل/الشركة في الفهرس، فتمنع أي
     *      إدراج حركة دفتر جديدة لنفس النطاق حتى انتهاء المعاملة — فلا
     *      يمكن لطلب صرف متزامن أن يزيد الخصم فوق الرصيد المقروء.
     *
     * ملاحظة: على SQLite تُترجم `lockForUpdate()` إلى استعلام بلا `FOR
     * UPDATE` (المحرك لا يدعمها) — والاختبارات تعمل على SQLite، فتبقى
     * القفل منطقياً في طبقة التطبيق بينما الحماية الحقيقية تكون على
     * MySQL/InnoDB في بيئة الإنتاج.
     *
     * يجب استدعاؤها داخل معاملة قائمة (DB::transaction) — وإلا فُكّ
     * القفل فور انتهاء الاستعلام.
     */
    public static function lockedAvailableCredit(?int $customerId, ?int $companyId = null, string $currency = 'EGP'): float
    {
        $query = static::query()
            ->whereNull('reversed_at')
            ->where('currency', $currency);

        if ($companyId) {
            $query->where('company_id', $companyId);
        } elseif ($customerId) {
            $query->where('customer_id', $customerId);
        } else {
            return 0.0;
        }

        // القفل يُقرأ على مستوى الصفوف، فنجمع في PHP بعد قفل النطاق.
        $rows = $query->lockForUpdate()->get();

        return round((float) $rows->sum(fn ($row) => (float) $row->amount), 2);
    }

    /**
     * تسجيل حركة جديدة في دفتر رصيد العميل الموحد (Immutable Entry).
     *
     * @param array{
     *     customer_id?: int|null,
     *     company_id?: int|null,
     *     source_type: string,
     *     source_id?: int|null,
     *     amount: float,
     *     currency?: string,
     *     notes?: string|null,
     *     created_by?: int|null,
     *     balance_after?: float|null
     * } $data
     */
    public static function recordEntry(array $data): self
    {
        $customerId = $data['customer_id'] ?? null;
        $companyId = $data['company_id'] ?? null;
        $currency = $data['currency'] ?? 'EGP';
        $amount = round((float) $data['amount'], 2);

        if (abs($amount) < 0.005) {
            throw new \InvalidArgumentException('مبلغ الحركة في دفتر الرصيد يجب أن يكون أكبر من الصفر.');
        }

        // قفل الرصيد الحالي وحساب الرصيد اللاحق.
        //
        // `balance_after` اختياري: يمرّره مسار الصرف المحمي (Phase 8) بقيمة
        // مقروءة بقراءة قفلية (lockedAvailableCredit) — لأن المجموع هنا
        // محسوب بقراءة غير قفلية قد يختلف عن الرصيد المقروء فعلياً تحت
        // عزل REPEATABLE READ.
        $authoritativeBalance = $data['balance_after'] ?? null;

        if ($authoritativeBalance !== null && is_numeric($authoritativeBalance)) {
            $balanceAfter = round((float) $authoritativeBalance, 2);
        } else {
            $currentBalance = static::getAvailableCredit($customerId, $companyId, $currency);
            $balanceAfter = round($currentBalance + $amount, 2);
        }

        return static::create([
            'customer_id' => $customerId,
            'company_id' => $companyId,
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'] ?? null,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'currency' => $currency,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    /**
     * عكس حركة سابقة بحركة عكسية مطابقة دون تعديل السجل الأصلي.
     */
    public function reverse(int $userId, ?string $reason = null): self
    {
        if ($this->reversed_at !== null) {
            throw new \RuntimeException('هذه الحركة معكوسة بالفعل.');
        }

        return DB::transaction(function () use ($userId, $reason) {
            $now = now();
            $this->reversed_at = $now;
            $this->save();

            // إنشاء حركة عكسية معاكسة في الإشارة ومربوطة محاسبياً بالحركة الأصلية
            $reversal = static::recordEntry([
                'customer_id' => $this->customer_id,
                'company_id' => $this->company_id,
                'source_type' => static::SOURCE_REVERSAL,
                'source_id' => $this->id,
                'amount' => -1 * (float) $this->amount,
                'currency' => $this->currency,
                'notes' => 'عكس حركة #' . $this->id . ($reason ? ": {$reason}" : ''),
                'created_by' => $userId,
            ]);

            $reversal->reversed_at = $now;
            $reversal->reversal_entry_id = $this->id;
            $reversal->save();

            $this->reversal_entry_id = $reversal->id;
            $this->save();

            return $reversal;
        });
    }
}
