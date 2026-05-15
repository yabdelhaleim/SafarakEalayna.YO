<div class="mt-6 rounded-xl border border-primary-500/25 bg-primary-500/5 p-4">
    <div class="mb-3 flex items-center gap-2">
        <x-heroicon-o-information-circle class="h-4 w-4 shrink-0 text-primary-500" />
        <span class="text-xs font-bold text-gray-900 dark:text-white">بيانات الدخول التجريبية</span>
    </div>
    <div class="grid grid-cols-1 gap-2">
        <button
            type="button"
            wire:click="fillAdminDemo"
            class="flex w-full items-center justify-between rounded-lg border border-gray-200 bg-white px-3 py-2 text-start transition-colors hover:border-primary-500 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
        >
            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">مدير النظام</span>
            <code class="font-mono text-[11px] text-primary-600 dark:text-primary-400">admin@admin.com</code>
        </button>
        <button
            type="button"
            wire:click="fillEmployeeDemo"
            class="flex w-full items-center justify-between rounded-lg border border-gray-200 bg-white px-3 py-2 text-start transition-colors hover:border-primary-500 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
        >
            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">موظف</span>
            <code class="font-mono text-[11px] text-primary-600 dark:text-primary-400">employee1@office.com</code>
        </button>
    </div>
    <p class="mt-2 text-[10px] text-gray-500 dark:text-gray-400 text-center italic">
        * كلمة المرور الافتراضية هي <span class="font-bold">11223311</span> للمدير و <span class="font-bold">password</span> للموظف.
    </p>
</div>
