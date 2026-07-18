<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filter bar --}}
        <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
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
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">التجميع</label>
                    <select wire:model.live="groupBy"
                            class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md shadow-sm">
                        <option value="daily">يومي</option>
                        <option value="weekly">أسبوعي</option>
                        <option value="monthly">شهري</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">القسم</label>
                    <select wire:model.live="division"
                            class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md shadow-sm">
                        <option value="tourism">السياحة</option>
                        <option value="office">المكتب</option>
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
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                <div class="text-sm text-gray-500 dark:text-gray-400">رصيد افتتاحي</div>
                <div class="text-xl font-bold text-gray-700 dark:text-gray-200 mt-1">
                    {{ number_format($summary['opening_balance'] ?? 0, 2) }} ج.م
                </div>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                <div class="text-sm text-gray-500 dark:text-gray-400">إجمالي الإيرادات (Inflow)</div>
                <div class="text-xl font-bold text-success-600 mt-1">
                    {{ number_format($summary['total_inflow'] ?? 0, 2) }} ج.م
                </div>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                <div class="text-sm text-gray-500 dark:text-gray-400">إجمالي المصروفات (Outflow)</div>
                <div class="text-xl font-bold text-error-600 mt-1">
                    {{ number_format($summary['total_outflow'] ?? 0, 2) }} ج.م
                </div>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                <div class="text-sm text-gray-500 dark:text-gray-400">صافي التغيّر</div>
                <div class="text-xl font-bold {{ ($summary['net_change'] ?? 0) >= 0 ? 'text-success-600' : 'text-error-600' }} mt-1">
                    {{ number_format($summary['net_change'] ?? 0, 2) }} ج.م
                </div>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                <div class="text-sm text-gray-500 dark:text-gray-400">رصيد ختامي</div>
                <div class="text-xl font-bold text-primary-600 mt-1">
                    {{ number_format($summary['closing_balance'] ?? 0, 2) }} ج.م
                </div>
            </div>
        </div>

        {{-- Period movements --}}
        @if(!empty($movements))
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold">الحركة حسب الفترة</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">الفترة</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">عدد الحركات</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Inflow</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Outflow</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">الصافي</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($movements as $m)
                                <tr>
                                    <td class="px-4 py-2 text-sm font-medium">{{ $m['period'] ?? '?' }}</td>
                                    <td class="px-4 py-2 text-sm">{{ $m['count'] ?? 0 }}</td>
                                    <td class="px-4 py-2 text-sm text-success-600">{{ number_format($m['inflow'] ?? 0, 2) }}</td>
                                    <td class="px-4 py-2 text-sm text-error-600">{{ number_format($m['outflow'] ?? 0, 2) }}</td>
                                    <td class="px-4 py-2 text-sm font-semibold {{ ($m['net'] ?? 0) >= 0 ? 'text-success-600' : 'text-error-600' }}">
                                        {{ number_format($m['net'] ?? 0, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Per-account breakdown --}}
        @if(!empty($accounts))
            <div class="bg-white dark:bg-gray-900 rounded-lg shadow">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold">التفصيل حسب الحساب</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">الحساب</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">النوع</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">الرصيد الحالي</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Inflow</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Outflow</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">صافي الفترة</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">عدد الحركات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($accounts as $a)
                                <tr>
                                    <td class="px-4 py-2 text-sm font-medium">{{ $a['account_name'] ?? '?' }}</td>
                                    <td class="px-4 py-2 text-sm">{{ $a['account_type'] ?? '?' }}</td>
                                    <td class="px-4 py-2 text-sm">{{ number_format($a['current_balance'] ?? 0, 2) }}</td>
                                    <td class="px-4 py-2 text-sm text-success-600">{{ number_format($a['inflow'] ?? 0, 2) }}</td>
                                    <td class="px-4 py-2 text-sm text-error-600">{{ number_format($a['outflow'] ?? 0, 2) }}</td>
                                    <td class="px-4 py-2 text-sm font-semibold {{ ($a['net'] ?? 0) >= 0 ? 'text-success-600' : 'text-error-600' }}">
                                        {{ number_format($a['net'] ?? 0, 2) }}
                                    </td>
                                    <td class="px-4 py-2 text-sm">{{ $a['transaction_count'] ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>