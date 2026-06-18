<template>
  <div class="finance-dashboard flight-booking animate-in pb-10 fade-in duration-700">
    <div v-if="isError()" class="mx-auto mb-6 max-w-7xl rounded-2xl border border-red-500/30 bg-red-500/10 p-6 text-center px-4 sm:px-6 lg:px-8">
      <p class="text-red-400 font-bold">تعذر تحميل بيانات لوحة المالية.</p>
      <p v-if="error" class="mt-1 text-sm text-red-300/70">{{ error }}</p>
      <button
        type="button"
        @click="fetchData"
        class="mt-4 rounded-xl bg-red-500 px-6 py-2 text-white transition hover:bg-red-400"
      >
        إعادة المحاولة
      </button>
    </div>

    <header class="flight-hero relative">
      <div
        class="relative z-10 mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:flex-row lg:items-end lg:justify-between lg:px-8"
      >
        <div class="min-w-0 flex-1">
          <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-sky-400/90">المالية</p>
          <h1 class="mt-1 text-xl font-black tracking-tight text-text-main sm:text-3xl lg:text-4xl">
            الحسابات والمعاملات
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-text-muted">
            لوحة موحّدة مع مؤشرات الأداء، الحسابات، وسجل المعاملات بنفس أسلوب لوحة الطيران.
          </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 sm:gap-3">
          <router-link
            to="/finance/transactions/create"
            class="btn-airline inline-flex items-center gap-2 px-4 py-2.5 text-sm shadow-xl sm:px-5"
          >
            <Plus class="h-4 w-4" />
            معاملة جديدة
          </router-link>
          <router-link
            to="/finance/transfers/create"
            class="btn-airline-ghost inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold sm:px-5"
          >
            <ArrowRightLeft class="h-4 w-4" />
            تحويل
          </router-link>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
      <div class="flight-panel !p-5 sm:!p-6">
        <h2 class="flight-panel__title mb-1">تصفية البيانات</h2>
        <p class="flight-panel__subtitle mb-5">النطاق الزمني يؤثر على جدول المعاملات والإحصائيات</p>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
          <div>
            <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">من تاريخ</label>
            <input v-model="filters.date_from" type="date" class="flight-input w-full" />
          </div>
          <div>
            <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">إلى تاريخ</label>
            <input v-model="filters.date_to" type="date" class="flight-input w-full" />
          </div>
          <div>
            <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">نوع الحساب</label>
            <select v-model="filters.account_type" class="flight-select w-full">
              <option value="">الكل</option>
              <option v-for="t in financeStore.accountTypes" :key="t.value" :value="t.value">
                {{ t.label }}
              </option>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">نوع المعاملة</label>
            <select v-model="filters.transaction_type" class="flight-select w-full">
              <option value="">الكل</option>
              <option v-for="t in financeStore.transactionTypes" :key="t.value" :value="t.value">
                {{ t.label }}
              </option>
            </select>
          </div>
        </div>
        <div class="mt-5 flex flex-wrap gap-2">
          <button type="button" class="btn-airline px-6 py-2.5 text-sm font-bold" @click="applyFilters">
            تطبيق الفلاتر
          </button>
          <button type="button" class="btn-airline-ghost px-6 py-2.5 text-sm font-bold" @click="resetFilters">
            إعادة تعيين
          </button>
        </div>
      </div>

      <!-- قسم مؤشرات الأداء الموحد للقطاعات (رأس المال - الأرباح - المصروفات) -->
      <div v-if="isLoading()" class="grid grid-cols-1 lg:grid-cols-2 gap-6 animate-pulse">
        <div v-for="i in 2" :key="`cap-skeleton-${i}`" class="flight-panel !p-6 rounded-2xl bg-white/5 border border-white/10 h-[220px]">
          <div class="h-6 bg-white/10 rounded w-1/3 mb-4"></div>
          <div class="grid grid-cols-3 gap-4">
            <div v-for="j in 3" :key="j" class="h-16 bg-white/10 rounded"></div>
          </div>
        </div>
      </div>
      <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- قطاع السياحة والطيران -->
        <div class="flight-panel !p-6 border border-amber-500/20 bg-gradient-to-br from-slate-900 via-amber-950/10 to-slate-900 rounded-2xl relative overflow-hidden transition-all hover:border-amber-500/40">
          <div class="absolute -left-6 -bottom-6 text-amber-500/5 text-8xl font-black select-none pointer-events-none">✈️</div>
          
          <div class="mb-5 flex items-center justify-between border-b border-white/5 pb-3">
            <div class="flex items-center gap-2">
              <span class="text-xl">✈️</span>
              <h3 class="text-base font-black text-white font-bold">قطاع السياحة والطيران</h3>
            </div>
            
            <!-- محول العملات للوحة التحكم -->
            <div class="flex items-center gap-1 bg-white/5 p-1 rounded-xl border border-white/5">
              <button
                v-for="currency in capitalData.currencies"
                :key="currency"
                type="button"
                @click="selectedCurrency = currency"
                :class="[
                  'px-3 py-1 text-[11px] font-bold rounded-lg transition-all',
                  selectedCurrency === currency
                    ? 'bg-gradient-to-r from-gold to-amber-500 text-slate-950 shadow-md'
                    : 'text-text-muted hover:text-text-main hover:bg-white/5'
                ]"
              >
                {{ currency }}
              </button>
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <!-- رأس المال -->
            <div 
              @click="openCapitalDetails('tourism')" 
              class="cursor-pointer rounded-xl border border-amber-500/10 bg-white/5 p-4 transition-all hover:border-amber-500/40 hover:bg-white/10 group/item relative overflow-hidden flex flex-col justify-between min-h-[120px]"
            >
              <div>
                <div class="text-[10px] font-bold text-amber-400 mb-1 flex items-center gap-1">
                  <span>رأس المال الفعلي</span>
                  <Info class="h-3 w-3 inline opacity-70 group-hover/item:opacity-100" />
                </div>
                <div class="font-mono text-2xl font-black text-white tracking-tight">
                  {{ formatAmount(getCapitalValue('tourism', 'total'), selectedCurrency) }}
                </div>
              </div>
              <div class="text-[9px] text-amber-400/80 font-bold mt-2 flex items-center gap-1">
                <span>عرض البنود الـ 5</span>
                <span>←</span>
              </div>
            </div>

            <!-- الأرباح -->
            <div class="rounded-xl border border-success/10 bg-success/5 p-4 flex flex-col justify-between min-h-[120px]">
              <div>
                <div class="text-[10px] font-bold text-success mb-1">صافي الأرباح</div>
                <div class="font-mono text-2xl font-black text-success tracking-tight">
                  {{ formatAmount(getCapitalValue('tourism', 'profit'), selectedCurrency) }}
                </div>
              </div>
              <div class="text-[9px] text-success/70 mt-2 font-medium">خلال الفترة المحددة</div>
            </div>

            <!-- المصروفات -->
            <div class="rounded-xl border border-error/10 bg-error/5 p-4 flex flex-col justify-between min-h-[120px]">
              <div>
                <div class="text-[10px] font-bold text-error mb-1">مصروفات تشغيلية</div>
                <div class="font-mono text-2xl font-black text-error tracking-tight">
                  {{ formatAmount(getCapitalValue('tourism', 'expense'), selectedCurrency) }}
                </div>
              </div>
              <div class="text-[9px] text-error/70 mt-2 font-medium">
                تكلفة حجوزات: {{ formatAmount(getCapitalValue('tourism', 'cogs'), selectedCurrency) }}
              </div>
            </div>
          </div>
        </div>

        <!-- قطاع المكتب والخدمات -->
        <div class="flight-panel !p-6 border border-sky-500/20 bg-gradient-to-br from-slate-900 via-sky-950/10 to-slate-900 rounded-2xl relative overflow-hidden transition-all hover:border-sky-500/40">
          <div class="absolute -left-6 -bottom-6 text-sky-500/5 text-8xl font-black select-none pointer-events-none">🏢</div>
          
          <div class="mb-5 flex items-center justify-between border-b border-white/5 pb-3">
            <div class="flex items-center gap-2">
              <span class="text-xl">🏢</span>
              <h3 class="text-base font-black text-white font-bold">قطاع المكتب والخدمات</h3>
            </div>
            
            <span class="text-[10px] font-bold text-sky-400 bg-sky-500/10 px-2 py-0.5 rounded-full border border-sky-500/25">
              باص / فوري / خدمات
            </span>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <!-- رأس المال -->
            <div 
              @click="openCapitalDetails('office')" 
              class="cursor-pointer rounded-xl border border-sky-500/10 bg-white/5 p-4 transition-all hover:border-sky-500/40 hover:bg-white/10 group/item relative overflow-hidden flex flex-col justify-between min-h-[120px]"
            >
              <div>
                <div class="text-[10px] font-bold text-sky-400 mb-1 flex items-center gap-1">
                  <span>رأس المال الفعلي</span>
                  <Info class="h-3 w-3 inline opacity-70 group-hover/item:opacity-100" />
                </div>
                <div class="font-mono text-2xl font-black text-white tracking-tight">
                  {{ formatAmount(getCapitalValue('office', 'total'), selectedCurrency) }}
                </div>
              </div>
              <div class="text-[9px] text-sky-400/80 font-bold mt-2 flex items-center gap-1">
                <span>عرض البنود</span>
                <span>←</span>
              </div>
            </div>

            <!-- الأرباح -->
            <div class="rounded-xl border border-success/10 bg-success/5 p-4 flex flex-col justify-between min-h-[120px]">
              <div>
                <div class="text-[10px] font-bold text-success mb-1">صافي الأرباح</div>
                <div class="font-mono text-2xl font-black text-success tracking-tight">
                  {{ formatAmount(getCapitalValue('office', 'profit'), selectedCurrency) }}
                </div>
              </div>
              <div class="text-[9px] text-success/70 mt-2 font-medium">خلال الفترة المحددة</div>
            </div>

            <!-- المصروفات -->
            <div class="rounded-xl border border-error/10 bg-error/5 p-4 flex flex-col justify-between min-h-[120px]">
              <div>
                <div class="text-[10px] font-bold text-error mb-1">مصروفات تشغيلية</div>
                <div class="font-mono text-2xl font-black text-error tracking-tight">
                  {{ formatAmount(getCapitalValue('office', 'expense'), selectedCurrency) }}
                </div>
              </div>
              <div class="text-[9px] text-error/70 mt-2 font-medium">
                تكلفة حجوزات: {{ formatAmount(getCapitalValue('office', 'cogs'), selectedCurrency) }}
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- Modal تفاصيل رأس المال الموزع للقطاع -->
      <div v-if="isCapitalModalOpen && activeCapitalDetails" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-950/80 backdrop-blur-sm animate-in fade-in duration-200">
        <div class="w-full max-w-lg rounded-2xl border border-white/10 bg-slate-900 p-6 shadow-2xl animate-in zoom-in-95 duration-200 text-right">
          <div class="mb-5 flex items-center justify-between border-b border-white/5 pb-3">
            <h3 class="text-base font-black text-white flex items-center gap-2 font-bold">
              <Coins class="h-5 w-5 text-gold" />
              تفاصيل رأس المال: {{ activeCapitalDetails.title }}
            </h3>
            <button @click="isCapitalModalOpen = false" type="button" class="text-text-muted hover:text-white p-1">
              <X class="h-5 w-5" />
            </button>
          </div>

          <div class="space-y-3">
            <p class="text-xs text-text-muted mb-2 font-bold">مكونات رأس المال للعملة <span class="text-gold font-bold">{{ selectedCurrency }}</span>:</p>
            
            <div 
              v-for="(item, idx) in activeCapitalDetails.items" 
              :key="idx"
              :class="[
                'flex items-center justify-between p-3 rounded-xl border transition-all text-sm',
                item.isSubtracted 
                  ? 'border-rose-500/20 bg-rose-500/5 text-rose-300' 
                  : 'border-white/5 bg-white/5 text-text-main'
              ]"
            >
              <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-text-muted font-mono bg-white/5 px-2 py-0.5 rounded">
                  {{ idx + 1 }}
                </span>
                <span class="font-bold">{{ item.label }}</span>
              </div>
              <div class="font-mono font-bold text-left">
                {{ item.isSubtracted ? '-' : '' }}{{ formatAmount(item.value, selectedCurrency) }}
              </div>
            </div>

            <div class="mt-6 border-t border-white/10 pt-4 flex items-center justify-between">
              <div>
                <div class="text-xs font-bold text-text-muted">صافي رأس المال الحالي</div>
                <div class="text-[9px] text-text-muted mt-0.5">المعادلة: (1 + 2 + 3 + 4) - 5</div>
              </div>
              <div class="text-xl font-black text-gold font-mono font-bold">
                {{ formatAmount(activeCapitalDetails.total, selectedCurrency) }}
              </div>
            </div>
          </div>

          <div class="mt-6 flex justify-end">
            <button 
              @click="isCapitalModalOpen = false" 
              type="button" 
              class="btn-airline px-6 py-2 text-xs font-bold"
            >
              إغلاق
            </button>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <div class="flight-panel !p-5 sm:!p-6">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
              <div>
                <h2 class="flight-panel__title mb-1">الحسابات البنكية والخزينة</h2>
                <p class="flight-panel__subtitle">أرصدة الحسابات النشطة (من واجهة الحسابات الفعلية)</p>
              </div>
              <router-link to="/finance/accounts" class="text-sm font-bold text-gold hover:underline">
                عرض الكل
              </router-link>
            </div>

            <div v-if="isLoading()" class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <KPICardSkeleton v-for="i in 4" :key="`acc-${i}`" />
            </div>
            <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <div
                v-for="account in bankAccounts"
                :key="account.id"
                class="group rounded-xl border border-sky-400/20 bg-gradient-to-br from-sky-500/10 to-transparent p-5 transition-all hover:border-sky-400/40"
              >
                <div class="mb-4 flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-sky-500/25 text-sky-200 shadow-inner">
                      <Building2 class="h-5 w-5" />
                    </div>
                    <div>
                      <div class="font-bold text-text-main">{{ account.name }}</div>
                      <div class="text-xs text-text-muted">{{ getAccountTypeLabel(account.type) }}</div>
                    </div>
                  </div>
                  <div class="text-right">
                    <div class="font-mono text-xl font-bold text-text-main">{{ formatCurrency(account.balance) }}</div>
                    <div class="text-xs text-text-muted">{{ account.currency }}</div>
                  </div>
                </div>

              </div>

              <div
                v-for="account in cashAccounts"
                :key="account.id"
                class="group rounded-xl border border-gold/25 bg-gradient-to-br from-gold/10 to-transparent p-5 transition-all hover:border-gold/45"
              >
                <div class="mb-4 flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gold/20 text-gold shadow-inner">
                      <Wallet class="h-5 w-5" />
                    </div>
                    <div>
                      <div class="font-bold text-text-main">{{ account.name }}</div>
                      <div class="text-xs text-text-muted">{{ getAccountTypeLabel(account.type) }}</div>
                    </div>
                  </div>
                  <div class="text-right">
                    <div class="font-mono text-xl font-bold text-text-main">{{ formatCurrency(account.balance) }}</div>
                    <div class="text-xs text-text-muted">{{ account.currency }}</div>
                  </div>
                </div>

              </div>
            </div>
          </div>

          <div class="flight-panel !overflow-hidden !rounded-2xl !p-0">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-white/10 px-5 py-5 sm:px-6">
              <div>
                <h2 class="flight-panel__title mb-1">جميع المعاملات</h2>
                <p class="flight-panel__subtitle">سجل المعاملات من تقرير التقارير المالية (نفس مصدر Filament / API)</p>
              </div>
              <router-link to="/finance/transactions" class="text-sm font-bold text-gold hover:underline">
                عرض الكل
              </router-link>
            </div>

            <div v-if="isLoading()" class="p-5">
              <TableSkeleton :rows="5" :columns="6" />
            </div>
            <div v-else class="overflow-x-auto">
              <table class="w-full border-collapse text-right">
                <thead>
                  <tr class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-text-muted">
                    <th class="px-4 py-3 font-semibold">التاريخ</th>
                    <th class="px-4 py-3 font-semibold">النوع</th>
                    <th class="px-4 py-3 font-semibold">المبلغ</th>
                    <th class="px-4 py-3 font-semibold">الحساب</th>
                    <th class="px-4 py-3 font-semibold">الوصف</th>
                    <th class="px-4 py-3 font-semibold">الوحدة</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(transaction, index) in transactions"
                    :key="index"
                    class="border-b border-white/5 transition-colors hover:bg-white/5"
                  >
                    <td class="px-4 py-3 text-sm text-text-main">{{ formatDate(transaction.created_at) }}</td>
                    <td class="px-4 py-3">
                      <span
                      :class="[
                        'inline-flex rounded-full px-2 py-1 text-[11px] font-bold',
                        flowKindBadgeClass(transaction),
                      ]"
                      >
                        {{ flowKindLabel(transaction) }}
                      </span>
                    </td>
                    <td
                      class="px-4 py-3 font-mono text-sm font-bold"
                      :class="flowKindAmountClass(transaction)"
                    >
                      {{ flowKindPrefix(transaction) }}
                      {{ formatCurrency(transaction.amount) }}
                    </td>
                    <td class="px-4 py-3 text-sm text-text-muted">{{ transaction.account?.name || '-' }}</td>
                    <td class="max-w-[200px] truncate px-4 py-3 text-sm text-text-main" :title="transaction.description">
                      {{ transaction.description }}
                    </td>
                    <td class="px-4 py-3 text-sm text-text-muted">{{ transaction.module_label }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              v-if="pagination.total > pagination.per_page"
              class="flex flex-wrap items-center justify-between gap-4 border-t border-white/10 px-5 py-4 sm:px-6"
            >
              <div class="text-sm text-text-muted">
                عرض {{ (pagination.current_page - 1) * pagination.per_page + 1 }}
                إلى {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }}
                من {{ pagination.total }} معاملة
              </div>
              <div class="flex gap-2">
                <button
                  type="button"
                  class="btn-airline-ghost rounded-xl px-4 py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
                  :disabled="pagination.current_page === 1"
                  @click="goToPage(pagination.current_page - 1)"
                >
                  السابق
                </button>
                <button
                  type="button"
                  class="btn-airline-ghost rounded-xl px-4 py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
                  :disabled="pagination.current_page === pagination.last_page"
                  @click="goToPage(pagination.current_page + 1)"
                >
                  التالي
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="space-y-6">
          <div class="flight-panel !p-5 sm:!p-6">
            <h2 class="flight-panel__title mb-4 flex items-center gap-2">
              <BarChart3 class="h-5 w-5 text-gold" />
              إحصائيات سريعة
            </h2>

            <div v-if="isLoading()" class="space-y-3">
              <TextLineSkeleton :lines="4" heightClass="h-10" />
            </div>
            <div v-else class="space-y-3">
              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 p-3">
                <div class="flex items-center gap-3">
                  <div class="rounded-lg bg-sky-500/15 p-2 text-sky-300">
                    <Building2 class="h-4 w-4" />
                  </div>
                  <span class="text-sm text-text-muted">عدد البنوك</span>
                </div>
                <span class="font-mono font-bold text-text-main">{{ bankAccounts.length }}</span>
              </div>

              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 p-3">
                <div class="flex items-center gap-3">
                  <div class="rounded-lg bg-gold/15 p-2 text-gold">
                    <Wallet class="h-4 w-4" />
                  </div>
                  <span class="text-sm text-text-muted">الخزينة</span>
                </div>
                <span class="font-mono font-bold text-text-main">{{ cashAccounts.length }}</span>
              </div>

              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 p-3">
                <div class="flex items-center gap-3">
                  <div class="rounded-lg bg-violet-500/15 p-2 text-violet-300">
                    <CreditCard class="h-4 w-4" />
                  </div>
                  <span class="text-sm text-text-muted">المحافظ</span>
                </div>
                <span class="font-mono font-bold text-text-main">{{ walletAccounts.length }}</span>
              </div>

              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 p-3">
                <div class="flex items-center gap-3">
                  <div class="rounded-lg bg-white/10 p-2 text-text-muted">
                    <FileText class="h-4 w-4" />
                  </div>
                  <span class="text-sm text-text-muted">المعاملات اليوم</span>
                </div>
                <span class="font-mono font-bold text-text-main">{{ todayTransactionsTotal }}</span>
              </div>
            </div>
          </div>

          <div class="flight-panel !p-5 sm:!p-6">
            <h2 class="flight-panel__title mb-4 flex items-center gap-2">
              <PieChart class="h-5 w-5 text-gold" />
              الدخل مقابل المصروف
            </h2>

            <div v-if="isLoading()" class="space-y-4">
              <ChartSkeleton height="150px" />
            </div>
            <div v-else class="space-y-4">
              <div>
                <div class="mb-2 flex items-center justify-between">
                  <span class="text-sm text-text-muted">الدخل</span>
                  <span class="text-sm font-bold text-success">{{ formatCurrency(stats.total_income) }}</span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-white/10">
                  <div
                    class="h-full rounded-full bg-gradient-to-l from-success to-success/70 transition-all"
                    :style="{ width: `${incomePercentage}%` }"
                  ></div>
                </div>
              </div>

              <div>
                <div class="mb-2 flex items-center justify-between">
                  <span class="text-sm text-text-muted">تكاليف ومصروفات</span>
                  <span class="text-sm font-bold text-error">{{ formatCurrency(stats.total_expense) }}</span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-white/10">
                  <div
                    class="h-full rounded-full bg-gradient-to-l from-error to-error/70 transition-all"
                    :style="{ width: `${expensePercentage}%` }"
                  ></div>
                </div>
              </div>
            </div>
          </div>

          <div class="flight-panel !p-5 sm:!p-6">
            <h2 class="flight-panel__title mb-4 flex items-center gap-2">
              <TrendingUp class="h-5 w-5 text-gold" />
              أكبر الحسابات حسب الرصيد
            </h2>

            <div v-if="isLoading()" class="space-y-3">
              <TextLineSkeleton :lines="5" heightClass="h-12" />
            </div>
            <div v-else class="space-y-3">
              <div
                v-for="(account, index) in topAccounts"
                :key="index"
                class="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 p-3"
              >
                <div class="flex items-center gap-3">
                  <div
                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-gold/30 bg-gold/10 text-sm font-bold text-gold"
                  >
                    {{ index + 1 }}
                  </div>
                  <div>
                    <div class="text-sm font-bold text-text-main">{{ account.name }}</div>
                    <div class="text-xs text-text-muted">{{ getAccountTypeLabel(account.type) }}</div>
                  </div>
                </div>
                <div class="text-right">
                  <div class="font-mono text-sm font-bold text-text-main">{{ formatCurrency(account.balance) }}</div>
                  <div class="text-xs text-text-muted">رصيد حالي</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { useFinanceStore } from '@/stores/financeStore';
import { useAsyncState } from '@/composables/useAsyncState';
import KPICardSkeleton from '@/components/skeletons/KPICardSkeleton.vue';
import TableSkeleton from '@/components/skeletons/TableSkeleton.vue';
import ChartSkeleton from '@/components/skeletons/ChartSkeleton.vue';
import GridSkeleton from '@/components/skeletons/GridSkeleton.vue';
import TextLineSkeleton from '@/components/skeletons/TextLineSkeleton.vue';
import { unwrapAccountItems } from '@/composables/useTreasuryAccountGroups';

import {
  Plus,
  ArrowRightLeft,
  Wallet,
  TrendingUp,
  TrendingDown,
  DollarSign,
  Building2,
  BarChart3,
  PieChart,
  FileText,
  CreditCard,
  Coins,
  X,
  Info,
} from 'lucide-vue-next';

const financeStore = useFinanceStore();
const { state, error, setLoading, setSuccess, setEmpty, setError, isLoading, isSuccess, isEmpty, isError } = useAsyncState('loading');

// Filters
const filters = ref({
  date_from: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
  date_to: new Date().toISOString().split('T')[0],
  account_type: '',
  transaction_type: '',
});

// Data
const accounts = ref([]);
const transactions = ref([]);
const stats = ref({
  total_balance: 0,
  total_income: 0,
  total_cogs: 0,
  total_operating_expenses: 0,
  total_expense: 0,
  net_profit: 0,
});

// Capital Analysis Data
const capitalData = ref({ tourism: {}, office: {}, currencies: [] });
const selectedCurrency = ref('EGP');
const activeCapitalDetails = ref(null);
const isCapitalModalOpen = ref(false);

const todayTransactionsTotal = ref(0);

const pagination = ref({
  total: 0,
  current_page: 1,
  last_page: 1,
  per_page: 15,
});

let fetchController = null;

// Computed
const isProfitable = computed(() => stats.value.net_profit > 0);

const bankAccounts = computed(() => {
  return accounts.value.filter(a => a.type === 'bank');
});

const cashAccounts = computed(() => {
  return accounts.value.filter((a) => a.type === 'treasury' || a.type === 'cashbox');
});

const walletAccounts = computed(() => {
  return accounts.value.filter(a => a.type === 'wallet');
});

const incomePercentage = computed(() => {
  const total = stats.value.total_income + stats.value.total_expense;
  return total > 0 ? ((stats.value.total_income / total) * 100).toFixed(0) : 0;
});

const expensePercentage = computed(() => {
  const total = stats.value.total_income + stats.value.total_expense;
  return total > 0 ? ((stats.value.total_expense / total) * 100).toFixed(0) : 0;
});

const topAccounts = computed(() => {
  return [...accounts.value]
    .sort((a, b) => Number(b.balance ?? 0) - Number(a.balance ?? 0))
    .slice(0, 5);
});

/** تقرير المعاملات: يطابق أعمدة DB + عرض لوحة التحكم */
const normalizeReportTransaction = (row) => {
  const notes = row.notes ?? '';
  let accountSummary = '';
  if (row.type === 'income') {
    accountSummary = row.to_account_name || '';
  } else if (row.type === 'expense' || row.type === 'refund') {
    accountSummary = row.from_account_name || '';
  } else {
    const from = row.from_account_name || '—';
    const to = row.to_account_name || '—';
    accountSummary = `${from} ⟶ ${to}`;
  }
  const mod = row.module;
  const modRow = financeStore.meta.transactionModules?.find((m) => m.value === mod);
  return {
    ...row,
    description: notes,
    account: accountSummary ? { name: accountSummary } : null,
    module_label: modRow?.label || mod || '—',
  };
};

const unwrapPaginatedItems = (body) => {
  const inner = body?.data;
  if (inner && Array.isArray(inner.items)) {
    return {
      items: inner.items,
      pagination: inner.pagination || {},
    };
  }
  return { items: [], pagination: {} };
};

// Methods
const formatCurrency = (amount) => {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount || 0);
};

const formatAmount = (value, currency = 'EGP') => {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency || 'EGP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(value || 0);
};

const getCapitalValue = (sector, key) => {
  const currencyData = capitalData.value[sector]?.[selectedCurrency.value];
  if (!currencyData) return 0;
  if (key === 'profit' || key === 'expense' || key === 'revenue' || key === 'cogs') {
    return currencyData[key] || 0;
  }
  return currencyData.capital?.[key] || 0;
};

const openCapitalDetails = (sector) => {
  const currencyData = capitalData.value[sector]?.[selectedCurrency.value];
  if (!currencyData || !currencyData.capital) return;
  
  const cap = currencyData.capital;
  const isTourism = sector === 'tourism';
  
  const items = isTourism ? [
    { label: 'أرصدة الأنظمة (GDS/NDC)', value: cap.systems, isSubtracted: false },
    { label: 'أرصدة الساينات (خطوط الطيران)', value: cap.carriers, isSubtracted: false },
    { label: 'أرصدة الخزن والتحصيل (نقدي، بنوك، بريد، محافظ)', value: cap.vaults, isSubtracted: false },
    { label: 'مديونيات العملاء (أفراد وشركات)', value: cap.receivables, isSubtracted: false },
    { label: 'مديونيات المجموعات (Flight Groups)', value: cap.payables, isSubtracted: true },
  ] : [
    { label: 'أرصدة أنظمة فوري والخدمات', value: cap.systems, isSubtracted: false },
    { label: 'أرصدة الساينات (غير مفعل)', value: cap.carriers, isSubtracted: false },
    { label: 'أرصدة الخزن والتشغيل للمكتب', value: cap.vaults, isSubtracted: false },
    { label: 'مديونيات العملاء (باصات وخدمات)', value: cap.receivables, isSubtracted: false },
    { label: 'مستحقات شركات الباصات والموردين', value: cap.payables, isSubtracted: true },
  ];

  activeCapitalDetails.value = {
    title: isTourism ? 'قطاع السياحة والطيران' : 'قطاع المكتب والخدمات',
    items,
    total: cap.total,
  };
  isCapitalModalOpen.value = true;
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('ar-EG');
};

const getAccountTypeLabel = (type) => {
  const row = financeStore.meta.accountTypes?.find((t) => t.value === type);
  return row?.label || type;
};

const getTransactionTypeLabel = (type) => {
  const row = financeStore.meta.transactionTypes?.find((t) => t.value === type);
  return row?.label || type;
};

const resolveFlowKind = (transaction) => {
  if (transaction.flow_kind) {
    return transaction.flow_kind;
  }
  if (transaction.type === 'income') {
    return 'inflow';
  }
  if (transaction.type === 'expense' || transaction.type === 'refund') {
    return 'outflow';
  }
  return 'neutral';
};

const flowKindLabel = (transaction) => {
  const kind = resolveFlowKind(transaction);
  if (kind === 'inflow') return 'قبض / إيراد';
  if (kind === 'outflow') return 'صرف / مصروف';
  return getTransactionTypeLabel(transaction.type);
};

const flowKindBadgeClass = (transaction) => {
  const kind = resolveFlowKind(transaction);
  if (kind === 'inflow') return 'bg-success/15 text-success';
  if (kind === 'outflow') return 'bg-error/15 text-error';
  if (transaction.type === 'refund') return 'bg-violet-500/15 text-violet-300';
  return 'bg-gold/10 text-gold';
};

const flowKindAmountClass = (transaction) => {
  const kind = resolveFlowKind(transaction);
  if (kind === 'inflow') return 'text-success';
  if (kind === 'outflow') return 'text-error';
  return 'text-text-main';
};

const flowKindPrefix = (transaction) => {
  const kind = resolveFlowKind(transaction);
  if (kind === 'inflow') return '+';
  if (kind === 'outflow') return '-';
  return '';
};

const applyFilters = async () => {
  pagination.value.current_page = 1;
  await fetchData();
};

const resetFilters = () => {
  filters.value = {
    date_from: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    date_to: new Date().toISOString().split('T')[0],
    account_type: '',
    transaction_type: '',
  };
  pagination.value.current_page = 1;
  fetchData();
};

const goToPage = (page) => {
  pagination.value.current_page = page;
  fetchData();
};

const fetchData = async () => {
  if (fetchController) {
    fetchController.abort();
  }
  fetchController = new AbortController();
  const { signal } = fetchController;

  setLoading();
  try {
    const accountParams = {
      per_page: 100,
      is_active: true,
      _t: Date.now(),
    };
    if (filters.value.account_type) {
      accountParams.account_type = filters.value.account_type;
    }

    const today = new Date().toISOString().split('T')[0];

    const [accountsRes, txRes, summaryRes, balRes, todayTxRes, capitalRes] = await Promise.all([
      axios.get('/api/v1/finance/accounts', { params: accountParams, signal }),
      axios.get('/api/v1/reports/transactions', {
        params: {
          from_date: filters.value.date_from,
          to_date: filters.value.date_to,
          type: filters.value.transaction_type || undefined,
          account_type: filters.value.account_type || undefined,
          page: pagination.value.current_page,
          per_page: pagination.value.per_page,
          _t: Date.now(),
        },
        signal,
      }),
      axios.get('/api/v1/reports/financial/summary', {
        params: {
          from_date: filters.value.date_from,
          to_date: filters.value.date_to,
          _t: Date.now(),
        },
        signal,
      }),
      axios.get('/api/v1/reports/financial/accounts-balance', {
        params: { _t: Date.now() },
        signal,
      }),
      axios.get('/api/v1/reports/transactions', {
        params: {
          from_date: today,
          to_date: today,
          per_page: 1,
          page: 1,
          _t: Date.now(),
        },
        signal,
      }),
      axios.get('/api/v1/reports/capital-analysis', {
        params: {
          from_date: filters.value.date_from,
          to_date: filters.value.date_to,
          _t: Date.now(),
        },
        signal,
      }),
    ]);

    const accountsPayload = accountsRes.data?.data;
    accounts.value = unwrapAccountItems(accountsPayload);

    // Assign capital data
    const cap = capitalRes.data?.data || { tourism: {}, office: {}, currencies: ['EGP'] };
    capitalData.value = cap;
    if (cap.currencies && cap.currencies.length > 0) {
      if (!cap.currencies.includes(selectedCurrency.value)) {
        selectedCurrency.value = cap.currencies[0];
      }
    }

    const { items, pagination: pag } = unwrapPaginatedItems(txRes.data);
    transactions.value = items.map(normalizeReportTransaction);

    const p = pag || {};
    pagination.value = {
      total: Number(p.total) || transactions.value.length,
      current_page: Number(p.current_page) || pagination.value.current_page,
      last_page: Number(p.last_page) || 1,
      per_page: Number(p.per_page) || pagination.value.per_page,
    };

    const s = summaryRes.data?.data || {};
    const liquidityTotal =
      accountsPayload?.stats?.total_balance ??
      balRes.data?.data?.grand_total ??
      accounts.value.reduce((sum, acc) => sum + (Number(acc.balance) || 0), 0);

    const { pagination: todayPag } = unwrapPaginatedItems(todayTxRes.data);
    todayTransactionsTotal.value = Number(todayPag.total) || 0;

    stats.value = {
      total_balance: Number(liquidityTotal) || 0,
      total_income: Number(s.total_income) || 0,
      total_cogs: Number(s.total_cogs) || 0,
      total_operating_expenses: Number(s.total_operating_expenses) || 0,
      total_expense: Number(s.total_expense) || 0,
      net_profit: Number(s.net_profit) || 0,
    };
    
    setSuccess();
  } catch (error) {
    if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Failed to fetch finance data:', error);
    setError(error);
    accounts.value = [];
    transactions.value = [];
    stats.value = {
      total_balance: 0,
      total_income: 0,
      total_cogs: 0,
      total_operating_expenses: 0,
      total_expense: 0,
      net_profit: 0,
    };
    todayTransactionsTotal.value = 0;
  }
};

let pollingInterval = null;

onMounted(async () => {
  await financeStore.fetchSettingsMeta();
  await fetchData();
  
  // Auto-refresh every 15 seconds to fetch new financial data without manual reload
  pollingInterval = setInterval(async () => {
    if (!isLoading()) {
      await fetchData();
    }
  }, 15000);
});

onBeforeUnmount(() => {
  if (fetchController) {
    fetchController.abort();
  }
  if (pollingInterval) {
    clearInterval(pollingInterval);
  }
});
</script>
