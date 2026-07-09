-- =====================================================
-- PHASE 3b v3 (Option B): ROLLBACK SCRIPT
-- =====================================================
-- للتراجع عن Phase 3b v3 write-offs (Option B — 385,503.49 EGP)
-- لو حصلت مشكلة
--
-- ⚠️ READ-ONLY verification أولاً:
--   - شغّل الـ SELECT queries في [1] قبل ما تشغل الـ DELETE
--   - تأكد إن transactions + writeoff account + AuditLog متطابقة
--
-- الاستخدام:
--   mysql -u root -p safarakealayna < phase3b_v3_rollback.sql
-- =====================================================

-- ────────────────────────────────────────────────────────
-- [1] التحقق: اعرض الـ transactions والـ entries اللي هتتحذف
-- ────────────────────────────────────────────────────────
SELECT 'Write-off transactions to delete:' AS step, COUNT(*) AS count_to_delete
FROM transactions
WHERE type = 'writeoff'
  AND related_type IN ('App\\Models\\Flight\\FlightCarrier', 'App\\Models\\Flight\\FlightSystem')
  AND created_at >= '2026-07-08 00:00:00';

SELECT 'Write-off account_entries to delete:' AS step, COUNT(*) AS count_to_delete
FROM account_entries
WHERE notes LIKE '%Write-off for%'
  AND notes LIKE '%Phase 3b v3%'
  AND created_at >= '2026-07-08 00:00:00';

SELECT 'Audit logs to mark as rolled back:' AS step, COUNT(*) AS count_to_update
FROM audit_logs
WHERE action = 'writeoff_phase3b_v3';

-- ────────────────────────────────────────────────────────
-- [2] التنفيذ الفعلي (TX آمن)
-- ────────────────────────────────────────────────────────

START TRANSACTION;

-- حذف الـ AccountEntries المرتبطة
DELETE FROM account_entries
WHERE notes LIKE '%Write-off for%'
  AND notes LIKE '%Phase 3b v3%'
  AND created_at >= '2026-07-08 00:00:00';

-- حذف الـ Transactions (هيتمسحوا لكن الـ AuditLog يفضل)
DELETE FROM transactions
WHERE type = 'writeoff'
  AND related_type IN ('App\\Models\\Flight\\FlightCarrier', 'App\\Models\\Flight\\FlightSystem')
  AND created_at >= '2026-07-08 00:00:00';

-- الـ carrier.balance هيرجع تلقائياً لما الـ transactions تتمسح (لكن مش automatically)
-- لازم نرجّع الرصيد يدوياً:
-- (ملاحظة: ده manual SQL لأن Eloquent observer شغال لكن مش مربوط)
-- Option B: الأرقام الجديدة (385,503.49 EGP)
UPDATE flight_carriers SET balance = balance + 103731.44, updated_at = NOW() WHERE id = 1;  -- العربية
UPDATE flight_carriers SET balance = balance + 11099.43, updated_at = NOW() WHERE id = 2;   -- الجزيرة_مصري
UPDATE flight_carriers SET balance = balance + 43164.43, updated_at = NOW() WHERE id = 4;   -- نسما
UPDATE flight_carriers SET balance = balance + 335.52, updated_at = NOW() WHERE id = 5;     -- فلاي أديل
UPDATE flight_carriers SET balance = balance + 35818.23, updated_at = NOW() WHERE id = 6;   -- اير كايرو
UPDATE flight_systems  SET balance = balance + 102358.85, updated_at = NOW() WHERE id = 1;  -- NDC_WONDR
UPDATE flight_systems  SET balance = balance + 88995.59, updated_at = NOW() WHERE id = 2;   -- NDC_X_NSAS

-- ترجيع Writeoff Expense balance
UPDATE accounts
SET balance = balance - 385503.49, updated_at = NOW()  -- 103731.44 + 11099.43 + 43164.43 + 335.52 + 35818.23 + 102358.85 + 88995.59
WHERE name = 'مصروفات شطب أرصدة الناقلين - طيران';

-- تسجيل الـ rollback
INSERT INTO audit_logs (
    user_id, action, model_type, model_id,
    ip_address, user_agent, old_values, new_values, notes,
    created_at, updated_at
) VALUES (
    1, 'phase3b_v3_rollback', 'App\\Models\\Account', 0,
    '127.0.0.1', 'phase3b_v3_rollback',
    JSON_OBJECT('status', 'writeoff_applied'),
    JSON_OBJECT('status', 'rolled_back', 'rolled_back_by', USER()),
    'Phase 3b v3 rollback: restored all 7 carrier/system balances. Will re-apply after re-review.',
    NOW(), NOW()
);

COMMIT;

-- ────────────────────────────────────────────────────────
-- [3] التحقق بعد الـ rollback
-- ────────────────────────────────────────────────────────
SELECT 'After rollback — carriers:' AS step;
SELECT id, name, balance FROM flight_carriers WHERE id IN (1,2,4,5,6);

SELECT 'After rollback — systems:' AS step;
SELECT id, name, balance FROM flight_systems WHERE id IN (1,2);

SELECT 'After rollback — writeoff account:' AS step;
SELECT id, name, balance FROM accounts WHERE name = 'مصروفات شطب أرصدة الناقلين - طيران';

-- ────────────────────────────────────────────────────────
-- 4] المتوقّع بعد الـ rollback:
--     - flight_carriers.id=1: balance = 107,414.01 (مرجع — بعد الـ 50K recharge)
--     - flight_systems.id=1: balance = 76,184.20 (مرجع)
--     - accounts (writeoff): balance = 0 (مرجع)
-- ────────────────────────────────────────────────────────
