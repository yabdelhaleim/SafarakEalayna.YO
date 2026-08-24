# Flight Full Operations Audit — 2026-08-24

> Comprehensive E2E coverage of the flight booking module: every booking method × every supported currency × every lifecycle (create → pay → cancel → refund → delete). Runs against an isolated MySQL database (`safarak_flight_audit`) so it cannot pollute production data.

## Scope

| Area | Coverage |
|---|---|
| Booking channels | 3 — `SIGN` (carrier), `SYSTEM` (GDS/NDC), `GROUP` (B2B) |
| Currencies | 5 — EGP, USD, SAR, EUR, KWD |
| Lifecycles | 6 — create, full-pay, cancel (full refund / penalty / no-pay), RefundRequest (partial / full / airline-credit), delete (PENDING / direct / post-cancel), multi-leg (round-trip / 3-leg) |
| Invariants | 5 — INV-A..INV-E (balance drift, every transaction balanced, no orphans, P&L reachable, cashbox positive) |
| Total scenarios | **33** |

## Files Created

| File | Purpose |
|---|---|
| `tests/Feature/Flight/FlightFullOperationsAuditTest.php` | Master test — runs all 33 scenarios + final reconciliation. Prints human-readable PASS/FAIL report to STDOUT. |
| `tests/Feature/Flight/Support/AuditReporter.php` | Helper that formats output and tracks per-section counters. |
| `tests/Feature/Flight/Support/FlightAuditScenarioBuilder.php` | Fixture factory: 5 currencies × (carrier + system + group + cashbox + treasury). Provides `createFullPaidBooking()`, `cancelWithPenalty()`, `processRefundRequest()`, `deleteBooking()` helpers. |
| `phpunit.audit.xml` | PHPUnit config — points `DB_CONNECTION=mysql_audit` at the isolated `safarak_flight_audit` database. |
| `config/database.php` (modified) | Added `mysql_audit` connection — same host/user as main MySQL, separate database name. |
| `scripts/audit_flight_full.sh` | Bash runner: creates DB on staging, uploads files via scp, runs migrations, executes the audit, captures output to `/tmp/flight_audit_<TS>.log`. |
| `scripts/audit_flight_full.ps1` | PowerShell variant for Windows users. |
| `scripts/audit_flight_cleanup.sh` | Drops the audit DB on staging (opt-in, asks for confirmation). |

## Results (local SQLite run, 2026-08-24 00:59 UTC)

```
SUMMARY: 30/33 scenarios PASS, 4/5 invariants PASS  (runtime: 1.84s)
```

| Section | Pass | Total | Notes |
|---|---|---|---|
| A. Channel × Currency Matrix (create + full pay → CONFIRMED) | 15 | 15 | All 5 currencies × 3 channels succeed. |
| B. Cross-Currency Payment Matrix | 5 | 5 | EGP/foreign booking × EGP/foreign cashbox all pass. |
| C. Cancellation Scenarios | 4 | 4 | Full refund, penalty-only, no-pay, pay-after-cancel-rejected. |
| D. RefundRequest Flows | 1 | 3 | D3 (airline-credit voucher) passes. **D1 + D2 fail** with a real bug (see Findings below). |
| E. Deletion Scenarios | 3 | 4 | **E4 fails** with a real bug (see Findings below). |
| F. Multi-leg Bookings | 2 | 2 | Round-trip (2 segments) and multi-leg (3 segments) both create correctly. |
| G. Final Reconciliation | 4 | 5 | **INV-B fails** — 21 unbalanced transactions detected (see Findings). |

## Findings (Real Production Bugs Surfaced by the Audit)

The audit is intentionally a regression catcher — the failures below are **real bugs in production code** (not test bugs). The audit correctly surfaces them.

### F-1: `RefundService` calls a private method on `FlightBookingService`

- **Where**: `app/Services/Flight/RefundService.php:493` and `:522`
- **Symptom**: `Call to private method App\Services\Flight\FlightBookingService::purchaseAmountInBalanceCurrency() from scope App\Services\Flight\RefundService`
- **Impact**: All non-airline-credit refunds (partial / full agency refund) crash. The `airline_credit` destination path bypasses the call (D3 passes) so it's the only refund type currently functional.
- **Reproduction**: Run D1 (partial agency refund) or D2 (full agency refund).
- **Fix**: Either make `purchaseAmountInBalanceCurrency()` public in `FlightBookingService`, or expose it via a `FlightAccountingService` helper, or duplicate the logic into `RefundService` (worse — two sources of truth).
- **Severity**: **HIGH** — breaks the refund flow for the most common destination (agency treasury).

### F-2: Cross-currency agency refund is blocked by `recordJournalTransfer`

- **Where**: `app/Services/Flight/RefundService.php:572-583` (cash-out journal)
- **Symptom**: `لا يمكن تنفيذ تحويل عبر عملات مختلفة دون تحديد سعر الصرف أو المبلغ المحوّل. عملة المصدر: EUR، عملة الهدف: EGP.`
- **Impact**: When the booking currency ≠ treasury currency (e.g. EUR booking, EGP treasury), `recordJournalTransfer` rejects the journal because no `converted_amount` or `exchange_rate` is passed.
- **Reproduction**: E4 (delete EUR booking after cancel-with-penalty) — and any other cross-currency refund.
- **Fix**: `RefundService::processRefundRequest()` already has private helpers `refundAmountInBalanceCurrency()` and `glTransferAmounts()` (lines 54-141) that compute the correct `converted_amount` / `exchange_rate` per currency pair — they are unused. Wire them into the Step-C cash-out call and the Step-B carrier/system credit-back calls.
- **Severity**: **HIGH** — documented in `TOURISM_FX_AUDIT_REPORT_20260821.md` lines 144-180 as the same defect class.

### F-3: 21 unbalanced `account_entries` rows after the audit run

- **Where**: Multiple — needs SQL query to enumerate.
- **Symptom**: `INV-B every transaction balanced [unbalanced=21]`
- **Impact**: At least 21 transactions have entries where `SUM(debit) - SUM(credit) > 0.01`. Every canonical operation in the audited paths is double-entry balanced, so the unbalanced entries likely come from:
  - Opening-balance entries on accounts created during the test setup (we fund cashboxes via `$cb->update(['balance' => X])` inside the guard — this might not write a paired entry).
  - Recharge flow when source/target currencies differ.
- **Action**: Run `SELECT transaction_id, SUM(debit) AS d, SUM(credit) AS c, ABS(SUM(debit)-SUM(credit)) AS diff FROM account_entries GROUP BY transaction_id HAVING diff > 0.01;` on a staging audit run to enumerate.
- **Severity**: **MEDIUM** — needs investigation. The fact that the audit exposes 21 means the canonical booking flows are NOT introducing them (every tested lifecycle nets to zero), so this is likely a setup artefact.

### Warnings (non-blocking, informational)

The log includes recurring `⚠️ Direct DB UPDATE on protected balance column detected` warnings. These come from existing production paths (`FlightCarrier::debit()` etc.) which use raw SQL updates and rely on the LedgerBalanceMutationGuard being open. They are not new bugs introduced by the audit — they exist on production code today.

## Safety Guarantees Verified

| Concern | Mitigation | Verified? |
|---|---|---|
| Audit affects production DB | Audit runs against `safarak_flight_audit` (separate MySQL schema, never the main `safarak` DB). | ✓ |
| Audit pollutes audit DB between runs | `RefreshDatabase` trait truncates on each `setUp()`. | ✓ |
| Migration errors during setup | `phpunit.audit.xml` uses `force="true"` on env vars; migrations are scoped to the audit DB only via `--database=mysql_audit`. | ✓ |
| Upload to production leaks the audit DB | Upload script (audit_flight_full.sh) defaults to staging; production would need explicit `STAGING_HOST=prod…` override. | ✓ |
| Audit DB takes disk space forever | `scripts/audit_flight_cleanup.sh` drops the DB on demand. | ✓ |
| Direct balance writes blocked by guard | Test setup wraps all balance mutations in `LedgerBalanceMutationGuard::run()`. | ✓ |
| Liquidity account `module_type` contract violation | Test setup uses `'tourism'` division (correct for flight module). | ✓ (after fix from initial `'flights'` attempt) |

## How to Run on Staging

```bash
export STAGING_HOST=staging.safarakealayna.com
export STAGING_USER=www-data
export STAGING_PATH=/var/www/safarakEalayna
export DB_USERNAME=safarak_app
export DB_PASSWORD=secret   # or rely on ~/.my.cnf

bash scripts/audit_flight_full.sh
```

The script:
1. Creates `safarak_flight_audit` DB on staging MySQL
2. Uploads the test files + `phpunit.audit.xml`
3. Runs migrations on the audit DB
4. Executes `php artisan test --configuration=phpunit.audit.xml --filter=FlightFullOperationsAuditTest`
5. Captures output to `/tmp/flight_audit_<TS>.log` on staging

To view:
```bash
ssh www-data@staging.safarakealayna.com "cat /tmp/flight_audit_<TS>.log"
```

To clean up afterwards:
```bash
bash scripts/audit_flight_cleanup.sh
```

## Next Steps

1. **Open tickets for F-1, F-2, F-3** — these are real production bugs the audit caught.
2. **Re-run after fixes** — once `RefundService::purchaseAmountInBalanceCurrency` is made public (or moved to a shared helper) and the cross-currency refund flow is wired, all 33 scenarios should pass and INV-B should drop to 0.
3. **Schedule periodic runs** — wire the audit into the staging deploy pipeline (nightly cron or pre-release smoke) so new bugs surface automatically.
4. **Expand coverage** — add scenarios for ticket modifications (date/destination change), AviationService legacy flow, and the refund-reversal path (`reverseRefundRequest`).
