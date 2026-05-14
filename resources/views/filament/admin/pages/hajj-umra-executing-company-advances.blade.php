<x-filament-panels::page>
    <div class="space-y-2">
        @if(isset($netDue))
            <div class="text-sm text-gray-500">
                صافي المستحق (سحب - سداد): <span class="font-semibold">{{ number_format((float) $netDue, 2) }}</span> ج.م
            </div>
        @endif
    </div>

    <div class="mt-4 space-y-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>

