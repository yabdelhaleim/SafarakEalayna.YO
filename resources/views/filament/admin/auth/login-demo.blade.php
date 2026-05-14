{{-- يظهر فقط على صفحة تسجيل دخول Filament؛ نستخدم :has لتنسيق الصفحة دون المساس بباقي الصفحات البسيطة --}}
<style>
    html:has(.fi-safarak-login-demo) .fi-simple-layout {
        min-height: 100vh;
        background-color: #020810;
        background-image:
            radial-gradient(ellipse 80% 50% at 50% -20%, rgba(56, 189, 248, 0.18), transparent),
            radial-gradient(ellipse 60% 40% at 100% 100%, rgba(212, 168, 67, 0.12), transparent),
            linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
        background-size: auto, auto, 48px 48px, 48px 48px;
    }

    html:has(.fi-safarak-login-demo) .fi-simple-main {
        padding: 1.5rem 1rem 2.5rem;
    }

    html:has(.fi-safarak-login-demo) .fi-simple-page {
        border-radius: 1rem;
        border: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(10, 21, 40, 0.96);
        box-shadow:
            0 0 0 1px rgba(56, 189, 248, 0.06),
            0 25px 50px -12px rgba(0, 0, 0, 0.55);
        backdrop-filter: blur(16px);
        padding: 1.5rem;
    }

    @media (min-width: 640px) {
        html:has(.fi-safarak-login-demo) .fi-simple-page {
            padding: 2rem;
        }
    }

    html:has(.fi-safarak-login-demo) .fi-simple-header-heading {
        color: #eef2ff !important;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    html:has(.fi-safarak-login-demo) .fi-simple-header-subheading {
        color: #8ba4c8 !important;
        line-height: 1.6;
    }

    html:has(.fi-safarak-login-demo) .fi-fo-field-wrp label {
        color: #8ba4c8 !important;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    html:has(.fi-safarak-login-demo) .fi-input-wrp,
    html:has(.fi-safarak-login-demo) [data-field-wrapper] .fi-input-wrp {
        border-radius: 0.75rem;
        border-color: rgba(255, 255, 255, 0.1) !important;
        background-color: #0c1830 !important;
    }

    html:has(.fi-safarak-login-demo) .fi-input-wrp input,
    html:has(.fi-safarak-login-demo) .fi-input-wrp textarea {
        color: #eef2ff !important;
    }

    html:has(.fi-safarak-login-demo) .fi-input-wrp input::placeholder {
        color: rgba(139, 164, 200, 0.75) !important;
    }

    html:has(.fi-safarak-login-demo) .fi-btn.fi-color-primary[type='submit'],
    html:has(.fi-safarak-login-demo) button.fi-btn.fi-color-primary {
        width: 100%;
        justify-content: center;
        border-radius: 0.75rem;
        font-weight: 700;
        background: linear-gradient(to left, #0ea5e9, #06b6d4) !important;
        border: none !important;
        box-shadow: 0 10px 25px -5px rgba(14, 165, 233, 0.35);
    }

    html:has(.fi-safarak-login-demo) .fi-fo-checkbox-wrp label {
        color: #c8d4e8 !important;
    }
</style>

<div class="fi-safarak-login-demo mt-6 rounded-xl border border-amber-400/25 bg-amber-400/[0.08] p-4">
    <div class="mb-3 flex items-center gap-2">
        <svg class="h-4 w-4 shrink-0 text-amber-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="10" />
            <path d="M12 16v-4M12 8h.01" />
        </svg>
        <span class="text-xs font-bold text-[#eef2ff]">بيانات الدخول بعد التهيئة (UserSeeder)</span>
    </div>
    <p class="mb-3 text-[11px] leading-relaxed text-[#8ba4c8]">
        إن لم تعمل، نفّذ من مجلد المشروع:
        <code class="rounded bg-white/10 px-1.5 py-0.5 font-mono text-[10px] text-amber-300">php artisan migrate:fresh --seed</code>
        <span class="block mt-1">(للبيئة التطويرية فقط — يحذف البيانات)</span>
    </p>
    <div class="space-y-2">
        <button
            type="button"
            wire:click="fillAdminDemo"
            class="flex w-full flex-col gap-1 rounded-lg border border-white/10 bg-[#0a1528]/90 px-3 py-2.5 text-start transition hover:border-amber-400/35 hover:bg-white/5 sm:flex-row sm:items-center sm:justify-between"
        >
            <span class="text-xs font-semibold text-[#8ba4c8]">مدير النظام</span>
            <code class="break-all font-mono text-[11px] text-amber-300 sm:text-end">admin@admin.com — 11223311</code>
        </button>
        <button
            type="button"
            wire:click="fillEmployeeDemo"
            class="flex w-full flex-col gap-1 rounded-lg border border-white/10 bg-[#0a1528]/90 px-3 py-2.5 text-start transition hover:border-amber-400/35 hover:bg-white/5 sm:flex-row sm:items-center sm:justify-between"
        >
            <span class="text-xs font-semibold text-[#8ba4c8]">موظف (مثال)</span>
            <code class="break-all font-mono text-[11px] text-amber-300 sm:text-end">employee1@office.com — password</code>
        </button>
    </div>
    <p class="mt-3 text-center text-[11px] text-[#8ba4c8]/90">
        <a href="{{ url('/login') }}" class="font-bold text-sky-400 hover:underline">الانتقال لتسجيل دخول التطبيق (Vue)</a>
    </p>
</div>
