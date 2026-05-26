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
            'cost_price'     => 'required_without:inventory_id|nullable|numeric|min:0',   // سعر الشراء — مديونية الشركة
            'selling_price'  => 'required_without:inventory_id|nullable|numeric|min:0',   // سعر البيع — إيراد العميل
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
            'cost_price.min'                => 'سعر الشراء يجب أن يكون موجباً',
            'selling_price.required_without' => 'يجب إدخال سعر البيع (للعميل)',
            'selling_price.min'              => 'سعر البيع يجب أن يكون موجباً',
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
