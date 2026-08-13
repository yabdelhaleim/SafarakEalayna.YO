-- ════════════════════════════════════════════════════════════════════════════
-- Office Liquidity Snapshot — مرحلة 0 من إصلاح حسابات المكتب
-- ════════════════════════════════════════════════════════════════════════════
-- READ-ONLY queries only. لا تـ DROP / UPDATE / DELETE أي شيء من هنا.
-- شغّلهم على production MySQL (مثلاً في phpMyAdmin أو MySQL Workbench)
-- وابعت النتيجة عشان نكمّل.
-- ════════════════════════════════════════════════════════════════════════════

-- ───────────────────────────────────────────────────────────────────────────
-- Q1) Snapshot كامل — كل الخزن النقدية والبنوك والمحافظ في قسم المكتب
-- ───────────────────────────────────────────────────────────────────────────
SELECT 
    id,
    name,
    type,
    treasury_type,
    bank_name,
    account_number,
    currency,
    balance,
    is_active,
    is_module_vault,
    created_at,
    updated_at
FROM accounts
WHERE (type IN ('cashbox', 'bank', 'wallet') OR type = 'treasury')
  AND module_type = 'office'
  AND deleted_at IS NULL
ORDER BY type, currency, name;

-- ───────────────────────────────────────────────────────────────────────────
-- Q2) ملخص حسب النوع — كام حساب من كل نوع
-- ───────────────────────────────────────────────────────────────────────────
SELECT 
    type,
    COUNT(*) AS account_count,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
FROM accounts
WHERE (type IN ('cashbox', 'bank', 'wallet') OR type = 'treasury')
  AND module_type = 'office'
  AND deleted_at IS NULL
GROUP BY type
ORDER BY type;

-- ───────────────────────────────────────────────────────────────────────────
-- Q3) ملخص حسب العملة — إجمالي الرصيد المخزّن
-- ───────────────────────────────────────────────────────────────────────────
SELECT 
    currency,
    type,
    COUNT(*) AS account_count,
    SUM(balance) AS total_stored_balance
FROM accounts
WHERE (type IN ('cashbox', 'bank', 'wallet') OR type = 'treasury')
  AND module_type = 'office'
  AND deleted_at IS NULL
GROUP BY currency, type
ORDER BY currency, type;

-- ───────────────────────────────────────────────────────────────────────────
-- Q4) الأرصدة السالبة — أي حساب عنده رصيد أقل من صفر
-- ───────────────────────────────────────────────────────────────────────────
SELECT 
    id,
    name,
    type,
    currency,
    balance,
    created_at
FROM accounts
WHERE (type IN ('cashbox', 'bank', 'wallet') OR type = 'treasury')
  AND module_type = 'office'
  AND deleted_at IS NULL
  AND balance < 0
ORDER BY balance ASC;

-- ───────────────────────────────────────────────────────────────────────────
-- Q5) حسابات غير نشطة (is_active = 0) — مفيد نشوف ليه معطلة
-- ───────────────────────────────────────────────────────────────────────────
SELECT 
    id,
    name,
    type,
    currency,
    balance,
    is_active,
    updated_at
FROM accounts
WHERE (type IN ('cashbox', 'bank', 'wallet') OR type = 'treasury')
  AND module_type = 'office'
  AND deleted_at IS NULL
  AND is_active = 0
ORDER BY type, name;

-- ───────────────────────────────────────────────────────────────────────────
-- Q6) حسابات قديمة من نوع treasury (لو في) — احتياطياً
-- ───────────────────────────────────────────────────────────────────────────
SELECT 
    id,
    name,
    currency,
    current_balance,
    is_active,
    created_at
FROM treasuries
WHERE deleted_at IS NULL  -- لو الجدول فيه soft delete
ORDER BY currency, name;

-- ───────────────────────────────────────────────────────────────────────────
-- Q7) عدد الـ account_entries لكل liquidity account — نشوف مين عنده ledger
-- ───────────────────────────────────────────────────────────────────────────
SELECT 
    a.id,
    a.name,
    a.type,
    a.currency,
    a.balance AS stored_balance,
    COUNT(ae.id) AS ledger_entries_count,
    COALESCE(SUM(ae.credit), 0) - COALESCE(SUM(ae.debit), 0) AS computed_balance_from_ledger
FROM accounts a
LEFT JOIN account_entries ae ON ae.account_id = a.id
WHERE (a.type IN ('cashbox', 'bank', 'wallet') OR a.type = 'treasury')
  AND a.module_type = 'office'
  AND a.deleted_at IS NULL
GROUP BY a.id, a.name, a.type, a.currency, a.balance
ORDER BY a.type, a.currency, a.name;

-- ════════════════════════════════════════════════════════════════════════════
-- ملخص ما تـطلعه من Q1–Q7:
--   - عدد الخزن والبنوك والمحافظ
--   - رصيد كل واحد (المخزّن)
--   - الرصيد المحسوب من الـ ledger (Q7) — ده مهم للخطوة الجاية
--   - أي حساب مالهوش entries في الـ ledger (هيظهر بـ computed_balance = 0)
--   - أي حساب رصيده سالب
--
-- ابعتلي الجداول دي وأنا هكمّل بإعادة بناء الأرصدة (المرحلة 1).
-- ════════════════════════════════════════════════════════════════════════════
