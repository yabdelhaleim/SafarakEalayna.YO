<x-filament-widgets::widget>
    <x-filament::section>
        <div class="space-y-4 text-right" dir="rtl">
            <div>
                <h2 class="text-xl font-bold text-gray-900">لوحة إعدادات النظام</h2>
                <p class="mt-2 text-sm text-gray-600 leading-relaxed">
                    هذه الواجهة مخصّصة للإعدادات والبيانات المرجعية فقط. التشغيل اليومي والتقارير المالية
                    والعمليات تتم من التطبيق الرئيسي.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a
                    href="{{ url('/dashboard') }}"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-primary-700"
                >
                    الانتقال للتطبيق الرئيسي
                </a>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                <p class="font-bold mb-2">من هنا يمكنك إدارة:</p>
                <ul class="list-disc pr-5 space-y-1">
                    <li>الحسابات والخزائن وإعدادات التحويل</li>
                    <li>أنواع الخدمات والبيانات المرجعية (فوري، أونلاين، محافظ...)</li>
                    <li>الشركات والوكلاء والموردين عند الحاجة</li>
                </ul>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
