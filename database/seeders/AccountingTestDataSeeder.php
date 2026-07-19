<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AccountingTestDataSeeder — v4 PRO
 * ضخ بيانات محاسبية شاملة بالقيود المزدوجة المتوازنة والقيود الافتتاحية لضمان توازن الميزان 100%
 */
class AccountingTestDataSeeder extends Seeder
{
    private array $accountIds  = [];
    private array $localBal    = [];   
    private int   $adminId     = 1;

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('══════════════════════════════════════════════════════');
        $this->command->info('  AccountingTestDataSeeder v4 — ضخ بيانات محاسبية متوازنة');
        $this->command->info('══════════════════════════════════════════════════════');

        DB::transaction(function () {
            $this->seedAccountsAndOpeningEntries();
            $this->seedTreasuries();
            $this->seedCustomers();
            $this->seedSuppliers(); // الموردون يتم ترحيل أرصدتهم الافتتاحية هنا أيضاً بشكل متوازن
            $this->seedFlightData();
            $this->seedVisaData();
            $this->seedBusData();
            $this->seedHajjUmraData();
            $this->seedAccountingTransactions();
        });

        $this->command->info('');
        $this->command->info('✅ تم ضخ جميع البيانات المحاسبية المتوازنة بنجاح!');
    }

    // ────────────────────────────────────────────────────────
    // 1. الحسابات والأرصدة الافتتاحية المتوازنة
    // ────────────────────────────────────────────────────────
    private function seedAccountsAndOpeningEntries(): void
    {
        $this->command->info('  → إنشاء الحسابات والأرصدة الافتتاحية...');

        // الحسابات ذات الأرصدة الافتتاحية
        $initialAccounts = [
            // سيولة (مدين)
            ['name' => 'الخزينة النقدية الرئيسية', 'type' => 'cashbox',   'cur' => 'EGP', 'bal' => 250000.00, 'mod' => null,      'vault' => 1],
            ['name' => 'خزينة قسم الطيران',        'type' => 'cashbox',   'cur' => 'EGP', 'bal' => 75000.00,  'mod' => 'flight',  'vault' => 1],
            ['name' => 'خزينة الدولار',             'type' => 'cashbox',   'cur' => 'USD', 'bal' => 5000.00,   'mod' => null,      'vault' => 0],
            ['name' => 'بنك مصر — حساب جاري',       'type' => 'bank',      'cur' => 'EGP', 'bal' => 500000.00, 'mod' => null,      'vault' => 0,  'bank_name' => 'بنك مصر',  'acc_no' => '1234567890'],
            ['name' => 'CIB — حساب التوفير',         'type' => 'bank',      'cur' => 'EGP', 'bal' => 120000.00, 'mod' => null,      'vault' => 0,  'bank_name' => 'CIB',       'acc_no' => '9876543210'],
            // أرصدة مسبقة لشركات الطيران (مدين)
            ['name' => 'رصيد مسبق — مصرللطيران',   'type' => 'owner',     'cur' => 'EGP', 'bal' => 75000.00,  'mod' => 'flight',  'vault' => 0],
            ['name' => 'رصيد مسبق — فلاي إيدال',   'type' => 'owner',     'cur' => 'EGP', 'bal' => 40000.00,  'mod' => 'flight',  'vault' => 0],
            ['name' => 'رصيد مسبق — اير كايرو',    'type' => 'owner',     'cur' => 'EGP', 'bal' => 30000.00,  'mod' => 'flight',  'vault' => 0],
            // محفظة (مدين)
            ['name' => 'محفظة فوري',                 'type' => 'wallet',    'cur' => 'EGP', 'bal' => 15000.00, 'mod' => 'fawry',   'vault' => 0, 'wallet_provider' => 'fawry'],
            // التزامات (دائن)
            ['name' => 'ضرائب مستحقة',               'type' => 'liability', 'cur' => 'EGP', 'bal' => -25000.00, 'mod' => null,      'vault' => 0],
        ];

        // الحسابات ذات الأرصدة الصفرية مبدئياً
        $zeroAccounts = [
            ['name' => 'إيرادات الطيران',            'type' => 'revenue',   'cur' => 'EGP', 'bal' => 0,        'mod' => 'flight',  'vault' => 0],
            ['name' => 'إيرادات الفيزا',             'type' => 'revenue',   'cur' => 'EGP', 'bal' => 0,        'mod' => 'visa',    'vault' => 0],
            ['name' => 'إيرادات الباص',              'type' => 'revenue',   'cur' => 'EGP', 'bal' => 0,        'mod' => 'bus',     'vault' => 0],
            ['name' => 'إيرادات الحج والعمرة',       'type' => 'revenue',   'cur' => 'EGP', 'bal' => 0,        'mod' => 'hajj',    'vault' => 0],
            ['name' => 'مصروفات الطيران',            'type' => 'expense',   'cur' => 'EGP', 'bal' => 0,        'mod' => 'flight',  'vault' => 0],
            ['name' => 'مصروفات إدارية',             'type' => 'expense',   'cur' => 'EGP', 'bal' => 0,        'mod' => 'general', 'vault' => 0],
            ['name' => 'مصروفات رواتب',              'type' => 'expense',   'cur' => 'EGP', 'bal' => 0,        'mod' => null,      'vault' => 0],
        ];

        // 1. إدخال الحسابات
        $insertedAccounts = [];
        foreach (array_merge($initialAccounts, $zeroAccounts) as $a) {
            $id = DB::table('accounts')->insertGetId([
                'name'            => $a['name'],
                'type'            => $a['type'],
                'currency'        => $a['cur'],
                'balance'         => $a['bal'],
                'is_active'       => 1,
                'owner_type'      => 'owner',
                'module_type'     => 'tourism',
                'module'          => $a['mod'],
                'bank_name'       => $a['bank_name'] ?? null,
                'account_number'  => $a['acc_no'] ?? null,
                'branch_name'     => null,
                'wallet_provider' => $a['wallet_provider'] ?? null,
                'wallet_number'   => null,
                'is_module_vault' => $a['vault'],
                'created_by'      => $this->adminId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            $this->accountIds[$a['name']] = $id;
            $this->localBal[$id] = (float)$a['bal'];
            
            if ($a['bal'] != 0) {
                $insertedAccounts[] = [
                    'id' => $id,
                    'name' => $a['name'],
                    'type' => $a['type'],
                    'balance' => $a['bal']
                ];
            }
        }

        // 2. حساب رأس المال الافتتاحي للتوازن
        // مجموع الأرصدة المدينة الافتتاحية
        $debSum = 0; $crdSum = 0;
        foreach ($insertedAccounts as $ia) {
            $isDeb = in_array($ia['type'], ['cashbox', 'bank', 'wallet', 'expense', 'customer', 'owner']);
            if ($isDeb) {
                $debSum += abs($ia['balance']);
            } else {
                $crdSum += abs($ia['balance']);
            }
        }

        // إجمالي الموردين الافتتاحي (سننشئهم لاحقاً بأرصدة افتتاحية دائنة)
        // شركة رحلات (85,000) + مصر للطيران (45,000) + السعودية (22,000) = 152,000
        $suppliersOpeningDebt = 85000 + 45000 + 22000;
        $crdSum += $suppliersOpeningDebt;

        $diff = round($debSum - $crdSum, 2);
        
        // إنشاء حساب رأس المال الافتتاحي
        $capitalAccId = DB::table('accounts')->insertGetId([
            'name'            => 'رأس المال الافتتاحي',
            'type'            => 'owner',
            'currency'        => 'EGP',
            'balance'         => -$diff,
            'is_active'       => 1,
            'owner_type'      => 'owner',
            'module_type'     => 'tourism',
            'notes'           => 'موازن الأرصدة الافتتاحية',
            'created_by'      => $this->adminId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        $this->accountIds['رأس المال الافتتاحي'] = $capitalAccId;
        $this->localBal[$capitalAccId] = -$diff;

        // 3. كتابة قيود الأرصدة الافتتاحية المتوازنة تماماً
        $openingTxId = DB::table('transactions')->insertGetId([
            'type'            => 'transfer',
            'amount'          => max($debSum, $crdSum),
            'currency'        => 'EGP',
            'module'          => 'general',
            'notes'           => 'قيد إدخال الأرصدة الافتتاحية المتوازنة',
            'created_by'      => $this->adminId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // قيود الحسابات الافتتاحية
        foreach ($insertedAccounts as $ia) {
            $isDeb = in_array($ia['type'], ['cashbox', 'bank', 'wallet', 'expense', 'customer', 'owner']);
            DB::table('account_entries')->insert([
                'account_id'     => $ia['id'],
                'transaction_id' => $openingTxId,
                'debit'          => $isDeb ? abs($ia['balance']) : 0,
                'credit'         => !$isDeb ? abs($ia['balance']) : 0,
                'balance_after'  => $ia['balance'],
                'notes'          => 'رصيد افتتاحي: ' . $ia['name'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        // قيد رأس المال الافتتاحي الموازن (دائن بقيمة الفرق)
        DB::table('account_entries')->insert([
            'account_id'     => $capitalAccId,
            'transaction_id' => $openingTxId,
            'debit'          => 0,
            'credit'         => $diff,
            'balance_after'  => -$diff,
            'notes'          => 'رصيد افتتاحي: رأس المال الافتتاحي الموازن',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        
        $this->command->info('    ✓ تم تهيئة الحسابات والأرصدة الافتتاحية بالقيود المزدوجة المتوازنة.');
    }

    // ────────────────────────────────────────────────────────
    // الخزائن
    // ────────────────────────────────────────────────────────
    private function seedTreasuries(): void
    {
        $this->command->info('  → إنشاء الخزائن...');
        $rows = [
            ['name' => 'الخزينة الرئيسية', 'currency' => 'EGP', 'current_balance' => 250000.00],
            ['name' => 'خزينة الفروع',     'currency' => 'EGP', 'current_balance' => 45000.00],
            ['name' => 'خزينة الدولار',    'currency' => 'USD', 'current_balance' => 5000.00],
        ];
        foreach ($rows as $r) {
            DB::table('treasuries')->insert(array_merge($r, ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()]));
        }
        $this->command->info('    ✓ ' . count($rows) . ' خزينة');
    }

    // ────────────────────────────────────────────────────────
    // العملاء
    // ────────────────────────────────────────────────────────
    private function seedCustomers(): void
    {
        $this->command->info('  → إنشاء العملاء...');
        $rows = [
            ['full_name' => 'محمد أحمد علي',      'phone' => '01001234567', 'nat' => 'EG', 'gender' => 'male',   'status' => 'vip',    'tier' => 'VIP',      'spent' => 125000],
            ['full_name' => 'فاطمة حسن محمود',    'phone' => '01112345678', 'nat' => 'EG', 'gender' => 'female', 'status' => 'active', 'tier' => 'STANDARD', 'spent' => 35000],
            ['full_name' => 'أحمد سعد إبراهيم',   'phone' => '01223456789', 'nat' => 'SA', 'gender' => 'male',   'status' => 'active', 'tier' => 'PREMIUM',  'spent' => 280000],
            ['full_name' => 'سمية خالد عبدالله',  'phone' => '01534567890', 'nat' => 'EG', 'gender' => 'female', 'status' => 'active', 'tier' => 'STANDARD', 'spent' => 18500],
            ['full_name' => 'كريم محمود الشافعي', 'phone' => '01645678901', 'nat' => 'EG', 'gender' => 'male',   'status' => 'active', 'tier' => 'VIP',      'spent' => 450000],
        ];
        foreach ($rows as $r) {
            $accId = DB::table('accounts')->insertGetId([
                'name' => 'حساب عميل: ' . $r['full_name'], 'type' => 'customer',
                'currency' => 'EGP', 'balance' => 0, 'is_active' => 1,
                'owner_type' => 'owner', 'module_type' => 'tourism',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accountIds['حساب عميل: ' . $r['full_name']] = $accId;
            $this->localBal[$accId] = 0.0;

            DB::table('customers')->insert([
                'account_id' => $accId, 'full_name' => $r['full_name'],
                'phone' => $r['phone'], 'nationality' => $r['nat'],
                'gender' => $r['gender'], 'status' => $r['status'],
                'type' => 'individual', 'customer_tier' => $r['tier'],
                'total_spent' => $r['spent'], 'bookings_count' => 0,
                'created_by' => $this->adminId,
                'created_at' => now()->subDays(rand(10, 365)), 'updated_at' => now(),
            ]);
        }
        $this->command->info('    ✓ ' . count($rows) . ' عميل');
    }

    // ────────────────────────────────────────────────────────
    // الموردون
    // ────────────────────────────────────────────────────────
    private function seedSuppliers(): void
    {
        $this->command->info('  → إنشاء الموردين وقيودهم الافتتاحية...');
        $rows = [
            ['name' => 'مصرللطيران للتوزيع',  'type' => 'airline', 'phone' => '0225784512', 'email' => 'egyptair@test.com', 'city' => 'القاهرة',     'credit' => 500000, 'debt' => 45000],
            ['name' => 'الخطوط السعودية',      'type' => 'airline', 'phone' => '0113456789', 'email' => 'saudia@test.com',   'city' => 'الرياض',      'credit' => 300000, 'debt' => 22000],
            ['name' => 'شركة رحلات للفنادق',  'type' => 'hotel',   'phone' => '01234567891','email' => 'rehab@test.com',    'city' => 'مكة المكرمة','credit' => 200000, 'debt' => 85000],
        ];

        // قيد افتتاحي للموردين (مستند على معاملة الرصيد الافتتاحي الرئيسي)
        // المعاملة تم إدخالها مسبقاً برقم $openingTxId = 1
        $openingTxId = 1;

        foreach ($rows as $r) {
            $accId = DB::table('accounts')->insertGetId([
                'name' => 'حساب مورد: ' . $r['name'], 'type' => 'supplier',
                'currency' => 'EGP', 'balance' => -$r['debt'], 'is_active' => 1,
                'owner_type' => 'owner', 'module_type' => 'tourism',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accountIds['حساب مورد: ' . $r['name']] = $accId;
            $this->localBal[$accId] = -$r['debt'];

            // إضافة القيد الدائن الافتتاحي للمورد
            DB::table('account_entries')->insert([
                'account_id'     => $accId,
                'transaction_id' => $openingTxId,
                'debit'          => 0,
                'credit'         => $r['debt'],
                'balance_after'  => -$r['debt'],
                'notes'          => 'رصيد افتتاحي للمورد: ' . $r['name'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            DB::table('suppliers')->insert([
                'name' => $r['name'], 'code' => strtoupper(substr(md5($r['name']), 0, 6)),
                'type' => $r['type'], 'phone' => $r['phone'], 'email' => $r['email'],
                'city' => $r['city'], 'account_id' => $accId,
                'credit_limit' => $r['credit'], 'current_debt' => $r['debt'],
                'payment_terms' => 'credit_30', 'is_active' => 1,
                'created_by' => $this->adminId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->command->info('    ✓ ' . count($rows) . ' مورد بالقيود الافتتاحية.');
    }

    // ────────────────────────────────────────────────────────
    // بيانات الطيران
    // ────────────────────────────────────────────────────────
    private function seedFlightData(): void
    {
        $this->command->info('  → إنشاء بيانات الطيران...');

        $sysId = DB::table('flight_systems')->insertGetId([
            'name'        => 'Amadeus GDS',
            'code'        => 'AMD',          
            'type'        => 'gds',
            'currency'    => 'EGP',
            'balance'     => 50000.00,
            'credit_limit'=> 200000.00,
            'is_active'   => 1,
            'created_by'  => $this->adminId,
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $carriers = [
            ['name' => 'مصرللطيران', 'code' => 'MSR', 'iata' => 'MS', 'bal' => 75000,  'limit' => 300000],
            ['name' => 'فلاي إيدال', 'code' => 'FLD', 'iata' => 'F3', 'bal' => 40000,  'limit' => 150000],
            ['name' => 'اير كايرو',  'code' => 'ARC', 'iata' => 'SM', 'bal' => 30000,  'limit' => 100000],
        ];
        $carrierIds = [];
        foreach ($carriers as $c) {
            $cId = DB::table('flight_carriers')->insertGetId([
                'flight_system_id' => $sysId,
                'name'        => $c['name'],
                'code'        => $c['code'],   
                'iata_code'   => $c['iata'],
                'currency'    => 'EGP',
                'balance'     => $c['bal'],
                'credit_limit'=> $c['limit'],
                'is_active'   => 1,
                'created_by'  => $this->adminId,
                'created_at'  => now(), 'updated_at' => now(),
            ]);
            $carrierIds[] = $cId;
        }

        $custIds = DB::table('customers')->whereNull('deleted_at')->pluck('id')->toArray();
        if (empty($custIds)) { return; }

        $bookings = [
            ['ci'=>0,'cri'=>0,'orig'=>'CAI','dest'=>'DXB','sell'=>12500,'buy'=>10000,'profit'=>2500,'status'=>'CONFIRMED'],
            ['ci'=>1,'cri'=>1,'orig'=>'CAI','dest'=>'RUH','sell'=>8750, 'buy'=>7000, 'profit'=>1750,'status'=>'CONFIRMED'],
            ['ci'=>2,'cri'=>2,'orig'=>'CAI','dest'=>'JED','sell'=>15000,'buy'=>12000,'profit'=>3000,'status'=>'PENDING'],
            ['ci'=>3,'cri'=>0,'orig'=>'CAI','dest'=>'LHR','sell'=>22000,'buy'=>18000,'profit'=>4000,'status'=>'CANCELLED'],
            ['ci'=>4,'cri'=>1,'orig'=>'CAI','dest'=>'IST','sell'=>9500, 'buy'=>7600, 'profit'=>1900,'status'=>'CONFIRMED'],
        ];
        foreach ($bookings as $b) {
            DB::table('flight_bookings')->insertGetId([
                'booking_reference'        => 'FLT' . strtoupper(substr(md5(rand()), 0, 8)),
                'booking_channel_type'     => 'office',
                'booking_channel_provider' => 'direct',
                'system_type'              => 'manual',
                'status'                   => $b['status'],
                'customer_id'              => $custIds[$b['ci']] ?? $custIds[0],
                'agent_name'               => 'موظف اختبار',
                'origin'                   => $b['orig'],
                'destination'              => $b['dest'],
                'departure_date'           => now()->addDays(rand(7,90))->format('Y-m-d'),
                'departure_time'           => '10:00',
                'trip_type'                => 'one_way',
                'airline'                  => 'MS',
                'passenger_count'          => 1,
                'baggage_allowance_kg'     => 23,
                'purchase_price'           => $b['buy'],
                'selling_price'            => $b['sell'],
                'profit'                   => $b['profit'],
                'currency'                 => 'EGP',
                'exchange_rate'            => 1.0,
                'flight_carrier_id'        => $carrierIds[$b['cri']] ?? $carrierIds[0],
                'created_by'               => $this->adminId,
                'created_at'               => now()->subDays(rand(1,30)), 'updated_at' => now(),
            ]);
        }

        // Generate AirlineTransaction and FlightSystemTransaction for carriers and systems to populate operations history
        $dbCarriers = DB::table('flight_carriers')->whereIn('id', $carrierIds)->get();
        foreach ($dbCarriers as $carrier) {
            $carrierBookings = DB::table('flight_bookings')
                ->where('flight_carrier_id', $carrier->id)
                ->orderBy('id', 'asc')
                ->get();
                
            $activeBookingsSum = 0;
            foreach ($carrierBookings as $b) {
                if ($b->status !== 'CANCELLED') {
                    $activeBookingsSum += (float)$b->purchase_price;
                }
            }
            
            $currentBalance = (float)$carrier->balance;
            $openingBalance = $currentBalance + $activeBookingsSum;
            
            // Create Opening Balance Credit
            DB::table('airline_transactions')->insert([
                'flight_carrier_id' => $carrier->id,
                'flight_booking_id' => null,
                'type' => 'credit',
                'amount' => $openingBalance,
                'balance_before' => 0.00,
                'balance_after' => $openingBalance,
                'description' => 'الرصيد الافتتاحي للناقل (توليد تلقائي)',
                'created_by' => $this->adminId,
                'created_at' => now()->subDays(31),
                'updated_at' => now(),
            ]);
            
            $runningBalance = $openingBalance;
            foreach ($carrierBookings as $booking) {
                $amount = (float)$booking->purchase_price;
                
                // Debit
                $before = $runningBalance;
                $runningBalance -= $amount;
                
                DB::table('airline_transactions')->insert([
                    'flight_carrier_id' => $carrier->id,
                    'flight_booking_id' => $booking->id,
                    'type' => 'debit',
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $runningBalance,
                    'description' => 'خصم حجز تذكرة رقم: ' . ($booking->booking_reference ?: $booking->id),
                    'created_by' => $this->adminId,
                    'created_at' => $booking->created_at ?: now(),
                    'updated_at' => now(),
                ]);
                
                // If cancelled, add refund
                if ($booking->status === 'CANCELLED') {
                    $beforeRefund = $runningBalance;
                    $runningBalance += $amount;
                    
                    DB::table('airline_transactions')->insert([
                        'flight_carrier_id' => $carrier->id,
                        'flight_booking_id' => $booking->id,
                        'type' => 'refund',
                        'amount' => $amount,
                        'balance_before' => $beforeRefund,
                        'balance_after' => $runningBalance,
                        'description' => 'استرداد قيمة حجز ملغي رقم: ' . ($booking->booking_reference ?: $booking->id),
                        'created_by' => $this->adminId,
                        'created_at' => ($booking->updated_at ?: $booking->created_at) ?: now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
        
        $dbSystem = DB::table('flight_systems')->where('id', $sysId)->first();
        if ($dbSystem) {
            $systemBookings = DB::table('flight_bookings')
                ->whereIn('flight_carrier_id', $carrierIds)
                ->orderBy('id', 'asc')
                ->get();
                
            $activeBookingsSum = 0;
            foreach ($systemBookings as $b) {
                if ($b->status !== 'CANCELLED') {
                    $activeBookingsSum += (float)$b->purchase_price;
                }
            }
            
            $currentBalance = (float)$dbSystem->balance;
            $openingBalance = $currentBalance + $activeBookingsSum;
            
            // Create Opening Balance Credit
            DB::table('flight_system_transactions')->insert([
                'flight_system_id' => $sysId,
                'flight_booking_id' => null,
                'type' => 'credit',
                'amount' => $openingBalance,
                'balance_before' => 0.00,
                'balance_after' => $openingBalance,
                'description' => 'الرصيد الافتتاحي للنظام (توليد تلقائي)',
                'created_by' => $this->adminId,
                'created_at' => now()->subDays(31),
                'updated_at' => now(),
            ]);
            
            $runningBalance = $openingBalance;
            foreach ($systemBookings as $booking) {
                $amount = (float)$booking->purchase_price;
                
                // Debit
                $before = $runningBalance;
                $runningBalance -= $amount;
                
                DB::table('flight_system_transactions')->insert([
                    'flight_system_id' => $sysId,
                    'flight_booking_id' => $booking->id,
                    'type' => 'debit',
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $runningBalance,
                    'description' => 'خصم حجز تذكرة رقم: ' . ($booking->booking_reference ?: $booking->id),
                    'created_by' => $this->adminId,
                    'created_at' => $booking->created_at ?: now(),
                    'updated_at' => now(),
                ]);
                
                // If cancelled, add refund
                if ($booking->status === 'CANCELLED') {
                    $beforeRefund = $runningBalance;
                    $runningBalance += $amount;
                    
                    DB::table('flight_system_transactions')->insert([
                        'flight_system_id' => $sysId,
                        'flight_booking_id' => $booking->id,
                        'type' => 'refund',
                        'amount' => $amount,
                        'balance_before' => $beforeRefund,
                        'balance_after' => $runningBalance,
                        'description' => 'استرداد قيمة حجز ملغي رقم: ' . ($booking->booking_reference ?: $booking->id),
                        'created_by' => $this->adminId,
                        'created_at' => ($booking->updated_at ?: $booking->created_at) ?: now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->command->info('    ✓ نظام + ' . count($carriers) . ' ناقل + ' . count($bookings) . ' حجز طيران');
    }

    // ────────────────────────────────────────────────────────
    // الفيزا
    // ────────────────────────────────────────────────────────
    private function seedVisaData(): void
    {
        $this->command->info('  → إنشاء بيانات الفيزا...');

        $vdId = DB::table('visa_details')->insertGetId([
            'visa_type'  => 'tourist',
            'country'    => 'الإمارات العربية المتحدة',
            'entry_type' => 'single',
            'status'     => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $custIds = DB::table('customers')->whereNull('deleted_at')->pluck('id')->toArray();
        if (empty($custIds)) { return; }

        $visas = [
            ['ci'=>0,'buy'=>2500,'sell'=>3200,'profit'=>700,'status'=>'approved'],
            ['ci'=>1,'buy'=>2500,'sell'=>3200,'profit'=>700,'status'=>'pending'],
            ['ci'=>2,'buy'=>2500,'sell'=>3200,'profit'=>700,'status'=>'processing'],
            ['ci'=>3,'buy'=>2500,'sell'=>3200,'profit'=>700,'status'=>'rejected'],
        ];
        foreach ($visas as $v) {
            DB::table('visa_bookings')->insertGetId([
                'customer_id'    => $custIds[$v['ci']] ?? $custIds[0],
                'visa_detail_id' => $vdId,
                'module'         => 'VISA',
                'purchase_price' => $v['buy'],
                'selling_price'  => $v['sell'],
                'profit'         => $v['profit'],
                'currency'       => 'EGP',
                'status'         => $v['status'],
                'agent_name'     => 'موظف اختبار',
                'created_by'     => $this->adminId,
                'created_at'     => now()->subDays(rand(1,60)), 'updated_at' => now(),
            ]);
        }
        $this->command->info('    ✓ ' . count($visas) . ' حجز فيزا');
    }

    // ────────────────────────────────────────────────────────
    // الباص
    // ────────────────────────────────────────────────────────
    private function seedBusData(): void
    {
        $this->command->info('  → إنشاء بيانات الباص...');

        $compId = DB::table('bus_companies')->insertGetId([
            'name'       => 'شركة الدلتا للنقل',
            'phone'      => '0225783456',
            'is_active'  => 1,
            'created_by' => $this->adminId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $invId = DB::table('bus_inventories')->insertGetId([
            'company_id'          => $compId,
            'route'               => 'القاهرة — الإسكندرية',
            'travel_date'         => now()->addDays(7)->format('Y-m-d'),
            'departure_time'      => '08:00:00',
            'total_tickets'       => 45,
            'available_tickets'   => 30,
            'cost_per_ticket'     => 120.00,
            'selling_price'       => 150.00,
            'currency'            => 'EGP',
            'exchange_rate_to_egp'=> 1.000000,
            'payment_type'        => 'cash',
            'total_cost'          => 45 * 120,
            'amount_paid'         => 0,
            'remaining_debt'      => 45 * 120,
            'is_auto_created'     => 0,
            'created_by'          => $this->adminId,
            'created_at'          => now(), 'updated_at' => now(),
        ]);

        $custIds = DB::table('customers')->whereNull('deleted_at')->pluck('id')->toArray();
        if (empty($custIds)) { return; }

        $rows = [
            ['ci'=>0,'qty'=>2,'unit'=>150,'paid'=>300,'status'=>'paid',      'ps'=>'paid'],
            ['ci'=>1,'qty'=>1,'unit'=>150,'paid'=>75, 'status'=>'pending',   'ps'=>'partial'],
            ['ci'=>2,'qty'=>3,'unit'=>150,'paid'=>450,'status'=>'paid',      'ps'=>'paid'],
            ['ci'=>3,'qty'=>1,'unit'=>150,'paid'=>0,  'status'=>'cancelled', 'ps'=>'pending'],
        ];
        foreach ($rows as $r) {
            $total  = $r['qty'] * $r['unit'];
            $profit = ($r['unit'] - 120.00) * $r['qty'];
            DB::table('bus_bookings')->insertGetId([
                'inventory_id'        => $invId,
                'customer_id'         => $custIds[$r['ci']] ?? $custIds[0],
                'quantity'            => $r['qty'],
                'unit_price'          => $r['unit'],
                'total_price'         => $total,
                'paid_amount'         => $r['paid'],
                'payment_status'      => $r['ps'],
                'profit'              => $profit,
                'status'              => $r['status'],
                'currency'            => 'EGP',
                'exchange_rate_to_egp'=> 1.000000,
                'created_by'          => $this->adminId,
                'created_at'          => now()->subDays(rand(1,14)), 'updated_at' => now(),
            ]);
        }
        $this->command->info('    ✓ شركة + مخزون + ' . count($rows) . ' حجز باص');
    }

    // ────────────────────────────────────────────────────────
    // الحج والعمرة
    // ────────────────────────────────────────────────────────
    private function seedHajjUmraData(): void
    {
        $this->command->info('  → إنشاء بيانات الحج والعمرة...');

        $progId = DB::table('programs')->insertGetId([
            'program_name'          => 'برنامج عمرة رمضان 2026',
            'program_type'          => 'UMRAH',
            'season'                => '2026',
            'total_nights'          => 10,
            'accommodation_type'    => 'standard',
            'mecca_hotel_name'      => 'فندق مكة جراند',   
            'mecca_nights'          => 7,                  
            'medina_hotel_name'     => 'فندق المدينة بلاتينيوم',
            'medina_nights'         => 3,
            'departure_date'        => now()->addDays(30)->format('Y-m-d'),
            'return_date'           => now()->addDays(40)->format('Y-m-d'),
            'airline'               => 'مصرللطيران',
            'departure_point'       => 'القاهرة',           
            'booking_status'        => 'OPEN',
            'program_price_tier'    => 'standard',
            'default_purchase_price'=> 42000.00,
            'default_selling_price' => 50000.00,
            'is_active'             => 1,
            'created_at'            => now(), 'updated_at' => now(),
        ]);

        $custIds = DB::table('customers')->whereNull('deleted_at')->pluck('id')->toArray();
        if (empty($custIds)) { return; }

        $rows = [
            ['ci'=>0,'buy'=>42000,'sell'=>50000,'profit'=>8000,'status'=>'confirmed'],
            ['ci'=>1,'buy'=>42000,'sell'=>50000,'profit'=>8000,'status'=>'pending'],
            ['ci'=>2,'buy'=>42000,'sell'=>50000,'profit'=>8000,'status'=>'confirmed'],
        ];
        foreach ($rows as $h) {
            DB::table('hajj_umra_bookings')->insertGetId([
                'customer_id'               => $custIds[$h['ci']] ?? $custIds[0],
                'program_id'                => $progId,
                'module'                    => 'HAJJ_UMRA',
                'purchase_price'            => $h['buy'],
                'companion_purchase_price'  => 0,
                'selling_price'             => $h['sell'],
                'companion_selling_price'   => 0,
                'profit'                    => $h['profit'],
                'currency'                  => 'EGP',
                'per_person'                => 1,
                'accommodation_choice'      => 'standard',
                'accommodation_extra_charge'=> 0,
                'status'                    => $h['status'],
                'agent_name'                => 'موظف اختبار',
                'created_by'                => $this->adminId,
                'created_at'                => now()->subDays(rand(1,45)), 'updated_at' => now(),
            ]);
        }
        $this->command->info('    ✓ برنامج + ' . count($rows) . ' حجز حج/عمرة');
    }

    // ────────────────────────────────────────────────────────
    // المعاملات المحاسبية الجارية المتوازنة
    // ────────────────────────────────────────────────────────
    private function seedAccountingTransactions(): void
    {
        $this->command->info('  → إنشاء المعاملات والقيود المحاسبية الجارية...');

        $g = fn($n) => $this->accountIds[$n] ?? null;

        $cashbox   = $g('الخزينة النقدية الرئيسية');
        $bank      = $g('بنك مصر — حساب جاري');
        $flightRev = $g('إيرادات الطيران');
        $visaRev   = $g('إيرادات الفيزا');
        $busRev    = $g('إيرادات الباص');
        $hajjRev   = $g('إيرادات الحج والعمرة');
        $flightExp = $g('مصروفات الطيران');
        $adminExp  = $g('مصروفات إدارية');

        if (!$cashbox || !$flightRev) {
            $this->command->warn('    ⚠️ حسابات ناقصة — تخطي المعاملات الجارية');
            return;
        }

        $txs = [
            // إيرادات طيران
            ['type'=>'income',  'mod'=>'flight',  'amt'=>12500, 'from'=>$flightRev, 'to'=>$cashbox, 'notes'=>'بيع تذكرة CAI-DXB'],
            ['type'=>'income',  'mod'=>'flight',  'amt'=>8750,  'from'=>$flightRev, 'to'=>$cashbox, 'notes'=>'بيع تذكرة CAI-RUH'],
            ['type'=>'income',  'mod'=>'flight',  'amt'=>9500,  'from'=>$flightRev, 'to'=>$bank,    'notes'=>'بيع تذكرة CAI-IST'],
            // إيرادات فيزا
            ['type'=>'income',  'mod'=>'visa',    'amt'=>3200,  'from'=>$visaRev,   'to'=>$cashbox, 'notes'=>'رسوم فيزا إمارات 1'],
            ['type'=>'income',  'mod'=>'visa',    'amt'=>3200,  'from'=>$visaRev,   'to'=>$cashbox, 'notes'=>'رسوم فيزا إمارات 2'],
            // إيرادات باص
            ['type'=>'income',  'mod'=>'bus',     'amt'=>300,   'from'=>$busRev,    'to'=>$cashbox, 'notes'=>'حجز باص 2 مقاعد'],
            ['type'=>'income',  'mod'=>'bus',     'amt'=>450,   'from'=>$busRev,    'to'=>$cashbox, 'notes'=>'حجز باص 3 مقاعد'],
            // إيرادات حج وعمرة
            ['type'=>'income',  'mod'=>'hajj',    'amt'=>50000, 'from'=>$hajjRev,   'to'=>$bank,    'notes'=>'عمرة رمضان عميل 1'],
            ['type'=>'income',  'mod'=>'hajj',    'amt'=>50000, 'from'=>$hajjRev,   'to'=>$bank,    'notes'=>'عمرة رمضان عميل 3'],
            // مصروفات
            ['type'=>'expense', 'mod'=>'flight',  'amt'=>5000,  'from'=>$cashbox,   'to'=>$flightExp,'notes'=>'رسوم Amadeus يوليو'],
            ['type'=>'expense', 'mod'=>'general', 'amt'=>3500,  'from'=>$cashbox,   'to'=>$adminExp, 'notes'=>'مصروفات إدارية يوليو'],
            ['type'=>'expense', 'mod'=>'general', 'amt'=>25000, 'from'=>$bank,      'to'=>$adminExp, 'notes'=>'رواتب يوليو 2026'],
        ];

        $count = 0;
        foreach ($txs as $tx) {
            if (!$tx['from'] || !$tx['to'] || $tx['from'] === $tx['to']) continue;

            $txId = DB::table('transactions')->insertGetId([
                'type'            => $tx['type'],
                'amount'          => $tx['amt'],
                'currency'        => 'EGP',
                'module'          => $tx['mod'],
                'from_account_id' => $tx['from'],
                'to_account_id'   => $tx['to'],
                'notes'           => $tx['notes'],
                'created_by'      => $this->adminId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $amt = (float)$tx['amt'];

            // from_account (المُصدِر): دائماً دائن (Credit) لأن القيمة تخرج منه
            $fromBal = $this->localBal[$tx['from']] - $amt;
            $this->localBal[$tx['from']] = $fromBal;
            DB::table('account_entries')->insert([
                'account_id'     => $tx['from'],
                'transaction_id' => $txId,
                'debit'          => 0,
                'credit'         => $amt,
                'balance_after'  => round($fromBal, 2),
                'notes'          => 'صادر: ' . $tx['notes'],
                'created_at'     => now(), 'updated_at' => now(),
            ]);
            DB::table('accounts')->where('id', $tx['from'])->update(['balance' => round($fromBal, 2)]);

            // to_account (المستلم): دائماً مدين (Debit) لأن القيمة تدخل إليه
            $toBal = $this->localBal[$tx['to']] + $amt;
            $this->localBal[$tx['to']] = $toBal;
            DB::table('account_entries')->insert([
                'account_id'     => $tx['to'],
                'transaction_id' => $txId,
                'debit'          => $amt,
                'credit'         => 0,
                'balance_after'  => round($toBal, 2),
                'notes'          => 'وارد: ' . $tx['notes'],
                'created_at'     => now(), 'updated_at' => now(),
            ]);
            DB::table('accounts')->where('id', $tx['to'])->update(['balance' => round($toBal, 2)]);

            $count++;
        }

        $this->command->info("    ✓ {$count} معاملة جارية + " . ($count * 2) . " قيد محاسبي متوازن");
    }
}
