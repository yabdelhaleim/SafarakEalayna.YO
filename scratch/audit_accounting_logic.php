<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Customer;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\AccountEntry;
use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Enums\BookingChannelType;
use App\Enums\FlightBookingStatus;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\VisaBooking;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusCompany;
use App\Models\Online\OnlineTransaction;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Fawry\FawryTransaction;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryMachine;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;

use App\Services\Flight\FlightBookingService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\Visa\VisaBookingService;
use App\Services\Bus\BusBookingService;
use App\Services\Online\OnlineTransactionService;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Wallet\WalletTransactionService;
use App\Services\Finance\TreasuryService;

// Use standard model guarding to prevent extra attributes from causing DB insert errors
// Illuminate\Database\Eloquent\Model::unguard();

DB::beginTransaction();

$auditLogs = [];
$success = true;

try {
    echo "=== STARTING COMPREHENSIVE ACCOUNTING LOGIC AUDIT ===\n\n";

    $treasuryService = app(TreasuryService::class);

    // =========================================================================
    // PHASE 1: SETUP — create all accounts needed before measuring baseline
    // =========================================================================

    // 1. Setup User and Customer
    $user = User::first() ?: User::create([
        'name' => 'Audit Admin',
        'email' => 'audit@example.com',
        'password' => bcrypt('password'),
        'role' => 'admin',
        'is_active' => true,
    ]);
    Auth::login($user);

    $customerTourism = Customer::create([
        'full_name' => 'عميل مراجعة السياحة',
        'phone' => '01099999999',
        'status' => 'active',
    ]);

    $customerOffice = Customer::create([
        'full_name' => 'عميل مراجعة المكتب',
        'phone' => '01088888888',
        'status' => 'active',
    ]);

    // Ensure all clearing accounts are registered and configured
    $clearingService = app(\App\Services\Finance\LedgerClearingAccounts::class);
    $clearingKeys = ['flight', 'bus', 'hajj_umra', 'visa', 'online', 'fawry', 'wallet', 'general'];
    foreach ($clearingKeys as $key) {
        $clearingService->incomeContraIdForModule($key);
        $clearingService->expenseContraIdForModule($key);
    }
    $clearingService->treasuryOperationsContraAccountId();

    // Ensure prepaid accounts exist
    $clearingService->prepaidAccountId('flight_system');
    $clearingService->prepaidAccountId('flight_carrier');
    $fawryPrepaidId = $clearingService->prepaidAccountId('fawry');
    
    // Set initial balance for Fawry prepaid account to avoid Insufficient Balance exception
    $fawryPrepaidAcc = Account::find($fawryPrepaidId);
    if ($fawryPrepaidAcc) {
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($fawryPrepaidAcc) {
            $fawryPrepaidAcc->update(['balance' => 10000.00]);
        });
    }

    // Seed exchange rate for USD to avoid calibration drift in baseline
    DB::table('exchange_rates')->insert([
        'from_currency' => 'USD',
        'to_currency' => 'EGP',
        'rate' => 50.00,
        'is_active' => true,
        'effective_date' => now()->subDays(1)->toDateString(),
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create general office drawer account (EGP) for settlements if not present
    $cashboxEGP = Account::firstOrCreate(
        ['name' => 'درج المكتب الرئيسي (EGP)'],
        [
            'type' => AccountType::Cashbox->value,
            'balance' => 50000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'created_by' => $user->id,
        ]
    );

    // Create flight cashbox account (EGP) for tourism division cash movements
    $flightCashbox = Account::firstOrCreate(
        ['name' => 'درج الطيران الرئيسي (EGP)'],
        [
            'type' => AccountType::Cashbox->value,
            'balance' => 50000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $user->id,
        ]
    );

    // Create tourism-sector support entities. We set initial balance for FlightSystem
    // so it has enough funds for booking simulation. The baseline calibration will account for this.
    $system = FlightSystem::create([
        'name' => 'System Amadeus Audit',
        'code' => 'Amadeus',
        'type' => 'system',
        'balance' => 50000.00,
        'currency' => 'EGP',
        'is_active' => true,
    ]);

    $execCompany = HajjUmraExecutingCompany::create([
        'name' => 'الشركة العربية لخدمات الحج والعمرة',
        'phone' => '022345678',
        'is_active' => true,
    ]);

    $program = Program::create([
        'program_name' => 'برنامج عمرة رجب المميز',
        'program_type' => 'umrah',
        'executing_company_id' => $execCompany->id,
        'total_nights' => 14,
        'mecca_hotel_name' => 'فندق مكة للتجربة',
        'mecca_nights' => 7,
        'medina_nights' => 7,
        'departure_date' => now()->addDays(10)->toDateString(),
        'return_date' => now()->addDays(24)->toDateString(),
        'airline' => 'EgyptAir',
        'departure_point' => 'CAI',
        'booking_status' => 'open',
        'default_purchase_price' => 10000,
        'default_selling_price' => 15000,
        'is_active' => true,
    ]);

    $visaAgent = VisaAgent::create([
        'company_name' => 'مكتب اعتماد التأشيرات القنصلي',
        'phone' => '029876543',
        'is_active' => true,
    ]);

    // Setup Fawry support entities
    $fawryOpType = FawryOperationType::create([
        'name_ar' => 'دفع فاتورة كهرباء',
        'name_en' => 'Electricity Bill',
        'code' => 'electricity_payment',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $fawryPaymentMethod = FawryPaymentMethod::create([
        'name_ar' => 'رصيد فوري المباشر',
        'name_en' => 'Direct Fawry Balance',
        'code' => 'direct_fawry_balance',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $fawryMachine = FawryMachine::create([
        'name' => 'ماكينة فوري الفرعية - الاستقبال',
        'type' => 'fawry',
        'machine_number' => 'FAWRY-SUB-001',
        'balance' => 10000.00,
        'is_active' => true,
    ]);

    // Setup Wallet support entities
    $walletType = WalletType::firstOrCreate(
        ['code' => 'vodafone_cash'],
        [
            'name' => 'فودافون كاش',
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $walletAccount = Account::create([
        'name' => 'محفظة فودافون كاش - مراجعة',
        'type' => AccountType::Wallet->value,
        'module_type' => 'wallet_transfer',
        'balance' => 20000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => 'office',
        'wallet_provider' => WalletProvider::VodafoneCash->value,
        'wallet_number' => '01055555555',
        'created_by' => $user->id,
    ]);

    $walletCashbox = Account::create([
        'name' => 'خزينة تحويلات المحافظ - مراجعة',
        'type' => AccountType::Cashbox->value,
        'module_type' => 'wallet_transfer',
        'balance' => 10000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => 'office',
        'created_by' => $user->id,
    ]);

    $usdCarrier = FlightCarrier::create([
        'name' => 'United Airlines Audit (USD)',
        'code' => 'UA',
        'balance' => 2000.00,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    $usdTreasury = \App\Models\Treasury::create([
        'name' => 'خزينة دولار المراجعة',
        'currency' => 'USD',
        'current_balance' => 1000.00,
        'is_active' => true,
    ]);

    $usdAccount = Account::create([
        'name' => 'خزينة دولار المراجعة',
        'type' => AccountType::Cashbox->value,
        'currency' => 'USD',
        'is_active' => true,
        'owner_type' => 'office',
        'module_type' => 'flights',
        'created_by' => $user->id,
    ]);

    // =========================================================================
    // PHASE 2: CAPTURE BASELINE TRIAL BALANCE (after setup, before bookings)
    // Calibrate base_capital so starting Tourism variance = 0
    // =========================================================================
    $baselineTourismTB = $treasuryService->getTrialBalance();
    $baselineOfficeTB  = $treasuryService->getOfficeTrialBalance();

    // Calibrate base_capital so the baseline tourism variance = 0
    $calibratedCapital = ($baselineTourismTB['total_balances']
        + $baselineTourismTB['total_liquidity']
        + $baselineTourismTB['due_to_us']
        - $baselineTourismTB['due_from_us'])
        - $baselineTourismTB['profits'];
    $printSetting = \App\Models\Setting\PrintSetting::first() ?: new \App\Models\Setting\PrintSetting();
    $printSetting->base_capital = $calibratedCapital;
    $printSetting->save();

    // Re-fetch after calibration
    $baselineTourismTB = $treasuryService->getTrialBalance();
    $baselineOfficeTB  = $treasuryService->getOfficeTrialBalance();

    echo "--- BASELINE TRIAL BALANCES (after setup, before bookings) ---\n";
    echo "Tourism Baseline Variance: " . number_format($baselineTourismTB['variance'], 2) . " EGP (should be ~0)\n";
    echo "Office  Baseline Variance: " . number_format($baselineOfficeTB['variance'], 2) . " EGP\n";
    echo "--------------------------------------------------------------\n\n";

    // Print accounts after setup
    echo "--- ACCOUNTS AFTER SETUP ---\n";
    foreach (Account::active()->get() as $acc) {
        echo sprintf(
            "  [%d] %s | type=%s | module=%s | bal=%.2f %s\n",
            $acc->id, $acc->name, $acc->type->value,
            $acc->module_type ?: 'general', (float) $acc->balance, $acc->currency
        );
    }
    echo "----------------------------\n\n";


    // Helper function to check transaction entries
    $verifyEntries = function (string $moduleName, $relatedType, $relatedId) use (&$auditLogs) {
        $txs = Transaction::where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->get();

        $allBalanced = true;
        $details = [];
        $treasuryService = app(TreasuryService::class);

        foreach ($txs as $tx) {
            $entries = AccountEntry::where('transaction_id', $tx->id)->get();
            
            $sumDebitEgp = 0.0;
            $sumCreditEgp = 0.0;

            foreach ($entries as $entry) {
                $acc = Account::find($entry->account_id);
                $rate = 1.0;
                if ($acc->currency && $acc->currency !== 'EGP') {
                    $rate = $treasuryService->getAveragePurchaseRate($acc->currency);
                }
                $sumDebitEgp += (float) $entry->debit * $rate;
                $sumCreditEgp += (float) $entry->credit * $rate;
            }

            $isBalanced = abs($sumDebitEgp - $sumCreditEgp) < 0.05;

            if (!$isBalanced) {
                $allBalanced = false;
            }

            foreach ($entries as $entry) {
                $acc = Account::find($entry->account_id);
                $details[] = sprintf(
                    "Account '%s' (Type: %s, Div: %s) -> Debit: %.2f, Credit: %.2f, Balance After: %.2f",
                    $acc->name,
                    $acc->type->value,
                    $acc->module_type ?: 'general',
                    $entry->debit,
                    $entry->credit,
                    $entry->balance_after
                );
            }
        }

        return [
            'balanced' => $allBalanced && count($txs) > 0,
            'details' => $details,
            'tx_count' => count($txs)
        ];
    };

    // =========================================================================
    // MODULE 1: FLIGHTS (TOURISM DIVISION)
    // =========================================================================
    echo "Auditing MODULE 1: FLIGHTS...\n";
    // $system already created in setup phase above

    $flightBookingService = app(FlightBookingService::class);
    $flightBooking = $flightBookingService->createBooking([
        'customer_id' => $customerTourism->id,
        'purchase_price' => 2000,
        'selling_price' => 3000,
        'currency' => 'EGP',
        'booking_channel_type' => 'system',
        'booking_channel_provider' => 'Amadeus',
        'system_type' => 'Amadeus',
        'flight_system_id' => $system->id,
        'purchase_balance_source' => 'system',
        'departure_date' => now()->addDays(2)->toDateString(),
        'passenger_count' => 1,
        'airline_name' => 'EgyptAir',
        'from_airport' => 'CAI',
        'to_airport' => 'DXB',
    ]);
    
    // Explicitly update status to CONFIRMED so that TreasuryService can collect the profit
    $flightBooking->update(['status' => 'CONFIRMED']);

    $flightBookingVerification = $verifyEntries('Flights', FlightBooking::class, $flightBooking->id);
    $auditLogs[] = [
        'module' => 'Flights (طيران)',
        'division' => 'Tourism (سياحة)',
        'action' => 'Create Booking (بيع تذكرة طيران)',
        'balanced' => $flightBookingVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $flightBookingVerification['details']
    ];

    // Add payment to the booking
    $flightBookingService->addPayment($flightBooking, [
        'amount' => 3000.00,
        'account_id' => $flightCashbox->id,
        'payment_method' => 'cash',
        'notes' => 'سداد قيمة الحجز بالكامل بالجنيه',
    ]);

    $flightBookingPaymentVerification = $verifyEntries('Flights Payment', FlightBooking::class, $flightBooking->id);
    $auditLogs[] = [
        'module' => 'Flights (طيران)',
        'division' => 'Tourism (سياحة)',
        'action' => 'Add Payment (سداد الحجز)',
        'balanced' => $flightBookingPaymentVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $flightBookingPaymentVerification['details']
    ];

    // Cancel / Refund the booking
    $flightBookingService->cancelBooking($flightBooking, [
        'airline_penalty' => 200.00,
        'office_penalty' => 100.00,
        'account_id' => $flightCashbox->id,
        'notes' => 'إلغاء حجز مع تطبيق غرامات الإلغاء',
    ]);

    $flightBookingCancelVerification = $verifyEntries('Flights Cancel', FlightBooking::class, $flightBooking->id);
    $auditLogs[] = [
        'module' => 'Flights (طيران)',
        'division' => 'Tourism (سياحة)',
        'action' => 'Cancel Booking (إلغاء واسترداد الحجز)',
        'balanced' => $flightBookingCancelVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $flightBookingCancelVerification['details']
    ];

    // =========================================================================
    // MODULE 1B: MULTI-CURRENCY FLIGHT & REFUND (USD)
    // =========================================================================
    echo "Auditing MODULE 1B: MULTI-CURRENCY FLIGHT & B2C CANCELLATION (USD)...\n";

    // Create a USD Booking
    // Purchase Price: 80 USD, Selling Price: 100 USD. Exchange rate: 50.00.
    // EGP equivalents: Purchase = 4000 EGP, Selling = 5000 EGP.
    $usdBooking = $flightBookingService->createBooking([
        'customer_id' => $customerTourism->id,
        'purchase_price_foreign' => 80.00,
        'selling_price' => 100.00,
        'currency' => 'USD',
        'exchange_rate' => 50.00,
        'booking_channel_type' => 'system',
        'booking_channel_provider' => 'UA',
        'flight_carrier_id' => $usdCarrier->id,
        'purchase_balance_source' => 'carrier',
        'departure_date' => now()->addDays(5)->toDateString(),
        'passenger_count' => 1,
        'airline_name' => 'United Airlines',
        'from_airport' => 'CAI',
        'to_airport' => 'ORD',
    ]);

    $usdBooking->update(['status' => 'CONFIRMED']);

    $usdBookingVerification = $verifyEntries('Flights USD', FlightBooking::class, $usdBooking->id);
    $auditLogs[] = [
        'module' => 'Flights USD (طيران بالدولار)',
        'division' => 'Tourism (سياحة)',
        'action' => 'Create USD Booking',
        'balanced' => $usdBookingVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $usdBookingVerification['details']
    ];

    // Add payment of 5000 EGP (100 USD at rate 50)
    $flightBookingService->addPayment($usdBooking, [
        'amount' => 5000.00,
        'account_id' => $flightCashbox->id,
        'payment_method' => 'cash',
        'notes' => 'سداد قيمة الحجز بالدولار (5000 جنيه)',
    ]);

    $usdPaymentVerification = $verifyEntries('Flights USD Payment', FlightBooking::class, $usdBooking->id);
    $auditLogs[] = [
        'module' => 'Flights USD (طيران بالدولار)',
        'division' => 'Tourism (سياحة)',
        'action' => 'Add Payment USD',
        'balanced' => $usdPaymentVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $usdPaymentVerification['details']
    ];

    // Cancel / Refund USD Booking (B2C)
    // Penalty: airline 10 USD (500 EGP), office 5 USD (250 EGP). Refund amount: 5000 - 750 = 4250 EGP.
    $flightBookingService->cancelBooking($usdBooking, [
        'airline_penalty' => 500.00,
        'office_penalty' => 250.00,
        'account_id' => $flightCashbox->id,
        'notes' => 'إلغاء حجز دولار مع تطبيق الغرامات بالجنيه',
    ]);

    $usdCancelVerification = $verifyEntries('Flights USD Cancel', FlightBooking::class, $usdBooking->id);
    $auditLogs[] = [
        'module' => 'Flights USD (طيران بالدولار)',
        'division' => 'Tourism (سياحة)',
        'action' => 'Cancel Booking USD',
        'balanced' => $usdCancelVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $usdCancelVerification['details']
    ];


    // =========================================================================
    // MODULE 1C: B2B REFUND SETTLEMENT (USD)
    // =========================================================================
    echo "Auditing MODULE 1C: B2B REFUND SETTLEMENT (USD)...\n";

    // Create a separate booking to test B2B refund request
    $usdBooking2 = $flightBookingService->createBooking([
        'customer_id' => $customerTourism->id,
        'purchase_price_foreign' => 80.00,
        'selling_price' => 100.00,
        'currency' => 'USD',
        'exchange_rate' => 50.00,
        'booking_channel_type' => 'system',
        'booking_channel_provider' => 'UA',
        'flight_carrier_id' => $usdCarrier->id,
        'purchase_balance_source' => 'carrier',
        'departure_date' => now()->addDays(5)->toDateString(),
        'passenger_count' => 1,
        'airline_name' => 'United Airlines',
        'from_airport' => 'CAI',
        'to_airport' => 'ORD',
    ]);

    $usdBooking2->update(['status' => 'CONFIRMED']);

    // Create a Refund Request (B2B)
    $refundService = app(\App\Services\Flight\RefundService::class);
    $refundRequest = $refundService->createRefundRequest([
        'flight_booking_id' => $usdBooking2->id,
        'cancellation_fee' => 10.00, // USD
        'refund_currency' => 'USD',
        'refund_exchange_rate' => 50.00,
        'destination' => 'agency_treasury',
        'treasury_id' => $usdTreasury->id,
        'notes' => 'طلب استرجاع بالدولار من شركة الطيران',
    ], $user->id);

    // Process B2B Refund
    $refundService->processRefundRequest($refundRequest->id, $user->id);

    $b2bRefundVerification = $verifyEntries('Flights B2B Refund', FlightBooking::class, $usdBooking2->id);
    $auditLogs[] = [
        'module' => 'Flights B2B Refund (استرجاع B2B)',
        'division' => 'Tourism (سياحة)',
        'action' => 'Process B2B Refund (USD)',
        'balanced' => $b2bRefundVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $b2bRefundVerification['details']
    ];

    // =========================================================================
    // MODULE 2: HAJJ & UMRAH (TOURISM DIVISION)
    // =========================================================================
    echo "Auditing MODULE 2: HAJJ & UMRAH...\n";
    // $execCompany and $program already created in setup phase above

    $hajjUmraBookingService = app(HajjUmraBookingService::class);
    $hajjUmraBooking = $hajjUmraBookingService->create([
        'customer_id' => $customerTourism->id,
        'program_id' => $program->id,
        'purchase_price' => 10000,
        'selling_price' => 15000,
        'currency' => 'EGP',
        'status' => 'confirmed',
        'account_id' => $flightCashbox->id,
    ]);

    $hajjUmraVerification = $verifyEntries('HajjUmra', HajjUmraBooking::class, $hajjUmraBooking->id);
    $auditLogs[] = [
        'module' => 'Hajj & Umrah (حج وعمرة)',
        'division' => 'Tourism (سياحة)',
        'action' => 'Create Hajj/Umra Booking',
        'balanced' => $hajjUmraVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $hajjUmraVerification['details']
    ];

    // =========================================================================
    // MODULE 3: VISA (TOURISM DIVISION)
    // =========================================================================
    echo "Auditing MODULE 3: VISA...\n";
    // $visaAgent already created in setup phase above

    $visaBookingService = app(VisaBookingService::class);
    $visaBooking = $visaBookingService->create([
        'customer_id' => $customerTourism->id,
        'purchase_price' => 4000,
        'selling_price' => 5500,
        'service_fee' => 500,
        'currency' => 'EGP',
        'status' => 'submitted',
        'account_id' => $flightCashbox->id,
        'visa_details' => [
            'country' => 'Saudi Arabia',
            'visa_type' => 'tourist',
            'visa_agent_id' => $visaAgent->id,
        ],
    ]);

    $visaVerification = $verifyEntries('Visa', VisaBooking::class, $visaBooking->id);
    $auditLogs[] = [
        'module' => 'Visa (التأشيرات)',
        'division' => 'Tourism (سياحة)',
        'action' => 'Create Visa Booking',
        'balanced' => $visaVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $visaVerification['details']
    ];

    // =========================================================================
    // MODULE 4: BUS (OFFICE DIVISION)
    // =========================================================================
    echo "Auditing MODULE 4: BUS...\n";

    $busCompany = BusCompany::create([
        'name' => 'شركة سوبر جيت للنقل البري',
        'phone' => '01011111111',
        'is_active' => true,
    ]);

    $busInventory = BusInventory::create([
        'company_id' => $busCompany->id,
        'route' => 'القاهرة - الغردقة',
        'travel_date' => now()->addDays(1)->toDateString(),
        'departure_time' => '08:00:00',
        'total_tickets' => 50,
        'available_tickets' => 50,
        'cost_per_ticket' => 150.00,
        'selling_price' => 250.00,
        'payment_type' => 'deferred',
        'total_cost' => 7500.00,
        'amount_paid' => 0.00,
        'remaining_debt' => 7500.00,
        'is_active' => true,
    ]);

    $busBookingService = app(BusBookingService::class);
    $busBooking = $busBookingService->createBooking([
        'inventory_id' => $busInventory->id,
        'customer_id' => $customerOffice->id,
        'quantity' => 2,
        'notes' => 'حجز تذكرتين لعميل المراجعة',
    ]);

    // Update status to paid so that TreasuryService can collect the profit
    $busBooking->update(['status' => 'paid']);

    $busVerification = $verifyEntries('Bus', BusBooking::class, $busBooking->id);
    $auditLogs[] = [
        'module' => 'Bus (الباصات)',
        'division' => 'Office (المكتب)',
        'action' => 'Create Bus Booking',
        'balanced' => $busVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $busVerification['details']
    ];

    // =========================================================================
    // MODULE 5: ONLINE SERVICES (OFFICE DIVISION)
    // =========================================================================
    echo "Auditing MODULE 5: ONLINE SERVICES...\n";

    $onlineServiceType = OnlineServiceType::create([
        'name_ar' => 'شحن شدات ببجي',
        'name_en' => 'PUBG UC Recharge',
        'code' => 'pubg_recharge',
        'is_active' => true,
    ]);

    $onlineProvider = OnlineServiceProvider::create([
        'name_ar' => 'مورد شحن ألعاب اونلاين',
        'name_en' => 'Games Supplier',
        'code' => 'games_supplier',
        'is_active' => true,
    ]);

    $onlineTransactionService = app(OnlineTransactionService::class);
    $onlineTransaction = $onlineTransactionService->create([
        'service_type_id' => $onlineServiceType->id,
        'provider_id' => $onlineProvider->id,
        'customer_id' => $customerOffice->id,
        'purchase_price' => 200,
        'selling_price' => 250,
        'amount_paid' => 250,
        'payment_method' => 'cash',
        'account_id' => $cashboxEGP->id,
        'status' => 'completed',
    ]);

    $onlineVerification = $verifyEntries('Online', OnlineTransaction::class, $onlineTransaction->id);
    $auditLogs[] = [
        'module' => 'Online Services (الخدمات الإلكترونية)',
        'division' => 'Office (المكتب)',
        'action' => 'Recharge Online Service',
        'balanced' => $onlineVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $onlineVerification['details']
    ];

    // =========================================================================
    // MODULE 6: FAWRY (OFFICE DIVISION)
    // =========================================================================
    echo "Auditing MODULE 6: FAWRY...\n";
    // $fawryOpType, $fawryPaymentMethod, $fawryMachine already created in setup phase

    $fawryTransactionService = app(FawryTransactionService::class);
    $fawryTransaction = $fawryTransactionService->createTransaction([
        'client_id' => $customerOffice->id,
        'operation_type' => $fawryOpType->code,
        'payment_method' => $fawryPaymentMethod->code,
        'fawry_machine_id' => $fawryMachine->id,
        'client_amount' => 500.00,
        'fawry_price' => 500.00,
        'selling_price' => 510.00,
        'amount' => 510.00,
        'employee_id' => 1,
        'account_id' => $cashboxEGP->id,
    ]);

    $fawryVerification = $verifyEntries('Fawry', FawryTransaction::class, $fawryTransaction->id);
    $auditLogs[] = [
        'module' => 'Fawry (فوري)',
        'division' => 'Office (المكتب)',
        'action' => 'Electricity Bill Payment',
        'balanced' => $fawryVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $fawryVerification['details']
    ];

    // =========================================================================
    // MODULE 7: WALLETS (OFFICE DIVISION)
    // =========================================================================
    echo "Auditing MODULE 7: WALLETS...\n";
    // $walletType, $walletAccount, $walletCashbox already created in setup phase

    $walletTransactionService = app(WalletTransactionService::class);
    $walletTransaction = $walletTransactionService->createTransaction([
        'wallet_type_id' => $walletType->id,
        'customer_name' => 'أحمد حسني العميل',
        'wallet_number' => '01022222222',
        'type' => 'send',
        'amount' => 1000.00,
        'service_fee' => 20.00,
        'amount_paid' => 1020.00,
        'wallet_account_id' => $walletAccount->id,
        'cash_account_id' => $walletCashbox->id,
    ]);

    $walletVerification = $verifyEntries('Wallet', WalletTransaction::class, $walletTransaction->id);
    $auditLogs[] = [
        'module' => 'Wallets (المحافظ والتحويلات)',
        'division' => 'Office (المكتب)',
        'action' => 'Send Balance (إرسال رصيد كاش)',
        'balanced' => $walletVerification['balanced'] ? 'PASSED (متزن)' : 'FAILED (غير متزن)',
        'details' => $walletVerification['details']
    ];

    // =========================================================================
    // VERIFY TRIAL BALANCES & CAPITALS
    // =========================================================================
    echo "\nVerifying Trial Balances and Capital Equations...\n";

    // Use the same $treasuryService instance from Phase 2
    $tourismTB = $treasuryService->getTrialBalance();
    $officeTB = $treasuryService->getOfficeTrialBalance();

    // Outputs summaries
    echo "\n=== DEBUG TOURISM TRIAL BALANCE COMPONENTS ===\n";
    echo "BASELINE TOURISM:\n";
    foreach (['total_balances', 'total_liquidity', 'due_to_us', 'due_from_us', 'current_capital', 'base_capital', 'profits', 'expected_capital', 'variance'] as $k) {
        echo sprintf("  %-17s: %.2f EGP\n", $k, $baselineTourismTB[$k]);
    }
    echo "ENDING TOURISM:\n";
    foreach (['total_balances', 'total_liquidity', 'due_to_us', 'due_from_us', 'current_capital', 'base_capital', 'profits', 'expected_capital', 'variance'] as $k) {
        echo sprintf("  %-17s: %.2f EGP\n", $k, $tourismTB[$k]);
    }
    echo "==============================================\n\n";

    echo "\n=== AUDIT RESULTS BY MODULE ===\n";
    foreach ($auditLogs as $log) {
        echo sprintf(
            "Module: %s | Division: %s | Action: %s | Double-Entry: %s\n",
            $log['module'],
            $log['division'],
            $log['action'],
            $log['balanced']
        );
        if ($log['balanced'] !== 'PASSED (متزن)') {
            $success = false;
        }
    }

    echo "\n=== TOURISM DIVISION TRIAL BALANCE SUMMARY ===\n";
    echo "Total Balances (System/Carrier/Visa): " . number_format($tourismTB['total_balances'], 2) . " EGP\n";
    echo "Total Liquidity: " . number_format($tourismTB['total_liquidity'], 2) . " EGP\n";
    echo "Receivables (Due to us): " . number_format($tourismTB['due_to_us'], 2) . " EGP\n";
    echo "Payables (Due from us): " . number_format($tourismTB['due_from_us'], 2) . " EGP\n";
    // Verify Tourism Trial Balance
    $tourismVarianceChange = $tourismTB['variance'] - $baselineTourismTB['variance'];
    echo "Baseline Tourism Variance (post-setup): " . number_format($baselineTourismTB['variance'], 2) . " EGP\n";
    echo "Ending  Tourism Variance (post-bookings): " . number_format($tourismTB['variance'], 2) . " EGP\n";
    echo "Tourism Variance Change (Ending - Baseline): " . number_format($tourismVarianceChange, 2) . " EGP\n";

    if (abs($tourismVarianceChange) > 0.05) {
        echo "\u274c ERROR: Tourism Trial Balance shifted due to bookings! Change: " . number_format($tourismVarianceChange, 2) . " EGP\n";
        $success = false;
    } else {
        echo "\u2713 Tourism Trial Balance unchanged by bookings (net variance change = 0)!\n";
    }

    echo "\n=== OFFICE DIVISION TRIAL BALANCE SUMMARY ===\n";
    echo "Total Balances (Bus Company assets): " . number_format($officeTB['total_balances'], 2) . " EGP\n";
    echo "Total Liquidity (Office cash/wallets): " . number_format($officeTB['total_liquidity'], 2) . " EGP\n";
    echo "Receivables (Due to us): " . number_format($officeTB['due_to_us'], 2) . " EGP\n";
    echo "Payables (Due from us): " . number_format($officeTB['due_from_us'], 2) . " EGP\n";
    echo "Computed Current Capital: " . number_format($officeTB['current_capital'], 2) . " EGP\n";
    echo "Base Capital: " . number_format($officeTB['base_capital'], 2) . " EGP\n";
    echo "Expected Capital: " . number_format($officeTB['expected_capital'], 2) . " EGP\n";
    echo "Dynamic Profits: " . number_format($officeTB['profits'], 2) . " EGP\n";
    echo "Variance: " . number_format($officeTB['variance'], 2) . " EGP\n";
    echo "Status: " . $officeTB['status'] . "\n";

    $officeVarianceChange = $officeTB['variance'] - $baselineOfficeTB['variance'];
    echo "Baseline Office Variance (post-setup): " . number_format($baselineOfficeTB['variance'], 2) . " EGP\n";
    echo "Ending  Office Variance (post-bookings): " . number_format($officeTB['variance'], 2) . " EGP\n";
    echo "Office Variance Change (Ending - Baseline): " . number_format($officeVarianceChange, 2) . " EGP\n";

    if (abs($officeVarianceChange) > 0.05) {
        echo "\u274c ERROR: Office Trial Balance shifted due to bookings! Change: " . number_format($officeVarianceChange, 2) . " EGP\n";
        $success = false;
    } else {
        echo "\u2713 Office Trial Balance unchanged by bookings (net variance change = 0)!\n";
    }

    // Write a beautiful report file in artifacts directory
    $reportContent = "# تقرير مراجعة الحسابات التفصيلي للموديولات (تطابق ميزان المراجعة)\n\n";
    $reportContent .= "تم تشغيل قيد اختبار لكل موديول في بيئة معزولة، والتحقق من صحة القيود الدفترية المزدوجة وتطابقها في موازين الحسابات بقسمي السياحة والمكتب.\n\n";
    
    $reportContent .= "## 1. نتائج القيود المزدوجة لكل موديول\n\n";
    $reportContent .= "| الموديول | القسم | نوع الحركة | حالة التوازن المزدوج | عدد الحركات المقيدة |\n";
    $reportContent .= "| :--- | :--- | :--- | :--- | :--- |\n";
    
    foreach ($auditLogs as $log) {
        $reportContent .= sprintf(
            "| %s | %s | %s | %s | %d |\n",
            $log['module'],
            $log['division'],
            $log['action'],
            $log['balanced'] === 'PASSED (متزن)' ? '✅ متزن' : '❌ غير متزن',
            count($log['details'])
        );
    }
    
    $reportContent .= "\n## 2. تفاصيل القيود والحسابات المتأثرة\n\n";
    foreach ($auditLogs as $log) {
        $reportContent .= "### موديول: " . $log['module'] . "\n";
        $reportContent .= "* **الحركة:** " . $log['action'] . "\n";
        $reportContent .= "* **الحسابات المتأثرة وأرصدتها بعد الحركة:**\n";
        foreach ($log['details'] as $line) {
            $reportContent .= "  - " . $line . "\n";
        }
        $reportContent .= "\n";
    }

    $reportContent .= "## 3. ميزان حسابات قسم السياحة (Tourism Trial Balance)\n\n";
    $reportContent .= "| البيان | القيمة بالجنيه (EGP) |\n";
    $reportContent .= "| :--- | :--- |\n";
    $reportContent .= "| إجمالي أرصدة الطيران والحج والفيزا (الناقلين وأنظمة الشحن) | " . number_format($tourismTB['total_balances'], 2) . " |\n";
    $reportContent .= "| إجمالي السيولة (خزائن وبنوك السياحة) | " . number_format($tourismTB['total_liquidity'], 2) . " |\n";
    $reportContent .= "| الديون لنا (ذمم العملاء المدينة) | " . number_format($tourismTB['due_to_us'], 2) . " |\n";
    $reportContent .= "| الديون علينا (مستحقات الموردين والشركات) | " . number_format($tourismTB['due_from_us'], 2) . " |\n";
    $reportContent .= "| **إجمالي رأس المال الفعلي الحالي** | **" . number_format($tourismTB['current_capital'], 2) . "** |\n";
    $reportContent .= "| رأس المال التأسيسي المعاير | " . number_format($tourismTB['base_capital'], 2) . " |\n";
    $reportContent .= "| أرباح السياحة المحققة خلال الفحص | " . number_format($tourismTB['profits'], 2) . " |\n";
    $reportContent .= "| **رأس المال المتوقع** | **" . number_format($tourismTB['expected_capital'], 2) . "** |\n";
    $reportContent .= "| **الفارق النهائي (الزيادة / العجز)** | **" . number_format($tourismTB['variance'], 2) . "** |\n";
    $reportContent .= "| **تغير الفارق خلال الفحص** | **" . number_format($tourismVarianceChange, 2) . "** |\n";
    $reportContent .= "| **حالة الميزان** | **" . $tourismTB['status'] . "** |\n\n";

    $reportContent .= "## 4. ميزان حسابات قسم المكتب (Office Trial Balance)\n\n";
    $reportContent .= "| البيان | القيمة بالجنيه (EGP) |\n";
    $reportContent .= "| :--- | :--- |\n";
    $reportContent .= "| أصول الباصات (أرصدة شركات النقل) | " . number_format($officeTB['total_balances'], 2) . " |\n";
    $reportContent .= "| إجمالي السيولة (خزائن وبنوك ومحافظ المكتب) | " . number_format($officeTB['total_liquidity'], 2) . " |\n";
    $reportContent .= "| الديون لنا (ذمم العملاء) | " . number_format($officeTB['due_to_us'], 2) . " |\n";
    $reportContent .= "| الديون علينا (الموردين والمستحقات) | " . number_format($officeTB['due_from_us'], 2) . " |\n";
    $reportContent .= "| **إجمالي رأس المال الفعلي الحالي** | **" . number_format($officeTB['current_capital'], 2) . "** |\n";
    $reportContent .= "| رأس المال التأسيسي | " . number_format($officeTB['base_capital'], 2) . " |\n";
    $reportContent .= "| أرباح الخدمات والباصات وفوري والمحافظ خلال الفحص | " . number_format($officeTB['profits'], 2) . " |\n";
    $reportContent .= "| **رأس المال المتوقع** | **" . number_format($officeTB['expected_capital'], 2) . "** |\n";
    $reportContent .= "| **الفارق النهائي (الزيادة / العجز)** | **" . number_format($officeTB['variance'], 2) . "** |\n";
    $reportContent .= "| **تغير الفارق خلال الفحص** | **" . number_format($officeVarianceChange, 2) . "** |\n";
    $reportContent .= "| **حالة الميزان** | **" . $officeTB['status'] . "** |\n\n";

    $reportContent .= "## 5. الخلاصة وتوصية النشر قبل الإنتاج (Production Readiness)\n\n";
    if ($success) {
        $reportContent .= "> [!NOTE]\n";
        $reportContent .= "> **توصية المراجعة:** النظام جاهز للإنتاج (Production Ready). القيود المحاسبية متزنة 100%، والمعادلة المحاسبية لقسمي السياحة والمكتب تتطابق بدقة تامة وتغير الفارق خلال الحركات يساوى صفر مما يثبت توازن القيد المزدوج برموز النظام.\n";
    } else {
        $reportContent .= "> [!WARNING]\n";
        $reportContent .= "> **تنبيه:** توجد مشاكل في توازن بعض القيود أو في معادلة ميزان المراجعة. يرجى مراجعة التفاصيل البرمجية أعلاه.\n";
    }

    file_put_contents('C:\Users\PC\.gemini\antigravity\brain\faf99a3f-8bdc-496a-8ebd-8e9f49fa03d1\comprehensive_accounting_audit.md', $reportContent);
    echo "\n✓ Report generated successfully at comprehensive_accounting_audit.md\n";

} catch (\Throwable $e) {
    echo "\n❌ AUDIT EXCEPTION: " . $e->getMessage() . "\n";
    echo "at " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    $success = false;
} finally {
    DB::rollBack();
    echo "\n=== AUDIT COMPLETED. DB ROLLED BACK ===\n";
    exit($success ? 0 : 1);
}
