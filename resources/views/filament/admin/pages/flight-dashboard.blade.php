<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <x-filament::card>
            <div class="text-sm font-medium text-gray-500">إجمالي الأرصدة</div>
            <div class="text-2xl font-bold">{{ number_format($stats['total_balance'] ?? 0, 2) }} ج.م</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-sm font-medium text-gray-500">البنوك</div>
            <div class="text-2xl font-bold">{{ number_format($stats['banks']['balance'] ?? 0, 2) }} ج.م</div>
            <div class="text-xs text-gray-400">{{ $stats['banks']['count'] ?? 0 }} حساب</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-sm font-medium text-gray-500">المحافظ</div>
            <div class="text-2xl font-bold">{{ number_format($stats['wallets']['balance'] ?? 0, 2) }} ج.م</div>
            <div class="text-xs text-gray-400">{{ $stats['wallets']['count'] ?? 0 }} حساب</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-sm font-medium text-gray-500">الخزائن</div>
            <div class="text-2xl font-bold">{{ number_format($stats['cashboxes']['balance'] ?? 0, 2) }} ج.م</div>
            <div class="text-xs text-gray-400">{{ $stats['cashboxes']['count'] ?? 0 }} حساب</div>
        </x-filament::card>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <x-filament::card>
            <div class="text-lg font-bold mb-4">إحصائيات الحجوزات</div>
            <div class="flex justify-between items-center mb-2">
                <span>إجمالي الحجوزات</span>
                <span class="font-bold">{{ $stats['total_bookings'] ?? 0 }}</span>
            </div>
            <div class="flex justify-between items-center">
                <span>إيرادات هذا الشهر</span>
                <span class="font-bold">{{ number_format($stats['revenue_this_month'] ?? 0, 2) }} ج.م</span>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="text-lg font-bold mb-4">أحدث الحجوزات</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3">رقم الحجز</th>
                            <th scope="col" class="px-6 py-3">العميل</th>
                            <th scope="col" class="px-6 py-3">السعر</th>
                            <th scope="col" class="px-6 py-3">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentBookings as $booking)
                            <tr class="bg-white border-b">
                                <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">{{ $booking['booking_number'] ?? 'N/A' }}</td>
                                <td class="px-6 py-4">{{ $booking['customer']['full_name'] ?? 'N/A' }}</td>
                                <td class="px-6 py-4">{{ number_format($booking['selling_price'] ?? 0, 2) }}</td>
                                <td class="px-6 py-4">{{ $booking['status'] ?? 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-4 text-center">لا توجد حجوزات حديثة</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::card>
    </div>
</x-filament-panels::page>
