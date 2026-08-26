# Bus Module — Financial / Accounting Retest Report (FM-01..FM-67)

**Date:** 2026-08-26
**Method:** Single PHP script `phase10_bus_full_e2e_fm67.php` via `php artisan tinker --execute`
**Database:** SQLite (fresh-migrated, isolated)
**Run marker:** `FM67-RUN-7e348b39`

## 1. Executive Summary

| Metric | Count | % |
|---|---:|---:|
| Total scenarios | 67 | 100% |
| ✓ FULL PASS | 36 | 53.7% |
| ◐ PARTIAL | 3 | 4.5% |
| ✗ FAIL | 22 | 32.8% |
| ⏸ BLOCKED | 6 | — |
| ⊘ N/A | 0 | — |
| **Total assertions** | **282** | — |

**VERDICT:** **NO-GO** — 22 FAIL / 6 BLOCKED — see coverage matrix.

## 2. FM-01..FM-67 Coverage Matrix

| FM | Scenario | Section | Assertions | Tx | AE | Bal | FX | Idemp | Conc | Refund | Result | Detail |
|--|--|--|--:|--:|--:|--:|--:|--:|--:|--:|--|--|
| FM-01 | Create EGP booking (Mode A) | §B | 8 | ✓ | ✓ | ✓ | — | — | — | — | **PASS** | tx+=2 cust_ar_Δ=+200.00 avail=9 |
| FM-02 | Create USD booking (FX) | §B | 11 | ✓ | ✓ | ✓ | ✓ | — | — | — | **FAIL** | tx+=2 USD_AR_created=YES supplier_EGP=-80 |
| FM-03 | Auto-inventory Mode B (SAR) | §B | 6 | ✓ | ✓ | ✓ | ✓ | — | — | — | **FAIL** | total=30 SAR, auto_inv_created=YES |
| FM-04 | Auto-create customer + replay | §B | 7 | ✓ | ✓ | ✓ | — | — | — | — | **FAIL** | ledger_OK=NO no_dup_on_replay=YES |
| FM-05 | Invalid qty (0/neg/over) | §B | 4 | ✓ | — | ✓ | — | — | — | — | **PASS** | qty=0 rejected:Journal transfer amount must b ¦ qty=-1 rejected:Journal transfer amount must b ¦ qty>avail rejected:لا توجد تذاكر كا� tx_created=0 |
| FM-06 | Inventory capacity decrement+restore | §B | 5 | ✓ | — | ✓ | — | — | — | — | **PASS** | avail 10→8→10 (cancel restores) |
| FM-07 | Full EGP payment | §C | 7 | ✓ | ✓ | ✓ | — | ✓ | — | — | **FAIL** | cashbox_Δ=+200.00 cust_AR_Δ=-0.00 |
| FM-08 | Full USD wallet payment | §C | 6 | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | **PASS** | USD_AR_Δ=-100.00 USD_wallet_Δ=+100.00 |
| FM-09 | SAR booking → EGP cashbox (FX) [NEW] | §C | 9 | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | **FAIL** | SAR_AR_Δ=-50.00 EGP_cashbox_Δ=+666.6700 rate=0.0000 converted=0.0000 |
| FM-10 | Partial → top-up | §C | 7 | ✓ | ✓ | ✓ | — | ✓ | — | — | **PASS** | paid=200.00/200 cashbox_Δ=+200.00 payments=2 |
| FM-11 | Three partial payments | §C | 4 | ✓ | ✓ | ✓ | — | ✓ | — | — | **PASS** | paid=200.00 tx+=3 |
| FM-12 | Idempotency replay (same key) | §C | 5 | ✓ | ✓ | ✓ | — | ✓ | — | — | **PASS** | paid_after_first=200.00 paid_after_replay=200.00 tx+=1 |
| FM-13 | Safety-net 5s tuple replay | §C | 4 | ✓ | ✓ | ✓ | — | ✓ | — | — | **PASS** | rejected=YES tx+=1 paid=100.00 err=تم رفض عملية دفع بنفس المبلغ والح |
| FM-14 | Overpayment rejected | §C | 3 | ✓ | — | ✓ | — | ✓ | — | — | **PASS** | rejected=YES tx+=0 |
| FM-15 | Pay cancelled booking rejected | §C | 3 | ✓ | — | ✓ | — | ✓ | — | — | **PASS** | rejected=YES tx+=0 |
| FM-16 | Cancel unpaid booking | §D | 6 | ✓ | ✓ | ✓ | — | — | — | ✓ | **FAIL** | avail 9→10 cust_AR_Δ=-0.00 |
| FM-17 | Cancel paid (full refund, no penalty) | §D | 6 | ✓ | ✓ | ✓ | — | — | — | ✓ | **FAIL** | status=refunded cashbox_Δ=-200.00 cust_AR_Δ=+0.00 |
| FM-18 | Cancel paid (100% penalty) | §D | 5 | ✓ | ✓ | ✓ | — | — | — | ✓ | **PASS** | status=partially_refunded cashbox_Δ=0.0000 refund=0.00 |
| FM-19 | Cancel paid (partial penalty) | §D | 4 | ✓ | ✓ | ✓ | — | — | — | ✓ | **FAIL** | refund=0.00 cashbox_Δ=-150.00 |
| FM-20 | USD wallet refund | §D | 5 | ✓ | ✓ | ✓ | ✓ | — | — | ✓ | **FAIL** | USD_wallet_Δ=-0.00 USD_AR_Δ=+0.00 status=paid |
| FM-24 | Delete unpaid booking | §E | 5 | ✓ | ✓ | ✓ | — | — | — | ✓ | **FAIL** | avail 9→10 cust_AR_Δ=-0.00 trashed=YES |
| FM-25 | Delete paid booking rejected | §E | 3 | ✓ | — | ✓ | — | — | — | — | **PASS** | rejected=YES tx+=0 |
| FM-26 | Delete already-cancelled booking | §E | 4 | ✓ | ✓ | ✓ | — | — | — | ✓ | **FAIL** | trashed=YES avail_Δ=1 tx+=2 |
| FM-27 | Partial-paid delete with reversal | §F | 5 | ✓ | ✓ | ✓ | — | — | — | ✓ | **PASS** | cashbox_Δ=-80.00 avail_Δ=+1 |
| FM-28 | Fully-paid delete with reversal | §F | 5 | ✓ | ✓ | ✓ | — | — | — | ✓ | **PASS** | cashbox_Δ=-200.00 avail_Δ=+1 |
| FM-29 | Multi-payment delete | §F | 4 | ✓ | ✓ | ✓ | — | — | — | ✓ | **PASS** | cashbox_Δ=-200.00 payments_reversed=3/3 |
| FM-30 | Double delete rejected | §F | 3 | ✓ | — | ✓ | — | — | — | — | **PASS** | rejected=YES tx+=0 |
| FM-31 | BusRefundRequest.transaction_id nulled | §F | 2 | ✓ | — | ✓ | — | — | — | ✓ | **FAIL** | tx_id_before=109 tx_id_after=109 |
| FM-32 | Deferred inventory debt pay | §G | 8 | ✓ | ✓ | ✓ | — | — | — | — | **FAIL** | paid=1000.00 remaining=0.00 cashbox_Δ=-1000.00 supplier_Δ=+0.00 |
| FM-33 | Cash inventory delete reverses expense | §G | 4 | ✓ | ✓ | ✓ | — | — | — | ✓ | **FAIL** | cashbox_Δ=800.00 tx+=0 |
| FM-34 | Deferred inventory delete (no expense) | §G | 3 | ✓ | — | ✓ | — | — | — | ✓ | **PASS** | trashed=YES tx+=0 cashbox_Δ=0.00 |
| FM-35 | Inventory delete with bookings rejected | §G | 3 | ✓ | — | ✓ | — | — | — | — | **PASS** | rejected=YES tx+=0 |
| FM-36 | USD booking → USD wallet (HTTP) [NEW] | §H | 5 | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | **PASS** | USD_AR_Δ=-100.00 USD_wallet_Δ=+100.00 |
| FM-37 | USD booking → EGP cashbox (FX, HTTP) [NEW] | §H | 6 | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | **FAIL** | USD_AR_Δ=-100.00 EGP_cashbox_Δ=+5000.00 converted=0.00 |
| FM-38 | SAR booking → SAR wallet (HTTP) [NEW] | §H | 5 | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | **PASS** | SAR_AR_Δ=-50.00 SAR_wallet_Δ=+50.00 |
| FM-39 | SAR booking → EGP cashbox (FX, HTTP) [NEW] | §H | 6 | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | **FAIL** | SAR_AR_Δ=-50.00 EGP_cashbox_Δ=+666.6700 converted=0.0000 |
| FM-40 | KWD high-precision FX (HTTP) [NEW] | §H | 7 | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | **FAIL** | KWD_AR_Δ=-1.5000 EGP_cashbox_Δ=+243.7500 rate=0.0000 converted=0.0000 |
| FM-41 | Customer AR multi-currency stacking | §H | 7 | ✓ | ✓ | ✓ | ✓ | — | — | — | **PASS** | EGP_AR=200.00 USD_AR=100.00 (independent) |
| FM-42 | Same key ×3 → 1 movement | §I | 5 | ✓ | ✓ | ✓ | — | ✓ | — | — | **PASS** | cashbox_Δ=+200.00 tx+=1 payments=1 |
| FM-43 | Replay after 422 → 0 new rows [NEW] | §I | 4 | ✓ | — | ✓ | — | ✓ | — | — | **FAIL** | 1st_rejected=YES tx+=1 payments=1 |
| FM-44 | Same key + diff payment_method [NEW] | §I | 4 | ✓ | ✓ | ✓ | — | ✓ | — | — | **PASS** | tx+=1 payments=1 2nd_rejected=NO/undefined |
| FM-45 | Same key + diff amount [NEW] | §I | 4 | ✓ | ✓ | ✓ | — | ✓ | — | — | **PASS** | tx+=1 payments=1 paid=100.00 2nd_idx_rejected=NO |
| FM-46 | Same key on different bookings [NEW] | §I | 5 | ✓ | ✓ | ✓ | — | ✓ | — | — | **PASS** | A.paid=200.00 B.paid=200.00 tx+=2 |
| FM-47 | Concurrent same-key payments [TRUE] | §J | 4 | ✓ | ✓ | ✓ | — | ✓ | ✓ | — | **PASS** | workers=3 ok=1 reject=2 tx+=1 payments=1 |
| FM-51 | Direct total_price write after pay [NEW] | §K | 4 | — | — | ✓ | — | — | — | — | **PARTIAL** | blocked=NO total=200.00→0.00 paid=200.00→200.00 err= |
| FM-52 | Direct currency write after pay [NEW] | §K | 3 | — | — | ✓ | — | — | — | — | **PARTIAL** | blocked=NO currency=EGP→EUR err= |
| FM-53 | Direct restore after delete [NEW] | §K | 3 | ✓ | — | ✓ | — | — | — | ✓ | **PASS** | restore_attempted=Y succeeded=Y tx+=0 |
| FM-54 | Direct status write after cancel [NEW] | §K | 4 | ✓ | — | ✓ | — | — | — | ✓ | **PARTIAL** | blocked=NO status=cancelled→pending tx+=0 err= |
| FM-55 | Refund unpaid rejected | §L | 4 | ✓ | — | ✓ | — | — | — | ✓ | **PASS** | rejected=YES tx+=0 err=لا يمكن إنشاء استرداد لحجز غير مد |
| FM-56 | Refund > paid rejected [NEW] | §L | 4 | ✓ | — | ✓ | — | — | — | ✓ | **FAIL** | req_rejected=N cancel_rejected=Y tx+=0 err= |
| FM-57 | Refund twice (2nd no-op) | §L | 4 | ✓ | ✓ | ✓ | — | — | — | ✓ | **PASS** | 2nd_rejected=Y tx+=0 cust_AR_Δ=0.00 |
| FM-58 | Pay amount=0 + negative [NEW] | §L | 3 | ✓ | — | ✓ | — | ✓ | — | — | **PASS** | zero_rejected=Y neg_rejected=Y tx+=0 |
| FM-59 | Cancel after Refunded rejected [NEW] | §L | 4 | ✓ | — | ✓ | — | — | — | ✓ | **PASS** | status_after_1st=refunded 2nd_rejected=Y tx+=0 err=الحجز ملغي أو مسترد بالفعل. |
| FM-60 | Transaction count after lifecycle | §M | 4 | ✓ | — | ✓ | — | — | — | — | **FAIL** | create=0 pay=0 cancel=0 (expected=6) |
| FM-61 | Soft-deleted hidden from default query [NEW] | §M | 3 | — | — | ✓ | — | — | — | — | **PASS** | default=N withTrashed=Y onlyTrashed=Y |
| FM-62 | No orphan AccountEntries [NEW] | §M | 1 | — | ✓ | — | — | — | — | — | **PASS** | orphan_count=0 |
| FM-63 | No dangling transaction refs [NEW] | §M | 1 | ✓ | — | — | — | — | — | — | **PASS** | dangling_count=0 |
| FM-64 | Duplicate income rejected [NEW] | §M | 3 | ✓ | — | ✓ | — | — | — | — | **FAIL** | rejected=N tx+=1 err= |
| FM-65 | Cashbox Δ = Σ payments − Σ refunds | §N | 5 | ✓ | ✓ | ✓ | — | — | — | — | **PASS** | cashbox=213827.09 expected_delta=204701.50 inbound=4701.5000 outbound=0.0000 |
| FM-66 | Booking state = Σ tx [NEW] | §N | 3 | ✓ | ✓ | ✓ | — | — | — | — | **FAIL** | paid=200.00 Σ_payments=200.00 Σ_tx=0.00 |
| FM-67 | Refund net = 0 on customer AR [NEW] | §N | 3 | ✓ | ✓ | ✓ | — | — | — | ✓ | **PASS** | status=refunded AR_Δ_after_full_refund=0.0000 (vs baseline) |
| FM-21 | BLOCKED (script aborted before) | §? | 0 | — | — | — | — | — | — | — | **BLOCKED** | script did not reach this scenario |
| FM-22 | BLOCKED (script aborted before) | §? | 0 | — | — | — | — | — | — | — | **BLOCKED** | script did not reach this scenario |
| FM-23 | BLOCKED (script aborted before) | §? | 0 | — | — | — | — | — | — | — | **BLOCKED** | script did not reach this scenario |
| FM-48 | BLOCKED (script aborted before) | §? | 0 | — | — | — | — | — | — | — | **BLOCKED** | script did not reach this scenario |
| FM-49 | BLOCKED (script aborted before) | §? | 0 | — | — | — | — | — | — | — | **BLOCKED** | script did not reach this scenario |
| FM-50 | BLOCKED (script aborted before) | §? | 0 | — | — | — | — | — | — | — | **BLOCKED** | script did not reach this scenario |

## 3. Per-Section Summary

| Section | FMs | Pass |
|--|--:|--:|
| §B | 6 | 3/6 |
| §C | 9 | 7/9 |
| §D | 5 | 1/5 |
| §E | 3 | 1/3 |
| §F | 5 | 4/5 |
| §G | 4 | 2/4 |
| §H | 6 | 3/6 |
| §I | 5 | 4/5 |
| §J | 1 | 1/1 |
| §K | 4 | 1/4 |
| §L | 5 | 4/5 |
| §M | 5 | 3/5 |
| §N | 3 | 2/3 |

## 4. Files

- **Script:** `phase10_bus_full_e2e_fm67.php`
- **Run output:** `phase10_bus_full_e2e_fm67.txt`
- **This report:** `.zcode/plans/BUS_FM67_RETEST_REPORT_20260826.md`
