<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\FlightBookingStatus;
use App\Enums\FlightSystemType;
use App\Enums\PassengerType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Enums\TripType;
use App\Helpers\ApiResponse;
use App\Models\Setting\Currency;
use App\Models\Setting\OperationType;
use App\Models\Setting\PaymentMethod;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class SettingController extends Controller
{
    public function paymentMethods()
    {
        $methods = PaymentMethod::active()->get()->map(function ($method) {
            return [
                'value' => $method->code,
                'label' => $method->name_ar,
                'labelEn' => $method->name_en,
                'color' => $method->color,
            ];
        });

        return ApiResponse::success('تم جلب طرق الدفع بنجاح', $methods);
    }

    public function operationTypes()
    {
        $types = OperationType::active()->get()->map(function ($type) {
            return [
                'value' => $type->code,
                'label' => $type->name_ar,
                'labelEn' => $type->name_en,
                'color' => $type->color,
            ];
        });

        return ApiResponse::success('تم جلب أنواع العمليات بنجاح', $types);
    }

    public function currencies()
    {
        $currencies = Currency::active()->get()->map(function ($currency) {
            return [
                'code' => $currency->code,
                'name' => $currency->name_ar,
                'nameEn' => $currency->name_en,
                'symbol' => $currency->symbol,
                'exchangeRate' => $currency->exchange_rate,
            ];
        });

        return ApiResponse::success('تم جلب العملات بنجاح', $currencies);
    }

    public function tripTypes()
    {
        $tripTypes = collect(TripType::cases())->map(function ($type) {
            return [
                'value' => $type->value,
                'label' => $type->label(),
                'icon' => $type->icon(),
                'description' => $type->description(),
            ];
        });

        return ApiResponse::success('تم جلب أنواع الرحلات بنجاح', $tripTypes);
    }

    /**
     * Account types (aligned with accounts.type / Filament-managed accounts).
     */
    public function accountTypes()
    {
        $types = collect(AccountType::cases())->map(fn (AccountType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
        ]);

        return ApiResponse::success('تم جلب أنواع الحسابات بنجاح', $types);
    }

    /**
     * Transaction types for filters and forms.
     */
    public function transactionTypes()
    {
        $labels = [
            'income' => 'دخل',
            'expense' => 'مصروف',
            'transfer' => 'تحويل',
            'refund' => 'استرداد',
        ];

        $types = collect(TransactionType::cases())->map(fn (TransactionType $type) => [
            'value' => $type->value,
            'label' => $labels[$type->value] ?? $type->name,
        ]);

        return ApiResponse::success('تم جلب أنواع المعاملات بنجاح', $types);
    }

    /**
     * Transaction modules (business modules).
     */
    public function transactionModules()
    {
        $labels = [
            'flight' => 'طيران',
            'bus' => 'باصات',
            'service' => 'خدمات',
            'online' => 'أونلاين',
            'fawry' => 'فوري',
            'hajj_umra' => 'حج وعمرة',
            'visa' => 'تأشيرات',
            'wallet' => 'محافظ',
            'general' => 'عام',
        ];

        $modules = collect(TransactionModule::cases())->map(fn (TransactionModule $m) => [
            'value' => $m->value,
            'label' => $labels[$m->value] ?? $m->name,
        ]);

        return ApiResponse::success('تم جلب وحدات المعاملات بنجاح', $modules);
    }

    /**
     * Flight booking filters (statuses, payment buckets, system_type enum) aligned with Filament enums.
     */
    public function flightBookingReference()
    {
        $bookingStatuses = collect(FlightBookingStatus::cases())->map(fn (FlightBookingStatus $s) => [
            'value' => $s->value,
            'label' => $s->label(),
        ])->values();

        $systemTypes = collect(FlightSystemType::cases())->map(fn (FlightSystemType $t) => [
            'value' => $t->value,
            'label' => $t->label(),
        ])->values();

        $paymentStatuses = collect([
            ['value' => 'paid', 'label' => 'مدفوع بالكامل'],
            ['value' => 'partial', 'label' => 'جزئي'],
            ['value' => 'unpaid', 'label' => 'غير مدفوع'],
        ]);

        $passengerTypes = collect(PassengerType::cases())->map(fn (PassengerType $p) => [
            'value' => $p->value,
            'label' => $p->label(),
        ])->values();

        return ApiResponse::success('تم جلب مرجع حجوزات الطيران بنجاح', [
            'booking_statuses' => $bookingStatuses,
            'system_types' => $systemTypes,
            'payment_statuses' => $paymentStatuses,
            'passenger_types' => $passengerTypes,
        ]);
    }
}
