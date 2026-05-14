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
