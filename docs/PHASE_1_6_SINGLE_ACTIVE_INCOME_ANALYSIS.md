# Phase 1.6 — Single-Active-Income Rule Analysis

**Date:** 2026-08-18
**Scope:** Read-only analysis to clarify whether the WIP's "single active income" rule
        at `TransactionService.php:650–675` actually fixes defect **B-2**
        ("كل دفعة Flight بتسجل معاملتين متعاكستين (income + transfer) بدل واحدة").
**Status:** Analysis complete. Decision pending user approval for Phase 3 (B-2 fix).

---

## 1 — What the rule does

`App\Services\Finance\TransactionService::recordJournalTransfer()` blocks a second
**ACTIVE** income transaction on the same `(related_type, related_id)` slot.

A row is "ACTIVE" if its `notes` is NULL or does NOT start with the project's
reversal prefix (`عكس:` / `عكس `).

```
if ($typeValue === TransactionType::Income->value && $relatedType && $relatedId) {
    $existingActiveIncome = DB::table('transactions')
        ->where('related_type', $relatedType)
        ->where('related_id', $relatedId)
        ->where('type', TransactionType::Income->value)
        ->where(fn ($q) =>
            $q->whereNull('notes')
              ->orWhere(fn ($q2) =>
                  $q2->where('notes', 'not like', 'عكس:%')
                     ->where('notes', 'not like', 'عكس %')))
        ->exists();
    if ($existingActiveIncome) {
        throw new InvalidArgumentException('Duplicate income transaction blocked...');
    }
}
```

## 2 — Why it was introduced (FC-AUDIT 2026-08-12, commit `d6c67cb`)

The Bus module had been registering **two** income transactions per booking
(sale + payment) → trial balance income sum was **doubled**.

Path C extension (2026-08-14): when the original sale is REVERSED with the
`عكس:` prefix, the slot becomes available again so
`HajjUmraBookingService::repostIncomeTransaction()` can re-issue the sale after
a price change.

## 3 — Does the rule fix B-2?

**❌ NO. It only moves the duplicate.**

| Aspect | Before D3 fix | After D3 fix (current WIP) |
|--------|---------------|---------------------------|
| Duplicate income on `(FlightBooking, $id)`? | ✅ Caught by the guard | ✅ No (rekeyed to FlightPayment) |
| Duplicate income **overall**? | ❌ Yes | ❌ **Still YES** — one-per-FlightPayment instead of one-per-FlightBooking |
| Net financial effect | Income doubled per booking | **The same money is recorded TWICE**: once at `recordSaleToCustomer` (booking creation) and again per `recordIncome` (per payment) |

### Evidence in code

`FlightBookingService::addPayment()` at lines 2009–2011 (the comment) says:

> *"تحصيل الدفعة من حساب العميل (تخفيض المديونية) إلى الخزينة. الإيراد مُسجَّل
> مسبقاً عند إنشاء الحجز في recordSaleToCustomer (clearing → customer). هذا القيد
> محايد (neutral) — تحويل من مديونية → نقدية فقط"*

But line 2079 still calls `recordIncome`:

```php
$transaction = $this->transactionService->recordIncome([
    'amount' => $transferAmount,
    'converted_amount' => $convertedAmount,
    'exchange_rate' => $booking->exchange_rate ?? null,
    'to_account_id' => $accountId,                  // cashbox
    'contra_account_id' => $customerAccount->id,    // customer
    'module' => TransactionModule::Flight->value,
    'related_type' => FlightPayment::class,         // ← rekey trick
    'related_id' => $payment->id,                   // ← rekey trick
    'notes' => $paymentNotes,
]);
```

The comment at line 2013–2034 explicitly documents the rekey:

> *"Previously this called recordIncome with `related_type=FlightBooking + related_id=$booking->id`,
> which caused the duplicate-income guard to reject the SECOND and subsequent payment
> for the same booking. The fix is to (a) create the FlightPayment row FIRST, then (b) call
> recordIncome with `related_type=FlightPayment + related_id=$payment->id`."*

**This is a guard bypass, not a guard satisfaction.**

## 4 — What is missing for B-2 to be truly fixed

Three plausible directions. Only one is correct.

| Option | Description | Verdict |
|--------|-------------|---------|
| **A** ✅ | Remove `recordIncome` at `FlightBookingService.php:2079`; replace with `recordTransfer` (customer → cashbox). Matches the comment at lines 2009–2011. The single-active-income rule continues to enforce "one income per booking" naturally — no rekey needed. | **Recommended. True B-2 fix.** |
| **B** ⚠️ | Keep `recordIncome` per payment; remove income at booking creation. | Breaks existing accounting flows; wide audit needed. |
| **C** ❌ | Status quo (WIP as-is). | B-2 remains semantically broken — only the duplicate-key error is suppressed. |

## 5 — Migration status

| Check | Result |
|-------|--------|
| `php artisan migrate --pretend` | `Nothing to migrate` |
| `Schema::hasTable('refund_audit_logs')` | `true` |
| `Schema::hasColumn('refund_requests', 'idempotency_key')` | `true` |

The two new migrations were applied to the local MySQL DB before this session
started (likely by the previous session). **No action needed.**

## 6 — Environment verification

| Check | Value | Verdict |
|-------|-------|---------|
| `APP_ENV` | `local` | Not staging — but no `.env.staging` file exists |
| `DB_CONNECTION` | `mysql` | Local DB |
| `DB_HOST` | `127.0.0.1` | Local — clearly NOT production |
| `DB_DATABASE` | `safarakealayna` | Same name as production, but on local host |
| `.env.staging` file | missing | No separate staging config |
| Customers in DB | 570 | Dev/test volume |
| Flight bookings | 0 | No real flight data |
| Hajj/Umra bookings | 0 | No real Hajj data |
| Visa bookings | 2 | Minimal |

**The local MySQL `safarakealayna` is the user's dev/test environment** (no
separate staging server configured). It is clearly NOT production (local host,
root/no-password, minimal data). Running migrations here is acceptable per the
directive "test/staging فقط، مش على أي حاجة قريبة من إنتاج".

## 7 — Decision (pending user approval)

Recommended direction: **Option A**.

Implementation plan (for Phase 3):
1. Remove `recordIncome` at `FlightBookingService.php:2079`.
2. Replace with `recordTransfer` (type=transfer): `customerAccount → cashbox`.
3. Write test: N partial payments on one booking → exactly 1 income (at booking
   creation) + N transfers, sum of transfers = sum of payments.
4. Verify the single-active-income rule still works naturally (no rekey needed).
5. Do NOT touch historical data — the 22 legacy cases are in scope of Phase 4
   (separate historical-data correction, requires explicit user approval).
6. Compare test baseline: the "single-active-income" failure category should
   decrease, not increase.

## 8 — References

- `app/Services/Finance/TransactionService.php:650–675` — the single-active-income guard
- `app/Services/Flight/FlightBookingService.php:1831–2152` — `addPayment` (the B-2 site)
- `app/Services/Flight/FlightBookingService.php:2009–2011` — the comment promising "neutral" payment
- `app/Services/Flight/FlightBookingService.php:2013–2034` — the rekey-trick commentary
- Commit `d6c67cb` — original FC-AUDIT guard (Bus module)
- Commit `eb4cef6` — WIP hardening across modules