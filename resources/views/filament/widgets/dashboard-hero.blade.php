<x-filament-widgets::widget class="fi-wi-widget fi-safarak-dashboard-hero">
    <div class="safarak-dashboard-hero relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[#141e30] via-[#0e1525] to-[#080d18] p-6 shadow-2xl sm:p-8 transition-all duration-300 hover:shadow-3xl">
        <!-- Decorative Elements -->
        <div class="pointer-events-none absolute -left-24 top-0 h-64 w-64 rounded-full bg-sky-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -right-16 bottom-0 h-48 w-48 rounded-full bg-cyan-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute top-1/2 left-1/2 h-96 w-96 -translate-x-1/2 -translate-y-1/2 rounded-full bg-blue-500/5 blur-3xl" aria-hidden="true"></div>

        <!-- Gradient Border Top -->
        <div class="absolute left-0 right-0 top-0 h-1 bg-gradient-to-r from-blue-500 via-cyan-500 to-blue-600"></div>

        <!-- Content -->
        <div class="relative z-10 flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
            <div class="flex-1">
                <!-- Brand -->
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-cyan-500 shadow-lg shadow-blue-500/30">
                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.3em] text-sky-400/90">سفارك إليّنا</p>
                </div>

                <!-- Heading -->
                <h2 class="mt-4 text-2xl font-black tracking-tight text-white sm:text-3xl lg:text-4xl">
                    لوحة التحكم الإدارية
                </h2>

                <!-- Subheading -->
                <p class="mt-3 max-w-2xl text-sm leading-relaxed text-slate-400 sm:text-base">
                    إدارة شاملة للحجوزات والمالية والعملاء والموظفين في مكان واحد. واجهة احترافية وسريعة.
                </p>

                <!-- Quick Stats -->
                <div class="mt-6 flex flex-wrap gap-4 sm:gap-6">
                    <div class="flex items-center gap-2">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-green-500/10">
                            <svg class="h-4 w-4 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400">النظام</p>
                            <p class="text-sm font-bold text-white">نشط</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/10">
                            <svg class="h-4 w-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400">آخر تحديث</p>
                            <p class="text-sm font-bold text-white">{{ now()->format('H:i') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col gap-3 sm:flex-row">
                <a
                    href="{{ url('/dashboard') }}"
                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/5 px-5 py-3 text-sm font-semibold text-slate-200 transition-all duration-300 hover:border-sky-500/50 hover:bg-sky-500/10 hover:text-white hover:shadow-lg hover:shadow-blue-500/20"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path d="M7 3H4a1.5 1.5 0 00-1.5 1.5v11A1.5 1.5 0 004 17h3M13 14l4-4-4-4M17 10H7" />
                    </svg>
                    الواجهة الرئيسية
                </a>
                <button
                    onclick="window.location.reload()"
                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 bg-gradient-to-r from-blue-500 to-cyan-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-500/30 transition-all duration-300 hover:scale-105 hover:shadow-xl hover:shadow-blue-500/40"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    تحديث البيانات
                </button>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
