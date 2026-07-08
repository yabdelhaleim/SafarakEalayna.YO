-- ========================================================
-- PHASE 3b v1 — ROLLBACK
-- ========================================================
-- هذا الـ rollback بسبب أن الـ fix الأول طبّق في الاتجاه الخاطئ.
-- (credit بدل debit) ده بسأ الـ delta بدل ما يحلّها.
--
-- ⚠️ READ-ONLY verification أولاً — اعمل backup قبل التنفيذ!
--
-- الخطوات:
--   1) تأكد من الـ transaction id الخاطئ (137 من output الـ APPLY)
--   2) احذف الـ transaction_entries والـ transaction والـ audit_log الخاطئ
--   3) رجّع الـ balance بتاع prepaid لـ -31,587.15 (الأصلي)
--   4) صفر الـ adjustment account balance
-- ========================================================

-- ────────────────────────────────────────────────────────
-- [0] Backup أولاً (خارج الـ SQL — اعمله في الـ SSH)
-- ────────────────────────────────────────────────────────
-- ! في SSH run:
-- mysqldump -u root -p safarakealayna > /var/www/safarakealayna/backup_pre_rollback_$(date +%Y%m%d_%H%M%S).sql

-- ────────────────────────────────────────────────────────
-- [1] تحقق: اجمع الـ IDs اللي هنحذفهم
-- ────────────────────────────────────────────────────────
SELECT 'Bad transaction to delete:' AS step, id, amount, type, related_type, related_id, created_at
FROM transactions
WHERE related_type = 'phase3b_reconciliation'
  AND related_id = (SELECT id FROM accounts WHERE name = 'رصيد مسبق — ناقلو الطيران');

SELECT 'Bad account_entries to delete:' AS step, COUNT(*) AS entry_count
FROM account_entries
WHERE transaction_id IN (
    SELECT id FROM transactions
    WHERE related_type = 'phase3b_reconciliation'
    AND related_id = (SELECT id FROM accounts WHERE name = 'رصيد مسبق — ناقلو الطيران')
);

SELECT 'Bad audit_logs to delete:' AS step, COUNT(*) AS audit_count
FROM audit_logs
WHERE action = 'phase3b_reconciliation';

SELECT 'Adjustment account:' AS step, id, name, balance
FROM accounts
WHERE name = 'تسوية فروقات افتتاحية — ناقلو الطيران';

SELECT 'Prepaid current state:' AS step, id, name, balance
FROM accounts
WHERE name = 'رصيد مسبق — ناقلو الطيران';

-- ────────────────────────────────────────────────────────
-- [2] التنفيذ الفعلي (لما يوافق المحاسب على rollback)
-- ────────────────────────────────────────────────────────
-- ! اقرأ الـ output من [1] قبل ما تنفّذ [2]
-- ! الـ statements في [2] بتشتغل مع transaction_id ديناميكي

START TRANSACTION;

-- حذف الـ account_entries الخاطئة
DELETE FROM account_entries
WHERE transaction_id IN (
    SELECT id FROM transactions
    WHERE related_type = 'phase3b_reconciliation'
);

-- حذف الـ transaction نفسه
DELETE FROM transactions
WHERE related_type = 'phase3b_reconciliation';

-- حذف الـ audit_logs الخاطئة
DELETE FROM audit_logs
WHERE action = 'phase3b_reconciliation';

-- رجّع الـ prepaid balance لـ -31,587.15 (الأصلي)
UPDATE accounts
SET balance = -31587.15,
    updated_at = NOW()
WHERE name = 'رصيد مسبق — ناقلو الطيران';

-- صفر الـ adjustment balance (ده لسه صالح كحساب backup، نقدر نستخدمه بعدين)
UPDATE accounts
SET balance = 0,
    updated_at = NOW()
WHERE name = 'تسوية فروقات افتتاحية — ناقلو الطيران';

-- أكتب audit_log جديد يوضح الـ rollback
INSERT INTO audit_logs (
    user_id, action, model_type, model_id,
    ip_address, user_agent, old_values, new_values, notes,
    created_at, updated_at
) VALUES (
    1, 'phase3b_rollback_v1',
    'App\\Models\\Account',
    (SELECT id FROM accounts WHERE name = 'رصيد مسبق — ناقلو الطيران'),
    '127.0.0.1', 'phase3b_v1_rollback',
    JSON_OBJECT('balance', -15837.15, 'note', 'rollback of incorrect fix'),
    JSON_OBJECT('balance', -31587.15, 'note', 'restored to original state'),
    'Phase 3b v1 rollback: reverted incorrect reconciliation transaction. Will re-apply with correct approach after accounting review.',
    NOW(), NOW()
);

-- ════════════════════════════════════════════════════════════
COMMIT;

-- ────────────────────────────────────────────────────────
-- [3] التحقق بعد الـ rollback
-- ────────────────────────────────────────────────────────
SELECT 'Post-rollback Prepaid:' AS step, name, balance
FROM accounts
WHERE name IN ('رصيد مسبق — ناقلو الطيران', 'تسوية فروقات افتتاحية — ناقلو الطيران');

SELECT 'Post-rollback Prepaid entries sum:' AS step,
       SUM(credit) - SUM(debit) AS entries_sum
FROM account_entries
WHERE account_id = (SELECT id FROM accounts WHERE name = 'رصيد مسبق — ناقلو الطيران');

-- المتوقّع:
--   Prepaid balance: -31,587.15
--   Adjustment balance: 0
--   entries sum: -15,837.15
--   delta: -15,750 (نفس الحالة الأصلية)
