@props(['title' => 'لوحة التحكم', 'subtitle' => ''])

<div class="fi-dashboard-layout min-h-screen bg-gradient-to-br from-[#080d18] via-[#0e1525] to-[#080d18]">
    <!-- Background Effects -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-blue-500/5 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-cyan-500/5 rounded-full blur-3xl"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] bg-sky-500/3 rounded-full blur-3xl"></div>
    </div>

    <!-- Main Content -->
    <div class="relative z-10">
        <!-- Header Section -->
        @if($title || $subtitle)
        <div class="mb-8 px-4 sm:px-6 lg:px-8">
            <div class="bg-gradient-to-r from-slate-900/50 to-slate-900/30 backdrop-blur-xl border border-white/10 rounded-2xl p-6 sm:p-8 shadow-2xl">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">
                            {{ $title }}
                        </h1>
                        @if($subtitle)
                        <p class="mt-2 text-slate-400">{{ $subtitle }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2 px-4 py-2 bg-green-500/10 border border-green-500/20 rounded-lg">
                            <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                            <span class="text-green-400 text-sm font-medium">النظام نشط</span>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-slate-500">آخر تحديث</p>
                            <p class="text-sm font-medium text-slate-300">{{ now()->format('H:i') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Content Area -->
        <div class="px-4 sm:px-6 lg:px-8 pb-8">
            {{ $slot }}
        </div>
    </div>
</div>

<style>
.fi-dashboard-layout {
    font-family: 'IBM Plex Sans Arabic', system-ui, sans-serif;
}
</style>
