## خطة تدقيق موديول الباصات — 35 Phase UI-Driven E2E Audit (v2: Soft Delete كتول-كلاس)

> **النطاق:** Full 35-phase UI-driven audit (مُحدَّث لتأكيد أولوية SOFT DELETE)  
> **البيئة:** Isolated local SQLite (`storage/app/local_bus_test.sqlite`)  
> **معالجة T22/T23:** Strict contract tests (النتيجة الحالية ستسبب NO-GO، لن أُعدّل الكود)  
> **أولوية جديدة:** SOFT DELETE / RESTORE / FORCE DELETE مُعامَل كقسم كامل مستقل

---

### 1. الـ Soft Delete Audit Readiness (النتائج من الـ Discovery)

**الـ 7 models الـ soft-deletable في الـ Bus module:**

| Model | SoftDeletes | ModelDeletionGuard | بُعد observer إضافي | Filament Delete | Vue Delete | API Delete | **Restore UI** | **Force-Delete UI** |
|---|---|---|---|---|---|---|---|---|
| `BusBooking` | ✅ | ✅ | — | ❌ | ✅ | ✅ `DELETE /v1/bus/bookings/{id}` (line 288–291) | ❌ | ❌ |
| `BusInventory` | ✅ | ✅ | يرفض لو فيه bookings | ❌ | ✅ | ✅ `DELETE /v1/bus/inventories/{id}` (line 286–287) | ❌ | ❌ |
| `BusCompany` | ✅ | ✅ | — | ✅ (custom service) | ✅ | ✅ `DELETE /v1/bus/companies/{id}` (line 285) | ❌ | ❌ |
| `BusPayment` | ✅ | — | — | ❌ | ❌ | ❌ | ❌ | ❌ |
| `BusRefundRequest` | ✅ | — | — | ❌ | ❌ | ❌ | ❌ | ❌ |
| `BusCompanyPayment` | ✅ | — | — | ✅ | ❌ | ❌ | ❌ | ❌ |
| `BusTicket` | ✅ (per migration) | — | — | ✅ (resource) | ❌ | ❌ | ❌ | ❌ |

**الـ Findable Gap:**
- **`TrashedFilter::make()`** في `BusBookingResource` (line 279) و `BusInventoryResource` (line 235) و `BusCompanyResource` (line 176) — كلهم بيكشفوا الـ trashed records، **لكن مفيش أي `RestoreAction` أو `ForceDeleteAction` لاستعادتها**.
- `BusBookingController::show()` (line 102) بيستخدم `withTrashed()` لعرض soft-deleted record بالـ URL المباشر — بس مفيش UI route للـ View بعد الـ delete.
- `deleteBookingWithReversal` بيستخدم `withTrashed()` (line 1069) عشان idempotency check — ده اختبار داخلي مش user-facing.
- مفيش `restoreBooking` / `restoreInventory` / `restoreCompany` / `restorePayment` method في أي service class.
- مفيش `restore*` POST endpoint في `routes/api.php` (grep returned 0 hits).
- مفيش delete/restore/force-delete action في أي Vue page.

---

### 2. معمارية التنفيذ (مُحدَّثة)

```
┌──────────────────────────────────────────────────────────────────────────┐
│              SOFT DELETE / RESTORE / FORCE DELETE AUDIT                  │
│             (first-class section, own scripts + own report)              │
│                                                                          │
│  Per entity: BusBooking × BusInventory × BusCompany × BusPayment ×       │
│              BusRefundRequest × BusCompanyPayment × BusTicket             │
│                                                                          │
│  17 test scenarios per entity (see § 3 below)                            │
└──────────────────────────────────────────────────────────────────────────┘
                                  │
                                  │ cross-verify via
                                  ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                     UI-Driven Audit (Single Thread)                      │
│  Filament master data → Filament master data via browser (web-gui-tester) │
│  → Vue pages audit → Booking flow → Payment scenarios                    │
│  → Cancel + Refund                                                       │
└──────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌──────────────────────────────────────────────────────────────────────────┐
│           Service/API Layer Tests (PHP scripts, parallelizable)          │
│   T22 regression → Transaction types → Treasury → Authz matrix →         │
│   Validation → Reports → DB integrity → Cross-entity soft-delete         │
└──────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                    Coverage & Final Report                                │
│   Scenarios → Regression → Coverage matrix → Verdict (GO/NO-GO/BLOCKED)  │
└──────────────────────────────────────────────────────────────────────────┘
```

كل test record بيبدأ بـ `TX-BUS-AUDIT-*` لـ cleanup تلقائي.

---

### 3. SOFT DELETE / RESTORE / FORCE DELETE — 17 scenarios لكل entity

لكل entity (BusBooking, BusInventory, BusCompany, BusPayment, BusRefundRequest, BusCompanyPayment, BusTicket):

| # | الـ Scenario | الـ Method |
|---|---|---|
| **SD1** | **Create** | Via UI (Vue/Filament) لو متاح، أو API كـ fallback |
| **SD2** | **Delete via available UI** | Vue لو فيه button، Filament لو custom action، API لو مش هيبقى UI |
| **SD3** | **Verify `deleted_at IS NOT NULL`** | direct DB query عبر `php artisan tinker` |
| **SD4** | **Verify row physically present** | `SELECT COUNT(*) FROM table WHERE id = X` بدون `deleted_at filter` |
| **SD5** | **Verify excluded from Vue normal listing** | اقرأ Vue page → تأكد مش موجود |
| **SD6** | **Verify excluded from Filament normal listing** | اقرأ Filament table → تأكد مش موجود |
| **SD7** | **Verify excluded from API normal listing** | `GET /api/v1/bus/...` (no `with_trashed=1`) → مش لازم يكون في الـ response |
| **SD8** | **Verify direct lookup behavior** | `GET /api/v1/bus/.../{id}` — بيعمل 404 لو UI بيحترم soft-delete، أو بيجيب record لكن Vue مش بيعرضه |
| **SD9** | **Verify relations involving deleted record** | جيب booking → شوف الـ `inventory` returns null؟ الـ `inventoryWithTrashed()` returns record؟ |
| **SD10** | **Verify search/filters/counts** | Filter Vue/Filament بالـ ID → مش لازم يظهر |
| **SD11** | **Verify dashboards exclude deleted** | `BusDashboard.vue` aggregates ↔ DB — لازم متطابق (الـ deleted مش محسوب) |
| **SD12** | **Verify treasury doesn't include deleted** | `BusTreasury.vue` balances ↔ DB |
| **SD13** | **Verify reports exclude deleted** | `BusCompanyStatement.vue` ledger ↔ DB |
| **SD14** | **Re-delete (delete-after-delete)** | حاول تاني → متوقع: idempotency guard ثيرس |
| **SD15** | **Restore test (if supported)** | حاول تستعيد — لو مفيش endpoint: NOT SUPPORTED + report |
| **SD16** | **Force-delete test (if supported)** | لو مفيش: NOT SUPPORTED + report |
| **SD17** | **Unauthorized delete / restore / force-delete attempt** | بـ user مش admin/manager → متوقع: blocked |

**Cross-entity scenarios (XSD):**

| # | الـ Scenario | الـ Method |
|---|---|---|
| **XSD1** | Booking soft-delete → payments soft-deleted | `BusPayment::where('booking_id', X)->whereNotNull('deleted_at')` |
| **XSD2** | Booking soft-delete → refund requests `transaction_id` nulled | `BusRefundRequest::where('bus_booking_id', X)->whereNotNull('transaction_id')->count() == 0` |
| **XSD3** | Booking soft-delete → Transaction rows preserved (not destructive) | `Transaction::where('related_type', BusBooking::class)->where('related_id', X)->count() >= original` |
| **XSD4** | Booking soft-delete → Account balances restored additively | account.balance == balance_before - amount |
| **XSD5** | Inventory soft-delete → inventory row gone from Vue create wizard | `BusCreate.vue` لا يعرض الـ inventory في dropdown |
| **XSD6** | Booking referencing soft-deleted inventory → `inventory()` returns null + `inventoryWithTrashed()` returns row | DB query |
| **XSD7** | Company soft-delete → inventories + payments still list (parent hidden via TrashedFilter) | مش هتمسح parent من listings الـ الفرعية |
| **XSD8** | List all soft-deletable + count soft-deleted → tests scope leakage | DB-wide assertion |

---

### 4. الـ Files الإضافية للـ Soft Delete (الكتابة المتوقعة)

| File | الدور |
|---|---|
| `scripts/bus_audit_soft_delete_matrix.php` | generate `BUS_MODULE_SOFT_DELETE_MATRIX_20260813.md` (the pre-execution matrix) |
| `scripts/bus_audit_soft_delete_run.php` | runs ALL SD1–SD17 + XSD1–XSD8 scenarios across all 7 entities |
| `scripts/bus_audit_soft_delete_financial.php` | financial-history-preserved assertions (no destructive reversal) |
| `scripts/bus_audit_soft_delete_authz.php` | unauthorized delete/restore/force-delete attempts with 3 roles |
| `BUS_MODULE_SOFT_DELETE_MATRIX_20260813.md` | pre-execution matrix (created BEFORE running tests, per user's instruction "build a matrix for each one") |

---

### 5. تقرير الـ SOFT DELETE / RESTORE / FORCE DELETE AUDIT — شكله النهائي

```markdown
## SOFT DELETE / RESTORE / FORCE DELETE AUDIT

### Soft-Deletable Models Discovered
[List with: class path, migration, guards, observers]

### Per-Entity Matrix
| Entity | SD1 | SD2 | … | SD17 | XSD1 | XSD2 | … | XSD8 | Verdict |

### Test Operations by Entity
[For each entity: which UI/API path exercised, screenshot evidence, screenshot path]

### UI / API / DB Evidence
[Per entity: Filament screenshot, Vue screenshot, DB query + result]

### Relationship Impact
[Cross-entity affected records]

### Financial Impact
[Before/after snapshots per account; reconciliation; transaction preservation]

### Authorization Results
[3 roles × 3 ops × 7 entities matrix]

### Findings — Exact Failures + Root Causes
[NOT_SUPPORTED for restore/force-delete across all entities]
[TRASHED_FILTER_NO_ACTION for TrashedFilter existing without Restore button]
[IDEMPOTENCY_FINE for `deleteBookingWithReversal` re-delete guard]

### Coverage Summary
Discovered: 7
Testable: 5 (Booking, Inventory, Company, CompanyPayments, Ticket)
Tested: 5
NOT_SUPPORTED_RESTORE: 7
NOT_SUPPORTED_FORCE_DELETE: 7
Coverage: 100% of testable, 0% restore, 0% force-delete
```

---

### 6. تغطية الـ 35 Phase من الـ Prompt (مُحدَّثة)

| Phase | كيف سيُنفَّذ |
|---|---|
| **1-3** Discovery | ✅ Read-only DONE، output `BUS_MODULE_AUDIT_INVENTORY_20260813.md` |
| **4-6** Filament master data + Vue verify | main agent + web-gui-tester على `/admin/bus-*` وصفحات Vue |
| **7** Vue module audit | main agent + web-gui-tester لجميع الـ 9 routes |
| **8** Booking flow | main agent + web-gui-tester يكمّل الـ 4-step wizard |
| **9** Seat integrity | ⚠️ **NOT TESTABLE** — Vue مفيش seat-map picker |
| **10** Customer/Passenger | Customer ✅؛ Passenger ⚠️ **NOT TESTABLE** |
| **11-15** Payments | web-gui-tester يدفع الـ modal في `BusIndex`/`BusShow` |
| **16** Overpayment | API call + service-level assert |
| **17** Double-submit | rapid-click ثم cross-check |
| **18-20** Transactions + Treasury | `bus_audit_phase_i_transaction.php` + `bus_audit_phase_j_treasury.php` |
| **21-22** Cancel + Refund | web-gui-tester يكمّل `BusRefundWizard.vue` |
| **23** Validation | `bus_audit_phase_l_validation.php` |
| **24** Authorization | `bus_audit_phase_k_authz.php` |
| **25** Error handling | API-side tests |
| **26** Frontend/API contract | cross-verify بعد كل UI action |
| **27** Reports | `bus_audit_phase_m_reports.php` |
| **28** Real-life scenarios | `bus_audit_phase_o_scenarios.php` |
| **29** DB integrity | `bus_audit_phase_n_db_integrity.php` |
| **30** Financial reconciliation | treasury reconcile per account |
| **31** Regression | `bus_audit_phase_p_regression.php` يعيد الـ 167 PHPUnit + 23 e2e |
| **32** Idempotency | second run of the audit |
| **33** Test quality | inline code review |
| **34-35** Coverage + Final report | `bus_audit_phase_q_coverage.php` |
| **🆕 SOFT DELETE** | scripts soft_delete_matrix/run/financial/authz → `BUS_MODULE_SOFT_DELETE_MATRIX_20260813.md` + section in final report |

**التوقعات:**
- **T22** (cross-currency) → **FAIL** (production code مش بيطبق guard) → NO-GO
- **T23** (JSON envelope) → **FAIL** (production يستخدم `success` بدل `status`) → NO-GO
- **🆕 RESTORE** عبر الـ UI → **NOT SUPPORTED** (مفيش endpoint / action) → NO-GO per user's rule
- **🆕 FORCE-DELETE** عبر الـ UI → **NOT SUPPORTED** → NO-GO per user's rule
- Verdict النهائي → **NO-GO** (لأن أي من دول = NO-GO per user's expanded rule)

---

### 7. معايير الأمان (لم تتغير)

| Asset | Strategy |
|---|---|
| DB | SQLite جديد في `storage/app/local_bus_test.sqlite` |
| Records prefix | كل الـ records بـ `TX-BUS-AUDIT-*` |
| Dev servers | background با cleanup trap |
| Cleanup | `rm storage/app/local_bus_test.sqlite` + kill background processes |
| Auth | `auth_token` من Sanctum مع user بصلاحية admin |

---

### 8. Risk الـ Additions (من Soft Delete)

1. **Restore و Force-Delete مفيش user-facing** → الـ audit هيكتشف ده ويُسجّله كـ FAIL حقيقي (مش acceptable behavior)
2. **TrashedFilter موجود لكن مفيش Restore action** → users ممكن يكتشفوا الـ records المحذوفة لكن ميقدروش يستعيدوها
3. **`BusPayment` / `BusRefundRequest` مفيش ليهم delete endpoint** → ما يمكنش tests تختبر delete UI للـ records دي (NOT_TESTABLE لحد SD17)
4. **`BusTicket` module بالكامل orphan** → الاختبار هيكتشف إنه مش wired up anywhere
5. **DDL على cross-currency referrals**: بعد ما booking يتعمل soft-delete، الـ inventory dropdown في `BusCreate.vue` ممكن لسه يعرض الـ inventory لو الـ query معمل exclude للـ deleted → اختبر ده explicitly

---

### 9. خطوات التنفيذ (estimated ~3-4 hours)

1. **Setup + Discovery + Soft Delete matrix** (read-only): 30 min
2. **Soft Delete audit run** (per-entity matrix execution): 45 min
3. **Filament UI tests** (web-gui-tester): 30 min
4. **Vue UI tests** (web-gui-tester): 60 min
5. **Service-layer scripts**: 30 min
6. **Scenarios + Regression + Coverage + Final report**: 30 min

---

### 10. الـ Deliverables (مُحدَّثة)

```
✅ BUS_MODULE_AUDIT_INVENTORY_20260813.md         ← discovery report
✅ BUS_MODULE_SOFT_DELETE_MATRIX_20260813.md      ← soft-delete matrix (per user's "build a matrix" instruction)
✅ BUS_MODULE_FULL_E2E_AUDIT_20260813.md          ← final 35-phase report + verdict
   ├── includes dedicated SOFT DELETE section (per user's expansion)
   └── verdict: NO-GO (T22 + T23 + Restore NOT-SUPPORTED + ForceDelete NOT-SUPPORTED)
✅ scripts/bus_audit_*.php (~21 files)            ← the audit harness
✅ storage/logs/bus_audit_*.json                  ← per-phase JSON
✅ storage/logs/bus_audit_screenshots/            ← browser evidence (PNG)
```

---

### 11. Authority & Confirmations

- ✅ النطاق: Full 35-phase UI-driven audit — مُوافَق
- ✅ البيئة: Isolated SQLite — مُوافَق
- ✅ T22/T23: Strict contract (NO-GO لو failures) — مُوافَق
- 🆕 **SOFT DELETE كتول-كلاس** (per latest user message) — مُرحب به هنا
- ❓ **محتاج موافقتك الصريحة قبل التنفيذ** — الخطة كبيرة (3-4 ساعات execution + browser driving)

---

### Allowed prompts (للموافقة):
- `run PHP scripts under scripts/bus_audit_*.php`
- `start Laravel dev server (php artisan serve) in the background`
- `start Vite dev server (npx vite) in the background`
- `kill background processes (php artisan serve, vite)`
- `drive the browser via web-gui-tester skill`
- `run PHPUnit tests (vendor/bin/phpunit)`
- `query the database via php artisan tinker`
- `create and remove files under storage/app/, storage/logs/, and project root`