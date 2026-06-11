<template>
  <div class="finance-dashboard flight-booking animate-in pb-10 fade-in duration-700 print:hidden">
    <header class="flight-hero relative overflow-hidden">
      <div class="relative z-10 mx-auto flex max-w-7xl flex-col gap-4 px-4 sm:px-6 lg:flex-row lg:items-end lg:justify-between lg:px-8">
        <div class="min-w-0 flex-1">
          <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-sky-400/90">خزينة الطيران</p>
          <h1 class="mt-1 text-3xl font-black tracking-tight text-text-main sm:text-4xl">
            أرصدة الأنظمة والتحصيل
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-text-muted">
            أنظمة الحجز (GDS/NDC) مع الرصيد وحد الائتمان — يمكنك
            <span class="font-semibold text-emerald-200/90">شحن رصيد النظام</span>
            من محافظك أو حساباتك البنكية أو الخزائن (نفس عملة النظام). كما تظهر حسابات التحصيل وشركات الطيران وآخر
            الحركات المالية لوحدة الطيران.
          </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
          <router-link
            :to="{ name: 'flights.list' }"
            class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-text-muted transition hover:border-gold/40 hover:text-gold"
          >
            <ArrowRight class="h-4 w-4 rotate-180" />
            الحجوزات
          </router-link>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-sky-500/30 bg-sky-500/15 px-4 py-2 text-sm font-bold text-sky-200 transition hover:bg-sky-500/25"
            @click="reload"
          >
            <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': store.loading.treasuryOverview }" />
            تحديث
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl space-y-10 px-4 sm:px-6 lg:px-8 mt-8">
      <div v-if="store.loading.treasuryOverview && !ov" class="rounded-2xl border border-white/10 bg-white/[0.03] py-24 text-center text-text-muted">
      جاري تحميل بيانات الخزينة…
    </div>

    <template v-else-if="ov">

      <!-- ── ملخص السيولة حسب العملة ─────────────────────────────── -->
      <section v-if="ov.liquidity_by_currency?.length" class="space-y-3">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
          <h2 class="text-xl font-bold text-white">ملخص السيولة</h2>
          <div class="flex items-center gap-2 text-xs text-text-muted">
            <span class="inline-flex items-center gap-1 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-emerald-300">
              ● الرصيد الفعلي = المال المشحون فعلاً
            </span>
            <span class="inline-flex items-center gap-1 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-amber-300">
              ● حد الائتمان = تسهيل، ليس نقداً
            </span>
          </div>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-white/10 bg-white/[0.02]">
          <table class="min-w-full text-right text-sm">
            <thead class="border-b border-white/10 bg-white/[0.03] text-[11px] uppercase tracking-wider text-text-muted">
              <tr>
                <th class="px-5 py-3 font-bold">العملة</th>
                <th class="px-5 py-3 font-bold text-violet-300">رصيد الأنظمة</th>
                <th class="px-5 py-3 font-bold text-sky-300">رصيد الناقلين</th>
                <th class="px-5 py-3 font-bold text-emerald-300">الحسابات</th>
                <th class="px-5 py-3 font-bold text-white border-r border-white/10">
                  إجمالي الرصيد الفعلي
                </th>
                <th class="px-5 py-3 font-bold text-amber-300/70">حد الائتمان (أنظمة)</th>
                <th class="px-5 py-3 font-bold text-amber-300/70">حد الائتمان (ناقلين)</th>
                <th class="px-5 py-3 font-bold text-gold">المتاح للخصم</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr
                v-for="row in ov.liquidity_by_currency"
                :key="row.currency"
                class="transition-colors hover:bg-white/[0.03]"
              >
                <td class="px-5 py-3">
                  <span class="inline-flex items-center rounded-lg border border-white/15 bg-white/5 px-2.5 py-1 text-xs font-black text-white">
                    {{ row.currency }}
                  </span>
                </td>
                <td class="px-5 py-3 font-mono font-bold text-violet-300 tabular-nums">
                  {{ fmtNum(row.systems_balance) }}
                </td>
                <td class="px-5 py-3 font-mono font-bold text-sky-300 tabular-nums">
                  {{ fmtNum(row.carriers_balance) }}
                </td>
                <td class="px-5 py-3 font-mono font-bold text-emerald-300 tabular-nums">
                  {{ fmtNum(row.accounts_balance) }}
                </td>
                <td class="px-5 py-3 border-r border-white/10">
                  <span class="font-mono text-base font-black text-white tabular-nums">
                    {{ fmtNum(row.total_actual) }} {{ row.currency }}
                  </span>
                </td>
                <td class="px-5 py-3 font-mono text-amber-400/60 tabular-nums text-xs">
                  {{ fmtNum(row.systems_credit_limit) }}
                </td>
                <td class="px-5 py-3 font-mono text-amber-400/60 tabular-nums text-xs">
                  {{ fmtNum(row.carriers_credit_limit) }}
                </td>
                <td class="px-5 py-3">
                  <span class="font-mono font-black text-gold tabular-nums">
                    {{ fmtNum(row.total_available) }} {{ row.currency }}
                  </span>
                  <p class="text-[10px] text-text-muted mt-0.5">فعلي + ائتمان</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="text-xs text-text-muted">
          ⚠️ العملات المختلفة لا تُجمع مع بعض — كل عملة معروضة على حدة لتجنب الخلط.
          «المتاح للخصم» يشمل حد الائتمان وليس سيولة نقدية حقيقية.
        </p>
      </section>

      <!-- أنظمة الحجز -->
      <section class="space-y-4">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
          <h2 class="text-xl font-bold text-white">أنظمة الحجز</h2>
          <span class="text-xs text-text-muted">يُخصم الرصيد عند الحجز · زِد الرصيد بزر «شحن» من حساباتك</span>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <div
            v-for="sys in ov.systems || []"
            :key="sys.id"
            class="dashboard-kpi group flex flex-col gap-3 border border-violet-500/20 bg-gradient-to-br from-violet-500/10 to-transparent p-5"
          >
            <div class="flex items-start justify-between gap-2">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-violet-300/90">نظام</p>
                <h3 class="text-lg font-black text-white">{{ sys.name }}</h3>
                <p class="text-xs text-text-muted">{{ sys.code }} · {{ sys.currency }}</p>
              </div>
              <div class="flex shrink-0 flex-col gap-1 sm:flex-row sm:items-center">
                <button
                  type="button"
                  class="inline-flex items-center justify-center gap-1 rounded-lg border border-emerald-500/40 bg-emerald-500/15 px-2 py-1 text-[11px] font-bold text-emerald-200 transition hover:bg-emerald-500/25"
                  @click="openRecharge(sys)"
                >
                  <Wallet class="h-3.5 w-3.5" />
                  شحن
                </button>
                <button
                  type="button"
                  class="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[11px] font-bold text-sky-300 transition hover:border-sky-400/50"
                  @click="openSystemTx(sys)"
                >
                  العمليات
                </button>
              </div>
            </div>
            <div class="space-y-2 border-t border-white/10 pt-3 text-sm">
              <div class="flex justify-between gap-2 text-text-muted">
                <span>الرصيد</span>
                <span class="font-mono font-bold text-white tabular-nums">{{ fmt(sys.balance, sys.currency) }}</span>
              </div>
              <div class="flex justify-between gap-2 text-text-muted">
                <span>حد ائتمان</span>
                <span class="font-mono text-white/90 tabular-nums">{{ fmt(sys.credit_limit, sys.currency) }}</span>
              </div>
              <div class="flex justify-between gap-2">
                <span class="font-bold text-violet-200">المتاح</span>
                <span class="font-mono text-lg font-black tabular-nums text-gold">
                  {{ fmt(availableForSystem(sys), sys.currency) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- حسابات التحصيل -->
      <section class="space-y-6">
        <h2 class="text-xl font-bold text-white">الخزائن وحسابات التحصيل</h2>

        <div v-if="cashboxAccounts.length" class="space-y-3">
          <h3 class="text-sm font-bold text-amber-400">الخزائن النقدية</h3>
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="acc in cashboxAccounts" :key="'acc-' + acc.id" :class="settlementCardClass(acc.type)">
              <div class="flex flex-col gap-2 text-sm">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-[10px] font-bold uppercase tracking-wider text-white/60">
                    {{ settlementTypeLabel(acc.type) }}
                  </span>
                  <button type="button" class="rounded-lg border border-white/15 bg-black/20 px-2 py-1 text-[11px] font-bold text-sky-300 transition hover:border-sky-400/50" @click="openAccountTx(acc)">
                    العمليات
                  </button>
                </div>
                <p class="font-bold text-white">{{ acc.name }}</p>
                <p class="font-mono text-lg font-black text-gold tabular-nums">
                  {{ Number(acc.balance ?? 0).toLocaleString('ar-EG') }} {{ acc.currency || 'EGP' }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <div v-if="bankAccounts.length" class="space-y-3">
          <h3 class="text-sm font-bold text-sky-400">حسابات البنوك والبريد</h3>
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="acc in bankAccounts" :key="'acc-' + acc.id" :class="settlementCardClass(acc.type)">
              <div class="flex flex-col gap-2 text-sm">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-[10px] font-bold uppercase tracking-wider text-white/60">
                    {{ settlementTypeLabel(acc.type) }}
                  </span>
                  <button type="button" class="rounded-lg border border-white/15 bg-black/20 px-2 py-1 text-[11px] font-bold text-sky-300 transition hover:border-sky-400/50" @click="openAccountTx(acc)">
                    العمليات
                  </button>
                </div>
                <p class="font-bold text-white">{{ acc.name }}</p>
                <p class="font-mono text-lg font-black text-gold tabular-nums">
                  {{ Number(acc.balance ?? 0).toLocaleString('ar-EG') }} {{ acc.currency || 'EGP' }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <div v-if="walletAccounts.length" class="space-y-3">
          <h3 class="text-sm font-bold text-emerald-400">محافظ تحصيل الطيران</h3>
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="acc in walletAccounts" :key="'acc-' + acc.id" :class="settlementCardClass(acc.type)">
              <div class="flex flex-col gap-2 text-sm">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-[10px] font-bold uppercase tracking-wider text-white/60">
                    {{ settlementTypeLabel(acc.type) }}
                  </span>
                  <button type="button" class="rounded-lg border border-white/15 bg-black/20 px-2 py-1 text-[11px] font-bold text-sky-300 transition hover:border-sky-400/50" @click="openAccountTx(acc)">
                    العمليات
                  </button>
                </div>
                <p class="font-bold text-white">{{ acc.name }} <span v-if="acc.wallet_number" class="text-xs text-text-muted">({{ acc.wallet_number }})</span></p>
                <p class="font-mono text-lg font-black text-gold tabular-nums">
                  {{ Number(acc.balance ?? 0).toLocaleString('ar-EG') }} {{ acc.currency || 'EGP' }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <p v-if="!ov.settlement_accounts?.length" class="text-sm text-warning">
          لا توجد حسابات تحصيل نشطة مسجلة. أضف حسابات من لوحة الإدارة (Filament).
        </p>
      </section>

      <!-- شركات الطيران -->
      <section v-if="ov.carriers?.length" class="space-y-4">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
          <h2 class="text-xl font-bold text-white">أرصدة شركات الطيران</h2>
          <span class="text-xs text-text-muted">الرصيد المتاح = الرصيد + حد الائتمان · اشحن الرصيد بالنقر على «شحن»</span>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <div
            v-for="c in ov.carriers"
            :key="c.id"
            class="dashboard-kpi group flex flex-col gap-3 border border-sky-500/20 bg-gradient-to-br from-sky-500/10 to-transparent p-5"
          >
            <!-- Header: اسم + أزرار -->
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-wider text-sky-300/90">ناقل طيران</p>
                <h3 class="truncate text-base font-black text-white">{{ c.name }}</h3>
                <p class="text-xs text-text-muted">{{ c.code }} · {{ c.currency }}</p>
              </div>
              <div class="flex shrink-0 flex-col gap-1 sm:flex-row sm:items-center">
                <button
                  v-if="c.is_active !== false"
                  type="button"
                  class="inline-flex items-center justify-center gap-1 rounded-lg border border-emerald-500/40 bg-emerald-500/15 px-2 py-1 text-[11px] font-bold text-emerald-200 transition hover:bg-emerald-500/25"
                  @click="openRechargeCarrier(c)"
                >
                  <Wallet class="h-3.5 w-3.5" />
                  شحن
                </button>
              </div>
            </div>
            <!-- تفاصيل الأرصدة -->
            <div class="space-y-2 border-t border-white/10 pt-3 text-sm">
              <div class="flex justify-between gap-2 text-text-muted">
                <span>الرصيد</span>
                <span class="font-mono font-bold text-white tabular-nums">{{ fmt(c.balance, c.currency) }}</span>
              </div>
              <div v-if="Number(c.credit_limit) > 0" class="flex justify-between gap-2 text-text-muted">
                <span>حد ائتمان</span>
                <span class="font-mono text-white/90 tabular-nums">{{ fmt(c.credit_limit, c.currency) }}</span>
              </div>
              <div class="flex justify-between gap-2">
                <span class="font-bold text-sky-200">المتاح</span>
                <span class="font-mono text-lg font-black tabular-nums text-gold">
                  {{ fmt(availableForCarrier(c), c.currency) }}
                </span>
              </div>
              <div v-if="c.system?.name" class="pt-1">
                <span class="inline-flex items-center rounded-md border border-violet-500/20 bg-violet-500/10 px-2 py-0.5 text-[10px] font-bold text-violet-300">
                  {{ c.system.name }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- آخر حركات الطيران -->
      <section class="space-y-3">
        <h2 class="text-xl font-bold text-white">آخر حركات مالية (طيران)</h2>
        <div class="overflow-x-auto rounded-2xl border border-white/10 bg-white/[0.02]">
          <table class="min-w-full text-right text-sm">
            <thead class="border-b border-white/10 text-xs uppercase text-text-muted">
              <tr>
                <th class="px-4 py-3">التاريخ</th>
                <th class="px-4 py-3">المبلغ</th>
                <th class="px-4 py-3">من</th>
                <th class="px-4 py-3">إلى</th>
                <th class="px-4 py-3">ملاحظات</th>
                <th class="px-4 py-3 text-left">الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="tx in ov.recent_flight_transactions || []"
                :key="tx.id"
                class="border-b border-white/5 hover:bg-white/[0.03]"
              >
                <td class="px-4 py-2 font-mono text-xs text-text-muted">{{ formatDt(tx.created_at) }}</td>
                <td class="px-4 py-2 font-mono font-bold text-white tabular-nums">{{ Number(tx.amount).toLocaleString('ar-EG') }}</td>
                <td class="px-4 py-2 text-text-muted">{{ tx.from_account?.name || '—' }}</td>
                <td class="px-4 py-2 text-text-muted">{{ tx.to_account?.name || '—' }}</td>
                <td class="max-w-xs truncate px-4 py-2 text-xs text-text-muted">{{ tx.notes || '—' }}</td>
                <td class="px-4 py-2 text-left">
                  <button type="button" @click="openRecentTx(tx)" class="rounded-lg bg-white/5 p-1.5 text-text-muted hover:bg-white/10 hover:text-white transition-colors" title="عرض التفاصيل">
                    <Eye class="h-4 w-4" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <div v-else class="rounded-2xl border border-error/30 bg-error/10 p-8 text-center text-error">
      تعذر تحميل بيانات الخزينة.
    </div>
    </div> <!-- Closes mx-auto max-w-7xl -->

    <!-- Modal: تفاصيل حركة مالية (طيران) -->
    <Teleport to="body">
      <div
        v-if="modal.type === 'recentTx' && modal.recentTx"
        class="fixed inset-0 z-[120] flex items-center justify-center p-4 sm:p-6 bg-black/80 backdrop-blur-md animate-in fade-in duration-300 print:static print:block print:w-full print:bg-white print:p-0"
        role="dialog"
        aria-modal="true"
        @click.self="closeModal"
      >
        <div class="flex flex-col w-full max-w-lg overflow-hidden rounded-3xl border border-white/10 bg-card shadow-2xl animate-in zoom-in-95 duration-300 print:border-none print:shadow-none print:max-w-none print:rounded-none">
          
          <!-- Header -->
          <div class="flex items-center justify-between border-b border-white/10 bg-white/5 px-6 py-5 shrink-0 print:hidden">
            <div class="flex items-center gap-4">
              <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-500/15 text-sky-400 border border-sky-500/20">
                <Printer class="h-6 w-6" />
              </div>
              <div>
                <p class="text-xs font-bold uppercase tracking-widest text-sky-400">سند مالي</p>
                <h3 class="text-xl font-black text-white mt-1">تفاصيل الحركة</h3>
              </div>
            </div>
            <button type="button" class="rounded-xl bg-white/5 p-2.5 text-text-muted hover:bg-white/10 hover:text-white transition-colors" @click="closeModal">
              <X class="h-5 w-5" />
            </button>
          </div>
          
          <!-- Content -->
          <div class="p-8 overflow-y-auto custom-scrollbar flex-1 print:w-full print:text-black">
            <div class="text-center pb-4">
              <h2 class="text-2xl font-black text-gold print:text-black mb-1">{{ printSettingsStore.settings.company_name_ar || 'سفري علينا للسياحة' }}</h2>
              <p class="text-xs font-bold text-text-muted print:text-black/60">سند معاملة (خزينة الطيران)</p>
            </div>

            <!-- Amount -->
            <div class="text-center py-6 bg-white/[0.02] print:bg-transparent rounded-2xl border border-white/10 print:border-black/20 print:border-2 mb-6">
              <span class="text-[10px] font-black text-text-muted print:text-black/70 uppercase tracking-widest block mb-2">قيمة الحركة</span>
              <p class="text-4xl font-black font-mono" :class="modal.recentTx.type === 'credit' ? 'text-success print:text-green-700' : 'text-error print:text-red-700'">
                {{ Number(modal.recentTx.amount).toLocaleString('ar-EG', { minimumFractionDigits: 2 }) }}
              </p>
            </div>

            <!-- Basic Details -->
            <div class="space-y-3 text-sm bg-white/5 print:bg-transparent p-5 rounded-2xl print:border-b print:border-t print:border-black/20 text-right" dir="rtl">
              <div class="flex justify-between items-center py-2 border-b border-white/5 print:border-black/10">
                <span class="text-text-muted print:text-black/70 text-xs font-black">رقم المرجع (ID)</span>
                <span class="font-mono font-bold">{{ modal.recentTx.id }}</span>
              </div>
              <div class="flex justify-between items-center py-2 border-b border-white/5 print:border-black/10">
                <span class="text-text-muted print:text-black/70 text-xs font-black">التاريخ والوقت</span>
                <span class="font-mono text-xs">{{ formatDt(modal.recentTx.created_at) }}</span>
              </div>
              <div class="flex justify-between items-center py-2 border-b border-white/5 print:border-black/10">
                <span class="text-text-muted print:text-black/70 text-xs font-black">الحساب المحول منه (من)</span>
                <span class="font-bold">{{ modal.recentTx.from_account?.name || '—' }}</span>
              </div>
              <div class="flex justify-between items-center py-2 border-b border-white/5 print:border-black/10">
                <span class="text-text-muted print:text-black/70 text-xs font-black">الحساب المحول إليه (إلى)</span>
                <span class="font-bold">{{ modal.recentTx.to_account?.name || '—' }}</span>
              </div>
            </div>

            <!-- Description -->
            <div class="mt-6 text-right" dir="rtl">
              <span class="text-[10px] font-black text-gold print:text-black block uppercase tracking-widest mb-2">ملاحظات / البيان</span>
              <p class="text-sm font-bold bg-white/[0.02] print:bg-gray-50 border border-white/5 p-4 rounded-xl leading-relaxed whitespace-pre-wrap">{{ modal.recentTx.notes || '—' }}</p>
            </div>

            <!-- Footer for Print -->
            <div class="grid grid-cols-2 gap-4 pt-12 text-center text-xs text-text-muted print:text-black/70 font-bold hidden print:grid">
              <div>
                <p class="mb-4">توقيع المستلم</p>
                <p>___________________</p>
              </div>
              <div>
                <p class="mb-4">توقيع الموظف</p>
                <p>___________________</p>
              </div>
            </div>
            
          </div>
          
          <!-- Print Button (Footer) -->
          <div class="p-6 border-t border-white/10 bg-white/5 shrink-0 print:hidden flex justify-end">
             <button type="button" @click="printReceipt" class="flex items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-6 py-2.5 text-sm font-bold text-white hover:bg-white/20 transition-all">
               <Printer class="w-5 h-5" />
               طباعة السند
             </button>
          </div>

        </div>
      </div>
    </Teleport>

    <!-- Modal: عمليات نظام -->
    <Teleport to="body">
      <div
        v-if="modal.type === 'system' && modal.system"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6 bg-black/80 backdrop-blur-md animate-in fade-in duration-300"
        role="dialog"
        aria-modal="true"
        @click.self="closeModal"
      >
        <div class="flex flex-col max-h-[90vh] w-full max-w-5xl overflow-hidden rounded-3xl border border-white/10 bg-card shadow-2xl animate-in zoom-in-95 duration-300">
          
          <!-- Header -->
          <div class="flex items-center justify-between border-b border-white/10 bg-white/5 px-6 py-5 shrink-0">
            <div class="flex items-center gap-4">
              <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-500/15 text-violet-400 border border-violet-500/20">
                <Monitor class="h-6 w-6" />
              </div>
              <div>
                <p class="text-xs font-bold uppercase tracking-widest text-violet-400">عمليات نظام الحجز</p>
                <h3 class="text-2xl font-black text-white mt-1">{{ modal.system.name }}</h3>
              </div>
            </div>
            <button type="button" class="rounded-xl bg-white/5 p-2.5 text-text-muted hover:bg-white/10 hover:text-white transition-colors" @click="closeModal">
              <X class="h-5 w-5" />
            </button>
          </div>
          
          <!-- Content -->
          <div class="p-6 overflow-y-auto custom-scrollbar flex-1">
            <div class="overflow-x-auto rounded-2xl border border-white/10 bg-white/[0.02]">
              <table class="min-w-full text-right text-sm whitespace-nowrap">
                <thead class="border-b border-white/10 bg-white/[0.02]">
                  <tr>
                    <th class="px-6 py-4 font-black text-white">التاريخ</th>
                    <th class="px-6 py-4 font-black text-white">النوع</th>
                    <th class="px-6 py-4 font-black text-white">المبلغ</th>
                    <th class="px-6 py-4 font-black text-white">الرصيد بعد</th>
                    <th class="px-6 py-4 font-black text-white">مرجع الحجز</th>
                    <th class="px-6 py-4 font-black text-white">وصف العمليّة</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                  <tr v-for="row in systemTxRows" :key="row.id" class="transition-colors hover:bg-white/[0.04]">
                    <td class="px-6 py-4">
                      <div class="flex flex-col">
                        <span class="font-mono font-bold text-white">{{ formatDt(row.created_at).split(',')[0] }}</span>
                        <span class="text-[10px] text-text-muted">{{ formatDt(row.created_at).split(',')[1] }}</span>
                      </div>
                    </td>
                    <td class="px-6 py-4">
                      <span class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-black border"
                            :class="row.type === 'credit' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20'">
                        {{ row.type === 'credit' ? 'شحن / إضافة' : 'خصم / حجز' }}
                      </span>
                    </td>
                    <td class="px-6 py-4">
                      <span class="font-mono font-black" :class="row.type === 'credit' ? 'text-emerald-400' : 'text-red-400'">
                        {{ row.type === 'credit' ? '+' : '-' }}{{ Number(row.amount).toLocaleString('ar-EG', { minimumFractionDigits: 2 }) }}
                      </span>
                    </td>
                    <td class="px-6 py-4">
                      <span class="font-mono font-bold text-gold">{{ Number(row.balance_after).toLocaleString('ar-EG', { minimumFractionDigits: 2 }) }}</span>
                    </td>
                    <td class="px-6 py-4">
                      <span v-if="row.flight_booking?.booking_number || row.flight_booking_id" class="font-mono text-sky-400 bg-sky-400/10 px-2 py-1 rounded-lg border border-sky-400/20 text-xs font-bold">
                        {{ row.flight_booking?.booking_number || row.flight_booking_id }}
                      </span>
                      <span v-else class="text-text-muted">—</span>
                    </td>
                    <td class="px-6 py-4 text-xs text-text-muted max-w-[250px] truncate" :title="row.description">
                      {{ row.description || '—' }}
                    </td>
                  </tr>
                </tbody>
              </table>
              
              <div v-if="!systemTxLoading && !systemTxRows.length" class="flex flex-col items-center justify-center py-16 text-text-muted">
                <FileX class="h-12 w-12 mb-4 opacity-40" />
                <p class="text-lg font-bold">لا توجد عمليات</p>
                <p class="text-xs mt-1">لم يتم تسجيل أي عمليات شحن أو خصم لهذا النظام.</p>
              </div>
              <div v-if="systemTxLoading" class="flex flex-col items-center justify-center py-16 text-violet-400">
                <Loader2 class="h-10 w-10 animate-spin mb-4" />
                <p class="font-bold">جاري تحميل البيانات...</p>
              </div>
            </div>
          </div>
          
          <!-- Footer Pagination -->
          <div v-if="systemTxMeta && systemTxMeta.last_page > 1" class="flex items-center justify-between border-t border-white/10 bg-white/5 px-6 py-4 shrink-0">
            <span class="text-sm font-bold text-text-muted">
              الصفحة <span class="text-white">{{ systemTxMeta.current_page }}</span> من <span class="text-white">{{ systemTxMeta.last_page }}</span>
            </span>
            <div class="flex gap-2">
              <button
                type="button"
                class="flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white transition hover:bg-white/10 disabled:opacity-40"
                :disabled="systemTxMeta.current_page <= 1"
                @click="loadSystemPage(systemTxMeta.current_page - 1)"
              >
                <ArrowRight class="h-4 w-4" />
                السابق
              </button>
              <button
                type="button"
                class="flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white transition hover:bg-white/10 disabled:opacity-40"
                :disabled="systemTxMeta.current_page >= systemTxMeta.last_page"
                @click="loadSystemPage(systemTxMeta.current_page + 1)"
              >
                التالي
                <ArrowRight class="h-4 w-4 rotate-180" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Modal: شحن رصيد نظام -->
    <Teleport to="body">
      <div
        v-if="modal.type === 'recharge' && modal.system"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        @click.self="closeModal"
      >
        <div class="w-full max-w-md overflow-hidden rounded-2xl border border-white/15 bg-[#0b1220] shadow-2xl">
          <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
            <div>
              <p class="text-xs text-text-muted">إعادة شحن نظام الحجز</p>
              <h3 class="text-lg font-bold text-white">{{ modal.system.name }}</h3>
              <p class="text-xs text-text-muted">{{ modal.system.code }} · {{ modal.system.currency }}</p>
            </div>
            <button type="button" class="rounded-lg p-2 text-text-muted hover:bg-white/10" @click="closeModal">✕</button>
          </div>
          <form class="space-y-4 p-5" @submit.prevent="submitRecharge">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-text-muted">من حساب (محفظة / بنك / خزينة)</label>
              <select
                v-model="rechargeForm.from_account_id"
                required
                class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500/50"
              >
                <option value="">— اختر الحساب —</option>
                <option v-for="acc in rechargeAccounts" :key="'r-' + acc.id" :value="acc.id">
                  {{ formatRechargeSourceAccount(acc) }}
                </option>
              </select>
              <p v-if="!rechargeAccounts.length" class="mt-1 text-xs text-warning">
                لا يوجد حساب تحصيل بنفس عملة هذا النظام ({{ modal.system.currency }}). أضف حساباً أو غيّر العملة.
              </p>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-text-muted">المبلغ</label>
              <input
                v-model="rechargeForm.amount"
                type="number"
                min="0.01"
                step="0.01"
                required
                class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500/50"
                placeholder="0.00"
              />
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-text-muted">ملاحظات (اختياري)</label>
              <input
                v-model="rechargeForm.notes"
                type="text"
                maxlength="500"
                class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500/50"
                placeholder="مثال: تحويل من المحفظة الرئيسية"
              />
            </div>
            <p v-if="rechargeError" class="rounded-lg border border-error/40 bg-error/10 px-3 py-2 text-xs text-error">
              {{ rechargeError }}
            </p>
            <div class="flex gap-2 pt-1">
              <button
                type="button"
                class="flex-1 rounded-xl border border-white/10 py-2.5 text-sm font-bold text-text-muted transition hover:bg-white/5"
                :disabled="rechargeSubmitting"
                @click="closeModal"
              >
                إلغاء
              </button>
              <button
                type="submit"
                class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl border border-emerald-500/50 bg-emerald-600/90 py-2.5 text-sm font-black text-white shadow-lg shadow-emerald-900/30 transition hover:bg-emerald-500 disabled:opacity-50"
                :disabled="rechargeSubmitting || !rechargeAccounts.length"
              >
                <Loader2 v-if="rechargeSubmitting" class="h-4 w-4 animate-spin" />
                تنفيذ الشحن
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>

    <!-- Modal: شحن رصيد ناقل طيران -->
    <Teleport to="body">
      <div
        v-if="modal.type === 'rechargeCarrier' && modal.carrier"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm animate-in fade-in duration-300"
        role="dialog"
        aria-modal="true"
        @click.self="closeModal"
      >
        <div class="w-full max-w-md overflow-hidden rounded-2xl border border-sky-500/20 bg-[#0b1220] shadow-2xl animate-in zoom-in-95 duration-300">
          <!-- Header -->
          <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
            <div class="flex items-center gap-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/15 text-sky-400 border border-sky-500/20">
                <Wallet class="h-5 w-5" />
              </div>
              <div>
                <p class="text-xs text-text-muted">شحن رصيد ناقل طيران</p>
                <h3 class="text-lg font-bold text-white">{{ modal.carrier.name }}</h3>
                <p class="text-xs text-text-muted">
                  {{ modal.carrier.code }} · {{ modal.carrier.currency }}
                  · الرصيد الحالي: <span class="font-bold text-gold">{{ fmt(modal.carrier.balance, modal.carrier.currency) }}</span>
                </p>
              </div>
            </div>
            <button type="button" class="rounded-lg p-2 text-text-muted hover:bg-white/10 transition-colors" @click="closeModal">
              <X class="h-4 w-4" />
            </button>
          </div>
          <!-- Form -->
          <form class="space-y-4 p-5" @submit.prevent="submitRechargeCarrier">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-text-muted">من حساب (محفظة / بنك / خزينة)</label>
              <select
                v-model="carrierRechargeForm.from_account_id"
                required
                class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-sky-500/50 transition-colors"
              >
                <option value="">— اختر الحساب —</option>
                <option v-for="acc in carrierRechargeAccounts" :key="'cr-' + acc.id" :value="acc.id">
                  {{ formatRechargeSourceAccount(acc) }}
                </option>
              </select>
              <p v-if="!carrierRechargeAccounts.length" class="mt-1.5 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs text-warning">
                ⚠️ لا يوجد حساب تحصيل بعملة {{ modal.carrier.currency }}. أضف حساباً من لوحة الإدارة.
              </p>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-text-muted">المبلغ</label>
              <div class="relative">
                <input
                  v-model="carrierRechargeForm.amount"
                  type="number"
                  min="0.01"
                  step="0.01"
                  required
                  class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 pl-14 text-sm text-white outline-none focus:border-sky-500/50 transition-colors"
                  placeholder="0.00"
                />
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 rounded-md bg-sky-500/20 px-1.5 py-0.5 text-[10px] font-black text-sky-300">{{ modal.carrier.currency }}</span>
              </div>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-text-muted">ملاحظات (اختياري)</label>
              <input
                v-model="carrierRechargeForm.notes"
                type="text"
                maxlength="500"
                class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-sky-500/50 transition-colors"
                placeholder="مثال: شحن يدوي — رصيد مسبق الدفع"
              />
            </div>
            <p v-if="carrierRechargeError" class="rounded-lg border border-error/40 bg-error/10 px-3 py-2 text-xs text-error">
              {{ carrierRechargeError }}
            </p>
            <div class="flex gap-2 pt-1">
              <button
                type="button"
                class="flex-1 rounded-xl border border-white/10 py-2.5 text-sm font-bold text-text-muted transition hover:bg-white/5"
                :disabled="carrierRechargeSubmitting"
                @click="closeModal"
              >
                إلغاء
              </button>
              <button
                type="submit"
                class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl border border-sky-500/50 bg-sky-600/90 py-2.5 text-sm font-black text-white shadow-lg shadow-sky-900/30 transition hover:bg-sky-500 disabled:opacity-50"
                :disabled="carrierRechargeSubmitting || !carrierRechargeAccounts.length"
              >
                <Loader2 v-if="carrierRechargeSubmitting" class="h-4 w-4 animate-spin" />
                تنفيذ الشحن
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>

    <!-- Modal: حركات حساب (طيران) -->
    <Teleport to="body">
      <div
        v-if="modal.type === 'account' && modal.account"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6 bg-black/80 backdrop-blur-md animate-in fade-in duration-300"
        role="dialog"
        aria-modal="true"
        @click.self="closeModal"
      >
        <div class="flex flex-col max-h-[90vh] w-full max-w-5xl overflow-hidden rounded-3xl border border-white/10 bg-card shadow-2xl animate-in zoom-in-95 duration-300">
          
          <!-- Header -->
          <div class="flex items-center justify-between border-b border-white/10 bg-white/5 px-6 py-5 shrink-0">
            <div class="flex items-center gap-4">
              <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-500/15 text-sky-400 border border-sky-500/20">
                <Wallet class="h-6 w-6" />
              </div>
              <div>
                <p class="text-xs font-bold uppercase tracking-widest text-sky-400">حركات الطيران على الحساب</p>
                <h3 class="text-2xl font-black text-white mt-1">{{ modal.account.name }}</h3>
              </div>
            </div>
            <button type="button" class="rounded-xl bg-white/5 p-2.5 text-text-muted hover:bg-white/10 hover:text-white transition-colors" @click="closeModal">
              <X class="h-5 w-5" />
            </button>
          </div>
          
          <!-- Content -->
          <div class="p-6 overflow-y-auto custom-scrollbar flex-1">
            <div class="overflow-x-auto rounded-2xl border border-white/10 bg-white/[0.02]">
              <table class="min-w-full text-right text-sm whitespace-nowrap">
                <thead class="border-b border-white/10 bg-white/[0.02]">
                  <tr>
                    <th class="px-6 py-4 font-black text-white">التاريخ</th>
                    <th class="px-6 py-4 font-black text-white">المبلغ</th>
                    <th class="px-6 py-4 font-black text-white">من</th>
                    <th class="px-6 py-4 font-black text-white">إلى</th>
                    <th class="px-6 py-4 font-black text-white">البيان / ملاحظات</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                  <tr v-for="tx in accountTxRows" :key="tx.id" class="transition-colors hover:bg-white/[0.04]">
                    <td class="px-6 py-4">
                      <div class="flex flex-col">
                        <span class="font-mono font-bold text-white">{{ formatDt(tx.created_at).split(',')[0] }}</span>
                        <span class="text-[10px] text-text-muted">{{ formatDt(tx.created_at).split(',')[1] }}</span>
                      </div>
                    </td>
                    <td class="px-6 py-4">
                      <span class="inline-flex items-center gap-1 rounded-lg bg-emerald-500/10 px-3 py-1.5 font-mono text-emerald-400 font-bold border border-emerald-500/20">
                        {{ Number(tx.amount).toLocaleString('ar-EG', { minimumFractionDigits: 2 }) }}
                      </span>
                    </td>
                    <td class="px-6 py-4 font-bold text-white/90">{{ tx.from_account?.name || '—' }}</td>
                    <td class="px-6 py-4 font-bold text-white/90">{{ tx.to_account?.name || '—' }}</td>
                    <td class="px-6 py-4 text-xs text-text-muted leading-relaxed max-w-[300px] truncate" :title="tx.notes">
                      {{ tx.notes || '—' }}
                    </td>
                  </tr>
                </tbody>
              </table>
              
              <div v-if="!accountTxLoading && !accountTxRows.length" class="flex flex-col items-center justify-center py-16 text-text-muted">
                <FileX class="h-12 w-12 mb-4 opacity-40" />
                <p class="text-lg font-bold">لا توجد حركات مالية</p>
                <p class="text-xs mt-1">لم يتم تسجيل أي حركات طيران لهذا الحساب.</p>
              </div>
              <div v-if="accountTxLoading" class="flex flex-col items-center justify-center py-16 text-sky-400">
                <Loader2 class="h-10 w-10 animate-spin mb-4" />
                <p class="font-bold">جاري تحميل البيانات...</p>
              </div>
            </div>
          </div>
          
          <!-- Footer Pagination -->
          <div v-if="accountTxMeta && accountTxMeta.last_page > 1" class="flex items-center justify-between border-t border-white/10 bg-white/5 px-6 py-4 shrink-0">
            <span class="text-sm font-bold text-text-muted">
              الصفحة <span class="text-white">{{ accountTxMeta.current_page }}</span> من <span class="text-white">{{ accountTxMeta.last_page }}</span>
            </span>
            <div class="flex gap-2">
              <button
                type="button"
                class="flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white transition hover:bg-white/10 disabled:opacity-40"
                :disabled="accountTxMeta.current_page <= 1"
                @click="loadAccountPage(accountTxMeta.current_page - 1)"
              >
                <ArrowRight class="h-4 w-4" />
                السابق
              </button>
              <button
                type="button"
                class="flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white transition hover:bg-white/10 disabled:opacity-40"
                :disabled="accountTxMeta.current_page >= accountTxMeta.last_page"
                @click="loadAccountPage(accountTxMeta.current_page + 1)"
              >
                التالي
                <ArrowRight class="h-4 w-4 rotate-180" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useFlightStore } from '@/stores/flightStore';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';
import { ArrowRight, Loader2, RefreshCw, Wallet, X, FileX, Monitor, Eye, Printer } from 'lucide-vue-next';

const store = useFlightStore();
const printSettingsStore = usePrintSettingsStore();

const ov = computed(() => store.treasuryOverview);

const settlementTypeLabel = (type) => {
  const t = String(type || '');
  if (t === 'cashbox') return 'نقدي / درج';
  if (t === 'bank') return 'بنك';
  if (t === 'wallet') return 'محفظة';
  if (t === 'treasury') return 'خزينة عامة';
  if (t === 'post') return 'بريد';
  return t || 'حساب';
};

const settlementCardClass = (type) => {
  const t = String(type || '');
  const base = 'rounded-2xl border p-4';
  if (t === 'cashbox') return `${base} border-amber-500/25 bg-amber-500/10`;
  if (t === 'bank') return `${base} border-sky-500/25 bg-sky-500/10`;
  if (t === 'wallet') return `${base} border-emerald-500/25 bg-emerald-500/10`;
  if (t === 'treasury') return `${base} border-white/15 bg-white/[0.04]`;
  if (t === 'post') return `${base} border-sky-500/25 bg-sky-500/10`;
  return `${base} border-white/10 bg-white/[0.03]`;
};

const fmt = (n, cur) => `${Number(n ?? 0).toLocaleString('ar-EG')} ${cur || ''}`.trim();

// تنسيق رقم بدون عملة (للجداول)
const fmtNum = (n) => Number(n ?? 0).toLocaleString('ar-EG', { maximumFractionDigits: 2 });

const cashboxAccounts = computed(() => (ov.value?.settlement_accounts || []).filter(a => a.type === 'cashbox' || a.type === 'treasury'));
const bankAccounts = computed(() => (ov.value?.settlement_accounts || []).filter(a => a.type === 'bank' || a.type === 'post'));
const walletAccounts = computed(() => (ov.value?.settlement_accounts || []).filter(a => a.type === 'wallet'));

const availableForSystem = (sys) => {
  if (sys.available_balance != null && sys.available_balance !== '') {
    return Number(sys.available_balance);
  }
  return Number(sys.balance ?? 0) + Number(sys.credit_limit ?? 0);
};

const availableForCarrier = (c) => {
  if (c.available_balance != null && c.available_balance !== '') {
    return Number(c.available_balance);
  }
  return Number(c.balance ?? 0) + Number(c.credit_limit ?? 0);
};

const formatDt = (iso) => {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' });
  } catch {
    return iso;
  }
};

const reload = () => store.fetchFlightTreasuryOverview();

onMounted(() => {
  reload();
  printSettingsStore.fetch().catch(() => {});
});

/** @type {{ type: 'idle' | 'system' | 'account' | 'recharge', system?: object, account?: object }} */
const modal = ref({ type: 'idle' });

const rechargeForm = ref({
  from_account_id: '',
  amount: '',
  notes: '',
});
const rechargeSubmitting = ref(false);
const rechargeError = ref('');

const WALLET_PROVIDER_AR = {
  vodafone_cash: 'فودافون كاش',
  instapay: 'إنستاباي',
  cash_wallet: 'محفظة كاش',
  etisalat_cash: 'اتصالات كاش',
  orange_cash: 'أورانج كاش',
  we_pay: 'WE Pay',
  paymob: 'Paymob',
  postal: 'بريد',
  other: 'أخرى',
};

const normType = (t) => String(t?.value ?? t ?? '').toLowerCase();

const formatRechargeSourceAccount = (acc) => {
  const bal = Number(acc.balance ?? 0).toLocaleString('ar-EG');
  const cur = acc.currency || 'EGP';
  if (normType(acc.type) === 'wallet') {
    const p = String(acc.wallet_provider ?? '').toLowerCase();
    const pl = WALLET_PROVIDER_AR[p] || p || 'محفظة';
    const num = String(acc.wallet_number ?? '').trim();
    const mid = num ? `${pl} — ${num}` : pl;
    return `${acc.name} — ${mid} — ${bal} ${cur}`;
  }
  return `${acc.name} — ${settlementTypeLabel(acc.type)} — ${bal} ${cur}`;
};

const rechargeAccounts = computed(() => {
  const sys = modal.value.system;
  const list = ov.value?.settlement_accounts;
  if (modal.value.type !== 'recharge' || !sys || !Array.isArray(list)) return [];
  const cur = String(sys.currency || 'EGP').toUpperCase();
  return list.filter((a) => String(a.currency || 'EGP').toUpperCase() === cur);
});

const systemTxRows = ref([]);
const systemTxMeta = ref(null);
const systemTxLoading = ref(false);

const accountTxRows = ref([]);
const accountTxMeta = ref(null);
const accountTxLoading = ref(false);

const closeModal = () => {
  modal.value = { type: 'idle' };
  systemTxRows.value = [];
  systemTxMeta.value = null;
  accountTxRows.value = [];
  accountTxMeta.value = null;
  rechargeForm.value = { from_account_id: '', amount: '', notes: '' };
  rechargeError.value = '';
};

const openRecharge = (sys) => {
  rechargeError.value = '';
  rechargeForm.value = { from_account_id: '', amount: '', notes: '' };
  modal.value = { type: 'recharge', system: sys };
};

// ---- شحن ناقل طيران ----
const carrierRechargeForm = ref({ from_account_id: '', amount: '', notes: '' });
const carrierRechargeSubmitting = ref(false);
const carrierRechargeError = ref('');

const carrierRechargeAccounts = computed(() => {
  const carrier = modal.value.carrier;
  const list = ov.value?.settlement_accounts;
  if (modal.value.type !== 'rechargeCarrier' || !carrier || !Array.isArray(list)) return [];
  const cur = String(carrier.currency || 'EGP').toUpperCase();
  return list.filter((a) => String(a.currency || 'EGP').toUpperCase() === cur);
});

const openRechargeCarrier = (carrier) => {
  carrierRechargeError.value = '';
  carrierRechargeForm.value = { from_account_id: '', amount: '', notes: '' };
  modal.value = { type: 'rechargeCarrier', carrier };
};

const submitRechargeCarrier = async () => {
  carrierRechargeError.value = '';
  const carrier = modal.value.carrier;
  if (!carrier || modal.value.type !== 'rechargeCarrier') return;
  const aid = carrierRechargeForm.value.from_account_id;
  const amt = Number(carrierRechargeForm.value.amount);
  if (!aid) {
    carrierRechargeError.value = 'اختر حساب المصدر.';
    return;
  }
  if (!Number.isFinite(amt) || amt <= 0) {
    carrierRechargeError.value = 'أدخل مبلغاً أكبر من صفر.';
    return;
  }
  carrierRechargeSubmitting.value = true;
  try {
    await store.rechargeCarrier(carrier.id, {
      from_account_id: parseInt(String(aid), 10),
      amount: amt,
      notes: carrierRechargeForm.value.notes?.trim() || null,
    });
    await store.fetchFlightTreasuryOverview();
    closeModal();
  } catch (e) {
    carrierRechargeError.value = e.response?.data?.message || e.message || 'فشل تنفيذ الشحن';
  } finally {
    carrierRechargeSubmitting.value = false;
  }
};

const submitRecharge = async () => {
  rechargeError.value = '';
  const sys = modal.value.system;
  if (!sys || modal.value.type !== 'recharge') return;
  const aid = rechargeForm.value.from_account_id;
  const amt = Number(rechargeForm.value.amount);
  if (!aid) {
    rechargeError.value = 'اختر حساب المصدر.';
    return;
  }
  if (!Number.isFinite(amt) || amt <= 0) {
    rechargeError.value = 'أدخل مبلغاً أكبر من صفر.';
    return;
  }
  rechargeSubmitting.value = true;
  try {
    await store.rechargeFlightSystem(sys.id, {
      from_account_id: parseInt(String(aid), 10),
      amount: amt,
      notes: rechargeForm.value.notes?.trim() || null,
    });
    await store.fetchFlightTreasuryOverview();
    closeModal();
  } catch (e) {
    rechargeError.value = e.response?.data?.message || e.message || 'فشل تنفيذ الشحن';
  } finally {
    rechargeSubmitting.value = false;
  }
};

const openSystemTx = async (sys) => {
  modal.value = { type: 'system', system: sys };
  await loadSystemPage(1);
};

const loadSystemPage = async (page) => {
  if (!modal.value.system) return;
  systemTxLoading.value = true;
  try {
    const data = await store.fetchFlightSystemTransactions(modal.value.system.id, { page, per_page: 25 });
    systemTxRows.value = data?.data || [];
    systemTxMeta.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
    };
  } catch {
    systemTxRows.value = [];
    systemTxMeta.value = null;
  } finally {
    systemTxLoading.value = false;
  }
};

const openAccountTx = (account) => {
  modal.value = { type: 'account', account };
  loadAccountPage(1);
};

const openRecentTx = (tx) => {
  modal.value = { type: 'recentTx', recentTx: tx };
};

const printReceipt = () => {
  window.print();
};

const loadAccountPage = async (page) => {
  if (!modal.value.account) return;
  accountTxLoading.value = true;
  try {
    const data = await store.fetchAccountFlightTransactions(modal.value.account.id, { page, per_page: 25 });
    accountTxRows.value = data?.data || [];
    accountTxMeta.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
    };
  } catch {
    accountTxRows.value = [];
    accountTxMeta.value = null;
  } finally {
    accountTxLoading.value = false;
  }
};
</script>
