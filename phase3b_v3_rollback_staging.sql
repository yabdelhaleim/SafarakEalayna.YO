-- =====================================================
-- Phase 3b v3 ROLLBACK — STAGING (full cleanup)
-- =====================================================
-- Reverses the 7 writeoffs applied to staging DB:
--   1. Restore carrier balances
--   2. Restore system balances
--   3. Delete the 7 account_entries (NULL notes rows)
--   4. Delete the 7 transactions
--   5. Delete the 7 audit_logs
--   6. Reset the writeoff account balance to 0
--   7. (Optional) Delete the writeoff account entirely
--
-- ⚠️ READ-ONLY verification FIRST — run [1] before [2]
-- =====================================================

-- ────────────────────────────────────────────────────────
-- [1] VERIFICATION — show what will be rolled back
-- ────────────────────────────────────────────────────────
SELECT 'Account entries to delete (should be 14 = 7 DEBIT + 7 CREDIT):' AS step, COUNT(*) AS count_to_delete
FROM account_entries
WHERE transaction_id IN (
    SELECT id FROM transactions
    WHERE type = 'writeoff'
      AND created_at >= '2026-07-08'
);

SELECT 'Transactions to delete (should be 7):' AS step, COUNT(*) AS count_to_delete
FROM transactions
WHERE type = 'writeoff' AND created_at >= '2026-07-08';

SELECT 'Audit logs to mark as rolled back (should be 7):' AS step, COUNT(*) AS count_to_update
FROM audit_logs
WHERE action = 'writeoff_phase3b_v3';

SELECT 'Carriers that will be restored:' AS step;
SELECT id, name, balance,
       balance + 103731.44 AS restored_balance  -- العربية
FROM flight_carriers WHERE id = 1
UNION ALL
SELECT id, name, balance, balance + 11099.43 FROM flight_carriers WHERE id = 2   -- الجزيرة_مصري
UNION ALL
SELECT id, name, balance, balance + 43164.43 FROM flight_carriers WHERE id = 4   -- نسما
UNION ALL
SELECT id, name, balance, balance + 335.52   FROM flight_carriers WHERE id = 5   -- فلاي أديل
UNION ALL
SELECT id, name, balance, balance + 35818.23 FROM flight_carriers WHERE id = 6;  -- اير كايرو

SELECT 'Systems that will be restored:' AS step;
SELECT id, name, balance,
       balance + 102358.85 AS restored_balance  -- NDC_WONDR
FROM flight_systems WHERE id = 1
UNION ALL
SELECT id, name, balance, balance + 88995.59 FROM flight_systems WHERE id = 2;   -- NDC_X_NSAS

SELECT 'Writeoff account that will be reset (should be 0):' AS step;
SELECT id, name, balance FROM accounts WHERE name = 'مصروفات شطب أرصدة الناقلين - طيران';

SELECT 'Writeoff Contra account that will be reset (should be 0):' AS step;
SELECT id, name, balance FROM accounts WHERE name = 'مقابل شطب أرصدة الناقلين - طيران';

-- ────────────────────────────────────────────────────────
-- [2] EXECUTE THE ROLLBACK (TX-safe)
-- ────────────────────────────────────────────────────────

START TRANSACTION;

-- Restore carrier balances
UPDATE flight_carriers SET balance = balance + 103731.44, updated_at = NOW() WHERE id = 1;
UPDATE flight_carriers SET balance = balance + 11099.43, updated_at = NOW() WHERE id = 2;
UPDATE flight_carriers SET balance = balance + 43164.43, updated_at = NOW() WHERE id = 4;
UPDATE flight_carriers SET balance = balance + 335.52, updated_at = NOW() WHERE id = 5;
UPDATE flight_carriers SET balance = balance + 35818.23, updated_at = NOW() WHERE id = 6;

-- Restore system balances
UPDATE flight_systems SET balance = balance + 102358.85, updated_at = NOW() WHERE id = 1;
UPDATE flight_systems SET balance = balance + 88995.59, updated_at = NOW() WHERE id = 2;

-- Delete the 7 account_entries (transaction_id matches the 7 writeoff transactions)
DELETE FROM account_entries
WHERE transaction_id IN (
    SELECT id FROM transactions WHERE type = 'writeoff' AND created_at >= '2026-07-08'
);

-- Delete the 7 transactions
DELETE FROM transactions
WHERE type = 'writeoff' AND created_at >= '2026-07-08';

-- Delete the 7 audit_logs
DELETE FROM audit_logs
WHERE action = 'writeoff_phase3b_v3';

-- Reset the writeoff account balance to 0 (we keep the account itself for now)
UPDATE accounts
SET balance = 0, updated_at = NOW()
WHERE name = 'مصروفات شطب أرصدة الناقلين - طيران';

-- Reset the writeoff CONTRA account balance to 0
UPDATE accounts
SET balance = 0, updated_at = NOW()
WHERE name = 'مقابل شطب أرصدة الناقلين - طيران';

-- Log the rollback
INSERT INTO audit_logs (
    user_id, action, model_type, model_id,
    ip_address, user_agent, old_values, new_values, notes,
    created_at, updated_at
) VALUES (
    1, 'phase3b_v3_rollback_staging', 'App\\Models\\Account', 0,
    '127.0.0.1', 'phase3b_v3_rollback',
    JSON_OBJECT('status', 'writeoff_applied_then_reverted'),
    JSON_OBJECT('status', 'rolled_back_for_reapply_with_fixes'),
    'Phase 3b v3 staging rollback: will re-apply with AccountEntry notes fix + line 344 typo fix. Reason: notes field was NULL on all 7 entries (root cause: missing from $fillable).',
    NOW(), NOW()
);

COMMIT;

-- ────────────────────────────────────────────────────────
-- [3] VERIFICATION after rollback
-- ────────────────────────────────────────────────────────
SELECT 'After rollback — carriers:' AS step;
SELECT id, name, balance FROM flight_carriers WHERE id IN (1,2,4,5,6);

SELECT 'After rollback — systems:' AS step;
SELECT id, name, balance FROM flight_systems WHERE id IN (1,2);

SELECT 'After rollback — writeoff account:' AS step;
SELECT id, name, balance FROM accounts WHERE name = 'مصروفات شطب أرصدة الناقلين - طيران';

SELECT 'After rollback — writeoff contra account:' AS step;
SELECT id, name, balance FROM accounts WHERE name = 'مقابل شطب أرصدة الناقلين - طيران';

SELECT 'After rollback — writeoff transactions (should be 0):' AS step;
SELECT COUNT(*) AS remaining FROM transactions WHERE type = 'writeoff' AND created_at >= '2026-07-08';

SELECT 'After rollback — writeoff account_entries (should be 0):' AS step;
SELECT COUNT(*) AS remaining FROM account_entries
WHERE transaction_id IN (SELECT id FROM transactions WHERE type = 'writeoff' AND created_at >= '2026-07-08');

-- ────────────────────────────────────────────────────────
-- Expected after rollback:
--   flight_carriers.id=1: 107,414.01 (restored)
--   flight_carriers.id=2: 14,782.00 (restored)
--   flight_carriers.id=4: 46,847.00 (restored)
--   flight_carriers.id=5: 4,018.09 (restored)
--   flight_carriers.id=6: 39,500.80 (restored)
--   flight_systems.id=1:  76,184.20 (restored)
--   flight_systems.id=2:  62,820.94 (restored)
--   writeoff account: balance = 0 (reset, not deleted)
--   writeoff transactions: 0 (deleted)
--   writeoff account_entries: 0 (deleted)
-- ────────────────────────────────────────────────────────
