<x-filament-panels::page>
    @php
        $isDown = $isDown ?? false;
        $status = $status ?? [];
        $bypassUrl = $status['bypass_url'] ?? null;
        $downAt = $status['down_at'] ?? null;
        $retryAfter = $status['retry_after'] ?? null;
        $redirectUrl = $status['redirect_url'] ?? null;
        $secret = $status['secret'] ?? null;
    @endphp

    <div class="space-y-6" dir="rtl">
        {{-- Status Hero --}}
        <div class="overflow-hidden rounded-2xl border {{ $isDown ? 'border-amber-300 bg-amber-50 dark:bg-amber-950/20' : 'border-emerald-300 bg-emerald-50 dark:bg-emerald-950/20' }}">
            <div class="p-6 sm:p-8">
                <div class="flex flex-col items-start gap-6 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-4">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl {{ $isDown ? 'bg-amber-500/20' : 'bg-emerald-500/20' }}">
                            @if ($isDown)
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-7 w-7 text-amber-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-7 w-7 text-emerald-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                            @endif
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-2.5 w-2.5 rounded-full {{ $isDown ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500' }}"></span>
                                <h2 class="text-xl font-black {{ $isDown ? 'text-amber-900 dark:text-amber-200' : 'text-emerald-900 dark:text-emerald-200' }}">
                                    {{ $isDown ? 'الموقع في وضع الصيانة الآن' : 'الموقع يعمل بشكل طبيعي' }}
                                </h2>
                            </div>
                            <p class="mt-1 text-sm {{ $isDown ? 'text-amber-800 dark:text-amber-300' : 'text-emerald-800 dark:text-emerald-300' }}">
                                {{ $isDown
                                    ? 'جميع الزوار يرون صفحة 503 المخصصة. أنت كمشرف يمكنك الدخول من الرابط أدناه.'
                                    : 'يمكنك تفعيل وضع الصيانة لإغلاق الموقع أمام الزوار مؤقتاً.' }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-xl bg-white/60 px-4 py-3 text-center backdrop-blur dark:bg-black/20">
                        <div class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">الحالة</div>
                        <div class="mt-1 font-mono text-lg font-black {{ $isDown ? 'text-amber-600' : 'text-emerald-600' }}">
                            {{ $isDown ? 'DOWN' : 'UP' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Active Maintenance Details --}}
        @if ($isDown)
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                {{-- Bypass URL --}}
                @if ($bypassUrl)
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <div class="mb-3 flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5 text-primary-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                            </svg>
                            <h3 class="text-sm font-bold text-gray-900 dark:text-white">رابط دخول المشرفين</h3>
                        </div>
                        <div class="break-all rounded-lg bg-gray-100 p-3 font-mono text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200" dir="ltr">
                            {{ $bypassUrl }}
                        </div>
                        <a href="{{ $bypassUrl }}" target="_blank" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-600 hover:text-primary-700">
                            <span>فتح الرابط في تبويب جديد</span>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                        </a>
                    </div>
                @endif

                {{-- Meta --}}
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="mb-3 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5 text-primary-600">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        </svg>
                        <h3 class="text-sm font-bold text-gray-900 dark:text-white">تفاصيل الصيانة</h3>
                    </div>
                    <dl class="space-y-2 text-sm">
                        @if ($downAt)
                            <div class="flex justify-between">
                                <dt class="text-gray-500">منذ:</dt>
                                <dd class="font-mono font-bold text-gray-900 dark:text-white" dir="ltr">
                                    {{ \Carbon\Carbon::createFromTimestamp($downAt)->diffForHumans() }}
                                </dd>
                            </div>
                        @endif
                        @if ($retryAfter)
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Retry-After:</dt>
                                <dd class="font-mono font-bold text-gray-900 dark:text-white">{{ $retryAfter }} ثانية</dd>
                            </div>
                        @endif
                        @if ($redirectUrl)
                            <div class="flex justify-between">
                                <dt class="text-gray-500">تحويل:</dt>
                                <dd class="truncate font-mono text-xs font-bold text-gray-900 dark:text-white" dir="ltr">{{ $redirectUrl }}</dd>
                            </div>
                        @endif
                        @if ($secret)
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Secret Token:</dt>
                                <dd class="font-mono font-bold text-gray-900 dark:text-white" dir="ltr">{{ $secret }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>
        @endif

        {{-- Helper info --}}
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900/50 dark:bg-blue-950/20">
            <div class="flex items-start gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5 shrink-0 text-blue-600">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                </svg>
                <div class="text-sm text-blue-900 dark:text-blue-200">
                    <p class="mb-1 font-bold">معلومة سريعة</p>
                    <p class="leading-relaxed">
                        • عند تفعيل الصيانة، حتظهر صفحة <code class="rounded bg-blue-100 px-1 py-0.5 font-mono text-xs dark:bg-blue-900">503.blade.php</code> للزوار.<br>
                        • الـ <strong>Secret Token</strong> بيديك وصول كامل للموقع خلال الصيانة من غير ما تشيلها.<br>
                        • الأمر ده بيشتغل زي <code class="rounded bg-blue-100 px-1 py-0.5 font-mono text-xs dark:bg-blue-900">php artisan down</code> بالظبط من الـ CLI.
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>