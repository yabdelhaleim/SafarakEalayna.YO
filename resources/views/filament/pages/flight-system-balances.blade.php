<x-filament-panels::page>
    <div class="safarak-flight-page space-y-8">
        <x-filament::section
            icon="heroicon-o-scale"
            icon-color="primary"
            heading="أرصدة أنظمة الحجز"
            description="كل نظام (GDS/NDC) مع الرصيد الحالي، حد الائتمان، والمتاح للخصم. استخدم زر «شحن رصيد نظام» في أعلى الصفحة لزيادة الرصيد من محفظة أو بنك أو خزينة (نفس عملة النظام). يُحدَّث الرصيد أيضاً من الحجز والاسترداد."
        >
            <div class="mb-6 grid gap-4 sm:grid-cols-2">
                <div class="safarak-flight-card safarak-flight-card--accent p-5">
                    <p class="text-sm font-medium text-slate-400">عدد الأنظمة</p>
                    <p class="mt-1 text-3xl font-bold tracking-tight text-white">
                        {{ $this->systems->count() }}
                    </p>
                </div>
                <div class="safarak-flight-card border border-dashed border-white/15 p-5">
                    <p class="text-sm font-medium text-slate-300">الشحن والتعديل</p>
                    <p class="mt-1 text-sm leading-relaxed text-slate-400">
                        <span class="font-semibold text-emerald-300/90">شحن الرصيد:</span> من زر «شحن رصيد نظام» أعلى الصفحة (خصم من حسابات التحصيل).
                        <span class="mx-1 text-slate-500">|</span>
                        <span class="font-semibold text-slate-200">تعديل يدوي:</span> من مورد «أنظمة الطيران» لكل سجل.
                    </p>
                </div>
            </div>

            @if ($this->systems->isEmpty())
                <p class="text-center text-sm text-slate-400">لا توجد أنظمة مسجّلة.</p>
            @else
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->systems as $system)
                        <div class="safarak-flight-card flex flex-col gap-4 p-5">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <x-filament::badge color="primary" size="sm">
                                        {{ $system->code }}
                                    </x-filament::badge>
                                    <h3 class="mt-2 text-lg font-semibold leading-tight text-white">
                                        {{ $system->name }}
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-400">
                                        العملة: {{ $system->currency }}
                                    </p>
                                </div>
                            </div>

                            <dl class="space-y-2 border-t border-white/10 pt-3 text-sm">
                                <div class="flex justify-between gap-2">
                                    <dt class="text-slate-400">الرصيد</dt>
                                    <dd class="font-mono font-semibold text-white">
                                        {{ number_format((float) $system->balance, 2) }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-slate-400">حد الائتمان</dt>
                                    <dd class="font-mono text-slate-200">
                                        {{ number_format((float) $system->credit_limit, 2) }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-2 border-t border-white/10 pt-2">
                                    <dt class="font-semibold text-primary-300">المتاح</dt>
                                    <dd class="font-mono text-lg font-bold text-primary-400">
                                        {{ number_format((float) $system->available_balance, 2) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
