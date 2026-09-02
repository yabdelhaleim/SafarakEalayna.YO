## خطة مراجعة عميقة لموديول Flight (قسم السياحة)

### السياق
- موديول **Flight** = أهم موديول عندك في قسم السياحة
- متعدد العملات (EGP / KWD / SAR / USD / EUR / GBP)
- 3 طرق حجز، نظام ديون معقد، دفع أجزاء، refunds + deletions
- 41 ملف اختبار موجود — لازم أبني عليهم
- كود ضخم: `FlightBookingService.php` ≈ 4000 سطر

### المراحل (5 مراحل، ابدأ بـ Phase 1 = قراءة فقط)

---

### **Phase 1: قراءة عميقة (read-only)**

أقرأ الـ files الأساسية بالترتيب ده:

| # | الملف | الـ LOC | الهدف |
|---|------|---------|------|
| 1 | `app/Services/Flight/FlightBookingService.php` | ~4000 | المنسّق الأساسي — كل العمليات تمر منه |
| 2 | `app/Services/Flight/RefundService.php` | متوسط | منطق refund (voucher + agency treasury) |
| 3 | `app/Models/Flight/FlightBooking.php` | متوسط | الـ model + accessors + observers |
| 4 | `app/Models/Flight/FlightPayment.php` | متوسط | منطق الدفع + currency conversion |
| 5 | `app/Models/Flight/FlightCarrier.php` + `FlightSystem.php` + `FlightGroup.php` | 3 ملفات | الـ 3 طرق حجز (balance pools) |
| 6 | `app/Http/Controllers/Api/V1/Flight/FlightController.php` | كبير | الـ API endpoints |
| 7 | `app/Http/Controllers/Api/V1/Flight/RefundController.php` | متوسط | refund endpoints |
| 8 | `tests/Feature/Flight/Support/FlightTestCase.php` | — | الـ base class المشترك |
| 9 | أهم 5 ملفات اختبار: `FlightBookingCreationTest`, `FlightPaymentEdgeCasesTest`, `FlightRefundFlowTest`, `FlightBookingDeletionReversalTest`, `FlightCrossCurrencyCancelTest` | — | فهم الـ test patterns الموجودة |

**ناتج Phase 1:** فهم كامل لـ:
- 3 طرق الحجز (Carrier / System / Group) بالتفصيل — GL entries لكل واحدة
- لوجيك الدين: `recordSaleToCustomer`, `addPayment`, `payDebt` (FIFO)
- لوجيك الـ currency: `egpPerUnitOfCurrency`, `purchaseAmountInBalanceCurrency`, `lockedRateFromBookingSnapshot`
- لوجيك الـ Refund: `cancelBooking` vs `processRefundRequest` vs `reverseRefundRequest`
- لوجيك الـ Delete: `deleteBookingWithReversal` (8 steps)
- الـ Known limitations (GAP 6, FIN-3 BUG-7, DEFECT-2, إلخ)

---

### **Phase 2: تقرير الـ Audit**

ملف منظم فيه:
- **تغطية الـ tests الموجودة** (إيه الـ scenarios اللي متغطاة)
- **الثغرات** (إيه اللي مش متختبر — خصوصًا cross-currency edge cases)
- **bugs/risk محتملة** من الـ code review
- **خطة الـ tests الجديدة** المطلوبة

---

### **Phase 3: بناء الاختبارات الجديدة**

أبني tests للسيناريوهات دي (مثال، قد يتغير بعد الـ audit):

1. **Multi-currency matrix** — كل طريقة حجز × كل عملة (EGP/KWD/SAR/USD) × كل سيناريو
2. **Refund بـ cross-currency** (حجز EGP + دفع KWD + refund USD)
3. **Deletion بـ multi-currency** 
4. **Partial payment → partial refund → final refund** (3-دفعات + 2-refunds)
5. **Group debt lifecycle** — حد credit → حجز → دفع → دفعة من المديونية → refund → حذف
6. **Race conditions** — حجزتين متوازيتين على نفس الـ carrier/system balance
7. **Currency fallback rates** — حذف currencies table واستخدام الـ FALLBACK_EGP_PER_UNIT
8. **GAP 6 verification** — `reverseRefundRequest` لا يُرجع `booking.status`
9. **Audit trail tests** — التحقق من `sale_gl_transaction_id` preserved (DEFECT-2)
10. **Idempotency** — double-submit نفس الـ payment (FP-IDEM-UNIQ)

كل test جديد = commit منفصل.

---

### **Phase 4: تشغيل + إصلاح**

- أشغّل `php artisan test --testsuite=Feature --filter=Flight`
- كل failure = أصلحه فوراً
- commit per fix (commit واحد لكل إصلاح منطقي)
- أرجع أشغّل الـ suite كامل عشان أتأكد مفيش regression

---

### **Phase 5: GUI Verify عبر browser-use skill**

أشغل المتصفح على `http://127.0.0.1:8000` وأحاكي:
1. **Create booking** بكل طريقة من الـ 3 (Carrier / System / Group) — بكل عملة
2. **Add payment** (full + partial + cross-currency)
3. **Cancel booking** بغرامات (airline + office penalty)
4. **Refund request** (voucher + treasury cash-out)
5. **Delete booking** كامل
6. **Verify dashboard** — Treasury overview، P&L، Receivables

أتحقق من:
- الـ UI errors / validation messages
- الـ transaction timings
- الـ currency conversion في الـ UI
- الـ audit trail ظاهر

---

### النطاق المستبعد (في هذه الجولة)
- HajjUmra, OnlineTransaction, Fawry, Bus, Visa — في جولات لاحقة
- ما عدلت الـ Office division في هذه الجولة (تركّز Flight فقط)
- ما عملتش أي refactoring كبير — بس bug fixes فقط

### الملفات اللي هتتأثر (في الـ tests الجديدة + الـ fixes)
- tests/Feature/Flight/*.php (tests جديدة)
- app/Services/Flight/*.php (bug fixes فقط — لو لقيت)
- app/Models/Flight/*.php (bug fixes فقط)
- migrations/ — لو لزم DB fix نضيف migration
- (مش هلمس HajjUmraController, OnlineTransactionService في هذه الجولة)

### المخرجات النهائية
1. **تقرير الـ audit** (ملف .md)
2. **N Feature tests جديدة** كلهم passing
3. **M bug fixes** مع commits منفصلة
4. **GUI verification screenshots** للـ critical flows
5. **summary report** — Flight module cleanliness

### موافقتك المطلوبة على:
- ✅ الـ scope (Flight فقط، ما نتعداش لـ HajjUmra/Online قبل ما نخلص)
- ✅ الـ phases بالترتيب (قراءة → تقرير → tests → fix → GUI)
- ✅ مفيش commits على الـ modified files الموجودة (HajjUmra/Online) في هذه الجولة
- ✅ أبدأ بـ Phase 1 (read-only) فوراً بعد موافقتك