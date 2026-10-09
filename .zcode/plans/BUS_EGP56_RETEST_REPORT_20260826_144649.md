# PHASE 10 — BUS EGP-ONLY RETEST REPORT

**Date:** 2026-08-26 14:46:49
**Database:** C:\travile\SafarakEalayna\storage\app\local_bus_audit.sqlite (driver=sqlite)
**Source of truth:** `.zcode/plans/BUS_FINANCIAL_MOVEMENT_INVENTORY_20260826.md` (rev. 2 — EGP-only)

## Totals

| Metric | Count |
|---|---|
| In-scope positive scenarios | 56 |
| Negative rejection guards | 11 |
| Total tests executed | 59 |
| **PASS** | **54** |
| PARTIAL | 0 |
| **FAIL** | **5** |
| BLOCKED | 0 |
| N/A | 0 |
| **Assertions** | **176** |

## Final Checks

- Ledger invariant: **OK**
- Non-EGP Bus rows: **1**

## Per-FM Results

| FM | Scenario | Status | Assertions | Detail |
|----|----------|--------|------------|--------|
| FM-01 | Create EGP booking (Mode A) | PASS | 8 | ccy=EGP rate=1.000000 tx+=2 cust_ar_Δ=+200.00 avail=9 |
| FM-03 | Auto-inventory Mode B (EGP) | PASS | 5 | total=300 EGP, auto_inv_created=YES |
| FM-04 | Auto-create customer + replay | PASS | 6 | ledger_OK=YES no_dup_on_replay=YES |
| FM-05 | Invalid qty (0/neg/over) | PASS | 4 | qty=0 rejected \| qty=-1 rejected \| qty>avail rejected tx_created=0 |
| FM-06 | Inventory capacity decrement+restore | PASS | 5 | avail 10→8→10 (cancel restores) |
| FM-G02-RG | Reject USD booking at createBooking | RG-PASS | 3 | Bus module is EGP-only (EGP). Rejected non-EGP currency (inv |
| FM-07 | Full EGP payment (cashbox) | PASS | 5 | pmt_ccy=EGP paid=200.00 status=paid |
| FM-10 | Partial → top-up aggregation | PASS | 4 | pmts=2 paid=300.00 |
| FM-11 | Multi-payment (3 partials) | PASS | 4 | pmts=3 paid=600.00 |
| FM-12 | Idempotent replay (same key × 4) | PASS | 3 | pmts_before=1 pmts_after=1 |
| FM-13 | Safety-net 5s tuple window | PASS | 2 | safety_net_reject=YES |
| FM-14 | Overpayment rejected | FAIL | 3 | overpay_reject=NO pmt_delta=0 |
| FM-15 | Pay on cancelled booking rejected | PASS | 3 | cancelled_pay_reject=YES |
| FM-G08-RG | Reject non-EGP payment account at payBooking | RG-PASS | 3 | Bus module is EGP-only (EGP). Rejected non-EGP currency (pai |
| FM-16 | Cancel unpaid, no penalty | PASS | 6 | status=cancelled refund=0.00 refund_ccy=EGP |
| FM-17 | Cancel paid, no penalty | PASS | 5 | refund=200.00 cashbox_Δ=-200.00 |
| FM-18 | Cancel paid, 100% penalty | PASS | 4 | refund=0 fee=200 status=partially_refunded |
| FM-19 | Cancel paid, partial penalty | PASS | 3 | refund=150 (paid 200 - fee 50) |
| FM-22 | Double-cancel rejected | PASS | 2 | double_cancel_reject=YES |
| FM-23 | Cancel after pay-debt BLOCKED | FAIL | 2 | cancel_blocked=NO msg= |
| FM-G20-RG | Reject non-EGP refund account at cancelBooking | RG-PASS | 3 | Bus module is EGP-only (EGP). Rejected non-EGP currency (ref |
| FM-G21-RG | Reject non-EGP treasury at processRefundRequest | RG-PASS | 2 | Bus module is EGP-only (EGP). Rejected non-EGP currency (tre |
| FM-24 | Delete unpaid booking | PASS | 3 | soft_deleted=YES |
| FM-25 | Delete paid booking rejected | PASS | 2 | reject=YES |
| FM-26 | Delete already-cancelled booking | PASS | 3 | soft_deleted=YES |
| FM-27 | Partial-paid delete | PASS | 3 | cashbox_Δ=-80.00 |
| FM-28 | Fully-paid delete | PASS | 3 | cashbox_Δ=-200.00 |
| FM-29 | Multi-payment delete (3 pmts) | PASS | 5 | pmts_before=3 after=0 cashbox_Δ=-300.00 |
| FM-30 | Double delete rejected | PASS | 2 | double_delete_reject=YES |
| FM-31 | BusRefundRequest.transaction_id nulled | PASS | 2 | tx_id=NULL |
| FM-32 | Deferred inventory partial→full debt pay | PASS | 4 | total_debt=400.00 half_remaining=200.00 final=0.00 payments=2 |
| FM-33 | Cash inventory delete reverses expense | FAIL | 4 | tx_delta=0 cashbox_Δ=0.00 |
| FM-34 | Deferred inventory delete (no bookings) | PASS | 2 | soft_deleted=YES |
| FM-35 | Inventory delete with bookings rejected | PASS | 2 | reject=YES |
| FM-42 | Triple replay same Idempotency-Key | PASS | 2 | pmts=1 |
| FM-43 | Replay after first call 422 (no row created) | PASS | 3 | first_reject=YES pmts=0 |
| FM-44 | Replay with different payment_method | FAIL | 3 | diff_method_reject=NO |
| FM-45 | Replay with different amount | FAIL | 3 | diff_amount_reject=NO |
| FM-46 | Same key on different bookings (both succeed) | PASS | 3 | second_rejected=NO (allowed=YES) |
| FM-47 | 2 simultaneous same-key payments (1 row) | PASS | 2 | pmts=1 |
| FM-48 | Pay vs cancel (final state consistent) | PASS | 2 | before=paid after=refunded |
| FM-49 | 2 simultaneous deleteBookingWithReversal | PASS | 2 | second_reject=YES |
| FM-50 | 2 simultaneous cancelBooking | PASS | 2 | second_reject=YES |
| FM-51 | Direct total_price write after pay (operational) | PASS | 1 | original=200.00 after=0.00 |
| FM-52 | Direct currency write (column free, service asserts) | PASS | 1 | direct_write=EUR |
| FM-53 | Direct $booking->restore() after delete | PASS | 2 | restored=YES |
| FM-55 | Refund unpaid booking rejected | PASS | 2 | reject=YES |
| FM-56 | Refund > paid amount rejected | PASS | 2 | reject=YES |
| FM-57 | Refund twice rejected | PASS | 2 | reject=YES |
| FM-58 | Pay amount=0/negative rejected | PASS | 1 | reject=YES |
| FM-59 | Cancel after Refunded rejected | PASS | 2 | reject=YES |
| FM-60 | Transaction row count after lifecycle | PASS | 3 | tx_delta=2 (expected 2 pmts) |
| FM-61 | Soft-deleted rows hidden by default | PASS | 2 | hidden=YES trashed_found=YES |
| FM-62 | No orphan AccountEntry rows | PASS | 2 | orphans=0 |
| FM-63 | No dangling related_id after delete | PASS | 2 | dangling=0 |
| FM-64 | Income tx uniqueness (1 sale per booking) | PASS | 2 | income_txs=1 (expected 1) |
| FM-65 | Cashbox Δ = Σ payments − Σ refunds | PASS | 4 | pay_sum=200.00 refund_sum=200.00 expected_Δ=0.00 actual_Δ=0.00 |
| FM-66 | Booking financial state = Σ tx (paid_amount=total_price) | PASS | 3 | pmt_sum=200.00 total=200.00 |
| FM-67 | Customer AR net = sale - payment - reversal | PASS | 3 | ar_start=2450.00 after_pay=2250.00 after_cancel=2250.00 expected_Δ=-200.00 |

## GO/NO-GO

**NO-GO** — review failures above. Required to pass: all 56 in-scope EGP scenarios + all 11 rejection guards, ledger balanced, no foreign-currency Bus rows.
