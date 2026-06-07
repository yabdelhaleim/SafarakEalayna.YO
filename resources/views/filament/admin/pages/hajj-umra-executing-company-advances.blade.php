<x-filament-panels::page>
    @if ($this->accountId)
        <div class="mb-4 rounded-xl bg-gray-50 p-4 text-sm text-gray-700 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10">
            <span class="font-medium">صافي المستحق على الشركة:</span>
            <span class="{{ $this->netDue > 0 ? 'text-warning-600' : 'text-success-600' }}">
                {{ number_format($this->netDue, 2) }} ج.م
            </span>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
