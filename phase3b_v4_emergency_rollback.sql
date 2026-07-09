-- ════════════════════════════════════════════════════════════════════
-- PHASE 3b v4: EMERGENCY ROLLBACK SCRIPT
-- ════════════════════════════════════════════════════════════════════
-- ⚠️ USE ONLY IF: the commit succeeded but post-commit verification
--    failed (data corruption detected). This script manually reverses
--    all 7 write-off transactions.
--
-- Run with:
--   mysql -u root -p <DB_NAME> < phase3b_v4_emergency_rollback.sql
--
-- Replace <DB_NAME> with: safarakealayna (prod) or safarakealayna_staging
--
-- ⚠️ BEFORE RUNNING:
--   1. Verify the 7 transactions exist (SELECT * FROM transactions WHERE type='writeoff' AND notes LIKE '%Phase 3b v4%')
--   2. Confirm with the user before executing on production
-- ════════════════════════════════════════════════════════════════════

-- Start transaction for the rollback itself (atomic)
START TRANSACTION;

-- ───────────────────────────────────────────────────────────────────
-- Step 1: Restore entity balances (carriers)
-- ───────────────────────────────────────────────────────────────────
UPDATE flight_carriers SET balance = 107414.01 WHERE id = 1 AND name = 'العربية';
UPDATE flight_carriers SET balance = 14782.00  WHERE id = 2 AND name = 'الجزيرة_مصري';
UPDATE flight_carriers SET balance = 46847.00  WHERE id = 4 AND name = 'نسما للطيران';
UPDATE flight_carriers SET balance = 4018.09   WHERE id = 5 AND name = 'فلاي أديل';
UPDATE flight_carriers SET balance = 39500.80  WHERE id = 6 AND name = 'اير كايرو';

-- ───────────────────────────────────────────────────────────────────
-- Step 2: Restore entity balances (systems)
-- ───────────────────────────────────────────────────────────────────
UPDATE flight_systems SET balance = 76184.20  WHERE id = 1 AND name = 'NDC_ WONDR';
UPDATE flight_systems SET balance = 62820.94  WHERE id = 2 AND name LIKE '%NSAS%';

-- ───────────────────────────────────────────────────────────────────
-- Step 3: Reset writeoff accounts
-- ───────────────────────────────────────────────────────────────────
UPDATE accounts SET balance = 0 WHERE id = 67;  -- مصروفات شطب أرصدة الناقلين - طيران
UPDATE accounts SET balance = 0 WHERE id = 70;  -- مقابل شطب أرصدة الناقلين - طيران

-- ───────────────────────────────────────────────────────────────────
-- Step 4: Delete account entries (14 rows: 7 DEBIT + 7 CREDIT)
-- ───────────────────────────────────────────────────────────────────
DELETE FROM account_entries
WHERE transaction_id IN (
    SELECT id FROM transactions
    WHERE type = 'writeoff'
      AND notes LIKE '%Phase 3b v4%'
);

-- ───────────────────────────────────────────────────────────────────
-- Step 5: Delete transactions (7 rows)
-- ───────────────────────────────────────────────────────────────────
DELETE FROM transactions
WHERE type = 'writeoff'
  AND notes LIKE '%Phase 3b v4%';

-- ───────────────────────────────────────────────────────────────────
-- Step 6: Mark audit logs as rolled back (do NOT delete — preserve trail)
-- ───────────────────────────────────────────────────────────────────
UPDATE audit_logs
SET notes = CONCAT('[ROLLED BACK] ', notes)
WHERE action = 'writeoff_phase3b_v3'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
  AND notes NOT LIKE '[ROLLED BACK] %';

-- Commit the rollback
COMMIT;

-- ───────────────────────────────────────────────────────────────────
-- Verification queries (run after COMMIT)
-- ───────────────────────────────────────────────────────────────────
SELECT '─── POST-ROLLBACK VERIFICATION ───' AS '';

SELECT id, name, balance
FROM flight_carriers
WHERE id IN (1, 2, 4, 5, 6)
ORDER BY id;

SELECT id, name, balance
FROM flight_systems
WHERE id IN (1, 2)
ORDER BY id;

SELECT id, name, balance
FROM accounts
WHERE id IN (67, 70)
ORDER BY id;

SELECT COUNT(*) AS remaining_writeoff_transactions
FROM transactions
WHERE type = 'writeoff'
  AND notes LIKE '%Phase 3b v4%';

SELECT COUNT(*) AS remaining_writeoff_entries
FROM account_entries
WHERE transaction_id NOT IN (SELECT id FROM transactions);

-- ════════════════════════════════════════════════════════════════════
-- EXPECTED RESULTS after rollback:
--   Carriers: العربية=107414.01, الجزيرة=14782, نسما=46847, فلاي أديل=4018.09, اير كايرو=39500.80
--   Systems:  NDC_WONDR=76184.20, NDC_NSAS=62820.94
--   Accounts: 67=0, 70=0
--   remaining_writeoff_transactions = 0
--   remaining_writeoff_entries = 0
-- ════════════════════════════════════════════════════════════════════