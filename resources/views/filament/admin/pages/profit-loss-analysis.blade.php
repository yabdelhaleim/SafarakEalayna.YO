<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filter bar --}}
        <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">من تاريخ</label>
                    <input wire:model.live="fromDate" type="date"
                           class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md shadow-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">إلى تاريخ</label>
                    <input wire:model.live="toDate" type="date"
                           class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md shadow-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الموديول</label>
                    <select wire:model.live="module"
                            class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md shadow-sm">
                        <option value="all">الكل (السياحة)</option>
                        <option value="flight">طيران</option>
                        <option value="hajj_umra">حج وعمرة</option>
                        <option value="visa">تأشيرات</option>
                    </select>
                </div>
                <div>
                    <button wire:click="loadData"
                            class="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700">
                        تحديث
                    </button>
                </div>
            </div>
        </div>

        {{-- KPI cards --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                <div class="text-sm text-gray-500 dark:text-gray-400">إجمالي الإيرادات</div>
                <div class="text-2xl font-bold text-success-600 mt-1">
                    {{ number_format($totals['total_income'] ?? 0, 2) }} ج.م
                </div>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                <div class="text-sm text-gray-500 dark:text-gray-400">إجمالي التكاليف (COGS)</div>
                <div class="text-2xl font-bold text-error-600 mt-1">
                    {{ number_format($totals['total_cogs'] ?? 0, 2) }} ج.م
                </div>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                <div class="text-sm text-gray-500 dark:text-gray-400">المصروفات التشغيلية</div>
                <div class="text-2xl font-bold text-warning-600 mt-1">
                    {{ number_format($totals['total_operating_expenses'] ?? 0, 2) }} ج.م
                </div>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                <div class="text-sm text-gray-500 dark:text-gray-400">صافي الربح</div>
                <div class="text-2xl font-bold {{ ($totals['net_profit'] ?? 0) >= 0 ? 'text-success-600' : 'text-error-600' }} mt-1">
                    {{ number_format($totals['net_profit'] ?? 0, 2) }} ج.م
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    هامش ربح: {{ number_format($totals['profit_margin'] ?? 0, 2) }}%
                </div>
            </div>
        </div>

        {{-- Per-module breakdown --}}
        <div class="bg-white dark:bg-gray-900 rounded-lg shadow">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold">التفصيل حسب الموديول</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">الموديول</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">إيرادات</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">تكاليف (COGS)</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">مصروفات</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">صافي</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($modules as $m)
                            <tr>
                                <td class="px-4 py-2 text-sm font-medium">{{ $m['module'] ?? '?' }}</td>
                                <td class="px-4 py-2 text-sm text-success-600">{{ number_format($m['income'] ?? 0, 2) }}</td>
                                <td class="px-4 py-2 text-sm text-error-600">{{ number_format($m['cogs'] ?? 0, 2) }}</td>
                                <td class="px-4 py-2 text-sm text-warning-600">{{ number_format($m['expenses'] ?? 0, 2) }}</td>
                                <td class="px-4 py-2 text-sm font-semibold {{ ($m['profit'] ?? 0) >= 0 ? 'text-success-600' : 'text-error-600' }}">
                                    {{ number_format($m['profit'] ?? 0, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Daily timeline --}}
        @if(!empty($daily))
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold">الجدول اليومي (Daily Timeline)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">التاريخ</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">إيرادات</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">تكاليف</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">ربح</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($daily as $d)
                                <tr>
                                    <td class="px-4 py-2 text-sm">{{ $d['date'] ?? '?' }}</td>
                                    <td class="px-4 py-2 text-sm text-success-600">{{ number_format($d['income'] ?? 0, 2) }}</td>
                                    <td class="px-4 py-2 text-sm text-error-600">{{ number_format(($d['cogs'] ?? 0) + ($d['operating_expenses'] ?? 0), 2) }}</td>
                                    <td class="px-4 py-2 text-sm font-semibold {{ ($d['profit'] ?? 0) >= 0 ? 'text-success-600' : 'text-error-600' }}">
                                        {{ number_format($d['profit'] ?? 0, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>