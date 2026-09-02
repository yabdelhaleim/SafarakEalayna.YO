-- =====================================================================
-- Fawry B-2 Fix — Production Reconciliation Script
-- =====================================================================
-- Period: 2026-08-14 00:00:00 → 2026-08-20 23:59:59 (UTC)
-- Purpose: Detect any financial drift caused by B-2 (the Path C guard
--          blocking the registered-customer settlement flow)
--
-- B-2 background:
--   From 2026-08-14 (when the Path C guard landed in TransactionService),
--   every POST /api/v1/fawry/transactions with client_id != null AND
--   amount > 0 returned HTTP 422. This blocked the SETTLEMENT leg of the
--   registered-customer flow. The sale leg (to AR) also failed because
--   both legs are in the same DB transaction.
--
-- Net impact hypothesis:
--   1. ZERO registered-customer Fawry transactions were created during
--      this window (the API rejected them all before DB insert).
--   2. Cash physically collected but not recorded → could be in cashbox
--      (over-the-counter) but not in GL.
--   3. Some operations may have been re-entered as walk-in
--      (client_id=null) by employees as a workaround — these would
--      succeed but mis-classify the debt.
--
-- HOW TO RUN:
--   mysql -u root safarak < tests/scripts/fa_fawry_reconciliation_20260814_20.sql
--
-- Adjust the DB name to your actual production DB name.

-- ────────────────────────────────────────────────────────────────────
-- QUERY 1: Count of Fawry transactions by client_type and date
-- ────────────────────────────────────────────────────────────────────
-- Expected:
--   - registered-customer txs (client_id != null): should be 0 or very low
--   - walk-in txs (client_id IS NULL): normal volume
-- Any registered-customer txs in this window need manual review.
SELECT
    DATE(created_at) AS tx_date,
    SUM(CASE WHEN client_id IS NOT NULL THEN 1 ELSE 0 END) AS registered_count,
    SUM(CASE WHEN client_id IS NULL THEN 1 ELSE 0 END) AS walkin_count,
    SUM(CASE WHEN client_id IS NOT NULL THEN selling_price ELSE 0 END) AS registered_selling_total,
    SUM(CASE WHEN client_id IS NULL THEN selling_price ELSE 0 END) AS walkin_selling_total,
    SUM(CASE WHEN client_id IS NOT NULL THEN amount ELSE 0 END) AS registered_paid_total,
    SUM(CASE WHEN client_id IS NULL THEN amount ELSE 0 END) AS walkin_paid_total,
    SUM(CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END) AS soft_deleted
FROM fawry_transactions
WHERE created_at >= '2026-08-14 00:00:00'
  AND created_at <= '2026-08-20 23:59:59'
GROUP BY DATE(created_at)
ORDER BY tx_date ASC;

-- ────────────────────────────────────────────────────────────────────
-- QUERY 2: Detect any type=Income settlements (B-2 bug signature)
-- ────────────────────────────────────────────────────────────────────
-- If B-2 was active, any registered-customer Fawry tx in the period
-- would have a settlement entry with type='income' (which the old buggy
-- recordIncome() would have produced before being blocked).
--
-- Expected after 2026-08-14: 0 rows (no registered-customer Fawry tx
-- could be created, so no settlement entries exist at all).
--
-- If any rows appear, they were created BEFORE the B-2 fix was applied
-- but during a window where Path C guard was active. They need manual
-- reconciliation.
SELECT
    t.id AS transaction_id,
    t.related_id AS fawry_transaction_id,
    t.type,
    t.amount,
    t.from_account_id,
    t.to_account_id,
    t.created_at,
    t.notes
FROM transactions t
WHERE t.related_type = 'App\\Models\\Fawry\\FawryTransaction'
  AND t.created_at >= '2026-08-14 00:00:00'
  AND t.created_at <= '2026-08-20 23:59:59'
  AND t.type = 'income'
ORDER BY t.id;

-- ────────────────────────────────────────────────────────────────────
-- QUERY 3: Find Fawry transactions where the SETTLEMENT was recorded
--          as Income (B-2 bug signature, in case it slipped through)
-- ────────────────────────────────────────────────────────────────────
-- For each registered-customer Fawry tx (client_id != null), there
-- should be exactly 1 income tx (sale to AR) and 2 transfer txs
-- (expense + settlement). With B-2 active, the second income tx would
-- be blocked, and the entire transaction would fail (rollback).
--
-- Expected: 0 rows (no registered-customer Fawry tx could complete).
SELECT
    ftx.id AS fawry_tx_id,
    ftx.created_at,
    ftx.selling_price,
    ftx.amount,
    COUNT(t1.id) AS income_tx_count,
    COUNT(t2.id) AS transfer_tx_count
FROM fawry_transactions ftx
LEFT JOIN transactions t1 ON t1.related_type = 'App\\Models\\Fawry\\FawryTransaction'
    AND t1.related_id = ftx.id AND t1.type = 'income'
LEFT JOIN transactions t2 ON t2.related_type = 'App\\Models\\Fawry\\FawryTransaction'
    AND t2.related_id = ftx.id AND t2.type = 'transfer'
WHERE ftx.client_id IS NOT NULL
  AND ftx.created_at >= '2026-08-14 00:00:00'
  AND ftx.created_at <= '2026-08-20 23:59:59'
  AND ftx.deleted_at IS NULL
GROUP BY ftx.id
HAVING income_tx_count != 1 OR transfer_tx_count != 2
ORDER BY ftx.id;

-- ────────────────────────────────────────────────────────────────────
-- QUERY 4: Cashbox balance reconciliation
-- ────────────────────────────────────────────────────────────────────
-- For each cashbox tagged as Fawry/Office:
--   Opening balance (just before 2026-08-14)
--   + Σ credits during window (cash receipts)
--   - Σ debits during window (cash outflows)
--   = Expected closing balance
--
-- Compare to actual closing balance.
SELECT
    a.id AS account_id,
    a.name AS account_name,
    a.balance AS current_balance,
    -- Calculate expected using opening + movements
    COALESCE((
        SELECT a.balance
        FROM account_entries ae
        WHERE ae.account_id = a.id
          AND ae.created_at < '2026-08-14 00:00:00'
        ORDER BY ae.created_at DESC, ae.id DESC
        LIMIT 1
    ), a.balance) AS opening_balance_estimate,
    COALESCE((
        SELECT SUM(ae.credit)
        FROM account_entries ae
        WHERE ae.account_id = a.id
          AND ae.created_at >= '2026-08-14 00:00:00'
          AND ae.created_at <= '2026-08-20 23:59:59'
    ), 0) AS credits_in_window,
    COALESCE((
        SELECT SUM(ae.debit)
        FROM account_entries ae
        WHERE ae.account_id = a.id
          AND ae.created_at >= '2026-08-14 00:00:00'
          AND ae.created_at <= '2026-08-20 23:59:59'
    ), 0) AS debits_in_window
FROM accounts a
WHERE a.type IN ('cashbox', 'wallet', 'bank')
  AND (a.module_type IN ('fawry', 'office'))
  AND a.is_active = TRUE
ORDER BY a.id;

-- ────────────────────────────────────────────────────────────────────
-- QUERY 5: Walk-in Fawry AR balance check
-- ────────────────────────────────────────────────────────────────────
-- Walk-in AR account: "ذمم عملاء فوري غير مسجلين"
-- The balance should match SUM(selling_price - amount) of active walk-in txs.
SELECT
    a.id,
    a.name,
    a.balance AS ar_balance,
    (
        SELECT COALESCE(SUM(selling_price - amount), 0)
        FROM fawry_transactions
        WHERE client_id IS NULL
          AND deleted_at IS NULL
    ) AS expected_walkin_debt
FROM accounts a
WHERE a.name = 'ذمم عملاء فوري غير مسجلين';

-- ────────────────────────────────────────────────────────────────────
-- QUERY 6: Customer AR (registered) balance check
-- ────────────────────────────────────────────────────────────────────
-- For each customer account tagged fawry/office:
--   AR balance should = SUM(selling_price - amount) of their Fawry txs
--   (excluding soft-deleted)
SELECT
    c.id AS customer_id,
    c.full_name,
    c.account_id,
    a.balance AS ar_balance,
    (
        SELECT COALESCE(SUM(ft.selling_price - ft.amount), 0)
        FROM fawry_transactions ft
        WHERE ft.client_id = c.id
          AND ft.deleted_at IS NULL
    ) AS expected_debt
FROM customers c
JOIN accounts a ON a.id = c.account_id
WHERE a.module_type IN ('fawry', 'office')
ORDER BY c.id;

-- ────────────────────────────────────────────────────────────────────
-- QUERY 7: Total Fawry cash receipts in period vs expected
-- ────────────────────────────────────────────────────────────────────
-- Expected cash receipts = SUM(amount) for active (non-deleted)
-- Fawry transactions where amount > 0 AND created_at in period.
SELECT
    SUM(amount) AS total_cash_received_in_period,
    COUNT(*) AS active_tx_count,
    SUM(CASE WHEN client_id IS NOT NULL THEN amount ELSE 0 END) AS registered_cash,
    SUM(CASE WHEN client_id IS NULL THEN amount ELSE 0 END) AS walkin_cash
FROM fawry_transactions
WHERE deleted_at IS NULL
  AND created_at >= '2026-08-14 00:00:00'
  AND created_at <= '2026-08-20 23:59:59';

-- ────────────────────────────────────────────────────────────────────
-- QUERY 8: Audit log entries for failed Fawry creates in period
-- ────────────────────────────────────────────────────────────────────
-- If the application logs failed POST attempts, this would show how
-- many attempted registered-customer creates were rejected.
SELECT
    DATE(created_at) AS log_date,
    COUNT(*) AS failed_attempts,
    SUM(CASE WHEN payload LIKE '%"client_id"%' THEN 1 ELSE 0 END) AS registered_attempts
FROM audit_logs
WHERE created_at >= '2026-08-14 00:00:00'
  AND created_at <= '2026-08-20 23:59:59'
  AND (event LIKE '%fawry%' OR message LIKE '%fawry%' OR message LIKE '%Duplicate income%')
GROUP BY DATE(created_at)
ORDER BY log_date;

-- ────────────────────────────────────────────────────────────────────
-- END OF RECONCILIATION SCRIPT
-- ────────────────────────────────────────────────────────────────────
-- INTERPRETATION GUIDE:
--
--   If QUERY 1 shows registered_count = 0 across the entire period:
--     ✅ B-2 fully blocked registered-customer Fawry creates.
--     → Cash drift is possible if employees collected cash without recording.
--     → Need physical cash count to confirm.
--
--   If QUERY 2 returns rows:
--     ❌ Settlement was recorded as type=Income in this period — bug was
--        active AND somehow let the second income through. Investigate.
--
--   If QUERY 3 returns rows:
--     ❌ Mismatched tx counts — duplicate or missing entries. Investigate.
--
--   If QUERY 4 shows drift between current_balance and (opening + credits - debits):
--     ❌ Cashbox reconciliation mismatch. Investigate the difference.
--
--   If QUERY 5 shows ar_balance != expected_walkin_debt:
--     ❌ Walk-in AR doesn't match per-tx debt. Could be legacy rows or B-2 effect.
--
--   If QUERY 6 shows any customer with ar_balance != expected_debt:
--     ❌ Per-customer AR doesn't match. Investigate the customer.
--
--   If QUERY 7 returns 0 rows but operations team reports cash collected:
--     ❌ Cash collected but not recorded — strong sign of B-2 impact.
--     → Reconcile physical cash count against GL.
