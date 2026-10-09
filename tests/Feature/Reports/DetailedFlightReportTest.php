<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Flight\AirlineTransaction;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightSystemTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * تغطية اختبارات شاملة لنقطة النهاية GET /api/v1/reports/flights/detailed
 * (FlightDetailedReport.vue في الفرونت).
 *
 * يغطي:
 *  - الاستدعاء بدون فلاتر (يجب أن يعيد 200 + items من carrier/system/group)
 *  - فلتر نظام الحجز (system_X / carrier_X)
 *  - فلتر الفترة الزمنية (from_date / to_date)
 *  - البحث (PNR / رقم الحجز / الوجهة / العميل)
 *  - التقسيم والـ pagination
 *  - السيناريوهات الاستثنائية (لا توجد حركات / قيم غير معروفة / اختلالات)
 */
class DetailedFlightReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Flight Detailed Tester',
            'email' => 'flight-detailed-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    /**
     * مساعد: يبني مجموعة طيران + حجز مرتبط + معاملة شراء + معاملة سداد
     */
    protected function makeGroupBookingScenario(string $bookingNumber, string $pnr, int $amount, string $route = 'CAI-JED'): array
    {
        $customer = Customer::factory()->create([
            'full_name' => 'عميل '.$bookingNumber,
            'type' => 'individual',
        ]);

        $group = FlightGroup::create([
            'name' => 'مجموعة '.$bookingNumber,
            'code' => substr($bookingNumber, -3),
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        [$origin, $destination] = explode('-', $route);

        $booking = FlightBooking::create([
            'booking_reference' => $bookingNumber.'-REF',
            'booking_number' => $bookingNumber,
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Direct',
            'system_type' => 'manual',
            'status' => 'CONFIRMED',
            'customer_id' => $customer->id,
            'flight_group_id' => $group->id,
            'purchase_balance_source' => 'group',
            'pnr' => $pnr,
            'agent_name' => 'Test Agent',
            'airline' => 'Test Air',
            'airline_name' => 'Test Air',
            'origin' => $origin,
            'from_airport' => $origin,
            'destination' => $destination,
            'to_airport' => $destination,
            'departure_date' => now()->addDays(5),
            'departure_time' => now()->addDays(5)->setTime(10, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'purchase_price' => $amount,
            'purchase_price_egp' => $amount,
            'selling_price' => $amount + 200,
            'profit' => 200,
            'currency' => 'EGP',
            'created_by' => $this->user->id,
        ]);

        FlightGroupTransaction::create([
            'flight_group_id' => $group->id,
            'flight_booking_id' => $booking->id,
            'type' => 'debt',
            'amount' => $amount,
            'notes' => "شراء تذكرة طيران بالأجل — حجز #{$bookingNumber}",
            'created_by' => $this->user->id,
        ]);

        FlightGroupTransaction::create([
            'flight_group_id' => $group->id,
            'flight_booking_id' => null,
            'type' => 'payment',
            'amount' => $amount,
            'notes' => "سند صرف — دفع لمجموعة طيران: مجموعة {$bookingNumber}",
            'created_by' => $this->user->id,
        ]);

        return [$group, $booking, $customer];
    }

    public function test_unfiltered_call_returns_paginated_shape(): void
    {
        [$group, $booking] = $this->makeGroupBookingScenario('FLT-DET-A', 'PNR-A1', 1000);

        $response = $this->getJson('/api/v1/reports/flights/detailed');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'source_type',
                        'group_key',
                        'created_at',
                        'system_name',
                        'type',
                        'status_ar',
                        'amount',
                        'debit',
                        'credit',
                        'balance_before',
                        'balance_after',
                        'booking_number',
                        'pnr',
                        'route',
                        'departure_date',
                        'customer_name',
                        'customer_type',
                        'employee_name',
                        'payment_system',
                    ],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);

        $items = $response->json('data.data');
        $this->assertGreaterThanOrEqual(2, count($items));
        $this->assertEquals(1, $response->json('data.current_page'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.total'));
    }

    public function test_groups_debt_and_payment_for_same_booking_share_group_key(): void
    {
        [$group, $booking] = $this->makeGroupBookingScenario('FLT-DET-B', 'PNR-B1', 1500);

        $response = $this->getJson('/api/v1/reports/flights/detailed');
        $response->assertOk();

        $items = $response->json('data.data');

        $bookingRows = collect($items)->where('booking_number', 'FLT-DET-B');
        $this->assertCount(2, $bookingRows, 'Expected exactly 2 rows for one booking (debt + payment)');

        $groupKeys = $bookingRows->pluck('group_key')->unique();
        $this->assertCount(1, $groupKeys, 'Debt + payment of same booking must share a single group_key');

        $debitRow = $bookingRows->firstWhere('status_ar', 'حجز');
        $creditRow = $bookingRows->firstWhere('status_ar', 'سداد');

        $this->assertNotNull($debitRow);
        $this->assertNotNull($creditRow);
        $this->assertEquals(1500.0, (float) $debitRow['debit']);
        $this->assertEquals(1500.0, (float) $creditRow['credit']);
        $this->assertEquals(0.0, (float) $debitRow['credit']);
        $this->assertEquals(0.0, (float) $creditRow['debit']);

        // الحجز (debit) يجب أن يظهر قبل السداد (credit) داخل نفس المجموعة
        $firstIndex = $items ? array_search($debitRow['id'], array_column($items, 'id')) : null;
        $secondIndex = $items ? array_search($creditRow['id'], array_column($items, 'id')) : null;
        $this->assertNotFalse($firstIndex, 'Debit row should be present in response items');
        $this->assertNotFalse($secondIndex, 'Credit row should be present in response items');
        $this->assertLessThan($secondIndex, $firstIndex, 'Debit row must precede credit row in same group (debit first / سداد second)');
    }

    public function test_filters_by_carrier_scope(): void
    {
        // مجموعة على carrier_id = 1
        [$groupA, $bookingA] = $this->makeGroupBookingScenario('FLT-DET-C1', 'PNR-C1', 800);

        // أنشئ carrier ثم أعد group بشكل يدوي مع carrier_id
        $carrier = FlightCarrier::create([
            'flight_system_id' => null,
            'name' => 'EgyptAir Test',
            'code' => 'MS',
            'iata_code' => 'MS',
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        // ابنِ سيناريو ثاني على carrier معين
        $customer = Customer::factory()->create(['full_name' => 'عميل الناقل', 'type' => 'individual']);

        $groupWithCarrier = FlightGroup::create([
            'name' => 'مجموعة على الناقل',
            'code' => 'GF2',
            'flight_carrier_id' => $carrier->id,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        FlightGroupTransaction::create([
            'flight_group_id' => $groupWithCarrier->id,
            'flight_booking_id' => null,
            'type' => 'debt',
            'amount' => 500,
            'notes' => 'حجز على الناقل',
            'created_by' => $this->user->id,
        ]);

        // فلتر حسب carrier_<id>
        $response = $this->getJson('/api/v1/reports/flights/detailed?booking_system=carrier_'.$carrier->id);
        $response->assertOk();

        $items = $response->json('data.data');
        $this->assertGreaterThanOrEqual(1, count($items));
        foreach ($items as $item) {
            $this->assertContains($item['source_type'], ['group']);
        }
        $this->assertStringContainsString('مجموعة على الناقل', $items[0]['system_name']);
    }

    public function test_filters_by_date_range(): void
    {
// عملية قديمة (قبل 10 أيام) — created_at غير fillable في Model، نستخدم DB::table
        $oldGroupId = FlightGroup::create([
            'name' => 'مجموعة قديمة',
            'code' => 'OLD',
            'is_active' => true,
            'created_by' => $this->user->id,
        ])->id;
        DB::table('flight_group_transactions')->insert([
            'flight_group_id' => $oldGroupId,
            'flight_booking_id' => null,
            'type' => 'debt',
            'amount' => 300,
            'notes' => 'حركة قديمة',
            'created_by' => $this->user->id,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        // عملية حديثة (اليوم)
        FlightGroupTransaction::create([
            'flight_group_id' => FlightGroup::create([
                'name' => 'مجموعة حديثة',
                'code' => 'NEW',
                'is_active' => true,
                'created_by' => $this->user->id,
            ])->id,
            'flight_booking_id' => null,
            'type' => 'debt',
            'amount' => 700,
            'notes' => 'حركة حديثة',
            'created_by' => $this->user->id,
        ]);

        // فلتر: فقط آخر 3 أيام
        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();
        $response = $this->getJson("/api/v1/reports/flights/detailed?from_date={$from}&to_date={$to}");
        $response->assertOk();

        $items = $response->json('data.data');
        $this->assertCount(1, $items, 'Only the recent group transaction should remain');
        $this->assertStringContainsString('حديثة', $items[0]['system_name']);

        // فلتر: الماضي البعيد فقط
        $fromOld = now()->subDays(30)->toDateString();
        $toOld = now()->subDays(5)->toDateString();
        $oldResponse = $this->getJson("/api/v1/reports/flights/detailed?from_date={$fromOld}&to_date={$toOld}");
        $oldResponse->assertOk();
        $oldItems = $oldResponse->json('data.data');
        $this->assertCount(1, $oldItems, 'Only the old group transaction should remain');
        $this->assertStringContainsString('قديمة', $oldItems[0]['system_name']);
    }

    public function test_search_by_booking_number(): void
    {
        $this->makeGroupBookingScenario('UNIQUE-XYZ-99', 'PNR-UNI', 1200);
        $this->makeGroupBookingScenario('OTHER-77', 'PNR-OTH', 900);

        $response = $this->getJson('/api/v1/reports/flights/detailed?search=UNIQUE-XYZ');
        $response->assertOk();

        $items = $response->json('data.data');
        $bookingNumbers = collect($items)->pluck('booking_number')->filter()->unique();
        $this->assertCount(1, $bookingNumbers);
        $this->assertEquals('UNIQUE-XYZ-99', $bookingNumbers->first());
    }

    public function test_search_by_pnr(): void
    {
        $this->makeGroupBookingScenario('SR-A', 'SEARCHPNR123', 500);
        $this->makeGroupBookingScenario('SR-B', 'OTHERPNR999', 700);

        $response = $this->getJson('/api/v1/reports/flights/detailed?search=SEARCHPNR');
        $response->assertOk();

        $items = $response->json('data.data');
        $pnrs = collect($items)->pluck('pnr')->filter()->unique();
        $this->assertCount(1, $pnrs);
        $this->assertEquals('SEARCHPNR123', $pnrs->first());
    }

    public function test_paginates_results_correctly(): void
    {
        // أنشئ 6 معاملات على نفس المجموعة لتجاوز الصفحة الأولى
        $group = FlightGroup::create([
            'name' => 'صفحة اختبار',
            'code' => 'PG',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        for ($i = 0; $i < 6; $i++) {
            FlightGroupTransaction::create([
                'flight_group_id' => $group->id,
                'flight_booking_id' => null,
                'type' => 'debt',
                'amount' => 100 + $i,
                'notes' => "حركة رقم {$i}",
                'created_by' => $this->user->id,
            ]);
        }

        // الصفحة الأولى: per_page = 2 — من إجمالي 6 معاملات نحصل على 3 صفحات
        $first = $this->getJson('/api/v1/reports/flights/detailed?per_page=2&page=1');
        $first->assertOk();
        $this->assertEquals(2, count($first->json('data.data')));
        $this->assertEquals(3, $first->json('data.last_page'), 'ceil(6/2)=3 pages expected');
        $this->assertEquals(6, $first->json('data.total'));
        $this->assertEquals(1, $first->json('data.current_page'));

        // الصفحة الثانية: نفس الباج
        $second = $this->getJson('/api/v1/reports/flights/detailed?per_page=2&page=2');
        $second->assertOk();
        $this->assertEquals(2, count($second->json('data.data')));
        $this->assertEquals(2, $second->json('data.current_page'));

        $firstIds = collect($first->json('data.data'))->pluck('id');
        $secondIds = collect($second->json('data.data'))->pluck('id');
        $this->assertEmpty($firstIds->intersect($secondIds), 'Pagination pages must not overlap');
    }

    public function test_empty_data_array_when_no_transactions(): void
    {
        $response = $this->getJson('/api/v1/reports/flights/detailed');
        $response->assertOk();

        $this->assertEquals([], $response->json('data.data'));
        $this->assertEquals(0, $response->json('data.total'));
        $this->assertEquals(1, $response->json('data.current_page'));
        $this->assertEquals(1, $response->json('data.last_page'));
    }

    public function test_handles_garbage_booking_system_value_without_500(): void
    {
        $this->makeGroupBookingScenario('SAFE-1', 'SAFE-PNR', 500);

        // قيمة غير معروفة — يجب ألا تتسبب في 500
        $response = $this->getJson('/api/v1/reports/flights/detailed?booking_system=garbage_value');
        $response->assertOk();
        $this->assertIsArray($response->json('data.data'));

        // قيمة بـ prefix غير معروف
        $response2 = $this->getJson('/api/v1/reports/flights/detailed?booking_system=foo_99');
        $response2->assertOk();
        $this->assertIsArray($response2->json('data.data'));
    }

    public function test_includes_all_three_sources_carrier_system_group(): void
    {
        // carrier
        $carrier = FlightCarrier::create([
            'flight_system_id' => null,
            'name' => 'Test Carrier',
            'code' => 'TC',
            'iata_code' => 'TC',
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $airlineAccount = \App\Models\Flight\AirlineAccount::create([
            'name' => 'Test Airline Acc',
            'code' => 'TAA',
            'system_type' => 'manual',
            'currency' => 'EGP',
            'balance' => 1000,
            'is_active' => true,
        ]);

        AirlineTransaction::create([
            'flight_carrier_id' => $carrier->id,
            'airline_account_id' => $airlineAccount->id,
            'flight_booking_id' => null,
            'type' => 'debit',
            'amount' => 300,
            'balance_before' => 1000,
            'balance_after' => 700,
            'description' => 'خصم حجز',
            'created_by' => $this->user->id,
        ]);

        // system
        $system = FlightSystem::create([
            'name' => 'Test GDS',
            'code' => 'tgds',
            'type' => 'gds',
            'currency' => 'EGP',
            'balance' => 500,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        FlightSystemTransaction::create([
            'flight_system_id' => $system->id,
            'flight_booking_id' => null,
            'type' => 'credit',
            'amount' => 200,
            'balance_before' => 300,
            'balance_after' => 500,
            'description' => 'شحن رصيد',
            'created_by' => $this->user->id,
        ]);

        // group
        $this->makeGroupBookingScenario('GRP-X', 'GRPPNR', 400);

        $response = $this->getJson('/api/v1/reports/flights/detailed');
        $response->assertOk();

        $sources = collect($response->json('data.data'))->pluck('source_type')->unique()->values()->all();
        sort($sources);
        $this->assertEquals(['carrier', 'group', 'system'], $sources, 'Report must include all 3 sources: carrier, system, group');
    }

    public function test_rejects_unauthenticated_request(): void
    {
        // لا تعتمد على Sanctum::actingAs — أنشئ request جديد بدون مصادقة
        auth()->forgetGuards();

        $response = $this->getJson('/api/v1/reports/flights/detailed');

        // إما 401 أو 403 حسب middleware المستخدم — المهم أن لا يكون 200
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }
}