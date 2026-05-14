<x-filament-panels::page>
    <style>
        .dashboard-glass {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 1rem;
            transition: all 0.3s ease;
        }
        .dashboard-glass:hover {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5);
        }
        .icon-box {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            border-radius: 0.75rem;
            font-size: 1.5rem;
        }
        .text-gradient {
            background: linear-gradient(135deg, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-black text-white">الملخص المالي والإداري</h1>
                <p class="text-gray-400 mt-1">نظرة عامة على نشاط قسم الطيران وحركة الأموال</p>
            </div>
            <div>
                <x-filament::button wire:click="mount" icon="heroicon-o-arrow-path">
                    تحديث البيانات
                </x-filament::button>
            </div>
        </div>

        <!-- Top Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <!-- Total Revenue -->
            <div class="dashboard-glass p-5">
                <div class="flex items-center gap-4">
                    <div class="icon-box bg-emerald-500/20 text-emerald-400">
                        @svg('heroicon-o-currency-dollar', 'w-6 h-6')
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">إيرادات الشهر</p>
                        <h3 class="text-2xl font-black text-white mt-1">{{ number_format($stats['revenue_this_month'], 2) }} <span class="text-sm font-normal text-gray-500">ج.م</span></h3>
                    </div>
                </div>
            </div>

            <!-- Total Bookings -->
            <div class="dashboard-glass p-5">
                <div class="flex items-center gap-4">
                    <div class="icon-box bg-indigo-500/20 text-indigo-400">
                        @svg('heroicon-o-ticket', 'w-6 h-6')
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">إجمالي الحجوزات</p>
                        <h3 class="text-2xl font-black text-white mt-1">{{ number_format($stats['total_bookings']) }} <span class="text-sm font-normal text-gray-500">تذكرة</span></h3>
                    </div>
                </div>
            </div>

            <!-- Cashboxes -->
            <div class="dashboard-glass p-5 border-t-2 border-t-amber-500/50">
                <div class="flex items-center gap-4">
                    <div class="icon-box bg-amber-500/20 text-amber-400">
                        @svg('heroicon-o-banknotes', 'w-6 h-6')
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">الخزائن النقدية ({{ $stats['cashboxes']['count'] }})</p>
                        <h3 class="text-2xl font-black text-white mt-1">{{ number_format($stats['cashboxes']['balance'], 2) }}</h3>
                    </div>
                </div>
            </div>

            <!-- Banks -->
            <div class="dashboard-glass p-5 border-t-2 border-t-sky-500/50">
                <div class="flex items-center gap-4">
                    <div class="icon-box bg-sky-500/20 text-sky-400">
                        @svg('heroicon-o-building-library', 'w-6 h-6')
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">البنوك والبريد ({{ $stats['banks']['count'] }})</p>
                        <h3 class="text-2xl font-black text-white mt-1">{{ number_format($stats['banks']['balance'], 2) }}</h3>
                    </div>
                </div>
            </div>

            <!-- Wallets -->
            <div class="dashboard-glass p-5 border-t-2 border-t-emerald-500/50">
                <div class="flex items-center gap-4">
                    <div class="icon-box bg-emerald-500/20 text-emerald-400">
                        @svg('heroicon-o-device-phone-mobile', 'w-6 h-6')
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">محافظ التحصيل ({{ $stats['wallets']['count'] }})</p>
                        <h3 class="text-2xl font-black text-white mt-1">{{ number_format($stats['wallets']['balance'], 2) }}</h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Recent Bookings Table -->
            <div class="lg:col-span-2 dashboard-glass p-6">
                <h2 class="text-lg font-bold text-white mb-4">أحدث الحجوزات</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="text-xs text-gray-400 uppercase bg-gray-800/30 rounded-lg">
                            <tr>
                                <th class="px-4 py-3 rounded-r-lg">رقم الحجز</th>
                                <th class="px-4 py-3">العميل</th>
                                <th class="px-4 py-3">الوجهة</th>
                                <th class="px-4 py-3">السعر</th>
                                <th class="px-4 py-3 rounded-l-lg">الحالة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800/50">
                            @forelse($recentBookings as $booking)
                                <tr class="hover:bg-white/5 transition-colors">
                                    <td class="px-4 py-3 font-mono text-sky-400 font-bold">{{ $booking['booking_number'] }}</td>
                                    <td class="px-4 py-3 text-white">{{ $booking['customer']['full_name'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-300">{{ $booking['destination'] ?? '—' }}</td>
                                    <td class="px-4 py-3 font-mono font-bold text-emerald-400">{{ number_format($booking['selling_price'], 2) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-1 text-xs rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                            {{ $booking['status'] ?? 'مؤكد' }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-gray-500">لا توجد حجوزات حديثة.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 pt-4 border-t border-white/10 text-center">
                    <a href="{{ \App\Filament\Admin\Resources\FlightBookings\FlightBookingResource::getUrl('index') }}" class="text-sm font-bold text-sky-400 hover:text-sky-300 transition-colors">
                        عرض جميع الحجوزات &larr;
                    </a>
                </div>
            </div>

            <!-- Quick Actions / Info -->
            <div class="space-y-4">
                <div class="dashboard-glass p-6">
                    <h2 class="text-lg font-bold text-white mb-4">إجمالي سيولة الطيران</h2>
                    <div class="flex items-center justify-center py-6 bg-gradient-to-br from-indigo-500/10 to-purple-500/10 rounded-xl border border-indigo-500/20">
                        <div class="text-center">
                            <span class="text-sm font-bold text-indigo-300 uppercase tracking-widest">إجمالي الأرصدة المتاحة</span>
                            <div class="mt-2 text-4xl font-black text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-sky-400">
                                {{ number_format($stats['total_balance'], 2) }}
                            </div>
                            <span class="text-gray-400 mt-1 block">جنيه مصري</span>
                        </div>
                    </div>
                </div>

                <div class="dashboard-glass p-6">
                    <h2 class="text-lg font-bold text-white mb-4">الوصول السريع</h2>
                    <div class="grid grid-cols-2 gap-3">
                        <a href="{{ \App\Filament\Admin\Resources\FlightBookings\FlightBookingResource::getUrl('create') }}" class="flex flex-col items-center justify-center p-4 rounded-xl bg-sky-500/10 border border-sky-500/20 hover:bg-sky-500/20 transition group">
                            @svg('heroicon-o-plus-circle', 'w-6 h-6 text-sky-400 mb-2 group-hover:scale-110 transition-transform')
                            <span class="text-xs font-bold text-sky-100">حجز جديد</span>
                        </a>
                        <a href="{{ \App\Filament\Admin\Resources\FlightTreasuries\FlightTreasuryResource::getUrl('index') }}" class="flex flex-col items-center justify-center p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 hover:bg-amber-500/20 transition group">
                            @svg('heroicon-o-banknotes', 'w-6 h-6 text-amber-400 mb-2 group-hover:scale-110 transition-transform')
                            <span class="text-xs font-bold text-amber-100">خزينة جديدة</span>
                        </a>
                        <a href="{{ \App\Filament\Admin\Resources\BankAccounts\BankAccountResource::getUrl('index') }}" class="flex flex-col items-center justify-center p-4 rounded-xl bg-indigo-500/10 border border-indigo-500/20 hover:bg-indigo-500/20 transition group">
                            @svg('heroicon-o-building-library', 'w-6 h-6 text-indigo-400 mb-2 group-hover:scale-110 transition-transform')
                            <span class="text-xs font-bold text-indigo-100">إدارة البنوك</span>
                        </a>
                        <a href="{{ \App\Filament\Admin\Resources\WalletAccounts\WalletAccountResource::getUrl('index') }}" class="flex flex-col items-center justify-center p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 hover:bg-emerald-500/20 transition group">
                            @svg('heroicon-o-device-phone-mobile', 'w-6 h-6 text-emerald-400 mb-2 group-hover:scale-110 transition-transform')
                            <span class="text-xs font-bold text-emerald-100">المحافظ</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
