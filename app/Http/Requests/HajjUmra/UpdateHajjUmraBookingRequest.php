<?php

namespace App\Http\Requests\HajjUmra;

use App\Enums\HajjUmraStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHajjUmraBookingRequest extends FormRequest
{
    /**
     * Fields that are LOCKED after booking creation.
     *
     * Once a Hajj/Umrah booking is saved, its financial price columns cannot
     * be edited. The booking's financial truth is fixed at creation time:
     * changing any of these later would force a reversal+repost of the
     * corresponding Income / Expense transactions, which:
     *   1. Bypasses the MySQL `transactions_income_unique_key` index
     *      (generated column on (type='income', related_id)) — INSERT
     *      would fail with `1062 Duplicate entry`.
     *   2. Doubles the audit trail on every edit (reversed Income + new
     *      Income), contaminating downstream revenue reports.
     *   3. Risks a `paid_amount > new selling_price` situation if the new
     *      price is below what the customer already paid.
     *
     * The lock is enforced in TWO places for defense-in-depth:
     *   - This Form Request strips the fields before validation so the
     *     API never sees them and returns a clean 422.
     *   - HajjUmraBookingService::update() raises a RuntimeException if
     *     any of these fields are present in the data array (covers
     *     non-HTTP callers like Tinker, queue jobs, internal scripts).
     *
     * @var array<int, string>
     */
    public const LOCKED_FIELDS = [
        'selling_price',
        'purchase_price',
        'companion_selling_price',
        'companion_purchase_price',
        'accommodation_extra_charge',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reject (don't silently strip) any LOCKED_FIELDS present in the request.
     *
     * Returns a clean 422 ValidationException for the FIRST locked field
     * detected, with the full Arabic business message. The remaining locked
     * fields remain untouched in the input bag — they would be caught by
     * the service-side defense-in-depth guard anyway on internal callers.
     *
     * Why reject (not strip): an API contract of "silent drop" violates
     * client expectations (PATCH says "I changed X" → 200 doesn't say
     * "but X was ignored"). A 422 with `errors.{field}` keys + Arabic
     * message tells the client WHY their update was rejected, in their
     * language.
     *
     * Order matters: this runs BEFORE `rules()`, so even if no rule is
     * declared on these fields, ValidationException bubbles up and
     * Laravel's handler converts it to 422 with the proper envelope.
     */
    protected function prepareForValidation(): void
    {
        $arFieldNames = [
            'selling_price'              => 'سعر البيع',
            'purchase_price'             => 'سعر الشراء',
            'companion_selling_price'    => 'سعر بيع المرافق',
            'companion_purchase_price'   => 'سعر شراء المرافق',
            'accommodation_extra_charge' => 'رسوم الإقامة الإضافية',
        ];

        $present = [];
        foreach (self::LOCKED_FIELDS as $locked) {
            // Caller is meaningfully trying to set this field — null/empty
            // (which a UI may emit as a re-emission of the full row) is
            // ignored. Only non-null, non-empty values trigger rejection.
            if (! $this->has($locked)) {
                continue;
            }
            $val = $this->input($locked);
            if ($val !== null && $val !== '') {
                $present[$locked] = $val;
            }
        }

        if (empty($present)) {
            return;
        }

        $firstKey = array_key_first($present);
        $firstName = $arFieldNames[$firstKey] ?? $firstKey;
        $allList = implode('، ', $arFieldNames);
        $message = "لا يمكن تعديل {$firstName} بعد إنشاء الحجز. "
                  ."الحقول المالية مُقفلة بعد الإنشاء: {$allList}. "
                  ."لتصحيح سعر، ألغِ الحجز (cancel) وأنشئ حجزاً جديداً.";

        // For every locked field the caller attempted, attach a structured
        // validation error under `errors.{field}` — this matches Laravel's
        // standard 422 envelope so clients can react programmatically.
        $errors = [];
        foreach (array_keys($present) as $field) {
            $errors[$field] = [$message];
        }

        throw \Illuminate\Validation\ValidationException::withMessages($errors);
    }

    public function rules(): array
    {
        return [
            'companion_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            // NOTE: LOCKED_FIELDS (selling_price, purchase_price,
            //       companion_selling_price, companion_purchase_price,
            //       accommodation_extra_charge) intentionally have NO
            //       validation rule — they are stripped in
            //       prepareForValidation() and additionally guarded in
            //       HajjUmraBookingService::update().
            'per_person' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(array_keys(HajjUmraStatus::forDropdown()))],
            'agent_name' => ['sometimes', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],

            'supplier_id' => ['nullable', 'integer', 'exists:umrah_suppliers,id'],
            'accommodation_choice' => ['nullable', 'string', 'max:50'],
            'passengers' => ['nullable', 'array'],
            'passengers.*.category' => ['required_with:passengers', 'string', 'in:adult,child_with_bed,child_no_bed,infant'],
            'passengers.*.count' => ['required_with:passengers', 'integer', 'min:0'],
            'passengers.*.unit_price' => ['required_with:passengers', 'numeric', 'min:0'],
            'passengers.*.subtotal' => ['required_with:passengers', 'numeric', 'min:0'],
        ];
    }

    /**
     * FIX (GAP #HJ-8, fixed 2026-07-16):
     *   Arabic error messages for enum validation (Rule::in).
     *   See StoreHajjUmraBookingRequest for full context.
     */
    public function messages(): array
    {
        $statusValues = implode('، ', array_keys(HajjUmraStatus::forDropdown()));
        $passengerCategories = 'adult، child_with_bed، child_no_bed، infant';

        return [
            'status.Illuminate\Validation\Rules\In' => "قيمة الحالة غير صحيحة. القيم المسموحة: {$statusValues}.",
            'status.in'                              => "قيمة الحالة غير صحيحة. القيم المسموحة: {$statusValues}.",
            'status.string'                           => 'قيمة الحالة يجب أن تكون نصاً.',

            // NOTE: The locked financial fields
            //   (selling_price, purchase_price,
            //    companion_selling_price, companion_purchase_price,
            //    accommodation_extra_charge)
            // have NO validation messages here — they are stripped at the
            // Form Request boundary and additionally guarded in
            // HajjUmraBookingService::update() (which produces the user-
            // facing Arabic error message).

            'passengers.*.category.in' => "فئة الراكب غير صحيحة. القيم المسموحة: {$passengerCategories}.",
        ];
    }
}
