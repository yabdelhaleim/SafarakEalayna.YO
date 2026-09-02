# Phase 25 — Large-Scale Financial Stress / Load / Data Volume

**Mode**: STRESS / NEW FILES ONLY (zero production code changes)  
**Date**: 2026-08-14  
**Plan file**: `.zcode/plans/phase-25-financial-stress-test-plan-20260814.md`

---

## 1. Scope & acceptance

All 23 sub-phases of the Phase 25 spec are in scope, but **scaled down proportionally** per the spec's allowance ("If the application cannot safely support these exact numbers, scale down proportionally but NEVER skip the stress phase").

**Primary acceptance**: zero money-loss / zero money-creation / zero duplicate financial effect / zero ledger corruption across NORMAL + DUPLICATE + FAILURE + PARTIAL + REVERSAL + CONCURRENT operations, with the ledger reconciling exactly with raw DB and application reports.

**Hard rule**: financial correctness overrides performance. We do not declare PASS because of speed alone.

**Hard rule**: this plan does NOT modify any file under `app/`, `config/`, `routes/`, `database/migrations/`, `bootstrap/`, the existing `phpunit.xml`, the existing `.env`, or any existing application behavior. If a real application defect is discovered, the harness STOPS and reports it. Any production fix is a separate audit task.

---

## 2. Scale targets (3 staged phases)

| Phase | Tier | Total tx | Workers | Hot-spot focus |
|---|---|---|---|---|
| **A** | SQLite WAL + MySQL `safarak_stress` | ~20,000 | **10** | Functional baseline + light concurrency |
| **B** | SQLite WAL + MySQL `safarak_stress` | ~50,000 | **25** | Full proportional data + medium concurrency |
| **C** | MySQL `safarak_stress` (reuses Phase B dataset) | reuse | **50** | Hot account / hot debt / hot booking contention |

**Per-tier allocations** (Phase B reference; Phase A is 40% of these):

| Entity | Phase A | Phase B | Spec target |
|---|---|---|---|
| Customers | 400 | 1,000 | 10,000 |
| Suppliers | 80 | 200 | 2,000 |
| Accounts (liquidity + subject) | 20 | 50 | 500+ |
| Bookings (across 6 modules) | 2,000 | 5,000 | 50,000 |
| Customer debts | 800 | 2,000 | 20,000 |
| Supplier debts | 400 | 1,000 | 10,000 |
| Payments (incl. partial) | 4,000 | 10,000 | 100,000 |
| Transfers | 800 | 2,000 | 20,000 |
| Income tx | 4,000 | 10,000 | 50,000 |
| Expense tx | 1,600 | 4,000 | 20,000 |
| Reversals / refunds | 800 | 2,000 | 10,000 |

Booking distribution follows the spec example: 30% fully paid / 25% partially paid / 20% unpaid / 10% cancelled / 5% refunded / 5% reversed / 5% edge cases.

---

## 3. Safety guard (mandatory, hard-coded)

A single `StressSafetyGuard` class gates every entry point:

- **Pre-flight** (before any work): print `STRESS DB:` / `HOST:` / `DATABASE:` / `APP_ENV:` / `PORT:` / `PID:`.
- **Hard-abort** if `DB_CONNECTION=mysql` AND `DB_DATABASE ∈ {safarakealayna, safarak_ealayna, travel_office, production}` OR `APP_ENV ∈ {production, prod, live}`.
- **Hard-abort** if running on port ≠ `18000` for the stress artisan serve.
- The Laravel artisan serve for stress is launched with `--env=stress` so `.env.stress` is loaded automatically — the existing `.env` is never touched.

---

## 4. Directory layout (all new files)

### 4.1 Config & environment

```
.env.stress                       # DB_DATABASE=safarak_stress for MySQL tier; storage/app/stress.sqlite for SQLite tier
phpunit.stress.xml                # New PHPUnit config; <testsuite name="stress"> -> tests/Stress
storage/app/stress.sqlite         # Generated SQLite file (deleted at teardown)
storage/app/stress/phase-A.json   # Phase A metrics
storage/app/stress/phase-B.json
storage/app/stress/phase-C.json
storage/app/stress/stress-Reports/  # Per-phase reconciliation reports
```

### 4.2 PHPUnit stress suite (functional/bulk on SQLite)

```
tests/Stress/FinanceStressTestCase.php   # Base: SQLite WAL + busy_timeout, mt_srand(20260814), Faker seed 20260814, bulk helpers
tests/Stress/Support/StressSafetyGuard.php
tests/Stress/Support/StressBulkFactory.php  # Chunked Model::factory()->count(N)->create() helpers (no existing helper in repo)
tests/Stress/Support/StressReconciliation.php  # assertLedgerGloballyBalanced() + assertJournalBalanced()
tests/Stress/DataVolumeStressTest.php    # 25.2, 25.3
tests/Stress/CustomerDebtStressTest.php  # 25.4
tests/Stress/SupplierDebtStressTest.php  # 25.5
tests/Stress/PaymentDistributionStressTest.php  # 25.6
tests/Stress/FinancialOperationMixTest.php      # 25.7
tests/Stress/DuplicateStressTest.php            # 25.8
tests/Stress/RandomizedStressTest.php           # 25.9
tests/Stress/FailureInjectionStressTest.php     # 25.10
tests/Stress/ReversalStressTest.php             # 25.15
tests/Stress/BookingLifecycleStressTest.php     # 25.16
tests/Stress/DataIntegrityStressTest.php        # 25.17, 25.19
tests/Stress/BeforeAfterSnapshotStressTest.php  # 25.20
```

These run via `vendor/bin/phpunit -c phpunit.stress.xml` against SQLite `:memory:` (per-tier) — single-writer; covers invariants, lifecycles, duplicates, reversals, randomization, and failure injection. They cannot validate `lockForUpdate()` semantics; that is the MySQL tier's job.

### 4.3 Standalone scripts (real concurrency on MySQL `safarak_stress`)

```
tests/scripts/stress_safety_check.php      # Pre-flight guard
tests/scripts/stress_setup_mysql.php       # CREATE DATABASE safarak_stress; migrate --env=stress --force
tests/scripts/stress_teardown.php          # DROP DATABASE safarak_stress; DELETE storage/app/stress.sqlite
tests/scripts/stress_run_phase.php         # Entry: --phase=A|B|C --tier=sqlite|mysql [--workers=N]
tests/scripts/stress_seeder_bulk.php       # Inserts Phase A/B dataset (chunked)
tests/scripts/stress_curl_multi.php        # curl_multi driver + metrics capture (extends tests/scripts/concurrency_race_tests.php pattern)
tests/scripts/stress_cli_workers.php       # Direct-service parallel PHP processes (true DB-level concurrency)
tests/scripts/stress_hot_account.php       # 25.12 — 1M EGP cash account, thousands of concurrent deposit/withdrawal/transfer
tests/scripts/stress_hot_debt.php          # 25.13 — 100K EGP debt, 100 workers × 1K EGP
tests/scripts/stress_hot_booking.php       # 25.14 — 10K EGP booking, 20 workers × 1K EGP
tests/scripts/stress_concurrent_payments.php   # 25.11
tests/scripts/stress_concurrent_transfers.php  # 25.11
tests/scripts/stress_concurrent_reversals.php  # 25.15 in concurrent mode
tests/scripts/stress_reconcile.php         # 25.17, 25.20, 25.22 — final reconciliation report
tests/scripts/stress_metrics.php           # 25.18, 25.21 — P50/P95/P99/max latency, ops/sec, deadlock count
```

### 4.4 Seeders

```
database/seeders/StressBulkSeeder.php      # Inserts baseline GL accounts, suppliers, treasury (mirrors AccountingTestDataSeeder but isolated to stress DB)
```

### 4.5 Shell wrappers

```
scripts/stress_run.sh                      # bash scripts/stress_run.sh --phase=A --tier=sqlite
scripts/stress_run.bat                     # Windows equivalent (Git Bash)
scripts/stress_setup_mysql.sh
scripts/stress_teardown.sh
```

### 4.6 Reports

```
.zcode/plans/phase-25-financial-stress-test-plan-20260814.md       # This file
.zcode/plans/phase-25-financial-stress-test-report-20260814.md     # Final report (Phase 3/4 11-section template)
```

---

## 5. Execution workflow

### 5.1 One-time setup
```bash
# 1. Verify safety (no DB writes)
php tests/scripts/stress_safety_check.php

# 2. Create MySQL schema safarak_stress (isolated)
php tests/scripts/stress_setup_mysql.php

# 3. Start Laravel on dedicated stress port (loads .env.stress automatically)
php artisan serve --host=127.0.0.1 --port=18000 --env=stress &
# (verify port 18000 + DB safarak_stress via stress_safety_check.php)
```

### 5.2 Phase A
```bash
# Functional / bulk on SQLite
php -d memory_limit=2G tests/scripts/stress_run_phase.php --phase=A --tier=sqlite

# Concurrency on MySQL with 10 workers
php -d memory_limit=2G tests/scripts/stress_run_phase.php --phase=A --tier=mysql --workers=10
```

### 5.3 Phase B
```bash
php -d memory_limit=2G tests/scripts/stress_run_phase.php --phase=B --tier=sqlite
php -d memory_limit=2G tests/scripts/stress_run_phase.php --phase=B --tier=mysql --workers=25
```

### 5.4 Phase C
```bash
# Reuses Phase B dataset; 50 workers; hot-account / hot-debt / hot-booking
php -d memory_limit=2G tests/scripts/stress_hot_account.php --workers=50
php -d memory_limit=2G tests/scripts/stress_hot_debt.php --workers=50
php -d memory_limit=2G tests/scripts/stress_hot_booking.php --workers=50
```

### 5.5 Reconciliation & cleanup
```bash
php -d memory_limit=2G tests/scripts/stress_reconcile.php     # writes phase-25-...-REPORT.md
php tests/scripts/stress_teardown.php                        # DROP safarak_stress; rm storage/app/stress.sqlite
```

### 5.6 Guardrails during execution
- Resource monitor between phases (RAM, disk free). If disk < 5 GiB or RAM > 90% → STOP.
- Each phase must print its pre-flight banner (DB / host / APP_ENV / port) before the first write.
- If any worker logs a deadlock > 5 times for the same operation → STOP, capture evidence, escalate.
- If any assertion in `StressReconciliation` fails → STOP.

---

## 6. Concurrency design — three execution modes

**Mode 1: curl_multi HTTP** (matches `tests/scripts/concurrency_race_tests.php` precedent). Worker count = number of curl handles fired at once. Reuses the stress artisan serve on port 18000.

**Mode 2: direct-service parallel PHP CLI** (`stress_cli_workers.php`). Each worker is a separate `php` process that bootstraps Laravel, gets its own DB connection, and calls the service layer directly (no HTTP). This is the **true** parallel-workers test for MySQL `lockForUpdate`. Worker count = number of forked processes.

**Mode 3: dedicated hot-spot scripts** (`stress_hot_account.php`, `stress_hot_debt.php`, `stress_hot_booking.php`). Mode 2 with all workers targeting the same record to expose lost-update / overpayment / underpayment bugs.

Every concurrency run captures:
- requested concurrency (curl handles / processes)
- successful operations (HTTP 2xx)
- rejected operations (HTTP 4xx/5xx) — broken down by reason class (duplicate, overpay, race-loss, deadlock-retry-exhausted, etc.)
- HTTP status code histogram
- response-time P50 / P95 / P99 / max
- deadlocks observed (MySQL error 1213) and retries triggered
- lock-wait timeouts (MySQL error 1205)
- duplicate financial effects (count > 1 for any `related_type+related_id` income leg)
- final ledger state — independently computed from raw DB.

---

## 7. Reconciliation (final report inputs)

`stress_reconcile.php` computes, independently of any service:

1. **Per-account**: `balance == SUM(credit) − SUM(debit)` on its `account_entries` rows. Variance > `accounting.reconciliation.tolerance` (0.02) → FAIL.
2. **Per-transaction**: `SUM(debit) == SUM(credit)` across its entries. Variance > 0.0001 → FAIL.
3. **Per-booking**: `paid_amount + remaining_amount == total_price` (with sign convention).
4. **Customer debts**: outstanding sum = `total_price − paid_amount` per booking.
5. **Supplier debts**: outstanding sum = `purchases − settled`.
6. **Income / expense / transfer totals**: independent SUMs compared against `transactions` aggregate.
7. **Reversals**: every original with `notes LIKE 'عكس:%'` exists; ledger net effect = 0.
8. **Orphan check**: every `AccountEntry.transaction_id` resolves to a `Transaction`; every `Transaction.id` has ≥ 2 entries (except explicitly-allowed single-leg legacy rows).
9. **FK integrity**: every `from_account_id` / `to_account_id` / `customer_id` / `supplier_id` / `booking_id` resolves.
10. **Dead-record check**: no unexpected soft deletes on active bookings/accounts.

Reconciliation produces `phase-25-...-REPORT.md` plus `phase-25-...-RECONCILIATION.json`. Verdict is GO only if **all 10 checks pass**.

---

## 8. Performance metrics

`stress_metrics.php` records (per phase, per tier):

- total operations, successful / failed / blocked-duplicates / rollback / deadlock / retry counts
- average latency, P50 / P95 / P99 / max latency
- transactions/sec and operations/sec
- DB query count where measurable
- memory usage per worker where measurable
- per-account contention map (which accounts became hot)

These feed the report's Performance section. PASS is **never** awarded on speed alone.

---

## 9. Defect escalation protocol

If the harness detects:
- a money-loss event (expected ≠ actual beyond tolerance),
- a duplicate financial effect,
- a ledger imbalance > tolerance,
- an unreconciled debt,
- an authorization bypass,
- production-code reads/writes to `safarakealayna`,

then the affected phase STOPS at the failure point, writes `storage/app/stress/FAIL-<phase>-<timestamp>.json` with full evidence (request payload, response, DB state at failure), and continues only to the next safe phase OR halts the whole run. No silent fixes. No production-code edits.

---

## 10. Final report (`.zcode/plans/phase-25-financial-stress-test-report-20260814.md`)

Follows the Phase 3 / Phase 4 11-section template:

1. Title + mode + constraint anchors (zero production code changes)
2. Scope — 23 sub-phases mapped to files
3. New tests added (table: file / tests / assertions / status)
4. Execution results — Phase A / B / C tables
5. Database integrity findings
6. Performance metrics (per tier, per worker count)
7. Financial invariants verified (the 10 reconciliation checks)
8. Concurrency results (per worker count, per scenario)
9. Files touched (created vs modified; **explicit confirmation that production files are NOT modified** + `git status` + `git diff --stat`)
10. Defects ledger (severity / title / status)
11. Verdict — GO / CONDITIONAL GO / NO-GO with conditions
12. Audit trail commands (copy-pasteable)
13. Recommended next phases

---

## 11. Cleanup

After report is approved and saved:

- `DROP DATABASE safarak_stress;` (via `stress_teardown.php`)
- `rm storage/app/stress.sqlite`
- `rm -rf storage/app/stress/` (per-phase JSON + reports)
- **NEVER** touch `safarakealayna`. The teardown script verifies this before any delete operation.

---

## 12. Allowed vs. forbidden file modifications (re-stated for clarity)

**Allowed** (NEW files only):
- `.env.stress`
- `phpunit.stress.xml`
- everything under `tests/Stress/`
- everything under `tests/scripts/stress_*.php`
- `database/seeders/StressBulkSeeder.php`
- everything under `scripts/stress_*.sh` and `scripts/stress_*.bat`
- `storage/app/stress.sqlite` and `storage/app/stress/` artifacts (runtime data)
- `.zcode/plans/phase-25-financial-stress-test-*.md`

**Forbidden** (no edits):
- any file under `app/`
- any file under `config/`
- any file under `routes/`
- any file under `database/migrations/`
- `bootstrap/`
- the existing `phpunit.xml`
- the existing `.env`, `.env.example`, `.env.testing`, `.env.sqlite`
- any existing `tests/Feature/*` or `tests/Unit/*` file
- any existing `scripts/*.php`, `scripts/*.sh`, `scripts/*.bat`
- any composer or package manifest

---

## 13. Open follow-ups (not blocking Phase 25 start)

- MySQL `max_connections=151` and `innodb_buffer_pool_size=128 MiB` are below the spec's needs. The harness will design around them (worker count capped to leave headroom) and document the limit in the report. Raising these requires a Laragon restart and is out of scope for the harness.
- Disk is at 92% (20 GiB free). The harness writes only machine-readable JSON + one final MD; if disk drops below 5 GiB mid-run, the safety monitor halts.
- PHP CLI memory_limit defaults to 512 MiB. Every stress command is invoked with `-d memory_limit=2G` (no `php.ini` change).

---

**End of plan.** Awaiting approval before any file creation.
