<x-filament-panels::page>
    <div class="space-y-6">
        @php
            $total = $this->getTotalStats();
            $modules = $this->getModuleStats();
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <x-filament::card class="p-4 bg-success-50 dark:bg-success-900/10 border-success-200">
                <div class="text-sm text-gray-500 dark:text-gray-400">إجمالي الإيرادات</div>
                <div class="text-2xl font-bold text-success-600">{{ number_format($total['income'], 2) }} ج.م</div>
            </x-filament::card>
            <x-filament::card class="p-4 bg-danger-50 dark:bg-danger-900/10 border-danger-200">
                <div class="text-sm text-gray-500 dark:text-gray-400">إجمالي المصروفات</div>
                <div class="text-2xl font-bold text-danger-600">{{ number_format($total['expense'], 2) }} ج.م</div>
            </x-filament::card>
            <x-filament::card class="p-4 bg-primary-50 dark:bg-primary-900/10 border-primary-200">
                <div class="text-sm text-gray-500 dark:text-gray-400">صافي الربح</div>
                <div class="text-2xl font-bold text-primary-600">{{ number_format($total['profit'], 2) }} ج.م</div>
            </x-filament::card>
        </div>

        <x-filament::card>
            <table class="w-full text-right border-collapse">
                <thead>
                    <tr class="border-b dark:border-gray-700">
                        <th class="py-3 px-4">الموديول / القسم</th>
                        <th class="py-3 px-4">الإيرادات</th>
                        <th class="py-3 px-4">المصروفات</th>
                        <th class="py-3 px-4">الصافي</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($modules as $stat)
                        <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="py-3 px-4 font-medium">{{ $stat['label'] }}</td>
                            <td class="py-3 px-4 text-success-600">{{ number_format($stat['income'], 2) }}</td>
                            <td class="py-3 px-4 text-danger-600">{{ number_format($stat['expense'], 2) }}</td>
                            <td class="py-3 px-4 font-bold {{ $stat['profit'] >= 0 ? 'text-primary-600' : 'text-danger-600' }}">
                                {{ number_format($stat['profit'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::card>
    </div>
</x-filament-panels::page>
