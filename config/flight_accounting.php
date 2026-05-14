<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Flight sales clearing (contra) account
    |--------------------------------------------------------------------------
    |
    | When set, flight bookings credit the treasury account via a balanced
    | journal (contra → treasury) instead of only a treasury row. Leave null
    | to resolve by account name after the seed migration runs.
    |
    */
    'ledger_clearing_account_id' => env('FLIGHT_LEDGER_CLEARING_ACCOUNT_ID'),

    'ledger_clearing_account_name' => env(
        'FLIGHT_LEDGER_CLEARING_ACCOUNT_NAME',
        'إقفال مبيعات الطيران (نظام)'
    ),

    /*
    |--------------------------------------------------------------------------
    | Purchase cost — single balance debit
    |--------------------------------------------------------------------------
    |
    | When both flight_carrier_id and flight_system_id are present, only one
    | pool is debited for the purchase amount. Default matches typical «ساين»
    | inventory; set to "system" when NDC/GDS balance lives on the system row.
    |
    | Allowed: carrier | system
    |
    */
    'purchase_balance_default_when_both' => env('FLIGHT_PURCHASE_BALANCE_DEFAULT', 'carrier'),
];
