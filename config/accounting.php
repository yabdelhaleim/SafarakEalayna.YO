<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Single entry façade (Posting API)
    |--------------------------------------------------------------------------
    |
    | يُستحسَن أن تمرّ كل الحركات المالية للموديولات من خلال
    | App\Services\Finance\AccountingService (قيد متوازن أو income/expense/transfer).
    |
    */
    'preferred_posting_service' => \App\Services\Finance\AccountingService::class,

    /*
    |--------------------------------------------------------------------------
    | Audit trail على جدول transactions
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'enabled' => env('ACCOUNTING_AUDIT_ENABLED', true),
        'capture_http' => env('ACCOUNTING_AUDIT_CAPTURE_HTTP', true),
        'warn_missing_context' => env('ACCOUNTING_AUDIT_WARN_NO_CONTEXT', false),
        'log_to_audit_logs_table' => env('ACCOUNTING_AUDIT_LOG_AUDIT_LOGS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | تسوية دورية Σ debit vs Σ credit
    |--------------------------------------------------------------------------
    */
    'reconciliation' => [
        'tolerance' => env('ACCOUNTING_RECON_TOLERANCE', 0.02),
        'balance_vs_entries_tolerance' => env('ACCOUNTING_BALANCE_VS_LEDGER_TOLERANCE', 0.05),
    ],

    /*
    |--------------------------------------------------------------------------
    | منع تعديل accounts.balance خارج مسار الدفتر
    |--------------------------------------------------------------------------
    */
    'balance_guard' => [
        'block_unauthorized_updates' => env('ACCOUNTING_BALANCE_GUARD', true),
        'disable_in_testing' => env('ACCOUNTING_BALANCE_GUARD_OFF_IN_TESTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | طبقة أمان HTTP (وسائط/رؤوس محظورة)
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'reject_bypass_markers' => env('ACCOUNTING_REJECT_FINANCIAL_BYPASS_MARKERS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | تفعيل صارم للـ guards في الـ tests
    |--------------------------------------------------------------------------
    |
    | عند true، الـ guards (FlightCarrier/FlightSystem observer + consumeCogs)
    | تكون نشطة حتى داخل الـ unit tests. الـ PrepaidCogsTest يفعّل هذا الخيار
    | في الـ setUp لاختبار الـ production logic. الـ tests القديمة التي تكتب
    | balance مباشرة تتركها false (الافتراضي).
    |
    */
    'strict_test_guards' => env('ACCOUNTING_STRICT_TEST_GUARDS', false),

    /*
    |--------------------------------------------------------------------------
    | Strict double-entry
    |--------------------------------------------------------------------------
    |
    | When true, recordIncome / recordExpense post two AccountEntry rows via a
    | balanced journal whenever a contra (clearing) account can be resolved.
    |
    */
    'strict_double_entry' => env('ACCOUNTING_STRICT_DOUBLE_ENTRY', true),

    /*
    |--------------------------------------------------------------------------
    | Legacy single-leg postings
    |--------------------------------------------------------------------------
    |
    | Only consulted when strict_double_entry is true but no contra account
    | exists for the given module — normally an error path.
    |
    */
    'allow_legacy_single_leg_fallback' => env('ACCOUNTING_ALLOW_LEGACY_SINGLE_LEG', false),

    /*
    |--------------------------------------------------------------------------
    | Clearing / suspense accounts (resolved by Arabic name → accounts.id)
    |--------------------------------------------------------------------------
    */
    'clearing' => [
        'income' => [
            'flight' => env('ACCOUNTING_INCOME_CLEARING_FLIGHT_NAME', 'إقفال مبيعات الطيران (نظام)'),
            'bus' => env('ACCOUNTING_INCOME_CLEARING_BUS_NAME', 'إقفال إيرادات الباصات'),
            'hajj_umra' => env('ACCOUNTING_INCOME_CLEARING_HAJJ_NAME', 'إقفال إيرادات الحج والعمرة'),
            'visa' => env('ACCOUNTING_INCOME_CLEARING_VISA_NAME', 'إقفال إيرادات التأشيرات'),
            'online' => env('ACCOUNTING_INCOME_CLEARING_ONLINE_NAME', 'إقفال إيرادات الخدمات الإلكترونية'),
            'fawry' => env('ACCOUNTING_INCOME_CLEARING_FAWRY_NAME', 'إقفال إيرادات فوري'),
            'wallet' => env('ACCOUNTING_INCOME_CLEARING_WALLET_NAME', 'إقفال إيرادات المحافظ'),
            'general' => env('ACCOUNTING_INCOME_CLEARING_GENERAL_NAME', 'إقفال إيراد عام (نظام)'),
        ],
        'expense' => [
            'flight' => env('ACCOUNTING_EXPENSE_CLEARING_FLIGHT_NAME', 'إقفال تكاليف الطيران'),
            'bus' => env('ACCOUNTING_EXPENSE_CLEARING_BUS_NAME', 'إقفال تكاليف الباصات'),
            'hajj_umra' => env('ACCOUNTING_EXPENSE_CLEARING_HAJJ_NAME', 'إقفال تكاليف الحج والعمرة'),
            'visa' => env('ACCOUNTING_EXPENSE_CLEARING_VISA_NAME', 'إقفال تكاليف التأشيرات'),
            'online' => env('ACCOUNTING_EXPENSE_CLEARING_ONLINE_NAME', 'إقفال تكاليف الخدمات الإلكترونية'),
            'fawry' => env('ACCOUNTING_EXPENSE_CLEARING_FAWRY_NAME', 'إقفال تكاليف فوري'),
            'wallet' => env('ACCOUNTING_EXPENSE_CLEARING_WALLET_NAME', 'إقفال تكاليف المحافظ'),
            'general' => env('ACCOUNTING_EXPENSE_CLEARING_GENERAL_NAME', 'إقفال تكلفة عامة (نظام)'),
        ],
        /*
         * Per-currency clearing accounts — Phase 7 (multi-currency visa).
         *
         * When a module posts an income/expense in a currency other than the
         * account base currency (e.g. a USD visa booking against an EGP clearing
         * account), routing the entry to a clearing account denominated in the
         * SAME currency preserves ledger integrity without an FX rate snapshot.
         *
         * Each per-currency key is the canonical Arabic name; the resolver
         * creates the row lazily on first use. Falls back to the single
         * `income`/`expense` key when (a) the module is not configured for
         * per-currency or (b) the booking currency is not in the map.
         */
        'income_per_currency' => [
            'visa' => [
                'EGP' => env('ACCOUNTING_VISA_INCOME_CLEARING_EGP', 'إقفال إيرادات التأشيرات (EGP)'),
                'USD' => env('ACCOUNTING_VISA_INCOME_CLEARING_USD', 'إقفال إيرادات التأشيرات (USD)'),
                'SAR' => env('ACCOUNTING_VISA_INCOME_CLEARING_SAR', 'إقفل إيرادات التأشيرات (SAR)'),
            ],
            // FX SAFETY (2026-08-21): add per-currency Hajj/Umra income
            // clearing buckets — mirror of the expense_per_currency block
            // above. Without this, USD/SAR HajjUmra bookings route the
            // income leg to the EGP clearing, hitting the safe-FX rule.
            'hajj_umra' => [
                'EGP' => env('ACCOUNTING_HAJJ_INCOME_CLEARING_EGP', 'إقفال إيرادات الحج والعمرة (EGP)'),
                'USD' => env('ACCOUNTING_HAJJ_INCOME_CLEARING_USD', 'إقفال إيرادات الحج والعمرة (USD)'),
                'SAR' => env('ACCOUNTING_HAJJ_INCOME_CLEARING_SAR', 'إقفال إيرادات الحج والعمرة (SAR)'),
            ],
        ],
        'expense_per_currency' => [
            'visa' => [
                'EGP' => env('ACCOUNTING_VISA_EXPENSE_CLEARING_EGP', 'إقفال تكاليف التأشيرات (EGP)'),
                'USD' => env('ACCOUNTING_VISA_EXPENSE_CLEARING_USD', 'إقفال تكاليف التأشيرات (USD)'),
                'SAR' => env('ACCOUNTING_VISA_EXPENSE_CLEARING_SAR', 'إقفال تكاليف التأشيرات (SAR)'),
            ],
            // FX SAFETY (2026-08-21): add per-currency Hajj/Umra clearing
            // buckets so USD/SAR bookings don't post into the EGP clearing
            // (cross-currency, would hit the safe-FX rejection in
            // TransactionService::recordJournalTransfer).
            'hajj_umra' => [
                'EGP' => env('ACCOUNTING_HAJJ_EXPENSE_CLEARING_EGP', 'إقفال تكاليف الحج والعمرة (EGP)'),
                'USD' => env('ACCOUNTING_HAJJ_EXPENSE_CLEARING_USD', 'إقفال تكاليف الحج والعمرة (USD)'),
                'SAR' => env('ACCOUNTING_HAJJ_EXPENSE_CLEARING_SAR', 'إقفال تكاليف الحج والعمرة (SAR)'),
            ],
        ],
        /*
         * Prepaid asset accounts — شحن الأنظمة/الناقلين/فوري (لا يدخل P&L حتى الاستهلاك).
         */
        'prepaid' => [
            'flight_system' => env('ACCOUNTING_PREPAID_FLIGHT_SYSTEM_NAME', 'رصيد مسبق — أنظمة حجز الطيران'),
            'flight_carrier' => env('ACCOUNTING_PREPAID_FLIGHT_CARRIER_NAME', 'رصيد مسبق — ناقلو الطيران'),
            'fawry' => env('ACCOUNTING_PREPAID_FAWRY_NAME', 'رصيد مسبق — ماكينات فوري'),
        ],
        /*
         * Sales-pending-receivable accounts — FIN-2 (2026-08-23).
         *
         * في الحجز الآجل (بدون دفعة فورية) يُسجَّل دين العميل فقط بدون
         * الاعتراف بالإيراد. تُستخدم هذه الحسابات كحساب مقابل في القيد
         * (pending_receivable → customer) فلا يصنّفه P&L كإيراد لأن
         * from_account ليس في incomeClearing. يُعترف بالإيراد فعلياً
         * عند استلام الدفع عبر addPayment().
         *
         * In credit bookings (no immediate payment) only the customer AR
         * is recorded — no revenue recognition. This account is used as
         * the contra leg of the (pending_receivable → customer) transfer,
         * which the P&L classifier therefore skips (not in incomeClearing).
         * Revenue is recognised at cash receipt via addPayment().
         */
        'sales_pending_receivable' => [
            'flight' => env('ACCOUNTING_FLIGHT_SALES_PENDING_RECEIVABLE_NAME', 'ذمم عملاء طيران معلق'),
        ],
        /*
         * Pending COGS accounts — RC-002 FIX (2026-08-26).
         *
         * FIN-3 cash-basis: COGS must only be recognised when cash arrives.
         * At booking creation we move `prepaid_carrier` → `pending_cogs` (NOT
         * `expense_clearing`). At payment, we move `pending_cogs` →
         * `expense_clearing` for the proportional amount
         * `purchase_price × (paid / selling_price)`. The P&L therefore stays
         * at 0 for unpaid credit bookings.
         */
        'pending_cogs' => [
            'flight' => env('ACCOUNTING_FLIGHT_PENDING_COGS_NAME', 'تكلفة طيران معلقة (تحت التحصيل)'),
        ],
        /*
         * Offset for raw TreasuryService::credit / ::debit when no module context exists.
         */
        'treasury_operations' => env(
            'ACCOUNTING_TREASURY_OPS_CLEARING_NAME',
            'ضبط حركات الخزينة (نظام)'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Treasury enum → حساب الدفتر
    |--------------------------------------------------------------------------
    |
    | اختياري: اربط رمز TreasuryAccount → accounts.id مباشرة (يفيد عند اختلاف أسماء
    | الحقول عن label() المعروضة). أمثلة مفاتيح: TREASURY_CASH_EGP, ...
    |
    */
    'treasury_route' => [
        'by_code' => array_filter([
            'TREASURY_POST_JAARI' => env('ACCOUNTING_TAS_TREASURY_POST_JAARI_ID'),
            'TREASURY_POST_FADDI' => env('ACCOUNTING_TAS_TREASURY_POST_FADDI_ID'),
            'TREASURY_POST_YAWM' => env('ACCOUNTING_TAS_TREASURY_POST_YAWM_ID'),
            'TREASURY_SAVING_YASSER' => env('ACCOUNTING_TAS_TREASURY_SAVING_YASSER_ID'),
            'TREASURY_CASH_EGP' => env('ACCOUNTING_TAS_TREASURY_CASH_EGP_ID'),
            'TREASURY_CASH_KWD' => env('ACCOUNTING_TAS_TREASURY_CASH_KWD_ID'),
            'TREASURY_CASH_SAR' => env('ACCOUNTING_TAS_TREASURY_CASH_SAR_ID'),
            'TREASURY_CASH_USD' => env('ACCOUNTING_TAS_TREASURY_CASH_USD_ID'),
            'TREASURY_BANK_MISR_SAFARK' => env('ACCOUNTING_TAS_BANK_MISR_SAFARK_ID'),
            'TREASURY_BANK_MISR_YASSER' => env('ACCOUNTING_TAS_BANK_MISR_YASSER_ID'),
            'TREASURY_BANK_ALEX' => env('ACCOUNTING_TAS_BANK_ALEX_ID'),
            'TREASURY_BANK_AHLY' => env('ACCOUNTING_TAS_BANK_AHLY_ID'),
            'TREASURY_WALLET_YASSER' => env('ACCOUNTING_TAS_WALLET_YASSER_ID'),
            'TREASURY_WALLET_YASSER2' => env('ACCOUNTING_TAS_WALLET_YASSER2_ID'),
            'TREASURY_WALLET_ARAFA' => env('ACCOUNTING_TAS_WALLET_ARAFA_ID'),
        ], fn ($v) => $v !== null && $v !== ''),

        /*
         | بعد فشل التعيين بـ env (by_code) وفشل تطابق اسم الحساب مع label():
         |
         | - مفتاح ثابت الـ Enum: مثل 'TREASURY_CASH_EGP'
         | - أو التسمية العربية من TreasuryAccount::label()
         |
         | القيمة: account_id رقمًا، أو نص الاسم الدقيق في accounts.name
         |
         | مثل: 'TREASURY_CASH_EGP' => 12, أو 'نقدي مصري' => 'درج المكتب — نقدي'
         */
        'label_aliases' => [],
    ],
];
