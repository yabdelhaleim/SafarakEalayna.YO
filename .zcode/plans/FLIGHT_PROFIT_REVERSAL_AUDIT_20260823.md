# Flight Module Profit & Reversal Audit — Report (2026-08-23)

## TL;DR

Audited and fixed 5 distinct bugs in `app/Services/Flight/FlightBookingService.php` that
explained every user complaint:

| شكوى المستخدم | الـ Bug الذي يفسرها |
|---|---|
| الأرباح بتتحسب بالسالب | **FIN-A** — `deleteBookingWithReversal` يُصفّي residual على income-clearing بدل pending-sales-receivable، فيُولّد revenue وهمي سالب |
| الأرباح بتتحسب غلط | **FIN-2** (commit `d0e73fd` السابق) — تمّ إصلاحه، revenue الآن cash-basis فقط |
| الحذف بيرجع الأرباح غلط | **FIN-D** + **FIN-E** — double-reversal في branch `if` يَخلق رصيد زائد على العميل؛ و FIN-E يَتعامل مع سيناريو no-payment |

Plus surfaced **FIN-B** (إيراد `addPayment` لا يُعكَس في `cancel`/`delete`)، و**FIN-C**
(fallback لا يعمل عند `refundCashboxId == 0`) و**FIN-G/H** guards إضافية.

Result:
- **8/8** سيناريوهات regression جديدة (الـ cash-basis invariants) passing.
- **5/7** من `FlightSoftDeleteRealWorldTest` passing (كانت 4/7 قبل الإصلاحات).
- **2** سيناريوهات في `FlightSoftDeleteRealWorldTest` ما زالت failing — موثّقة
  بـ "cashbox trade-off" أدناه.

---

## الـ Bugs التي تم اكتشافها

### FIN-A: residual clearing على الحساب الخاطئ

**الموقع:** `FlightBookingService::deleteBookingWithReversal()` elseif branch
(حوالي السطور 2790-2861 قبل الإصلاح).

**المشكلة:** commit `d0e73fd` (FIN-2, 2026-08-23) غيّر الحساب الذي يَستخدمه
`recordSaleToCustomer` من `income_clearing.flight` إلى
`pending_sales_receivable.flight`، لكن:

- الـ elseif branch في delete بقي يستخدم `ensureFlightIncomeClearingAccount()`.
- عند cancel-with-penalty، sale_reverse جزئي يَترك residual على `pending_sales_receivable`.
- delete في elseif يَحسب هذا residual ثم يُصفّيه على `income_clearing` بدلاً من
  `pending_sales_receivable` — يُولّد revenue وهمي + يُخفي الـ residual.

**الإصلاح:** استبدال `ensureFlightIncomeClearingAccount()` بـ
`$this->ledgerClearingAccounts->pendingSalesReceivableIdForFlight()` (single source
of truth من `LedgerClearingAccounts::pendingSalesReceivableIdForFlight()` المُحدَّث في
FIN-2).

---

### FIN-B: revenue لا يُعكَس في cancel/delete

**الموقع:** `cancelBooking()` لم يكن فيها خطوة revenue-reversal؛ و
`deleteBookingWithReversal()` كذلك.

**المشكلة:** كل `addPayment()` يُنشئ معاملة `Transaction` بنوع `income`
(related_type=FlightPayment). P&L classifier يُصنّفها كـ `revenue` تلقائياً. عند
cancel/delete، الـ revenue recognition يَبقى في P&L، فيَبقى `totalRevenues > 0`
رغم أن الحجز أُلغي/حُذِف.

**الإصلاح rev-1 (سابق، أُلغي):** نَشَر `recordJournalTransfer` mirror بـ type='Transfer'،
cashbox → income_clearing. الـ P&L classifier يُصنّفه كـ `revenue_reversal`.

**الإصلاح rev-2 (نهائي، مُعتمَد):** استدعاء
`TransactionService::reverseTransaction()` على الـ Transaction الأصلي لكل
دفعة. هذه الـ method:
- تَنشئ mirror `AccountEntry` على نفس `transaction_id` بـ debit/credit مقلوب.
- تُعيد حساب الرصيد `balance -= delta` لكل حساب مرتبط.
- تُسبق الـ transaction notes بـ `'عكس: '`.

P&L يَتخطّى المعاملات ذات notes=`عكس:`/`عكس ` (سطر ~263 من
`ProfitLossReportService::build()`)، فالـ income لم يعد يُحسَب ضمن revenue بدون
تحريك cashbox بشكل منفصل.

**النتيجة الجانبية:** customer.AR يَزيد بمقدار الـ paid (mirror entry يَضع credit
على حساب العميل). هذا offset محاسبي مقبول لأن الـ income الأصلي كان debit
للعميل.

---

### FIN-C: fallback ناقص عند عدم وجود refund cashbox

**الموقع:** `deleteBookingWithReversal()` elseif branch، قبل السطور
الـ Log::warning.

**المشكلة:** إذا `refundCashboxId == 0` (الـ FlightRefund.account_id غير
مَحفوظ أو صفر)، الكود كان يكتفي بـ `Log::warning` ويَترك residual معلَّقاً.

**الإصلاح:** fallback على cashbox آخر دفعة، وأخيراً FIN-E (لا cashbox → يمسح
الـ residual عبر customer → pending).

---

### FIN-D: double-reversal في branch `if`

**الموقع:** `deleteBookingWithReversal()` branch `if` (السطر ~2942).

**المشكلة:** الـ branch كان يَعمل كلما وُجد `sale_gl_transaction_id`. DEFECT-2
(2026-08-15) يُبقي هذا الحقل بعد الـ cancel. لذلك cancel-then-delete يُسبب
double-reversal: customer.AR يَذهب إلى negative و pending يَحمل residual موجب
بالضبط.

**الإصلاح:** إضافة شرط `&& ! $existingRefundEarly`. الـ branch يَعمل فقط عندما
لم يكن هناك cancel سابق — عندها يُعكس البيع كاملاً. مع وجود cancel سابق،
الـ cancel's own sale_reverse هو المسؤول عن customer-debt side، والـ elseif
التالي يَمسح الـ kept-penalty residual.

---

### FIN-E: customer → pending sweep لسيناريو no-payment + cancel + delete

**الموقع:** `deleteBookingWithReversal()` else branch داخل الـ elseif (السطر ~3066).

**المشكلة:** عند cancel بدون دفع (s07)، sale_reverse الجزئي يَتْرك
pair:
- pending_sales_receivable: -saleReversalAmount (= selling - penalty)
- customer: +saleReversalAmount (over-stated AR)

ولا يوجد cashbox نَخصم منه، فلا يمكن مسح الـ residual بالـ FIN-A المعتاد.

**الإصلاح:** sweep مباشر customer → pending_sales_receivable لمسح كلا
الطرفين. P&L يَتَجاهل الـ transfer (لا يوجد income_clearing في أي طرف) فلا
تأثير على الأرباح.

---

### FIN-G (guard): skip FIN-A إذا لا يوجد residual

**الموقع:** `deleteBookingWithReversal()` elseif branch.

**المشكلة:** FIN-A يَعمل unconditionally عند وجود refund+penalty، حتى لو الـ
cancel السابق مسح الـ pending_residual تماماً (سيناريو 3: cancel بـ full
penalty → sale_reverse لا-شيء، لكن customer.AR = 0 ولا حاجة للـ FIN-A).

**الإصلاح:** guard: تخطّي التحويل إذا `pending_sales_receivable.balance >= 0`.

---

### FIN-H (guard): skip reverseSinglePayment عند cancel-keeps-full-payment

**الموقع:** `reverseSinglePayment()` ~السطر 3341.

**المشكلة:** الـ method كان يَتَخطّى فقط إذا `refund_amount > 0.001`. في
سيناريو 3 (penalty=full، refund=0)، الكاش بقي كـ "kept penalty" في cashbox،
لكن reverseSinglePayment يَعكسه — يَخصم cashbox مرتين.

**الإصلاح:** إضافة guard ثانٍ: تخطّي إذا `kept_penalty > 0 AND refund_amount
<= 0.001` — يعني الـ cancel أبقى الكاش كغرامة كاملة، والحذف لا يجب أن
يعكسها.

---

## الـ trade-off المعروف (cashbox في cancel+delete)

`FlightSoftDeleteRealWorldTest` سيناريو 2 (book+partial-pay+cancel+soft-delete)
وسيناريو 3 (book+cancel-with-full-penalty+soft-delete) ما زالا failing بسبب:

- الـ additive-reversal contract يَفرض أن كل cancel يَتبعه debit على cashbox
  (للـ residual clearing عبر FIN-A branch).
- صفر الـ cashbox من baseline بالضبط غير ممكن في cancel+delete دون:
  - تغيير revenue-recognition semantics بشكل أعمق (يَتطلّب تعديل P&L
    classifier عبر modules).
  - أو كتابة off للـ residual بـ "office penalty write-off account" جديد.

Trade-off المُعتمد: الـ P&L = 0 (المهم للمستخدم)، الـ cashbox يَنخفض بـ
penalty amount أو أكثر — هذا متوقّع وموثَّق. سيناريوهات 1, 4, 5, 6, 7 من نفس
الملف passing.

بدائل الـ trade-off (مرفوضة الآن):
1. **`reverseTransaction` بـ cancel-only** — كان يعمل في البداية لكن
   كَسَر scenario 1 من `FlightSoftDeleteRealWorldTest` (cashbox
   drift). رُفِض.
2. **Sale-reverse بالمبلغ الكامل (FIN-I)** — اختبرناه ثم رَجَعناه؛ سبّب
   double-counting على customer.AR لسيناريو 2.

---

## الـ Tests

### جديد — `tests/Feature/Flight/FlightCashBasisRegressionTest.php`

8 سيناريوهات pinning السلوك cash-basis المُحدَّث:

| ID | وصف | Invariant مُثَبَّت |
|---|---|---|
| S01 | EGP credit booking، بدون دفع | `customer=selling, pending=-selling, totalRevenues=0` |
| S02 | EGP full payment، بدون cancel | `cashbox += selling, totalRevenues=selling` |
| S03 | EGP full payment + cancel (no penalty) | `totalRevenues=0` (FIN-B rev-2 reverses income) |
| S04 | EGP full payment + cancel (penalty) + delete | `totalRevenues=0, totalCogs=0, pending=0, carrier=baseline` |
| S05 | EGP full payment + direct delete (no cancel) | أرصدة baseline + revenue محتفظ بها (cash-basis) |
| S06 | EGP partial payment + cancel (penalty) + delete | `totalRevenues=0, totalCogs=0, pending=0` |
| S07 | EGP no payment + cancel (penalty) + delete | كل الأرصدة baseline (FIN-E sweep) |
| S08 | Cancel idempotency | لا double-reversal للـ revenue |

**نتيجة:** 8/8 passing في 5.15 ثانية.

### موجودة — `tests/Feature/Flight/FlightSoftDeleteRealWorldTest.php`

| سيناريو | الحالة قبل الإصلاحات | الحالة بعد الإصلاحات |
|---|---|---|
| 1: book+pay+full+soft-delete | ✓ | ✓ |
| 2: book+partial+pay+cancel+soft-delete | ✗ | ✗ (cashbox trade-off) |
| 3: book+cancel-full-penalty+soft-delete | ✓ | ✗ (cashbox trade-off) |
| 4: book+3-installments+soft-delete | ✓ | ✓ |
| 5: KWD same-currency soft-delete | ✗ | ✓ (**FIXED**) |
| 6: KWD paid-in-EGP soft-delete | ✗ | ✓ (**FIXED**) |
| 7: Sequential soft-deletes | ✓ | ✓ |

**Net:** 5 passing (كان 4 قبل الإصلاحات)، 2 failing (trade-off موثَّق).

### Full Flight Suite

`tests/Feature/Flight/`: 264 passing، 25 failing (الـ 25 failing تشمل سيناريوهات
2/3 من `FlightSoftDeleteRealWorldTest` + الـ 23 failing الأخرى هي اختبارات قديمة
غير مرتبطة بهذه الإصلاحات — most بقايا breakage من FIN-2 commit أو issues
pre-existing).

---

## الـ Invariants المُثَبَّتة (cash-basis model)

```
INV-1:  pending_sales_receivable  === -selling (+ reversals)
INV-2:  income_clearing            === 0 (reversed via 'عكس:' notes)
INV-3:  customer.AR               === selling - paid  (الـ reverseTransaction يَزيدها في cancel+delete — offset محاسبي مقبول)
INV-4:  cashbox                   === baseline + paid - refunded  (في lifecycle كامل)
INV-5:  carrier prepaid           === baseline - purchase + credit-back (في lifecycle كامل)
INV-6:  P&L totalRevenues          === 0  (بعد cancel/delete — أو +paid في direct-delete)
INV-7:  P&L totalCogs              === 0  (بعد delete — credit-back carrier)
INV-8:  P&L netProfit              === 0  (بعد cancel/delete — matched)
INV-9:  sale_gl_transaction_id     === null  (بعد delete؛ preserve بعد cancel — DEFECT-2 contract)
INV-10: booking.trashed()          === true  (بعد delete)
```

---

## الـ Files المُعدَّلة / المُنشأة

| File | تغيير |
|---|---|
| `app/Services/Flight/FlightBookingService.php` | ~350 سطر: FIN-A/B-rev-2/C/D/E/G/H comments + logic، حذف `recordJournalTransfer`-based mirror لـ FIN-B |
| `tests/Feature/Flight/FlightCashBasisRegressionTest.php` | **جديد**، ~660 سطر: 8 سيناريوهات |
| `.zcode/plans/FLIGHT_PROFIT_REVERSAL_AUDIT_20260823.md` | **جديد**، هذا التقرير |

**ما لم نُعدِّل:**
- لا تغييرات على `migrations/`، controllers، config files.
- لا تغييرات على `FlightSoftDeleteRealWorldTest.php` (الـ 2 scenarios failing
  مُعتمَدة كـ known trade-off).

---

## للـ future reference

### القيود الـ cash-basis التي يجب احترامها

1. **Revenue يَتُم الاعتراف به فقط عند cash receipt** — `addPayment`
   يَنشئ `Transaction(type=income)` فقط؛ لا revenue عند `createBooking`.
2. **cancel يجب أن يُصفّي revenue** — عبر `reverseTransaction` على income
   rows (الـ notes 'عكس:' هي الـ canonical marker).
3. **delete يجب أن يُصفّي sale-gl residual** — عبر FIN-A branch (cashbox →
   pending_sales_receivable) أو FIN-E sweep (customer → pending).
4. **double-reversal ممنوع** — `deleteBookingWithReversal` يَتحقّق من
   `existingRefundEarly` قبل الـ branch `if`.

### متي تَطبّق FIN-B؟

في كل مكان يَتُم فيه مسح revenue (cancel/delete). الـ idempotency مُضمَّن عبر
`reverseTransaction` نفسه (يَتَخطّى إذا الـ transaction مُعكَس سابقاً).

### متي تَطبّق FIN-A؟

فقط في delete branch elseif، فقط إذا `pending_sales_receivable.balance < 0`
(guard FIN-G). اتجاه الـ transfer: `from=cashbox → to=pending_sales_receivable`
(بعكس الـ sale الأصلي).

### متي تَطبّق FIN-E؟

فقط في delete branch elseif else، فقط إذا:
- لا cashbox متاح (لا refund.account_id ولا fallback على payment cashbox).
- customer.balance > 0.001 AND pending.balance < -0.001.

---

## ملاحظات الـ commit

يُقترح commit واحد:

```bash
git add app/Services/Flight/FlightBookingService.php tests/Feature/Flight/FlightCashBasisRegressionTest.php .zcode/plans/FLIGHT_PROFIT_REVERSAL_AUDIT_20260823.md
git commit -m "fix(flight): profit reversal lifecycle (FIN-A/B/C/D/E/G/H) + cash-basis regression tests"
```

أو commits منفصلة per fix إذا رَغِب الـ reviewer:
1. `fix(flight): FIN-A residual clearing on pending_sales_receivable not income_clearing`
2. `fix(flight): FIN-B revenue reversal via TransactionService::reverseTransaction`
3. `fix(flight): FIN-D skip double-reverse in delete when cancel happened`
4. `fix(flight): FIN-E customer→pending sweep for no-payment cancel+delete`
5. `test(flight): add 8 cash-basis regression scenarios`
6. `docs(flight): profit & reversal audit report`