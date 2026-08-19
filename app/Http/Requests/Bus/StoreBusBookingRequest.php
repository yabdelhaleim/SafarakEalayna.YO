<?php

namespace App\Http\Requests\Bus;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ─── Route Mode A: existing inventory (Filament-managed) ───────────
            'inventory_id'   => 'nullable|integer|exists:bus_inventories,id',

            // ─── Route Mode B: manual / auto-created inventory ─────────────────
            'company_id'     => 'required_without:inventory_id|nullable|integer|exists:bus_companies,id',
            'route'          => 'required_without:inventory_id|nullable|string|max:500',
            // Step 3 fix: cost_price must be > 0 (was min:0 — exploitable).
            // Cross-field: selling_price must be >= cost_price (no-loss booking).
            'cost_price'     => 'required_without:inventory_id|nullable|numeric|min:0.01',
            'selling_price'  => 'required_without:inventory_id|nullable|numeric|min:0.01|gte:cost_price',
            'travel_date'    => 'nullable|date',
            'departure_time' => 'nullable|string|max:10',

            // ─── Common booking fields ─────────────────────────────────────────
            'customer_id'    => 'nullable|integer|exists:customers,id',
            'customer_name'  => 'required_without:customer_id|string|max:255',
            'customer_phone' => 'required_without:customer_id|string|max:20',
            'employee_id'    => 'nullable|integer|exists:employees,id',
            'quantity'       => 'required|integer|min:1',
            'notes'          => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'inventory_id.exists'            => 'الرحلة المحددة غير صالحة',
            'company_id.required_without'    => 'يجب اختيار شركة النقل',
            'company_id.exists'              => 'شركة النقل المحددة غير صالحة',
            'route.required_without'         => 'يجب كتابة المسار',
            'cost_price.required_without'    => 'يجب إدخال سعر الشراء (الآجل للشركة)',
            'cost_price.min'                 => 'سعر الشراء يجب أن يكون أكبر من صفر (0.01 على الأقل)',
            'selling_price.required_without' => 'يجب إدخال سعر البيع (للعميل)',
            'selling_price.min'              => 'سعر البيع يجب أن يكون أكبر من صفر (0.01 على الأقل)',
            'selling_price.gte'              => 'سعر البيع يجب أن يكون أكبر من أو يساوي سعر الشراء (لا رحلات بخسارة)',
            'customer_name.required_without' => 'اسم العميل مطلوب',
            'customer_phone.required_without'=> 'رقم هاتف العميل مطلوب',
            'quantity.required'              => 'عدد التذاكر مطلوب',
            'quantity.integer'               => 'عدد التذاكر يجب أن يكون رقماً صحيحاً',
            'quantity.min'                   => 'عدد التذاكر يجب أن يكون 1 على الأقل',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('employee_id') && auth()->check()) {
            $user = auth()->user();
            if ($user->employee) {
                $this->merge(['employee_id' => $user->employee->id]);
            }
        }
    }
}
