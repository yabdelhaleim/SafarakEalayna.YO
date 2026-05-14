<x-filament-panels::page>
    <div class="fi-page-content-ctn space-y-8">
        <x-filament::section
            icon="heroicon-o-information-circle"
            icon-color="primary"
            heading="كيف تستخدم هذه الصفحة؟"
            description="خزائن منفصلة لكل عملة موجودة كحسابات في «جميع الحسابات» (مثل: نقدي مصري، نقدي دينار، …). هنا تسجّل شراء عملة من الصرافة: يقل رصيد حساب الجنيه بالمبلغ المدفوع، ويزيد رصيد خزينة العملة الأجنبية بالكمية المستلمة."
        >
            <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                استخدم زر <strong>شراء عملة</strong> أعلاه. إذا كانت عملتا الحسابين متطابقتين، اترك «المبلغ المستلم» فارغاً (يُعتبر مساوياً للمبلغ المخصوم).
                يمكن تنفيذ نفس العملية عبر واجهة الـ API: <code class="rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-gray-800">POST /api/v1/finance/transfers</code>
                مع الحقل <code class="rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-gray-800">converted_amount</code> عند اختلاف العملة.
            </p>
        </x-filament::section>

        <x-filament::section
            icon="heroicon-o-chart-bar"
            icon-color="warning"
            heading="أرصدة التحصيل (سياحة) حسب العملة"
            description="حسابات نشطة من نوع نقدي / خزينة عامة / محفظة / بنك — وحدة العمل: سياحة."
        >
            @if (count($this->summaryByCurrency) === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">لا توجد حسابات مطابقة. أضف حسابات من «جميع الحسابات» أو من صفحة خزينة الطيران.</p>
            @else
                <div class="space-y-6">
                    @foreach ($this->summaryByCurrency as $block)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10 dark:bg-white/5">
                            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-100 pb-2 dark:border-white/10">
                                <span class="text-lg font-semibold text-gray-900 dark:text-white">{{ $block['currency'] }}</span>
                                <span class="tabular-nums text-sm font-medium text-primary-600 dark:text-primary-400">
                                    إجمالي: {{ number_format($block['total'], 2) }}
                                </span>
                            </div>
                            <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/10">
                                @foreach ($block['accounts'] as $row)
                                    <li class="flex flex-wrap justify-between gap-2 py-2 text-sm">
                                        <span class="text-gray-700 dark:text-gray-300">{{ $row['name'] }}</span>
                                        <span class="tabular-nums text-gray-600 dark:text-gray-400">
                                            {{ number_format($row['balance'], 2) }}
                                            <span class="text-xs text-gray-400">({{ $row['type'] }})</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
