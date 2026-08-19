# Phase 4 — Flight Orphan Transaction Inventory (READ-ONLY)

## A. Orphan transactions by module + type

An orphan transaction = `transactions.related_id` does not exist in the
referenced parent table. We LEFT-JOIN transactions against each
module's payment table.

| Module | type | n | total EGP |
|--------|------|---|-----------|
| Flight | transfer | 11 | 8,099.99 |
| Flight | income | 11 | 8,099.99 |
| HajjUmra | — | 0 | 0.00 |
| Visa | — | 0 | 0.00 |
| Bus | — | 0 | 0.00 |

## B. ACTIVE income transactions by module (notes NOT 'عكس:…')

These are the transactions counted as ACTIVE income by every report
that filters on `type=income AND notes NOT LIKE 'عكس:%'` (the
canonical pattern, same as the single-active-income guard).

| Module | n | total EGP |
|--------|---|-----------|
| bus | 904 | 83,630.00 |
| visa | 1 | 3,000.00 |

## C. Orphan Flight transactions — the 22 legacy cases

Each row below is a transactions table entry whose `related_id`
references a `flight_payments.id` (41–51) that NO LONGER EXISTS.
These are the residual B-2 bug rows.

| tx_id | type | amount | related_id | from_account | to_account | notes (head) | created_at |
|-------|------|--------|------------|--------------|------------|-------------|------------|
| 4365 | income | 60.00 | 41 | 930 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:41 |
| 4366 | income | 40.00 | 42 | 930 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:41 |
| 4367 | transfer | 60.00 | 41 | 6 | 930 | عكس دفعة (حذف حجز) — دفعة #41 � | 2026-08-18 14:53:41 |
| 4368 | transfer | 40.00 | 42 | 6 | 930 | عكس دفعة (حذف حجز) — دفعة #42 � | 2026-08-18 14:53:41 |
| 4371 | income | 300.00 | 43 | 931 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:41 |
| 4372 | income | 200.00 | 44 | 931 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:41 |
| 4373 | transfer | 300.00 | 43 | 6 | 931 | عكس دفعة (حذف حجز) — دفعة #43 � | 2026-08-18 14:53:41 |
| 4374 | transfer | 200.00 | 44 | 6 | 931 | عكس دفعة (حذف حجز) — دفعة #44 � | 2026-08-18 14:53:41 |
| 4377 | income | 599.99 | 45 | 932 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:42 |
| 4378 | income | 400.00 | 46 | 932 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:42 |
| 4379 | transfer | 599.99 | 45 | 6 | 932 | عكس دفعة (حذف حجز) — دفعة #45 � | 2026-08-18 14:53:42 |
| 4380 | transfer | 400.00 | 46 | 6 | 932 | عكس دفعة (حذف حجز) — دفعة #46 � | 2026-08-18 14:53:42 |
| 4383 | income | 600.00 | 47 | 933 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:42 |
| 4384 | income | 400.00 | 48 | 933 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:42 |
| 4385 | transfer | 600.00 | 47 | 6 | 933 | عكس دفعة (حذف حجز) — دفعة #47 � | 2026-08-18 14:53:42 |
| 4386 | transfer | 400.00 | 48 | 6 | 933 | عكس دفعة (حذف حجز) — دفعة #48 � | 2026-08-18 14:53:42 |
| 4389 | income | 1,500.00 | 49 | 934 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:42 |
| 4390 | income | 1,000.00 | 50 | 934 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:42 |
| 4391 | transfer | 1,500.00 | 49 | 6 | 934 | عكس دفعة (حذف حجز) — دفعة #49 � | 2026-08-18 14:53:42 |
| 4392 | transfer | 1,000.00 | 50 | 6 | 934 | عكس دفعة (حذف حجز) — دفعة #50 � | 2026-08-18 14:53:42 |
| 4395 | income | 3,000.00 | 51 | 935 | 6 | عكس: (legacy B-2 duplicate income — soft-reve | 2026-08-18 14:53:42 |
| 4397 | transfer | 3,000.00 | 51 | 6 | 935 | عكس دفعة (حذف حجز) — دفعة #51 � | 2026-08-18 14:53:42 |

## D. Net financial impact per account (orphan transactions only)

For each account touched by an orphan transaction, compute:
  - debit total (sum of amounts where account_id = `from_account_id`)
  - credit total (sum of amounts where account_id = `to_account_id`)
  - net = credit − debit
Project convention: balance = SUM(credit) − SUM(debit).

| account_id | debits | credits | net | n_txs |
|------------|--------|---------|-----|-------|
| 6 | 8,099.99 | 8,099.99 | 0.00 | 22 |
| 930 | 100.00 | 100.00 | 0.00 | 4 |
| 931 | 500.00 | 500.00 | 0.00 | 4 |
| 932 | 999.99 | 999.99 | 0.00 | 4 |
| 933 | 1,000.00 | 1,000.00 | 0.00 | 4 |
| 934 | 2,500.00 | 2,500.00 | 0.00 | 4 |
| 935 | 3,000.00 | 3,000.00 | 0.00 | 2 |

## E. Parent table state

| table | total rows | trashed (deleted_at NOT NULL) | active |
|-------|------------|------------------------------|--------|
| flight_bookings | 0 | 0 | 0 |
| flight_payments | 0 | 0 | 0 |

> **CRITICAL:** Both tables are EMPTY. The orphan transactions
> reference flight_payment IDs in the range 41–51, but no
> flight_payment row with those IDs exists (active or trashed).
> The parent bookings and payments were hard-deleted at some
> prior point — only the ledger transactions survived.

## F. Existing `عكس:` reversal entries (companion to the orphan Income)

For each orphan FlightPayment Income row, there is already a companion
Transfer row with notes starting `عكس:`. These were added at the same
time as a manual workaround to keep cashbox/income-clearing balances
from inflating. Net financial impact on cashbox = ZERO (verified in
section D), but the Income row itself is still counted by income reports.

| Income tx_id | related_id | amount | matching عكس Transfer tx_id | notes head |
|--------------|------------|--------|------------------------------|------------|
| 4365 | 41 | 60.00 | 4367 | عكس دفعة (حذف حجز) — دف |
| 4366 | 42 | 40.00 | 4368 | عكس دفعة (حذف حجز) — دف |
| 4371 | 43 | 300.00 | 4373 | عكس دفعة (حذف حجز) — دف |
| 4372 | 44 | 200.00 | 4374 | عكس دفعة (حذف حجز) — دف |
| 4377 | 45 | 599.99 | 4379 | عكس دفعة (حذف حجز) — دف |
| 4378 | 46 | 400.00 | 4380 | عكس دفعة (حذف حجز) — دف |
| 4383 | 47 | 600.00 | 4385 | عكس دفعة (حذف حجز) — دف |
| 4384 | 48 | 400.00 | 4386 | عكس دفعة (حذف حجز) — دف |
| 4389 | 49 | 1,500.00 | 4391 | عكس دفعة (حذف حجز) — دف |
| 4390 | 50 | 1,000.00 | 4392 | عكس دفعة (حذف حجز) — دف |
| 4395 | 51 | 3,000.00 | 4397 | عكس دفعة (حذف حجز) — دف |

## G. Summary

- **22** orphan Flight transactions in `transactions` table.
- **0** of them are ACTIVE orphan Income (no `عكس:` prefix) → these inflate income reports by **0.00 EGP**.
- **11** orphan Income rows have already been soft-reversed (carry `عكس:` prefix).
- Each orphan Income has a companion orphan Transfer with `عكس:` prefix → **net cashbox impact = 0**.
- flight_bookings and flight_payments tables are **EMPTY** (hard-deleted at some prior point).

## H. Next step — correction plan (NOT executed in Phase 4)

See `docs/PHASE_4_HISTORICAL_CORRECTION_PLAN.md` for the proposed correction.
**Phase 4 is READ-ONLY — no data has been modified by this script.**
