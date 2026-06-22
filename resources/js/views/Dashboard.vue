<template>
  <div class="space-y-8 pb-12">
    <div v-if="isError()" class="rounded-2xl border border-red-500/30 bg-red-500/10 p-6 text-center">
      <p class="text-red-400 font-bold">تعذر تحميل بيانات لوحة القيادة.</p>
      <p v-if="error" class="mt-1 text-sm text-red-300/70">{{ error }}</p>
      <button
        type="button"
        @click="refreshData"
        class="mt-4 rounded-xl bg-red-500 px-6 py-2 text-white transition hover:bg-red-400"
      >
        إعادة المحاولة
      </button>
    </div>

    <!-- Premium App Header -->
    <header class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-slate-900 via-purple-950 to-slate-900 p-8 shadow-2xl border border-white/10">
      <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-amber-500/10 via-transparent to-transparent"></div>
      <div class="relative z-10 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
        <div>
          <div class="flex flex-wrap items-center gap-2 mb-3">
            <div class="inline-flex items-center gap-2 rounded-full bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-400 border border-amber-500/20">
              <span class="flex h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
              لوحة القيادة الذكية الموحدة
            </div>

            <!-- Capital matching status indicator -->
            <button
              v-if="consolidatedTrialBalance"
              type="button"
              @click="activeTab = 'treasury'; scrollToTrialBalance()"
              :class="[
                'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-black border transition-all cursor-pointer hover:scale-105',
                consolidatedTrialBalance.status === 'متساوية' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20 hover:bg-emerald-500/20' :
                consolidatedTrialBalance.status === 'يوجد زيادة' ? 'bg-sky-500/10 text-sky-400 border-sky-500/20 hover:bg-sky-500/20' :
                'bg-rose-500/10 text-rose-400 border-rose-500/20 hover:bg-rose-500/20'
              ]"
            >
              <span :class="[
                'h-2.5 w-2.5 rounded-full',
                consolidatedTrialBalance.status === 'متساوية' ? 'bg-emerald-400 animate-ping' :
                consolidatedTrialBalance.status === 'يوجد زيادة' ? 'bg-sky-400 animate-ping' : 'bg-rose-400 animate-ping'
              ]"></span>
              ميزان رأس المال الموحد: {{ consolidatedTrialBalance.status }}
            </button>
          </div>
          <h1 class="text-xl font-black tracking-tight text-white sm:text-3xl lg:text-4xl">
            نظام المراقبة والتحكم المالي
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-gray-300">
            متابعة حية وشاملة لقطاعي <span class="text-amber-400 font-bold">السياحة</span> و<span class="text-sky-400 font-bold">المكتب</span>، مع تحليل السيولة النقدية وحركة الخزائن من واقع السجلات المحاسبية الفعلية.
          </p>
        </div>
        <div class="flex flex-col sm:flex-row flex-wrap items-center gap-3 w-full sm:w-auto">
          <button
            type="button"
            @click="refreshData"
            :disabled="isRefreshing"
            class="flex items-center justify-center gap-2 w-full sm:w-auto rounded-xl bg-white/10 hover:bg-white/20 border border-white/10 px-4 py-2.5 text-sm font-semibold text-white backdrop-blur-md transition-all disabled:opacity-50"
          >
            <RefreshCw :class="['h-4 w-4', isRefreshing && 'animate-spin']" />
            تحديث البيانات الحية
          </button>
          <button
            type="button"
            @click="exportReport"
            class="flex items-center justify-center gap-2 w-full sm:w-auto rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 px-4 py-2.5 text-sm font-bold text-slate-950 shadow-xl transition-all"
          >
            <Download class="h-4 w-4" />
            تصدير التقرير المالي
          </button>
        </div>
      </div>

      <!-- Main Pillars / Tabs Selection -->
      <div class="relative z-10 mt-8 pt-6 border-t border-white/10 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <button
          type="button"
          @click="activeTab = 'tourism'"
          :class="[
            'flex items-center justify-between p-4 rounded-2xl border transition-all text-right group',
            activeTab === 'tourism'
              ? 'bg-gradient-to-l from-amber-500/20 to-transparent border-amber-500/50 shadow-lg shadow-amber-500/5'
              : 'bg-white/5 border-white/5 hover:border-white/10 hover:bg-white/10'
          ]"
        >
          <div>
            <div class="text-xs font-bold text-gray-400 group-hover:text-gray-300">القطاع الأول</div>
            <div class="text-lg font-black text-white mt-0.5 flex items-center gap-2">
              <span class="text-amber-400">✈️</span>
              حسابات السياحة
            </div>
          </div>
          <div class="text-left">
            <div class="text-xs text-gray-400">إجمالي الأرباح</div>
            <div class="text-sm font-black text-amber-400 font-mono">{{ formatCurrency(tourismSummary.total_profit) }}</div>
          </div>
        </button>

        <button
          type="button"
          @click="activeTab = 'office'"
          :class="[
            'flex items-center justify-between p-4 rounded-2xl border transition-all text-right group',
            activeTab === 'office'
              ? 'bg-gradient-to-l from-sky-500/20 to-transparent border-sky-500/50 shadow-lg shadow-sky-500/5'
              : 'bg-white/5 border-white/5 hover:border-white/10 hover:bg-white/10'
          ]"
        >
          <div>
            <div class="text-xs font-bold text-gray-400 group-hover:text-gray-300">القطاع الثاني</div>
            <div class="text-lg font-black text-white mt-0.5 flex items-center gap-2">
              <span class="text-sky-400">🏢</span>
              حسابات المكتب
            </div>
          </div>
          <div class="text-left">
            <div class="text-xs text-gray-400">إجمالي الأرباح</div>
            <div class="text-sm font-black text-sky-400 font-mono">{{ formatCurrency(officeSummary.total_profit) }}</div>
          </div>
        </button>

        <button
          type="button"
          @click="activeTab = 'treasury'"
          :class="[
            'flex items-center justify-between p-4 rounded-2xl border transition-all text-right group',
            activeTab === 'treasury'
              ? 'bg-gradient-to-l from-emerald-500/20 to-transparent border-emerald-500/50 shadow-lg shadow-emerald-500/5'
              : 'bg-white/5 border-white/5 hover:border-white/10 hover:bg-white/10'
          ]"
        >
          <div>
            <div class="text-xs font-bold text-gray-400 group-hover:text-gray-300">المركز المالي</div>
            <div class="text-lg font-black text-white mt-0.5 flex items-center gap-2">
              <span class="text-emerald-400">💰</span>
              الخزائن والسيولة
            </div>
          </div>
          <div class="text-left">
            <div class="text-xs text-gray-400">إجمالي الأرصدة</div>
            <div class="text-sm font-black text-emerald-400 font-mono">{{ formatCompactNumber(treasurySummary.total) }} EGP</div>
          </div>
        </button>
      </div>
    </header>

    <!-- Global Interactive Filter Bar -->
    <div class="bg-card-bg border border-white/10 rounded-2xl p-5 shadow-xl">
      <div class="flex items-center justify-between mb-4">
        <div class="text-sm font-bold text-white flex items-center gap-2">
          <span class="w-1.5 h-4 bg-amber-500 rounded-full"></span>
          نطاق التحليل الزمني والفلاتر
        </div>
        <span class="text-xs text-gray-400">تُطبق التواريخ على جميع القطاعات المحاسبية بالتزامن</span>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        <div>
          <label class="block text-xs font-medium text-gray-300 mb-1.5">من تاريخ</label>
          <input
            v-model="filters.date_from"
            type="date"
            class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:border-amber-500 text-white text-sm"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-300 mb-1.5">إلى تاريخ</label>
          <input
            v-model="filters.date_to"
            type="date"
            class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:border-amber-500 text-white text-sm"
          />
        </div>

        <!-- Carrier filter only useful in flights sub-view -->
        <div v-if="activeTab === 'tourism'">
          <label class="block text-xs font-medium text-gray-300 mb-1.5">شركة الطيران (للطيران فقط)</label>
          <select
            v-model="filters.carrier_id"
            class="w-full px-3 py-2 bg-slate-900 border border-white/10 rounded-xl focus:outline-none focus:border-amber-500 text-white text-sm"
          >
            <option value="" class="bg-slate-950 text-white font-bold">جميع الشركات</option>
            <option v-for="carrier in carriers" :key="carrier.id" :value="carrier.id" class="bg-slate-950 text-white font-bold">
              {{ carrier.name }}
            </option>
          </select>
        </div>

        <div v-if="activeTab === 'tourism'">
          <label class="block text-xs font-medium text-gray-300 mb-1.5">نظام الحجز</label>
          <select
            v-model="filters.system_type"
            class="w-full px-3 py-2 bg-slate-900 border border-white/10 rounded-xl focus:outline-none focus:border-amber-500 text-white text-sm"
          >
            <option value="" class="bg-slate-950 text-white font-bold">جميع الأنظمة</option>
            <option v-for="sys in flightSystems" :key="sys.id" :value="sys.id" class="bg-slate-950 text-white font-bold">
              {{ sys.name }}
            </option>
          </select>
        </div>

        <div v-if="activeTab !== 'tourism'" class="sm:col-span-2 flex items-end justify-end gap-2">
          <span class="text-xs text-gray-500 italic self-center">عرض الأرقام الشاملة من قاعدة البيانات للقسم المحدد</span>
        </div>
      </div>

      <div class="mt-4 pt-4 border-t border-white/5 flex flex-wrap gap-2 justify-end">
        <button type="button" @click="applyFilters" class="rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold px-5 py-2 text-xs transition-all shadow-md">
          تطبيق وعرض النتائج
        </button>
        <button type="button" @click="resetFilters" class="rounded-xl bg-white/5 hover:bg-white/10 text-gray-300 border border-white/10 px-5 py-2 text-xs transition-all">
          إعادة الضبط
        </button>
      </div>
    </div>

    <!-- ==================== PILLAR 1: TOURISM ==================== -->
    <template v-if="activeTab === 'tourism'">
      <!-- Total Aggregate KPI overview for Tourism -->
      <div v-if="isLoading()" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <KPICardSkeleton v-for="i in 3" :key="`t-kpi-${i}`" />
      </div>
      <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-br from-slate-900 to-slate-950 border border-amber-500/20 rounded-2xl p-5 relative overflow-hidden">
          <div class="absolute -left-4 -bottom-4 text-amber-500/5 text-7xl font-black select-none">✈️</div>
          <div class="text-xs font-bold text-amber-400 mb-1">إجمالي إيرادات قطاع السياحة</div>
          <div class="text-3xl font-black text-white font-mono">{{ formatCurrency(tourismSummary.total_revenue) }}</div>
          <div class="mt-2 text-xs text-gray-400 flex items-center justify-between">
            <span>الطيران: {{ formatCompactNumber(tourismSummary.flights.revenue) }}</span>
            <span>الحج والعمرة: {{ formatCompactNumber(tourismSummary.hajj.revenue) }}</span>
          </div>
        </div>

        <div class="bg-gradient-to-br from-slate-900 to-slate-950 border border-amber-500/20 rounded-2xl p-5 relative overflow-hidden">
          <div class="absolute -left-4 -bottom-4 text-emerald-500/5 text-7xl font-black select-none">💰</div>
          <div class="text-xs font-bold text-emerald-400 mb-1">صافي أرباح قطاع السياحة</div>
          <div class="text-3xl font-black text-emerald-400 font-mono">{{ formatCurrency(tourismSummary.total_profit) }}</div>
          <div class="mt-2 text-xs text-gray-400 flex items-center justify-between">
            <span>ربح الطيران: {{ formatCompactNumber(tourismSummary.flights.profit) }}</span>
            <span>ربح الحج: {{ formatCompactNumber(tourismSummary.hajj.profit) }}</span>
          </div>
        </div>

        <div class="bg-gradient-to-br from-slate-900 to-slate-950 border border-amber-500/20 rounded-2xl p-5 relative overflow-hidden">
          <div class="absolute -left-4 -bottom-4 text-sky-500/5 text-7xl font-black select-none">📊</div>
          <div class="text-xs font-bold text-sky-400 mb-1">إجمالي العمليات المنفذة</div>
          <div class="text-3xl font-black text-white font-mono">{{ tourismSummary.total_count }} <span class="text-sm font-normal text-gray-400">حجز منفذ</span></div>
          <div class="mt-2 text-xs text-gray-400 flex items-center justify-between">
            <span>تذاكر طيران: {{ tourismSummary.flights.count }}</span>
            <span>برامج حج/عمرة: {{ tourismSummary.hajj.count }}</span>
          </div>
        </div>
      </div>

      <!-- Subdivision: Flight module rich statistics -->
      <div class="bg-card-bg border border-white/10 rounded-3xl p-6 space-y-6">
        <div class="flex items-center justify-between border-b border-white/5 pb-4">
          <div class="flex items-center gap-3">
            <div class="p-2.5 bg-sky-500/10 rounded-xl text-sky-400 border border-sky-500/20">
              <Plane class="w-5 h-5" />
            </div>
            <div>
              <h3 class="text-lg font-black text-white">تفاصيل وحدة حجز الطيران</h3>
              <p class="text-xs text-gray-400 mt-0.5">أرصدة، أداء، ومخططات بيانية لحجوزات التذاكر</p>
            </div>
          </div>
          <div class="text-left">
            <span class="text-xs font-bold text-sky-400 bg-sky-500/10 px-3 py-1 rounded-full border border-sky-500/20">نشط ومتصل</span>
          </div>
        </div>

        <div v-if="isLoading()" class="space-y-6">
          <GridSkeleton :count="4" itemHeight="100px" />
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-4">
            <div class="lg:col-span-2">
              <ChartSkeleton height="250px" />
            </div>
            <div class="space-y-4">
              <TextLineSkeleton :lines="4" />
              <TextLineSkeleton :lines="4" />
            </div>
          </div>
        </div>
        <template v-else>
          <!-- Carrier balances cards -->
        <div v-if="carrierBalanceCards.length" class="space-y-3">
          <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider">أرصدة أنظمة وشركات الطيران (B2B)</h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div
              v-for="card in carrierBalanceCards"
              :key="card.id"
              class="p-4 bg-white/5 border border-white/5 rounded-2xl hover:border-sky-500/30 transition-all"
            >
              <div class="text-[10px] text-sky-400 font-bold mb-1">{{ card.system_name || 'نظام داخلي' }}</div>
              <div class="text-sm font-bold text-white truncate mb-2">{{ card.company_name }}</div>
              <div class="flex items-end justify-between">
                <div>
                  <div class="text-[10px] text-gray-400">الرصيد المتاح</div>
                  <div class="text-base font-black text-amber-400 font-mono">{{ formatCurrency(card.balance) }}</div>
                </div>
                <span :class="['text-[10px] font-bold px-2 py-0.5 rounded-full', card.is_active ? 'bg-emerald-500/10 text-emerald-400' : 'bg-white/5 text-gray-500']">
                  {{ card.is_active ? 'مفعل' : 'معطل' }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts & performance mapping -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-4">
          <!-- Chart -->
          <div class="lg:col-span-2 bg-white/5 border border-white/5 rounded-2xl p-5">
            <div class="mb-4">
              <h4 class="text-sm font-bold text-white">منحنى حجوزات الطيران اليومية</h4>
              <p class="text-xs text-gray-400">توزيع التذاكر والأرباح المحققة لكل يوم</p>
            </div>
            <div class="flex h-52 items-end justify-around gap-1 px-2">
              <div
                v-for="(day, index) in bookingsChart"
                :key="index"
                class="group flex flex-1 flex-col items-center gap-1.5"
              >
                <div class="relative h-36 w-full overflow-hidden rounded-md bg-white/5">
                  <div
                    class="absolute bottom-0 w-full rounded-t-md bg-gradient-to-t from-sky-600 to-cyan-400 transition-all group-hover:from-sky-500 group-hover:to-cyan-300"
                    :style="{ height: `${(day.count / Math.max(1, ...bookingsChart.map(d => d.count))) * 100}%` }"
                  ></div>
                </div>
                <span class="text-[10px] font-medium text-gray-400 truncate max-w-[50px]">{{ day.label }}</span>
                <span class="text-xs font-bold text-sky-400">{{ day.count }}</span>
              </div>
            </div>
          </div>

          <!-- Top routes & carriers -->
          <div class="space-y-4">
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4">
              <h4 class="text-xs font-bold text-amber-400 mb-3 flex items-center gap-1.5">
                <MapPin class="w-3.5 h-3.5" />
                أفضل خطوط الطيران المبيعة
              </h4>
              <div class="space-y-2">
                <div v-for="(route, i) in topRoutes.slice(0, 4)" :key="i" class="flex items-center justify-between text-xs p-2 bg-white/5 rounded-xl">
                  <span class="font-bold text-white">{{ route.from }} → {{ route.to }}</span>
                  <span class="text-emerald-400 font-mono font-bold">{{ formatCurrency(route.profit) }}</span>
                </div>
              </div>
            </div>

            <div class="bg-white/5 border border-white/5 rounded-2xl p-4">
              <h4 class="text-xs font-bold text-sky-400 mb-3 flex items-center gap-1.5">
                <Briefcase class="w-3.5 h-3.5" />
                أداء الشركات بالربحية
              </h4>
              <div class="space-y-2">
                <div v-for="c in carrierPerformance.slice(0, 3)" :key="c.id" class="flex items-center justify-between text-xs p-2 bg-white/5 rounded-xl">
                  <span class="text-gray-300 font-medium truncate max-w-[120px]">{{ c.name }}</span>
                  <span class="text-amber-400 font-bold font-mono">{{ formatCurrency(c.profit) }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        </template>
      </div>

      <!-- Subdivision: Hajj & Umra module summary block -->
      <div class="bg-card-bg border border-white/10 rounded-3xl p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="p-2.5 bg-amber-500/10 rounded-xl text-amber-400 border border-amber-500/20">
            <span class="text-lg">🕋</span>
          </div>
          <div>
            <h3 class="text-lg font-black text-white">إحصاءات برامج الحج والعمرة</h3>
            <p class="text-xs text-gray-400 mt-0.5">الحجوزات والبرامج المنفذة مباشرة من قاعدة البيانات</p>
          </div>
        </div>

        <div v-if="isLoading()" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <KPICardSkeleton v-for="i in 3" :key="`h-kpi-${i}`" />
        </div>
        <div v-else class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
            <div class="text-xs text-gray-400">إجمالي مبيعات البرامج</div>
            <div class="text-2xl font-black text-white mt-1 font-mono">{{ formatCurrency(tourismSummary.hajj.revenue) }}</div>
          </div>
          <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
            <div class="text-xs text-gray-400">أرباح الحج والعمرة</div>
            <div class="text-2xl font-black text-emerald-400 mt-1 font-mono">{{ formatCurrency(tourismSummary.hajj.profit) }}</div>
          </div>
          <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
            <div class="text-xs text-gray-400">عدد البرامج المحجوزة</div>
            <div class="text-2xl font-black text-amber-400 mt-1 font-mono">{{ tourismSummary.hajj.count }} <span class="text-xs font-normal text-gray-500">حجز</span></div>
          </div>
        </div>
      </div>
    </template>

    <!-- ==================== PILLAR 2: OFFICE ==================== -->
    <template v-if="activeTab === 'office'">
      <!-- Total Aggregate KPI overview for Office -->
      <div v-if="isLoading()" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <KPICardSkeleton v-for="i in 3" :key="`o-kpi-${i}`" />
      </div>
      <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-br from-slate-900 to-slate-950 border border-sky-500/20 rounded-2xl p-5 relative overflow-hidden">
          <div class="absolute -left-4 -bottom-4 text-sky-500/5 text-7xl font-black select-none">🏢</div>
          <div class="text-xs font-bold text-sky-400 mb-1">إجمالي إيرادات حسابات المكتب</div>
          <div class="text-3xl font-black text-white font-mono">{{ formatCurrency(officeSummary.total_revenue) }}</div>
          <div class="mt-2 text-xs text-gray-400 flex items-center justify-between">
            <span>الباصات: {{ formatCompactNumber(officeSummary.bus.revenue) }}</span>
            <span>فوري: {{ formatCompactNumber(officeSummary.fawry.revenue) }}</span>
          </div>
        </div>

        <div class="bg-gradient-to-br from-slate-900 to-slate-950 border border-sky-500/20 rounded-2xl p-5 relative overflow-hidden">
          <div class="absolute -left-4 -bottom-4 text-emerald-500/5 text-7xl font-black select-none">💎</div>
          <div class="text-xs font-bold text-emerald-400 mb-1">صافي أرباح حسابات المكتب</div>
          <div class="text-3xl font-black text-emerald-400 font-mono">{{ formatCurrency(officeSummary.total_profit) }}</div>
          <div class="mt-2 text-xs text-gray-400 flex items-center justify-between">
            <span>ربح الباص: {{ formatCompactNumber(officeSummary.bus.profit) }}</span>
            <span>ربح الخدمات: {{ formatCompactNumber(officeServiceProfit) }}</span>
          </div>
        </div>

        <div class="bg-gradient-to-br from-slate-900 to-slate-950 border border-sky-500/20 rounded-2xl p-5 relative overflow-hidden">
          <div class="absolute -left-4 -bottom-4 text-amber-500/5 text-7xl font-black select-none">⚡</div>
          <div class="text-xs font-bold text-amber-400 mb-1">العمليات التشغيلية (المكتب)</div>
          <div class="text-3xl font-black text-white font-mono">{{ officeSummary.total_count }} <span class="text-sm font-normal text-gray-400">حركة</span></div>
          <div class="mt-2 text-xs text-gray-400 flex items-center justify-between">
            <span>حجوزات باص: {{ officeSummary.bus.count }}</span>
            <span>حركات فوري وأونلاين: {{ officeServiceCount }}</span>
          </div>
        </div>
      </div>

      <!-- Bus operations full details -->
      <div class="bg-card-bg border border-white/10 rounded-3xl p-6 space-y-6">
        <div class="flex items-center gap-3 border-b border-white/5 pb-4">
          <div class="p-2.5 bg-amber-500/10 rounded-xl text-amber-400 border border-amber-500/20">
            <Bus class="w-5 h-5" />
          </div>
          <div>
            <h3 class="text-lg font-black text-white">إدارة حجوزات الباصات وشركات النقل</h3>
            <p class="text-xs text-gray-400 mt-0.5">الحجوزات، العوائد المحققة، والشركات الأفضل أداءً</p>
          </div>
        </div>

        <div v-if="isLoading()" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="lg:col-span-2">
            <ChartSkeleton height="250px" />
          </div>
          <div class="space-y-4">
            <TextLineSkeleton :lines="4" />
            <TextLineSkeleton :lines="2" />
          </div>
        </div>
        <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Bus Chart -->
          <div class="lg:col-span-2 bg-white/5 border border-white/5 rounded-2xl p-5">
            <h4 class="text-sm font-bold text-white mb-4">نشاط حجز الباص اليومي</h4>
            <div class="flex h-48 items-end justify-around gap-1 px-2">
              <div
                v-for="(day, index) in busBookingsChart"
                :key="'bc-'+index"
                class="group flex flex-1 flex-col items-center gap-1.5"
              >
                <div class="relative h-32 w-full overflow-hidden rounded-md bg-white/5">
                  <div
                    class="absolute bottom-0 w-full rounded-t-md bg-gradient-to-t from-amber-700 to-amber-400"
                    :style="{ height: `${(day.count / Math.max(1, ...busBookingsChart.map(d => d.count))) * 100}%` }"
                  ></div>
                </div>
                <span class="text-[10px] font-medium text-gray-400 truncate max-w-[50px]">{{ day.label }}</span>
                <span class="text-xs font-bold text-amber-400">{{ day.count }}</span>
              </div>
            </div>
          </div>

          <!-- Top bus route & summary -->
          <div class="space-y-4">
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4">
              <h4 class="text-xs font-bold text-amber-400 mb-3">أفضل شركات النقل ربحية</h4>
              <div v-if="!busCompanyPerformance.length" class="text-xs text-gray-500 py-2">لا توجد بيانات للشركات في هذا النطاق</div>
              <div v-else class="space-y-2">
                <div v-for="c in busCompanyPerformance.slice(0, 3)" :key="'bcp-'+c.id" class="flex items-center justify-between text-xs p-2 bg-white/5 rounded-xl">
                  <span class="text-white font-bold truncate max-w-[120px]">{{ c.name }}</span>
                  <span class="text-emerald-400 font-mono font-bold">{{ formatCurrency(c.profit) }}</span>
                </div>
              </div>
            </div>

            <div class="bg-white/5 border border-white/5 rounded-2xl p-4">
              <div class="text-xs text-gray-400">المدفوعات المعلقة للباصات</div>
              <div class="text-xl font-bold text-rose-400 mt-1 font-mono">{{ formatCurrency(busKpis.pending_payments) }}</div>
              <div class="text-[10px] text-gray-500 mt-1">المبالغ المستحقة غير المسددة بالكامل</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Fawry & Online modules parallel preview -->
      <div v-if="isLoading()" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <GridSkeleton :count="2" itemHeight="200px" />
      </div>
      <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Fawry -->
        <div class="bg-card-bg border border-white/10 rounded-3xl p-6 flex flex-col justify-between">
          <div>
            <div class="flex items-center gap-3 mb-4">
              <div class="p-2 bg-yellow-500/10 rounded-lg text-yellow-400 border border-yellow-500/20 font-black text-xs px-2.5">
                فوري
              </div>
              <div>
                <h4 class="text-base font-bold text-white">معاملات ماكينات فوري</h4>
                <p class="text-xs text-gray-400">الشحن والمدفوعات المباشرة</p>
              </div>
            </div>
            <div class="space-y-3">
              <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl text-sm">
                <span class="text-gray-400">إجمالي المبيعات</span>
                <span class="font-bold text-white font-mono">{{ formatCurrency(officeSummary.fawry.revenue) }}</span>
              </div>
              <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl text-sm">
                <span class="text-gray-400">الربح الصافي المحقق</span>
                <span class="font-bold text-emerald-400 font-mono">{{ formatCurrency(officeSummary.fawry.profit) }}</span>
              </div>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-white/5 text-left">
            <span class="text-xs text-gray-500 font-mono">{{ officeSummary.fawry.count }} عملية منفذة</span>
          </div>
        </div>

        <!-- Online Services -->
        <div class="bg-card-bg border border-white/10 rounded-3xl p-6 flex flex-col justify-between">
          <div>
            <div class="flex items-center gap-3 mb-4">
              <div class="p-2 bg-purple-500/10 rounded-lg text-purple-400 border border-purple-500/20 font-black text-xs px-2.5">
                أونلاين
              </div>
              <div>
                <h4 class="text-base font-bold text-white">الخدمات الإلكترونية الشاملة</h4>
                <p class="text-xs text-gray-400">منصات حجز الخدمات والتأشيرات</p>
              </div>
            </div>
            <div class="space-y-3">
              <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl text-sm">
                <span class="text-gray-400">إجمالي المبيعات</span>
                <span class="font-bold text-white font-mono">{{ formatCurrency(officeSummary.online.revenue) }}</span>
              </div>
              <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl text-sm">
                <span class="text-gray-400">الربح الصافي المحقق</span>
                <span class="font-bold text-emerald-400 font-mono">{{ formatCurrency(officeSummary.online.profit) }}</span>
              </div>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-white/5 text-left">
            <span class="text-xs text-gray-500 font-mono">{{ officeSummary.online.count }} معاملة منفذة</span>
          </div>
        </div>
      </div>
    </template>

    <!-- ==================== PILLAR 3: TREASURY ==================== -->
    <template v-if="activeTab === 'treasury'">
      <!-- Consolidated Trial Balance Section -->
      <div id="consolidated-trial-balance-section" class="bg-gradient-to-br from-indigo-950/40 via-slate-900 to-purple-950/30 border border-indigo-500/30 rounded-3xl p-6 space-y-6 relative overflow-hidden mb-8 shadow-xl shadow-indigo-950/20">
        <!-- Decorative Glow based on status -->
        <div :class="[
          'absolute -right-32 -top-32 w-64 h-64 rounded-full blur-3xl opacity-30 transition-all duration-500',
          consolidatedTrialBalance?.status === 'متساوية' ? 'bg-indigo-500' :
          consolidatedTrialBalance?.status === 'يوجد زيادة' ? 'bg-sky-500' : 'bg-rose-500'
        ]"></div>

        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-indigo-500/20 pb-4">
          <div class="flex items-center gap-3">
            <div :class="[
              'p-2.5 rounded-xl border transition-colors',
              consolidatedTrialBalance?.status === 'متساوية' ? 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20' :
              consolidatedTrialBalance?.status === 'يوجد زيادة' ? 'bg-sky-500/10 text-sky-400 border-sky-500/20' :
              'bg-rose-500/10 text-rose-400 border-rose-500/20'
            ]">
              <Layers class="w-5 h-5" />
            </div>
            <div>
              <h3 class="text-lg font-black text-white flex items-center gap-2">
                ميزان الحسابات ورأس المال الموحد
                <span class="text-[10px] font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 rounded-md px-1.5 py-0.5 font-sans">الشركة ككل</span>
              </h3>
              <p class="text-xs text-gray-400 mt-0.5">مطابقة رأس المال الفعلي مع الأرباح ورأس المال الدفتري المجمع لكافة القطاعات</p>
            </div>
          </div>
          
          <!-- Status Badge -->
          <div class="flex items-center gap-2">
            <span v-if="isLoadingConsolidatedTrialBalance" class="text-xs text-gray-400 animate-pulse">جاري الاحتساب...</span>
            <div v-else-if="consolidatedTrialBalance" :class="[
              'inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-black border shadow-lg backdrop-blur-md',
              consolidatedTrialBalance.status === 'متساوية' ? 'bg-indigo-500/15 text-indigo-400 border-indigo-500/30 shadow-indigo-500/5' :
              consolidatedTrialBalance.status === 'يوجد زيادة' ? 'bg-sky-500/10 text-sky-400 border-sky-500/30 shadow-sky-500/5' :
              'bg-rose-500/10 text-rose-400 border-rose-500/30 shadow-rose-500/5'
            ]">
              <span :class="[
                'h-2.5 w-2.5 rounded-full animate-ping',
                consolidatedTrialBalance.status === 'متساوية' ? 'bg-indigo-400' :
                consolidatedTrialBalance.status === 'يوجد زيادة' ? 'bg-sky-400' : 'bg-rose-400'
              ]"></span>
              حالة الميزان الموحد: {{ consolidatedTrialBalance.status }}
            </div>
          </div>
        </div>

        <div v-if="isLoadingConsolidatedTrialBalance && !consolidatedTrialBalance" class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <KPICardSkeleton v-for="i in 4" :key="`ctb-skeleton-${i}`" />
        </div>
        
        <div v-else-if="consolidatedTrialBalance" class="space-y-6">
          <!-- Main Equation Overview Grid -->
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Total Balances -->
            <div class="bg-indigo-950/10 border border-indigo-500/10 rounded-2xl p-4 relative overflow-hidden group hover:border-indigo-500/30 transition-all duration-300">
              <div class="text-xs font-bold text-gray-400 mb-1">إجمالي أرصدة الموديولات الموحد (+)</div>
              <div class="text-xl font-black text-white font-mono">{{ formatCurrency(consolidatedTrialBalance.total_balances) }}</div>
              <div class="mt-2.5 pt-2 border-t border-white/5 text-[10px] text-gray-500 flex flex-col gap-0.5">
                <div class="flex justify-between">
                  <span>قطاع السياحة:</span>
                  <span class="font-mono text-gray-400">{{ formatCurrency(trialBalance?.total_balances || 0) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>قطاع المكتب:</span>
                  <span class="font-mono text-gray-400">{{ formatCurrency(officeTrialBalance?.total_balances || 0) }}</span>
                </div>
              </div>
            </div>

            <!-- Total Liquidity -->
            <div class="bg-indigo-950/10 border border-indigo-500/10 rounded-2xl p-4 relative overflow-hidden group hover:border-indigo-500/30 transition-all duration-300">
              <div class="text-xs font-bold text-gray-400 mb-1">إجمالي السيولة النقدية الموحدة (+)</div>
              <div class="text-xl font-black text-white font-mono">{{ formatCurrency(consolidatedTrialBalance.total_liquidity) }}</div>
              <div class="mt-2.5 pt-2 border-t border-white/5 text-[10px] text-gray-500 flex flex-col gap-0.5">
                <div class="flex justify-between">
                  <span>سيولة السياحة:</span>
                  <span class="font-mono text-gray-400">{{ formatCurrency(trialBalance?.total_liquidity || 0) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>سيولة المكتب:</span>
                  <span class="font-mono text-gray-400">{{ formatCurrency(officeTrialBalance?.total_liquidity || 0) }}</span>
                </div>
              </div>
            </div>

            <!-- Receivables -->
            <div class="bg-indigo-950/10 border border-indigo-500/10 rounded-2xl p-4 relative overflow-hidden group hover:border-indigo-500/30 transition-all duration-300">
              <div class="text-xs font-bold text-emerald-400 mb-1">المستحق لنا الموحد (+)</div>
              <div class="text-xl font-black text-emerald-400 font-mono">{{ formatCurrency(consolidatedTrialBalance.due_to_us) }}</div>
              <div class="mt-2.5 pt-2 border-t border-white/5 text-[10px] text-gray-500 flex flex-col gap-0.5">
                <div class="flex justify-between">
                  <span>ذمم السياحة:</span>
                  <span class="font-mono text-gray-400">{{ formatCurrency(trialBalance?.due_to_us || 0) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>ذمم المكتب:</span>
                  <span class="font-mono text-gray-400">{{ formatCurrency(officeTrialBalance?.due_to_us || 0) }}</span>
                </div>
              </div>
            </div>

            <!-- Payables -->
            <div class="bg-indigo-950/10 border border-indigo-500/10 rounded-2xl p-4 relative overflow-hidden group hover:border-indigo-500/30 transition-all duration-300">
              <div class="text-xs font-bold text-rose-400 mb-1">المستحق علينا الموحد (-)</div>
              <div class="text-xl font-black text-rose-400 font-mono">{{ formatCurrency(consolidatedTrialBalance.due_from_us) }}</div>
              <div class="mt-2.5 pt-2 border-t border-white/5 text-[10px] text-gray-500 flex flex-col gap-0.5">
                <div class="flex justify-between">
                  <span>التزامات السياحة:</span>
                  <span class="font-mono text-gray-400">{{ formatCurrency(trialBalance?.due_from_us || 0) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>التزامات المكتب:</span>
                  <span class="font-mono text-gray-400">{{ formatCurrency(officeTrialBalance?.due_from_us || 0) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Capital Formula Match -->
          <div class="bg-gradient-to-r from-indigo-950/60 to-purple-950/50 border border-indigo-500/20 rounded-2xl p-5 flex flex-col lg:flex-row items-center justify-between gap-6">
            <div class="flex-1 space-y-2">
              <div class="text-xs text-indigo-300 font-bold">المعادلة المحاسبية الموحدة لرأس المال الفعلي الحالي:</div>
              <div class="text-xs font-mono bg-black/40 px-3 py-2 rounded-xl text-gray-300 leading-relaxed border border-indigo-500/10">
                رأس المال الحالي ({{ formatCompactNumber(consolidatedTrialBalance.current_capital) }}) = (الأرصدة المشتركة + السيولة المشتركة + ذمم مدينين) - ذمم دائنين
              </div>
            </div>
            
            <!-- Comparison Details -->
            <div class="flex flex-wrap items-center justify-end gap-6 shrink-0 text-left lg:text-right">
              <div>
                <div class="text-[10px] text-indigo-300">رأس المال المستهدف (الأساسي + الأرباح الكلية)</div>
                <div class="text-base font-black text-white font-mono">
                  {{ formatCurrency(consolidatedTrialBalance.expected_capital) }}
                  <span class="text-xs font-normal text-gray-500">
                    ({{ formatCompactNumber(consolidatedTrialBalance.base_capital) }} أساسي + {{ formatCompactNumber(consolidatedTrialBalance.profits) }} أرباح)
                  </span>
                </div>
              </div>
              <div class="border-r border-indigo-500/20 h-8 hidden md:block"></div>
              <div>
                <div class="text-[10px] text-indigo-300">الانحراف المالي الموحد</div>
                <div :class="[
                  'text-lg font-black font-mono',
                  consolidatedTrialBalance.variance === 0 ? 'text-emerald-400' :
                  consolidatedTrialBalance.variance > 0 ? 'text-sky-400' : 'text-rose-400'
                ]">
                  {{ consolidatedTrialBalance.variance > 0 ? '+' : '' }}{{ formatCurrency(consolidatedTrialBalance.variance) }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Trial Balance / Capital Matching Monitor -->
      <div id="trial-balance-section" class="bg-card-bg border border-white/10 rounded-3xl p-6 space-y-6 relative overflow-hidden mb-6">
        <!-- Decorative Glow based on status -->
        <div :class="[
          'absolute -right-32 -top-32 w-64 h-64 rounded-full blur-3xl opacity-20 transition-all duration-500',
          trialBalance?.status === 'متساوية' ? 'bg-emerald-500' :
          trialBalance?.status === 'يوجد زيادة' ? 'bg-sky-500' : 'bg-rose-500'
        ]"></div>

        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-white/5 pb-4">
          <div class="flex items-center gap-3">
            <div :class="[
              'p-2.5 rounded-xl border transition-colors',
              trialBalance?.status === 'متساوية' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' :
              trialBalance?.status === 'يوجد زيادة' ? 'bg-sky-500/10 text-sky-400 border-sky-500/20' :
              'bg-rose-500/10 text-rose-400 border-rose-500/20'
            ]">
              <TrendingUp v-if="trialBalance?.status === 'يوجد زيادة'" class="w-5 h-5" />
              <TrendingDown v-else-if="trialBalance?.status === 'يوجد عجز'" class="w-5 h-5" />
              <DollarSign v-else class="w-5 h-5" />
            </div>
            <div>
              <h3 class="text-lg font-black text-white">ميزان حسابات قسم السياحة (الجرد اللحظي لرأس المال)</h3>
              <p class="text-xs text-gray-400 mt-0.5">مطابقة رأس المال الفعلي مع رأس المال الدفتري والأرباح لقطاع السياحة</p>
            </div>
          </div>
          
          <!-- Status Badge -->
          <div class="flex items-center gap-2">
            <span v-if="isLoadingTrialBalance" class="text-xs text-gray-400 animate-pulse">جاري الاحتساب...</span>
            <div v-else-if="trialBalance" :class="[
              'inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-black border shadow-lg backdrop-blur-md',
              trialBalance.status === 'متساوية' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30 shadow-emerald-500/5' :
              trialBalance.status === 'يوجد زيادة' ? 'bg-sky-500/10 text-sky-400 border-sky-500/30 shadow-sky-500/5' :
              'bg-rose-500/10 text-rose-400 border-rose-500/30 shadow-rose-500/5'
            ]">
              <span :class="[
                'h-2.5 w-2.5 rounded-full animate-ping',
                trialBalance.status === 'متساوية' ? 'bg-emerald-400' :
                trialBalance.status === 'يوجد زيادة' ? 'bg-sky-400' : 'bg-rose-400'
              ]"></span>
              حالة الميزان: {{ trialBalance.status }}
            </div>
            <router-link
              to="/finance/treasury"
              class="text-xs text-amber-400 hover:text-amber-300 font-bold flex items-center gap-1 border border-amber-500/20 rounded-xl px-3 py-1.5 bg-amber-500/5 hover:bg-amber-500/10 transition-all"
            >
              عرض التفاصيل
              <span>←</span>
            </router-link>
          </div>
        </div>

        <div v-if="isLoadingTrialBalance && !trialBalance" class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <KPICardSkeleton v-for="i in 4" :key="`tb-skeleton-${i}`" />
        </div>
        
        <div v-else-if="trialBalance" class="space-y-6">
          <!-- Main Equation Overview Grid -->
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Total Balances -->
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4 relative overflow-hidden">
              <div class="text-xs font-bold text-gray-400 mb-1">إجمالي أرصدة الموديولات (+)</div>
              <div class="text-xl font-black text-white font-mono">{{ formatCurrency(trialBalance.total_balances) }}</div>
              <div class="mt-2 text-[10px] text-gray-500 flex justify-between">
                <span>طيران: {{ formatCompactNumber(trialBalance.details.flight_balances) }}</span>
                <span>حج/عمرة: {{ formatCompactNumber(trialBalance.details.hajj_umra_balances) }}</span>
              </div>
            </div>

            <!-- Total Liquidity -->
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4 relative overflow-hidden">
              <div class="text-xs font-bold text-gray-400 mb-1">إجمالي السيولة النقدية (+)</div>
              <div class="text-xl font-black text-white font-mono">{{ formatCurrency(trialBalance.total_liquidity) }}</div>
              <div class="mt-2 text-[10px] text-gray-500">
                الخزائن، البنوك والمحافظ
              </div>
            </div>

            <!-- Receivables -->
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4 relative overflow-hidden">
              <div class="text-xs font-bold text-emerald-400 mb-1">المستحق لنا (+)</div>
              <div class="text-xl font-black text-emerald-400 font-mono">{{ formatCurrency(trialBalance.due_to_us) }}</div>
              <div class="mt-2 text-[10px] text-gray-500">
                أرصدة العملاء والموردين المدينين
              </div>
            </div>

            <!-- Payables -->
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4 relative overflow-hidden">
              <div class="text-xs font-bold text-rose-400 mb-1">المستحق علينا (-)</div>
              <div class="text-xl font-black text-rose-400 font-mono">{{ formatCurrency(trialBalance.due_from_us) }}</div>
              <div class="mt-2 text-[10px] text-gray-500">
                أرصدة العملاء والموردين الدائنين
              </div>
            </div>
          </div>

          <!-- Capital Formula Match -->
          <div class="bg-gradient-to-r from-slate-900 to-purple-950/40 border border-white/10 rounded-2xl p-5 flex flex-col lg:flex-row items-center justify-between gap-6">
            <div class="flex-1 space-y-2">
              <div class="text-xs text-gray-400 font-bold">المعادلة المحاسبية لرأس المال الحالي:</div>
              <div class="text-xs font-mono bg-black/40 px-3 py-2 rounded-xl text-gray-300 leading-relaxed border border-white/5">
                رأس المال الحالي ({{ formatCompactNumber(trialBalance.current_capital) }}) = (الأرصدة + السيولة + لنا) - علينا
              </div>
            </div>
            
            <!-- Comparison Details -->
            <div class="flex flex-wrap items-center justify-end gap-6 shrink-0 text-left lg:text-right">
              <div>
                <div class="text-[10px] text-gray-400">رأس المال المستهدف (الأساسي + الأرباح)</div>
                <div class="text-base font-black text-white font-mono">
                  {{ formatCurrency(trialBalance.expected_capital) }}
                  <span class="text-xs font-normal text-gray-500">
                    ({{ formatCompactNumber(trialBalance.base_capital) }} + {{ formatCompactNumber(trialBalance.profits) }})
                  </span>
                </div>
              </div>
              <div class="border-r border-white/10 h-8 hidden md:block"></div>
              <div>
                <div class="text-[10px] text-gray-400">الفارق / الانحراف</div>
                <div :class="[
                  'text-lg font-black font-mono',
                  trialBalance.variance === 0 ? 'text-emerald-400' :
                  trialBalance.variance > 0 ? 'text-sky-400' : 'text-rose-400'
                ]">
                  {{ trialBalance.variance > 0 ? '+' : '' }}{{ formatCurrency(trialBalance.variance) }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ميزان حسابات قسم المكتب -->
      <div id="office-trial-balance-section" class="bg-card-bg border border-white/10 rounded-3xl p-6 space-y-6 relative overflow-hidden mb-6">
        <!-- Decorative Glow -->
        <div :class="[
          'absolute -right-32 -top-32 w-64 h-64 rounded-full blur-3xl opacity-20 transition-all duration-500',
          officeTrialBalance?.status === 'متساوية' ? 'bg-amber-500' :
          officeTrialBalance?.status === 'يوجد زيادة' ? 'bg-sky-500' : 'bg-rose-500'
        ]"></div>

        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-white/5 pb-4">
          <div class="flex items-center gap-3">
            <div :class="[
              'p-2.5 rounded-xl border transition-colors',
              officeTrialBalance?.status === 'متساوية' ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' :
              officeTrialBalance?.status === 'يوجد زيادة' ? 'bg-sky-500/10 text-sky-400 border-sky-500/20' :
              'bg-rose-500/10 text-rose-400 border-rose-500/20'
            ]">
              <TrendingUp v-if="officeTrialBalance?.status === 'يوجد زيادة'" class="w-5 h-5" />
              <TrendingDown v-else-if="officeTrialBalance?.status === 'يوجد عجز'" class="w-5 h-5" />
              <DollarSign v-else class="w-5 h-5" />
            </div>
            <div>
              <h3 class="text-lg font-black text-white">ميزان حسابات قسم المكتب (الجرد اللحظي لرأس المال)</h3>
              <p class="text-xs text-gray-400 mt-0.5">مطابقة رأس المال الفعلي مع رأس المال الدفتري والأرباح لقطاع المكتب</p>
            </div>
          </div>

          <div class="flex items-center gap-2">
            <span v-if="isLoadingOfficeTrialBalance" class="text-xs text-gray-400 animate-pulse">جاري الاحتساب...</span>
            <div v-else-if="officeTrialBalance" :class="[
              'inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-black border shadow-lg backdrop-blur-md',
              officeTrialBalance.status === 'متساوية' ? 'bg-amber-500/10 text-amber-400 border-amber-500/30' :
              officeTrialBalance.status === 'يوجد زيادة' ? 'bg-sky-500/10 text-sky-400 border-sky-500/30' :
              'bg-rose-500/10 text-rose-400 border-rose-500/30'
            ]">
              <span :class="[
                'h-2.5 w-2.5 rounded-full animate-ping',
                officeTrialBalance.status === 'متساوية' ? 'bg-amber-400' :
                officeTrialBalance.status === 'يوجد زيادة' ? 'bg-sky-400' : 'bg-rose-400'
              ]"></span>
              حالة الميزان: {{ officeTrialBalance.status }}
            </div>
          </div>
        </div>

        <div v-if="isLoadingOfficeTrialBalance && !officeTrialBalance" class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <KPICardSkeleton v-for="i in 4" :key="`otb-skeleton-${i}`" />
        </div>

        <div v-else-if="officeTrialBalance" class="space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- أصول شركات الباص -->
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4 relative overflow-hidden">
              <div class="text-xs font-bold text-gray-400 mb-1">أرصدة شركات الباص (+)</div>
              <div class="text-xl font-black text-white font-mono">{{ formatCurrency(officeTrialBalance.total_balances) }}</div>
              <div class="mt-2 text-[10px] text-gray-500">
                شركات: {{ formatCompactNumber(officeTrialBalance.details.bus_company_balances) }}
              </div>
            </div>

            <!-- السيولة النقدية للمكتب -->
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4 relative overflow-hidden">
              <div class="text-xs font-bold text-gray-400 mb-1">السيولة النقدية (+)</div>
              <div class="text-xl font-black text-white font-mono">{{ formatCurrency(officeTrialBalance.total_liquidity) }}</div>
              <div class="mt-2 text-[10px] text-gray-500">
                خزائن وصناديق المكتب
              </div>
            </div>

            <!-- المستحق لنا -->
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4 relative overflow-hidden">
              <div class="text-xs font-bold text-emerald-400 mb-1">المستحق لنا (+)</div>
              <div class="text-xl font-black text-emerald-400 font-mono">{{ formatCurrency(officeTrialBalance.due_to_us) }}</div>
              <div class="mt-2 text-[10px] text-gray-500">
                ذمم عملاء المكتب
              </div>
            </div>

            <!-- المستحق علينا -->
            <div class="bg-white/5 border border-white/5 rounded-2xl p-4 relative overflow-hidden">
              <div class="text-xs font-bold text-rose-400 mb-1">المستحق علينا (-)</div>
              <div class="text-xl font-black text-rose-400 font-mono">{{ formatCurrency(officeTrialBalance.due_from_us) }}</div>
              <div class="mt-2 text-[10px] text-gray-500">
                ذمم دائنة للمكتب
              </div>
            </div>
          </div>

          <!-- المعادلة المحاسبية -->
          <div class="bg-gradient-to-r from-slate-900 to-amber-950/30 border border-white/10 rounded-2xl p-5 flex flex-col lg:flex-row items-center justify-between gap-6">
            <div class="flex-1 space-y-2">
              <div class="text-xs text-gray-400 font-bold">المعادلة المحاسبية لرأس مال المكتب:</div>
              <div class="text-xs font-mono bg-black/40 px-3 py-2 rounded-xl text-gray-300 leading-relaxed border border-white/5">
                رأس المال الحالي ({{ formatCompactNumber(officeTrialBalance.current_capital) }}) = (أرصدة + سيولة + لنا) - علينا
              </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-6 shrink-0 text-left lg:text-right">
              <div>
                <div class="text-[10px] text-gray-400">الأرباح المتراكمة (الباص + فوري + أونلاين)</div>
                <div class="text-base font-black text-white font-mono">
                  {{ formatCurrency(officeTrialBalance.expected_capital) }}
                  <span class="text-xs font-normal text-gray-500">
                    ({{ formatCompactNumber(officeTrialBalance.base_capital) }} + {{ formatCompactNumber(officeTrialBalance.profits) }})
                  </span>
                </div>
              </div>
              <div class="border-r border-white/10 h-8 hidden md:block"></div>
              <div>
                <div class="text-[10px] text-gray-400">الفارق / الانحراف</div>
                <div :class="[
                  'text-lg font-black font-mono',
                  officeTrialBalance.variance === 0 ? 'text-amber-400' :
                  officeTrialBalance.variance > 0 ? 'text-sky-400' : 'text-rose-400'
                ]">
                  {{ officeTrialBalance.variance > 0 ? '+' : '' }}{{ formatCurrency(officeTrialBalance.variance) }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Live liquidity audit -->
      <div class="bg-card-bg border border-white/10 rounded-3xl p-6 space-y-6">
        <div>
          <h3 class="text-lg font-black text-white flex items-center gap-2">
            <span class="text-emerald-400">💰</span>
            توزيع السيولة النقدية للأرصدة النشطة
          </h3>
          <p class="text-xs text-gray-400 mt-0.5">تُجمع فورياً من حسابات الخزائن والبنوك والمحافظ الإلكترونية المعرفة بالنظام</p>
        </div>

        <!-- Progress Distribution Bar -->
        <template v-if="isLoading()">
          <TextLineSkeleton :lines="2" heightClass="h-4" gapClass="gap-4" />
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
            <KPICardSkeleton v-for="i in 3" :key="`tr-kpi-${i}`" />
          </div>
        </template>
        <template v-else>
          <div class="space-y-2">
          <div class="flex justify-between text-xs font-bold text-gray-300">
            <span>إجمالي المركز المالي للأرصدة:</span>
            <span class="text-emerald-400 font-mono text-base">{{ formatCurrency(treasurySummary.total) }}</span>
          </div>
          <div class="h-4 w-full bg-white/5 rounded-full overflow-hidden flex p-0.5 gap-0.5 border border-white/5">
            <div
              class="bg-gradient-to-r from-amber-500 to-amber-600 rounded-l-full transition-all"
              :style="{ width: `${treasurySummary.total > 0 ? (treasurySummary.cashbox / treasurySummary.total) * 100 : 33}%` }"
              title="الخزائن النقدية"
            ></div>
            <div
              class="bg-gradient-to-r from-sky-500 to-sky-600 transition-all"
              :style="{ width: `${treasurySummary.total > 0 ? (treasurySummary.bank / treasurySummary.total) * 100 : 33}%` }"
              title="الحسابات البنكية"
            ></div>
            <div
              class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-r-full transition-all"
              :style="{ width: `${treasurySummary.total > 0 ? (treasurySummary.wallet / treasurySummary.total) * 100 : 34}%` }"
              title="المحافظ الإلكترونية"
            ></div>
          </div>
        </div>

        <!-- Breakdown indicators -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
          <div class="p-4 bg-white/5 rounded-2xl border border-amber-500/20 border-r-4 border-r-amber-500">
            <div class="text-xs text-gray-400 font-bold">الخزائن والصناديق (Cashbox)</div>
            <div class="text-xl font-black text-white mt-1 font-mono">{{ formatCurrency(treasurySummary.cashbox) }}</div>
            <div class="text-[10px] text-amber-400 mt-1 font-bold">{{ treasurySummary.total > 0 ? ((treasurySummary.cashbox / treasurySummary.total) * 100).toFixed(1) : 0 }}% من السيولة</div>
          </div>

          <div class="p-4 bg-white/5 rounded-2xl border border-sky-500/20 border-r-4 border-r-sky-500">
            <div class="text-xs text-gray-400 font-bold">الحسابات البنكية (Banks)</div>
            <div class="text-xl font-black text-white mt-1 font-mono">{{ formatCurrency(treasurySummary.bank) }}</div>
            <div class="text-[10px] text-sky-400 mt-1 font-bold">{{ treasurySummary.total > 0 ? ((treasurySummary.bank / treasurySummary.total) * 100).toFixed(1) : 0 }}% من السيولة</div>
          </div>

          <div class="p-4 bg-white/5 rounded-2xl border border-purple-500/20 border-r-4 border-r-purple-500">
            <div class="text-xs text-gray-400 font-bold">المحافظ الإلكترونية (Wallets)</div>
            <div class="text-xl font-black text-white mt-1 font-mono">{{ formatCurrency(treasurySummary.wallet) }}</div>
            <div class="text-[10px] text-purple-400 mt-1 font-bold">{{ treasurySummary.total > 0 ? ((treasurySummary.wallet / treasurySummary.total) * 100).toFixed(1) : 0 }}% من السيولة</div>
          </div>
        </div>
        </template>
      </div>

      <!-- Financial flow and client overview -->
      <div v-if="isLoading()" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
          <GridSkeleton :count="2" itemHeight="150px" />
        </div>
        <div>
          <TextLineSkeleton :lines="6" heightClass="h-10" />
        </div>
      </div>
      <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Income vs expense stats -->
        <div class="lg:col-span-2 bg-card-bg border border-white/10 rounded-3xl p-6 space-y-4">
          <h4 class="text-base font-bold text-white flex items-center gap-2">
            <DollarSign class="w-4 h-4 text-emerald-400" />
            حركة المقبوضات والمدفوعات (النطاق الزمني)
          </h4>
          <div class="grid grid-cols-2 gap-4">
            <div class="p-4 bg-emerald-500/10 rounded-2xl border border-emerald-500/20">
              <div class="text-xs text-emerald-400 font-bold">إجمالي التدفقات الداخلة (Income)</div>
              <div class="text-2xl font-black text-white mt-1 font-mono">{{ formatCurrency(overviewStats.financial?.total_income || 0) }}</div>
            </div>
            <div class="p-4 bg-rose-500/10 rounded-2xl border border-rose-500/20">
              <div class="text-xs text-rose-400 font-bold">مصروفات تشغيلية (OpEx)</div>
              <div class="text-2xl font-black text-white mt-1 font-mono">{{ formatCurrency(overviewStats.financial?.total_operating_expenses || 0) }}</div>
              <p
                v-if="overviewStats.financial?.total_cogs || overviewStats.financial?.total_operating_expenses"
                class="text-[10px] text-rose-300/70 mt-1 font-mono"
              >
                تكاليف: {{ formatCurrency(overviewStats.financial?.total_cogs || 0) }}
                · تشغيل: {{ formatCurrency(overviewStats.financial?.total_operating_expenses || 0) }}
              </p>
            </div>
          </div>
          <div class="p-3 bg-white/5 rounded-xl flex items-center justify-between text-xs font-bold">
            <span class="text-gray-400">صافي ربحية المعاملات المحاسبية:</span>
            <span :class="['font-mono text-sm', (overviewStats.financial?.net_profit || 0) >= 0 ? 'text-emerald-400' : 'text-rose-400']">
              {{ formatCurrency(overviewStats.financial?.net_profit || 0) }}
            </span>
          </div>
        </div>

        <!-- CRM overview info -->
        <div class="bg-card-bg border border-white/10 rounded-3xl p-6 flex flex-col justify-between">
          <div>
            <h4 class="text-base font-bold text-white mb-4 flex items-center gap-2">
              <Users class="w-4 h-4 text-amber-400" />
              إحصاءات الموارد البشرية والعملاء
            </h4>
            <div class="space-y-3">
              <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl text-xs">
                <span class="text-gray-400 font-bold">قاعدة العملاء المسجلين</span>
                <span class="font-bold text-amber-400 font-mono text-sm">{{ overviewStats.overview?.total_customers || 0 }} عميل</span>
              </div>
              <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl text-xs">
                <span class="text-gray-400 font-bold">طاقم الموظفين بالنظام</span>
                <span class="font-bold text-sky-400 font-mono text-sm">{{ overviewStats.overview?.total_employees || 0 }} موظف</span>
              </div>
              <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl text-xs">
                <span class="text-gray-400 font-bold">الفواتير المستحقة المعلقة</span>
                <span class="font-bold text-rose-400 font-mono text-sm">{{ overviewStats.overview?.pending_invoices || 0 }} فاتورة</span>
              </div>
            </div>
          </div>
          <div class="mt-4 text-center">
            <span class="text-[10px] text-gray-500">محدثة ومربوطة لحظياً بالكيانات المحاسبية</span>
          </div>
        </div>
      </div>
    </template>

    <!-- Recent System wide activity feed -->
    <div class="bg-card-bg border border-white/10 rounded-3xl p-6">
      <h3 class="text-base font-bold text-white mb-4 flex items-center gap-2">
        <Clock class="w-4 h-4 text-amber-400" />
        سجل أحدث الحركات العامة بالنظام
      </h3>
      <div v-if="isLoading()">
        <TableSkeleton :rows="3" :columns="2" />
      </div>
      <template v-else>
        <div v-if="!recentActivity.length" class="text-xs text-gray-500 py-3 text-center">لا توجد حركات مسجلة مؤخراً</div>
        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div
          v-for="(act, idx) in recentActivity.slice(0, 6)"
          :key="idx"
          class="flex items-start gap-3 p-3 bg-white/5 rounded-xl border border-white/5"
        >
          <div class="p-2 rounded-lg bg-amber-500/10 text-amber-400 text-xs shrink-0 font-bold">
            ⚡
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-xs text-white font-medium truncate">{{ act.description }}</div>
            <div class="text-[10px] text-gray-500 mt-0.5">{{ act.time || act.created_at }}</div>
          </div>
          </div>
        </div>
      </template>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useFlightStore } from '@/stores/flightStore';
import axios from 'axios';
import { useAsyncState } from '@/composables/useAsyncState';
import KPICardSkeleton from '@/components/skeletons/KPICardSkeleton.vue';
import TableSkeleton from '@/components/skeletons/TableSkeleton.vue';
import ChartSkeleton from '@/components/skeletons/ChartSkeleton.vue';
import GridSkeleton from '@/components/skeletons/GridSkeleton.vue';
import TextLineSkeleton from '@/components/skeletons/TextLineSkeleton.vue';

import {
  RefreshCw,
  Download,
  Plane,
  Bus,
  DollarSign,
  TrendingUp,
  TrendingDown,
  Briefcase,
  XCircle,
  MapPin,
  Clock,
  Users,
  Layers,
} from 'lucide-vue-next';

const flightStore = useFlightStore();
const { state, error, setLoading, setSuccess, setEmpty, setError, isLoading, isSuccess, isEmpty, isError } = useAsyncState('loading');
const isRefreshing = computed(() => isLoading());

// Active layout view mapping: 'tourism' | 'office' | 'treasury'
const activeTab = ref('tourism');

const trialBalance = ref(null);
const isLoadingTrialBalance = ref(false);
const officeTrialBalance = ref(null);
const isLoadingOfficeTrialBalance = ref(false);
const consolidatedTrialBalance = ref(null);
const isLoadingConsolidatedTrialBalance = ref(false);

// Filters
const filters = ref({
  date_from: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
  date_to: new Date().toISOString().split('T')[0],
  carrier_id: '',
  system_type: '',
});

// Summaries objects filled by the API
const tourismSummary = ref({
  flights: { count: 0, revenue: 0, profit: 0 },
  hajj: { count: 0, revenue: 0, profit: 0 },
  total_count: 0,
  total_revenue: 0,
  total_profit: 0,
});

const officeSummary = ref({
  bus: { count: 0, revenue: 0, profit: 0 },
  fawry: { count: 0, revenue: 0, profit: 0 },
  online: { count: 0, revenue: 0, profit: 0 },
  total_count: 0,
  total_revenue: 0,
  total_profit: 0,
});

const treasurySummary = ref({
  total: 0,
  cashbox: 0,
  bank: 0,
  wallet: 0,
});

const overviewStats = ref({});

// Flight / legacy detailed lists
const carriers = ref([]);
const flightSystems = ref([]);
const carrierBalanceCards = ref([]);
const bookingsChart = ref([]);
const revenueChart = ref([]);
const carrierPerformance = ref([]);
const topRoutes = ref([]);
const recentActivity = ref([]);

// Bus detailed lists
const busKpis = ref({
  pending_payments: 0,
});
const busBookingsChart = ref([]);
const busCompanyPerformance = ref([]);

const parseAmount = (value) => {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
};

const normalizeModuleBlock = (block = {}) => ({
  count: parseAmount(block.count),
  revenue: parseAmount(block.revenue),
  profit: parseAmount(block.profit),
});

const normalizeTourismSummary = (raw = {}) => ({
  flights: normalizeModuleBlock(raw.flights),
  hajj: normalizeModuleBlock(raw.hajj),
  total_count: parseAmount(raw.total_count),
  total_revenue: parseAmount(raw.total_revenue),
  total_profit: parseAmount(raw.total_profit),
});

const normalizeOfficeSummary = (raw = {}) => ({
  bus: normalizeModuleBlock(raw.bus),
  fawry: normalizeModuleBlock(raw.fawry),
  online: normalizeModuleBlock(raw.online),
  total_count: parseAmount(raw.total_count),
  total_revenue: parseAmount(raw.total_revenue),
  total_profit: parseAmount(raw.total_profit),
});

const normalizeTreasurySummary = (raw = {}) => ({
  total: parseAmount(raw.total),
  cashbox: parseAmount(raw.cashbox),
  bank: parseAmount(raw.bank),
  wallet: parseAmount(raw.wallet),
});

const normalizeFinancial = (raw = {}) => ({
  total_income: parseAmount(raw.total_income),
  total_cogs: parseAmount(raw.total_cogs),
  total_operating_expenses: parseAmount(raw.total_operating_expenses),
  total_expense: parseAmount(raw.total_expense),
  net_profit: parseAmount(raw.net_profit),
  profit_margin: parseAmount(raw.profit_margin),
  transactions_count: parseAmount(raw.transactions_count),
});

const officeServiceProfit = computed(() =>
  officeSummary.value.fawry.profit + officeSummary.value.online.profit
);

const officeServiceCount = computed(() =>
  officeSummary.value.fawry.count + officeSummary.value.online.count
);

// Methods
const formatCurrency = (amount) => {
  const n = parseAmount(amount);
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n);
};

const formatCompactNumber = (amount) => {
  const val = parseAmount(amount);
  if (val >= 1000000) {
    return (val / 1000000).toFixed(1) + 'M';
  }
  if (val >= 1000) {
    return (val / 1000).toFixed(1) + 'K';
  }
  return val.toLocaleString('ar-EG', { maximumFractionDigits: 0 });
};

const refreshData = async () => {
  await fetchDashboardData();
};

const applyFilters = async () => {
  await fetchDashboardData();
};

const resetFilters = () => {
  filters.value = {
    date_from: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    date_to: new Date().toISOString().split('T')[0],
    carrier_id: '',
    system_type: '',
  };
  fetchDashboardData();
};

const exportReport = () => {
  alert('جاري تجهيز تقرير المركز المالي والتشغيلي للطباعة...');
};

const fetchTrialBalance = async () => {
  isLoadingTrialBalance.value = true;
  try {
    const response = await axios.get('/api/v1/reports/trial-balance');
    trialBalance.value = response.data?.data || null;
  } catch (err) {
    if (axios.isCancel?.(err) || err?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Failed to fetch trial balance:', err);
  } finally {
    isLoadingTrialBalance.value = false;
  }
};

const fetchOfficeTrialBalance = async () => {
  isLoadingOfficeTrialBalance.value = true;
  try {
    const response = await axios.get('/api/v1/reports/office-trial-balance');
    officeTrialBalance.value = response.data?.data || null;
  } catch (err) {
    if (axios.isCancel?.(err) || err?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Failed to fetch office trial balance:', err);
  } finally {
    isLoadingOfficeTrialBalance.value = false;
  }
};

const fetchConsolidatedTrialBalance = async () => {
  isLoadingConsolidatedTrialBalance.value = true;
  try {
    const response = await axios.get('/api/v1/reports/consolidated-trial-balance');
    consolidatedTrialBalance.value = response.data?.data || null;
  } catch (err) {
    if (axios.isCancel?.(err) || err?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Failed to fetch consolidated trial balance:', err);
  } finally {
    isLoadingConsolidatedTrialBalance.value = false;
  }
};

const scrollToTrialBalance = () => {
  setTimeout(() => {
    const el = document.getElementById('consolidated-trial-balance-section') || document.getElementById('trial-balance-section');
    if (el) {
      el.scrollIntoView({ behavior: 'smooth' });
    }
  }, 100);
};

const fetchDashboardData = async () => {
  setLoading();
  try {
    // Attempt store synchronizations safely
    if (flightStore && typeof flightStore.fetchFlightBookingReference === 'function') {
      await flightStore.fetchFlightBookingReference().catch(() => {});
    }
    if (flightStore && typeof flightStore.fetchCarriers === 'function') {
      await flightStore.fetchCarriers().catch(() => {});
      carriers.value = flightStore.carriers || [];
    }
    if (flightStore && typeof flightStore.fetchSystems === 'function') {
      await flightStore.fetchSystems().catch(() => {});
      flightSystems.value = flightStore.systems || [];
    }

    const response = await axios.get('/api/v1/dashboard', {
      params: {
        date_from: filters.value.date_from,
        date_to: filters.value.date_to,
        carrier_id: filters.value.carrier_id,
        system_type: filters.value.system_type,
      }
    });

    const data = response.data?.data || {};

    if (data.tourism_summary) {
      tourismSummary.value = normalizeTourismSummary(data.tourism_summary);
    }
    if (data.office_summary) {
      officeSummary.value = normalizeOfficeSummary(data.office_summary);
    }
    if (data.treasury_summary) {
      treasurySummary.value = normalizeTreasurySummary(data.treasury_summary);
    }

    overviewStats.value = {
      overview: data.overview || {},
      financial: normalizeFinancial(data.financial || {}),
    };

    carrierBalanceCards.value = (data.carrier_balance_cards || []).map((card) => ({
      ...card,
      balance: parseAmount(card.balance),
      available_balance: parseAmount(card.available_balance),
    }));
    bookingsChart.value = (data.bookings_chart || []).map((day) => ({
      ...day,
      count: parseAmount(day.count),
    }));
    revenueChart.value = data.revenue_chart || [];
    carrierPerformance.value = (data.carrier_performance || []).map((item) => ({
      ...item,
      profit: parseAmount(item.profit),
      revenue: parseAmount(item.revenue),
      bookings: parseAmount(item.bookings),
    }));
    topRoutes.value = (data.top_routes || []).map((route) => ({
      ...route,
      profit: parseAmount(route.profit),
      revenue: parseAmount(route.revenue),
      bookings: parseAmount(route.bookings),
    }));
    recentActivity.value = data.recent_activities || data.recent_activity || [];

    if (data.bus_kpis) {
      busKpis.value = {
        ...data.bus_kpis,
        pending_payments: parseAmount(data.bus_kpis.pending_payments),
      };
    }
    busBookingsChart.value = (data.bus_bookings_chart || []).map((day) => ({
      ...day,
      count: parseAmount(day.count),
    }));
    busCompanyPerformance.value = (data.bus_company_performance || []).map((item) => ({
      ...item,
      profit: parseAmount(item.profit),
      revenue: parseAmount(item.revenue),
      bookings: parseAmount(item.bookings),
    }));

    await Promise.all([
      fetchTrialBalance(),
      fetchOfficeTrialBalance(),
      fetchConsolidatedTrialBalance()
    ]);

    setSuccess();
  } catch (error) {
    if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Failed to fetch unified dashboard data:', error);
    setError(error);
  }
};

let pollingInterval = null;

onMounted(async () => {
  await fetchDashboardData();
  
  // Auto-refresh every 15 seconds to fetch new data without manual reload
  pollingInterval = setInterval(async () => {
    if (!isLoading()) {
      await fetchDashboardData();
    }
  }, 15000);
});

onUnmounted(() => {
  if (pollingInterval) {
    clearInterval(pollingInterval);
  }
});
</script>

<style scoped>
.bg-card-bg {
  background-color: rgba(255, 255, 255, 0.03);
  backdrop-filter: blur(12px);
}
</style>
