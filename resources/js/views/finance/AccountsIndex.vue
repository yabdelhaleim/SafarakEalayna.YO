<template>
  <div class="finance-dashboard flight-booking animate-in pb-10 fade-in duration-700">
    <!-- Header & Actions -->
    <header class="flight-hero relative overflow-hidden">
      <div class="relative z-10 mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:flex-row lg:items-end lg:justify-between lg:px-8">
        <div class="min-w-0 flex-1">
          <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-sky-400/90">الخزينة والمحاسبة</p>
          <h1 class="mt-1 text-3xl font-black tracking-tight text-text-main sm:text-4xl">
            الحسابات والخزائن
          </h1>
          <p class="mt-2 max-w-2xl text-base font-bold leading-relaxed text-text-muted">
            كل خزائن وبنوك ومحافظ الموديولات (طيران، باص، فوري، حج، فيزا…) — نفس بيانات Filament Admin.
          </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-3">
          <button
            type="button"
            class="btn-airline gap-2 shadow-xl"
            @click="showCreateModal = true"
          >
            <Plus class="h-5 w-5" />
            إضافة حساب جديد
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8 mt-8 accounts-page">
      <!-- Division Tabs -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap gap-2 rounded-2xl bg-white/[0.03] p-1.5 border border-white/5">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            class="flex items-center gap-2 rounded-xl px-5 py-3 text-base font-black transition-all"
            :class="activeTab === tab.id
              ? 'bg-gold text-slate-900 shadow-lg shadow-gold/20'
              : 'text-text-muted hover:bg-white/5 hover:text-text-main'"
            @click="setActiveTab(tab.id)"
          >
            <component :is="tab.icon" class="h-5 w-5" />
            {{ tab.name }}
          </button>
        </div>

        <div class="flex items-center gap-3">
          <button
            type="button"
            class="rounded-xl border border-white/10 p-3 text-text-muted transition-colors hover:border-gold/30 hover:text-gold"
            title="تحديث البيانات"
            @click="fetchAccounts"
          >
            <RefreshCw class="h-5 w-5" :class="{ 'animate-spin': isLoading() }" />
          </button>
          <button
            type="button"
            class="btn-airline flex items-center gap-2 px-6 py-3 text-base font-black shadow-xl shadow-gold/10"
            @click="showCreateModal = true"
          >
            <Plus class="h-5 w-5" />
            إضافة حساب / خزينة
          </button>
        </div>
      </div>

      <!-- KPI Summary -->
      <div v-if="isLoading()" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KPICardSkeleton v-for="i in 4" :key="`kpi-${i}`" />
      </div>
      <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="dashboard-kpi">
          <div class="mb-3 flex items-center justify-between">
            <div class="dashboard-kpi__icon"><DollarSign class="h-6 w-6" /></div>
            <span class="rounded-lg bg-gold/15 px-3 py-1 text-sm font-black text-gold">سيولة</span>
          </div>
          <p class="text-sm font-black text-text-muted">إجمالي السيولة المتاحة</p>
          <p class="mt-2 font-mono text-3xl font-black text-text-main">{{ formatCurrency(dbStats.total_balance, 'EGP') }}</p>
        </div>
        <div class="dashboard-kpi">
          <div class="mb-3 flex items-center justify-between">
            <div class="dashboard-kpi__icon"><Activity class="h-6 w-6" /></div>
            <span class="rounded-lg bg-success/15 px-3 py-1 text-sm font-black text-success">نشط</span>
          </div>
          <p class="text-sm font-black text-text-muted">الخزائن النشطة</p>
          <p class="mt-2 font-mono text-3xl font-black text-success">{{ dbStats.active_count }}</p>
        </div>
        <div class="dashboard-kpi">
          <div class="mb-3 flex items-center justify-between">
            <div class="dashboard-kpi__icon"><Plane class="h-6 w-6" /></div>
          </div>
          <p class="text-sm font-black text-text-muted">حسابات السياحة</p>
          <p class="mt-2 font-mono text-3xl font-black text-sky-300">{{ tourismCount }}</p>
        </div>
        <div class="dashboard-kpi">
          <div class="mb-3 flex items-center justify-between">
            <div class="dashboard-kpi__icon"><LayoutGrid class="h-6 w-6" /></div>
          </div>
          <p class="text-sm font-black text-text-muted">حسابات المكتب</p>
          <p class="mt-2 font-mono text-3xl font-black text-violet-300">{{ officeCount }}</p>
        </div>
      </div>

      <!-- Deficit Alerts -->
      <div v-if="hasDeficits" class="flight-panel border-error/30 bg-error/10 !p-5">
        <h3 class="mb-4 flex items-center gap-2 text-lg font-black text-error">
          <AlertTriangle class="h-5 w-5" />
          تنبيهات العجز
        </h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div
            v-for="acc in deficitAccounts"
            :key="acc.id"
            class="flex items-center justify-between rounded-xl border border-error/20 bg-black/20 px-4 py-3"
          >
            <span class="text-base font-black text-text-main">{{ acc.name }}</span>
            <span class="font-mono text-base font-black text-error">{{ formatCurrency(acc.balance) }}</span>
          </div>
        </div>
      </div>

      <!-- Portfolio + Recent Activity -->
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="flight-panel !p-6">
          <h3 class="mb-5 text-lg font-black text-text-main">توزيع المحفظة المالية</h3>
          <div v-if="dbStats.total_balance > 0" class="space-y-5">
            <div v-for="(val, type) in dbStats.liquidity" :key="type">
              <div class="mb-2 flex items-center justify-between gap-3">
                <span class="text-base font-black text-text-main">{{ getTypeLabel(type) }}</span>
                <div class="text-left">
                  <span class="block font-mono text-base font-black text-gold">{{ formatCurrency(val) }}</span>
                  <span class="text-sm font-bold text-text-muted">{{ getLiquidityPercent(type).toFixed(1) }}%</span>
                </div>
              </div>
              <div class="h-3 w-full overflow-hidden rounded-full bg-white/5">
                <div
                  class="h-full rounded-full transition-all duration-700"
                  :class="type === 'cashbox' ? 'bg-gold' : type === 'bank' ? 'bg-success' : type === 'wallet' ? 'bg-sky-400' : 'bg-violet-400'"
                  :style="{ width: getLiquidityPercent(type) + '%' }"
                />
              </div>
            </div>
          </div>
          <p v-else class="py-8 text-center text-base font-bold text-text-muted">لا توجد بيانات سيولة</p>
        </div>

        <div class="flight-panel !p-0 overflow-hidden">
          <div class="flex items-center justify-between border-b border-white/5 bg-white/[0.02] px-5 py-4">
            <h3 class="flex items-center gap-2 text-lg font-black text-text-main">
              <RefreshCw class="h-5 w-5 text-sky-400" />
              آخر العمليات
            </h3>
          </div>
          <div v-if="isLoading()" class="p-5">
            <TextLineSkeleton :lines="5" heightClass="h-14" gapClass="gap-3" />
          </div>
          <div v-else class="max-h-[420px] overflow-y-auto">
            <p v-if="!recentTransactions.length" class="p-8 text-center text-base font-bold text-text-muted">
              لا توجد حركات مسجلة مؤخراً
            </p>
            <div
              v-for="tx in recentTransactions"
              :key="tx.id"
              class="border-b border-white/5 px-5 py-4 last:border-0 hover:bg-white/[0.02]"
            >
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                  <p class="text-base font-black leading-snug text-text-main">{{ tx.notes || 'معاملة مالية' }}</p>
                  <p class="mt-1 text-sm font-bold text-text-muted">{{ formatDate(tx.created_at) }}</p>
                  <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="rounded-lg bg-white/5 px-2.5 py-1 text-sm font-black text-gold">
                      {{ getModuleLabel(tx.module) }}
                    </span>
                    <span class="text-sm font-bold text-text-muted">{{ tx.created_by_name || 'النظام' }}</span>
                  </div>
                </div>
                <span
                  class="shrink-0 font-mono text-lg font-black"
                  :class="flowKindAmountClass(tx)"
                >
                  {{ flowKindPrefix(tx) }}{{ formatCurrency(tx.amount) }}
                </span>
              </div>
            </div>
          </div>
          <div class="border-t border-white/5 bg-white/[0.02] px-5 py-3 text-center">
            <router-link to="/finance/transactions" class="text-base font-black text-gold hover:underline">
              عرض السجل الكامل
            </router-link>
          </div>
        </div>
      </div>

      <!-- Section Profitability -->
      <div v-if="!isLoading() && Object.keys(dbStats.performance).length" class="flight-panel !p-6">
        <h3 class="mb-5 flex items-center gap-2 text-lg font-black text-text-main">
          <Activity class="h-5 w-5 text-gold" />
          ربحية الأقسام
        </h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <div
            v-for="(perf, mod) in dbStats.performance"
            :key="mod"
            v-show="perf.profit !== 0 || perf.income !== 0"
            class="rounded-2xl border border-white/5 bg-white/[0.02] p-5"
          >
            <div class="mb-4 flex items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <div class="rounded-xl bg-gold/10 p-2.5 text-gold">
                  <component :is="getModuleIcon(mod)" class="h-5 w-5" />
                </div>
                <div>
                  <p class="text-sm font-black text-text-muted">قسم {{ getModuleLabel(mod) }}</p>
                  <p class="text-base font-black text-text-main">صافي الربح</p>
                </div>
              </div>
              <span
                class="font-mono text-xl font-black"
                :class="perf.profit >= 0 ? 'text-success' : 'text-error'"
              >
                {{ formatCurrency(perf.profit) }}
              </span>
            </div>
            <div class="grid grid-cols-2 gap-3 border-t border-white/5 pt-4">
              <div class="rounded-xl bg-success/10 p-3">
                <p class="text-sm font-black text-success/80">المبيعات</p>
                <p class="mt-1 font-mono text-base font-black text-text-main">{{ formatCurrency(perf.income) }}</p>
              </div>
              <div class="rounded-xl bg-error/10 p-3">
                <p class="text-sm font-black text-error/80">التكاليف</p>
                <p class="mt-1 font-mono text-base font-black text-text-main">{{ formatCurrency(perf.expense) }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Accounts Ledger -->
      <div class="flight-panel !p-0 overflow-hidden">
        <div class="border-b border-white/5 px-5 py-5 sm:px-6">
          <h3 class="mb-1 flex items-center gap-2 text-lg font-black text-text-main">
            <LayoutGrid class="h-5 w-5 text-gold" />
            بيان أرصدة الخزائن والبنوك
          </h3>
          <p class="text-sm font-bold text-text-muted">فلترة وعرض كل حسابات السيولة حسب القسم والنوع</p>

          <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <div class="relative sm:col-span-2 xl:col-span-2">
              <Search class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-text-muted" />
              <input
                v-model="filters.search"
                type="text"
                placeholder="البحث باسم الحساب أو رقم المحفظة..."
                class="acc-filter-select w-full !py-3 !pl-11"
                @input="debouncedSearch"
              />
            </div>

            <select v-model="filters.type" class="acc-filter-select" @change="onFilterChange">
              <option value="">كل أنواع الحسابات</option>
              <option v-for="t in financeStore.meta.accountTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>

            <select v-model="filters.currency" class="acc-filter-select" @change="onFilterChange">
              <option value="">كل العملات</option>
              <option v-for="c in financeStore.meta.currencies" :key="c.code" :value="c.code">{{ c.name }} ({{ c.code }})</option>
            </select>

            <select v-model="filters.module_type" class="acc-filter-select" @change="onModuleTypeFilterChange">
              <option value="">كل الأقسام</option>
              <option v-for="opt in moduleTypeFilterOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>

            <select v-model="filters.module" class="acc-filter-select" @change="onFilterChange">
              <option value="">كل الموديولات</option>
              <option v-for="m in availableModules" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>

            <select v-model="filters.is_active" class="acc-filter-select" @change="onFilterChange">
              <option value="">الحالة (الكل)</option>
              <option value="1">نشط</option>
              <option value="0">غير نشط</option>
            </select>

            <button type="button" class="acc-filter-clear" @click="clearFilters">
              <RefreshCw class="h-4 w-4" />
              مسح الفلاتر
            </button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="acc-table w-full border-collapse">
            <thead>
              <tr class="border-b border-white/10 bg-white/5">
                <th class="px-5 py-4 text-right sm:px-6">اسم الحساب</th>
                <th class="px-5 py-4 text-right sm:px-6">النوع</th>
                <th class="px-5 py-4 text-right sm:px-6">العملة</th>
                <th class="px-5 py-4 text-right sm:px-6">الموديول</th>
                <th class="px-5 py-4 text-right sm:px-6">الرصيد</th>
                <th class="px-5 py-4 text-right sm:px-6">القسم</th>
                <th class="px-5 py-4 text-right sm:px-6">الحالة</th>
                <th class="px-5 py-4 text-right sm:px-6">الإجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-if="isLoading()">
                <td colspan="9" class="py-10 bg-white/[0.01]">
                   <TableSkeleton :rows="5" :columns="9" />
                </td>
              </tr>
              <tr v-else-if="state === 'error' || storeError">
                <td colspan="9" class="px-6 py-10 text-center text-error bg-error/5 border-error/10">{{ storeError || 'حدث خطأ.' }}</td>
              </tr>
              <tr v-else-if="filteredAccounts.length === 0">
                <td colspan="9" class="px-6 py-20 text-center text-text-muted bg-white/[0.01]">
                  <div class="flex flex-col items-center gap-4">
                    <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center">
                      <Search class="w-8 h-8 opacity-20" />
                    </div>
                    <p class="text-lg">لا توجد حسابات مطابقة لهذه الفلاتر.</p>
                    <button @click="clearFilters" class="text-gold hover:underline font-bold">عرض جميع الحسابات</button>
                  </div>
                </td>
              </tr>
              <tr
                v-for="(acc, idx) in filteredAccounts"
                :key="acc.id"
                class="cursor-pointer transition-all hover:bg-gold/[0.03] group animate-in slide-in-from-right duration-300"
                :style="{ animationDelay: `${idx * 40}ms` }"
                @click="viewStatement(acc.id)"
              >
                <td class="px-5 py-5 sm:px-6">
                  <div class="flex flex-col">
                    <span class="text-base font-black text-text-main group-hover:text-gold transition-colors">{{ acc.name }}</span>
                    <span v-if="acc.owner_type && acc.owner_type !== 'office'" class="mt-1 inline-flex items-center gap-1 text-sm font-bold text-sky-400">
                      <div class="h-1.5 w-1.5 rounded-full bg-current"></div>
                      {{ acc.owner_type === 'customer' ? 'حساب عميل' : (acc.owner_type === 'supplier' ? 'حساب مورد' : acc.owner_type) }}
                    </span>
                    <span class="text-sm font-bold text-text-muted font-mono" v-if="acc.wallet_number"># {{ acc.wallet_number }}</span>
                  </div>
                </td>
                <td class="px-5 py-4 text-base font-bold text-text-muted sm:px-6">
                  <div class="flex items-center gap-2">
                    <div :class="['h-2 w-2 rounded-full', getTypeColor(acc.type)]"></div>
                    {{ getTypeLabel(acc.type) }}
                  </div>
                </td>
                <td class="px-5 py-4 text-base font-black text-text-muted sm:px-6">
                  {{ acc.currency }}
                </td>
                <td class="px-5 py-4 sm:px-6">
                  <span v-if="acc.module" class="inline-flex items-center gap-1 rounded-lg border border-gold/20 bg-white/5 px-3 py-1 text-sm font-black text-gold">
                    {{ getModuleLabel(acc.module) }}
                  </span>
                  <span v-else class="text-sm font-bold text-text-muted">عام</span>
                </td>
                <td class="px-5 py-4 sm:px-6">
                  <span
                    class="font-mono text-lg font-black"
                    :class="acc.balance >= 0 ? 'text-success' : 'text-error'"
                  >
                    {{ formatCurrency(acc.balance, acc.currency) }}
                  </span>
                </td>
                <td class="px-5 py-4 sm:px-6 text-right">
                  <span class="inline-flex items-center gap-1 rounded-lg border border-sky-400/25 bg-sky-500/10 px-3 py-1.5 text-sm font-black text-sky-200">
                    {{ getModuleTypeLabel(acc.module_type) }}
                  </span>
                </td>
                <td class="px-5 py-4 sm:px-6">
                  <span
                    :class="
                      acc.is_active
                        ? 'inline-flex items-center gap-2 rounded-full border border-success/30 bg-success/10 px-3 py-1.5 text-sm font-black text-success'
                        : 'inline-flex items-center gap-2 rounded-full border border-error/30 bg-error/10 px-3 py-1.5 text-sm font-black text-error'
                    "
                  >
                    <span :class="['h-2 w-2 rounded-full bg-current', acc.is_active ? 'animate-pulse' : '']"></span>
                    {{ acc.is_active ? 'نشط' : 'غير نشط' }}
                  </span>
                </td>
                <td class="px-5 py-4 text-base sm:px-6 text-left">
                  <div class="flex items-center justify-end gap-2">
                    <button
                      type="button"
                      class="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-black text-sky-400 transition-all hover:bg-sky-500/10"
                      @click.stop="viewStatement(acc.id)"
                    >
                      <FileText class="h-4 w-4" />
                      كشف
                    </button>
                    <button
                      type="button"
                      class="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-black text-gold transition-all hover:bg-gold/10"
                      @click.stop="openEdit(acc)"
                    >
                      <Pen class="h-4 w-4" />
                      تعديل
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Create Account Modal -->
    <teleport to="body">
      <div
        v-if="showCreateModal"
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/75 p-4 backdrop-blur-md animate-in fade-in duration-300"
        @click.self="showCreateModal = false"
      >
        <div
          class="flight-panel max-h-[90vh] w-full max-w-lg overflow-y-auto !p-8 shadow-[0_0_50px_rgba(0,0,0,0.5)] border-white/10 animate-in zoom-in-95 duration-300"
          role="dialog"
          aria-labelledby="create-account-heading"
          @click.stop
        >
          <div class="flex items-center justify-between mb-8">
             <h3 id="create-account-heading" class="text-2xl font-black text-text-main flex items-center gap-3">
              <div class="p-2 bg-gold/10 rounded-xl text-gold">
                <Plus class="w-6 h-6" />
              </div>
              إضافة حساب جديد
            </h3>
            <button @click="showCreateModal = false" class="p-2 hover:bg-white/5 rounded-full transition-colors text-text-muted">
              <X class="w-6 h-6" />
            </button>
          </div>

          <form class="space-y-6" @submit.prevent="createAccount">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div class="md:col-span-2">
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">اسم الحساب (مثلاً: خزينة محمد، بنك CIB، محفظة فودافون)</label>
                <input v-model="newAccount.name" type="text" required class="flight-input w-full" placeholder="ادخل اسماً مميزاً..." />
              </div>
              
              <div>
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">نوع الحساب</label>
                <select v-model="newAccount.type" required class="flight-select w-full" @change="onNewTypeChange">
                  <option v-for="t in financeStore.meta.accountTypes" :key="t.value" :value="t.value">
                    {{ t.label }}
                  </option>
                </select>
              </div>

              <div>
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">العملة الأساسية</label>
                <select v-model="newAccount.currency" required class="flight-select w-full">
                  <option v-for="c in financeStore.meta.currencies" :key="c.code" :value="c.code">
                    {{ c.name }} ({{ c.code }})
                  </option>
                </select>
              </div>

              <template v-if="newAccount.type === 'wallet'">
                <div>
                  <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">مزود المحفظة</label>
                  <select v-model="newAccount.wallet_provider" required class="flight-select w-full">
                    <option value="" disabled>اختر نوع المحفظة</option>
                    <option value="vodafone_cash">فودافون كاش</option>
                    <option value="instapay">إنستاباي</option>
                    <option value="etisalat_cash">اتصالات كاش</option>
                    <option value="orange_cash">أورانج كاش</option>
                    <option value="we_pay">WE Pay</option>
                    <option value="paymob">Paymob</option>
                    <option value="cash_wallet">محفظة كاش (عام)</option>
                    <option value="postal">بريد / مصاري</option>
                    <option value="other">أخرى</option>
                  </select>
                </div>
                <div>
                  <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">رقم المحفظة / الحساب</label>
                  <input v-model="newAccount.wallet_number" type="text" required class="flight-input w-full" placeholder="رقم الهاتف أو الحساب..." />
                </div>
              </template>

              <div>
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">القسم / الموديول (Filament)</label>
                <select v-model="newAccount.module_type" required class="flight-select w-full" @change="onNewModuleTypeChange">
                  <option v-for="opt in moduleTypeFilterOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
              </div>

              <div>
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">الموديول المختص</label>
                <select v-model="newAccount.module" class="flight-select w-full">
                  <option value="">عام (لا يتبع موديول محدد)</option>
                  <option v-for="m in newAccountAvailableModules" :key="m.value" :value="m.value">
                    {{ m.label }}
                  </option>
                </select>
              </div>

              <div>
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">ملكية الحساب</label>
                <select v-model="newAccount.owner_type" required class="flight-select w-full">
                  <option value="owner">مالك (عهدة شخصية)</option>
                  <option value="office">مكتب (خزينة عامة للمقر)</option>
                </select>
              </div>

              <div>
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">الرصيد الافتتاحي</label>
                <input v-model.number="newAccount.balance" type="number" step="0.01" min="0" required class="flight-input w-full font-mono font-bold" />
              </div>
            </div>

            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">ملاحظات إضافية</label>
              <textarea v-model="newAccount.notes" rows="3" class="flight-input w-full resize-none" placeholder="أي تفاصيل أخرى عن الحساب..."></textarea>
            </div>

            <div class="mt-8 flex gap-4">
              <button
                type="submit"
                :disabled="loading"
                class="btn-airline flex-1 py-4 text-sm font-black disabled:opacity-45 shadow-lg shadow-gold/20"
              >
                {{ loading ? 'جاري الحفظ…' : 'تأكيد الحفظ وإنشاء الحساب' }}
              </button>
              <button
                type="button"
                class="btn-airline-ghost rounded-xl px-6 py-4 text-sm font-bold border-white/10 hover:bg-white/10"
                @click="showCreateModal = false"
              >
                إلغاء
              </button>
            </div>
          </form>
        </div>
      </div>
    </teleport>

  <!-- Edit Account Modal -->
  <teleport to="body">
    <div
      v-if="showEditModal"
      class="fixed inset-0 z-[200] flex items-center justify-center bg-black/75 p-4 backdrop-blur-md animate-in fade-in duration-300"
      @click.self="showEditModal = false"
    >
      <div
        class="flight-panel max-h-[90vh] w-full max-w-lg overflow-y-auto !p-8 shadow-[0_0_50px_rgba(0,0,0,0.5)] border-white/10 animate-in zoom-in-95 duration-300"
        role="dialog"
        aria-labelledby="edit-account-heading"
        @click.stop
      >
        <div class="flex items-center justify-between mb-8">
           <h3 id="edit-account-heading" class="text-2xl font-black text-text-main flex items-center gap-3">
            <div class="p-2 bg-gold/10 rounded-xl text-gold">
              <Pen class="w-6 h-6" />
            </div>
            تعديل بيانات الحساب
          </h3>
          <button @click="showEditModal = false" class="p-2 hover:bg-white/5 rounded-full transition-colors text-text-muted">
            <X class="w-6 h-6" />
          </button>
        </div>

        <form v-if="editAccount" class="space-y-6" @submit.prevent="saveAccount">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="md:col-span-2">
              <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">اسم الحساب (مثلاً: خزينة محمد، بنك CIB، محفظة فودافون)</label>
              <input v-model="editAccount.name" type="text" required class="flight-input w-full" placeholder="ادخل اسماً مميزاً..." />
            </div>
            
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">نوع الحساب</label>
              <select v-model="editAccount.type" required class="flight-select w-full" @change="onEditTypeChange">
                <option v-for="t in financeStore.meta.accountTypes" :key="t.value" :value="t.value">
                  {{ t.label }}
                </option>
              </select>
            </div>

            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">العملة الأساسية</label>
              <select v-model="editAccount.currency" required class="flight-select w-full">
                <option v-for="c in financeStore.meta.currencies" :key="c.code" :value="c.code">
                  {{ c.name }} ({{ c.code }})
                </option>
              </select>
            </div>

            <template v-if="editAccount.type === 'wallet'">
              <div>
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">مزود المحفظة</label>
                <select v-model="editAccount.wallet_provider" required class="flight-select w-full">
                  <option value="" disabled>اختر نوع المحفظة</option>
                  <option value="vodafone_cash">فودافون كاش</option>
                  <option value="instapay">إنستاباي</option>
                  <option value="etisalat_cash">اتصالات كاش</option>
                  <option value="orange_cash">أورانج كاش</option>
                  <option value="we_pay">WE Pay</option>
                  <option value="paymob">Paymob</option>
                  <option value="cash_wallet">محفظة كاش (عام)</option>
                  <option value="postal">بريد / مصاري</option>
                  <option value="other">أخرى</option>
                </select>
              </div>
              <div>
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">رقم المحفظة / الحساب</label>
                <input v-model="editAccount.wallet_number" type="text" required class="flight-input w-full" placeholder="رقم الهاتف أو الحساب..." />
              </div>
            </template>

            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">القسم / الموديول (Filament)</label>
              <select v-model="editAccount.module_type" required class="flight-select w-full" @change="onEditModuleTypeChange">
                <option v-for="opt in moduleTypeFilterOptions" :key="`edit-${opt.value}`" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </div>

            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">الموديول المختص</label>
              <select v-model="editAccount.module" class="flight-select w-full">
                <option value="">عام (لا يتبع موديول محدد)</option>
                <option v-for="m in editAccountAvailableModules" :key="m.value" :value="m.value">
                  {{ m.label }}
                </option>
              </select>
            </div>

            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">ملكية الحساب</label>
              <select v-model="editAccount.owner_type" required class="flight-select w-full">
                <option value="owner">مالك (عهدة شخصية)</option>
                <option value="office">مكتب (خزينة عامة للمقر)</option>
              </select>
            </div>

            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">حالة الحساب</label>
              <select v-model="editAccount.is_active" required class="flight-select w-full">
                <option :value="true">نشط</option>
                <option :value="false">غير نشط</option>
              </select>
            </div>
          </div>

          <div>
            <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">ملاحظات إضافية</label>
            <textarea v-model="editAccount.notes" rows="3" class="flight-input w-full resize-none" placeholder="أي تفاصيل أخرى عن الحساب..."></textarea>
          </div>

          <div class="mt-8 flex gap-4">
            <button
              type="submit"
              :disabled="loading"
              class="btn-airline flex-1 py-4 text-sm font-black disabled:opacity-45 shadow-lg shadow-gold/20"
            >
              {{ loading ? 'جاري الحفظ…' : 'حفظ التعديلات' }}
            </button>
            <button
              type="button"
              class="btn-airline-ghost rounded-xl px-6 py-4 text-sm font-bold border-white/10 hover:bg-white/10"
              @click="showEditModal = false"
            >
              إلغاء
            </button>
          </div>
        </form>
      </div>
    </div>
  </teleport>
</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import axios from 'axios'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAccountStore } from '@/stores/accountStore'
import { useAuthStore } from '@/stores/authStore'
import { useFinanceStore } from '@/stores/financeStore'
import { debounce } from 'lodash-es'
import StatCard from '@/components/dashboard/StatCard.vue'
import {
  Plus,
  Search,
  RefreshCw,
  Plane,
  Activity,
  LayoutGrid,
  DollarSign,
  AlertTriangle,
  FileText,
  Pen,
  X,
} from 'lucide-vue-next'
import { useAsyncState } from '@/composables/useAsyncState';
import {
  getModuleTypeLabel,
  MODULE_TYPE_FILTER_OPTIONS,
  normalizeAccountModulePayload,
  TOURISM_MODULE_TYPES,
  OFFICE_MODULE_TYPES,
} from '@/composables/useTreasuryAccountGroups';
import KPICardSkeleton from '@/components/skeletons/KPICardSkeleton.vue';
import TableSkeleton from '@/components/skeletons/TableSkeleton.vue';
import ChartSkeleton from '@/components/skeletons/ChartSkeleton.vue';
import GridSkeleton from '@/components/skeletons/GridSkeleton.vue';
import TextLineSkeleton from '@/components/skeletons/TextLineSkeleton.vue';

const router = useRouter()
const accountStore = useAccountStore()
const authStore = useAuthStore()
const financeStore = useFinanceStore()

const activeTab = ref('all')
const showCreateModal = ref(false)
const showEditModal = ref(false)
const editAccount = ref(null)

const filters = ref({
  search: '',
  type: '',
  module: '',
  currency: '',
  is_active: '',
  module_type: '',
})

const moduleTypeFilterOptions = [
  { value: 'tourism', label: 'كل حسابات السياحة' },
  { value: 'office', label: 'كل حسابات المكتب' },
  ...MODULE_TYPE_FILTER_OPTIONS,
]

const availableModules = computed(() => {
  const modules = financeStore.meta.transactionModules || []
  const mt = filters.value.module_type || (activeTab.value === 'all' ? '' : activeTab.value)

  if (!mt) return modules

  if (mt === 'tourism' || TOURISM_MODULE_TYPES.includes(mt)) {
    return modules.filter((m) => ['flight', 'hajj_umra', 'visa'].includes(m.value))
  }
  if (mt === 'office' || OFFICE_MODULE_TYPES.includes(mt)) {
    return modules.filter((m) => ['bus', 'wallet', 'wallet_transfer', 'online', 'fawry', 'general', 'service'].includes(m.value))
  }
  return modules
})

const newAccountAvailableModules = computed(() => {
  const modules = financeStore.meta.transactionModules || []
  const mt = newAccount.value.module_type

  if (TOURISM_MODULE_TYPES.includes(mt)) {
    return modules.filter((m) => ['flight', 'hajj_umra', 'visa'].includes(m.value))
  }
  if (OFFICE_MODULE_TYPES.includes(mt) || mt === 'office') {
    return modules.filter((m) => ['bus', 'wallet', 'wallet_transfer', 'online', 'fawry', 'general', 'service'].includes(m.value))
  }
  return modules
})

const editAccountAvailableModules = computed(() => {
  const modules = financeStore.meta.transactionModules || []
  if (!editAccount.value) return modules
  const mt = editAccount.value.module_type

  if (TOURISM_MODULE_TYPES.includes(mt)) {
    return modules.filter((m) => ['flight', 'hajj_umra', 'visa'].includes(m.value))
  }
  if (OFFICE_MODULE_TYPES.includes(mt) || mt === 'office') {
    return modules.filter((m) => ['bus', 'wallet', 'wallet_transfer', 'online', 'fawry', 'general', 'service'].includes(m.value))
  }
  return modules
})

function getModuleIcon(mod) {
  const icons = {
    flight: Plane,
    bus: Activity,
    visa: FileText,
    hajj_umra: LayoutGrid,
    online: Activity,
    fawry: RefreshCw,
    general: LayoutGrid,
  }
  return icons[mod] || LayoutGrid
}

function getLiquidityPercent(type) {
  if (!dbStats.value.total_balance) return 0
  const val = dbStats.value.liquidity[type] || 0
  return (val / dbStats.value.total_balance) * 100
}

const tabs = [
  { id: 'all', name: 'جميع الحسابات', icon: LayoutGrid },
  { id: 'tourism', name: 'حسابات السياحة', icon: Plane },
  { id: 'office', name: 'حسابات المكتب', icon: Activity },
]

const newAccountDefaults = () => ({
  name: '',
  type: 'cashbox',
  currency: 'EGP',
  module_type: 'general',
  module: '',
  owner_type: 'office',
  balance: 0,
  notes: '',
  wallet_provider: 'vodafone_cash',
  wallet_number: '',
})

const newAccount = ref(newAccountDefaults())

const { state, setLoading, setSuccess, setEmpty, setError, isLoading, isSuccess, isEmpty } = useAsyncState('loading');

const { accounts, error: storeError, totalBalance, tourismCount, officeCount, activeAccountsCount, dbStats } =
  storeToRefs(accountStore)

const recentTransactions = computed(() => dbStats.value.recent_transactions || [])
const deficitAccounts = computed(() => dbStats.value.deficit_accounts || [])
const hasDeficits = computed(() => deficitAccounts.value.length > 0)

const filteredAccounts = computed(() => {
  return Array.isArray(accounts.value) ? accounts.value : []
})

const debouncedSearch = debounce(() => {
  fetchAccounts()
}, 300)

function getModuleLabel(val) {
  return financeStore.meta.transactionModules?.find(m => m.value === val)?.label || val
}

const resolveFlowKind = (transaction) => {
  if (transaction.flow_kind) {
    return transaction.flow_kind
  }
  if (transaction.type === 'income') {
    return 'inflow'
  }
  if (transaction.type === 'expense' || transaction.type === 'refund') {
    return 'outflow'
  }
  return 'neutral'
}

const flowKindAmountClass = (transaction) => {
  const kind = resolveFlowKind(transaction)
  if (kind === 'inflow') return 'text-success'
  if (kind === 'outflow') return 'text-error'
  return 'text-sky-300'
}

const flowKindPrefix = (transaction) => {
  const kind = resolveFlowKind(transaction)
  if (kind === 'inflow') return '+'
  if (kind === 'outflow') return '-'
  return ''
}

async function fetchAccounts() {
  setLoading()
  try {
    const params = {
      search: filters.value.search || undefined,
      account_type: filters.value.type || undefined,
      module: filters.value.module || undefined,
      currency: filters.value.currency || undefined,
      module_type: filters.value.module_type || undefined,
      owner_type: ['office', 'owner'],
      per_page: 100,
    }

    if (filters.value.is_active !== '') {
      params.is_active = filters.value.is_active
    }

    await accountStore.fetchAccounts(params)
    setSuccess()
  } catch (err) {
    if (axios.isCancel?.(err) || err?.code === 'ERR_CANCELED') {
      return
    }
    console.error('Failed to fetch accounts:', err)
    setError(err)
  }
}

function onFilterChange() {
  fetchAccounts()
}

function onModuleTypeFilterChange() {
  const val = filters.value.module_type
  if (val === 'tourism') {
    activeTab.value = 'tourism'
  } else if (val === 'office') {
    activeTab.value = 'office'
  } else if (val) {
    activeTab.value = 'all'
  }
  fetchAccounts()
}

function setActiveTab(tabId) {
  activeTab.value = tabId
  filters.value.module_type = tabId === 'all' ? '' : tabId
  
  // Reset module filter if it's not valid for the new tab
  if (filters.value.module) {
    const isValid = availableModules.value.some(m => m.value === filters.value.module)
    if (!isValid) {
      filters.value.module = ''
    }
  }
  
  fetchAccounts()
}

function clearFilters() {
  filters.value = {
    search: '',
    type: '',
    module: '',
    currency: '',
    is_active: '',
    module_type: activeTab.value === 'all' ? '' : activeTab.value,
  }
  fetchAccounts()
}

function onNewModuleTypeChange() {
  if (newAccount.value.module) {
    const isValid = newAccountAvailableModules.value.some(m => m.value === newAccount.value.module)
    if (!isValid) {
      newAccount.value.module = ''
    }
  }
}

function onNewTypeChange() {
  if (newAccount.value.type !== 'wallet') {
    newAccount.value.wallet_provider = 'vodafone_cash'
    newAccount.value.wallet_number = ''
  }
}

function onEditModuleTypeChange() {
  if (editAccount.value && editAccount.value.module) {
    const isValid = editAccountAvailableModules.value.some(m => m.value === editAccount.value.module)
    if (!isValid) {
      editAccount.value.module = ''
    }
  }
}

function onEditTypeChange() {
  if (editAccount.value && editAccount.value.type !== 'wallet') {
    editAccount.value.wallet_provider = 'vodafone_cash'
    editAccount.value.wallet_number = ''
  }
}

async function saveAccount() {
  if (!editAccount.value) return
  try {
    const payload = normalizeAccountModulePayload({ ...editAccount.value })
    if (payload.type !== 'wallet') {
      delete payload.wallet_provider
      delete payload.wallet_number
    }
    payload.is_active = !!payload.is_active
    await accountStore.updateAccount(payload.id, payload)
    showEditModal.value = false
    editAccount.value = null
    await fetchAccounts()
  } catch (err) {
    console.error('Failed to update account:', err)
  }
}

async function createAccount() {
  try {
    const payload = normalizeAccountModulePayload({ ...newAccount.value })
    if (payload.type !== 'wallet') {
      delete payload.wallet_provider
      delete payload.wallet_number
    }
    await accountStore.createAccount(payload)
    showCreateModal.value = false
    newAccount.value = newAccountDefaults()
    await fetchAccounts()
  } catch (err) {
    console.error('Failed to create account:', err)
  }
}

/** كشف الحساب ضمن SPA (Finance) */
function viewStatement(id) {
  router.push({ name: 'finance.accounts.statement.detail', params: { id } })
}

/** فتح نافذة تعديل الحساب المالي محلياً دون مغادرة التطبيق */
function openEdit(acc) {
  editAccount.value = {
    id: acc.id,
    name: acc.name,
    type: acc.type,
    currency: acc.currency,
    module_type: acc.module_type || 'general',
    module: acc.module || '',
    owner_type: acc.owner_type || 'owner',
    notes: acc.notes || '',
    is_active: acc.is_active,
    wallet_provider: acc.wallet_provider || 'vodafone_cash',
    wallet_number: acc.wallet_number || '',
  }
  showEditModal.value = true
}

function formatDate(date) {
  if (!date) return ''
  return new Intl.DateTimeFormat('ar-EG', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(date))
}

function getTypeLabel(type) {
  const labels = {
    cashbox: 'خزينة نقدي',
    wallet: 'محفظة إلكترونية',
    bank: 'حساب بنكي',
    treasury: 'خزينة عامة',
  }
  return labels[type] || type
}

function getTypeColor(type) {
  const colors = {
    cashbox: 'bg-gold',
    wallet: 'bg-sky-400',
    bank: 'bg-success',
    treasury: 'bg-violet-400',
  }
  return colors[type] || 'bg-text-muted'
}

function formatCurrency(amount, currency = 'EGP') {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(Number(amount) || 0)
}

onMounted(async () => {
  await financeStore.fetchSettingsMeta()
  await fetchAccounts()
})

onBeforeUnmount(() => {
  accountStore.abortPendingRequests()
})
</script>

<style scoped>
.accounts-page :deep(.acc-filter-select) {
  width: 100%;
  cursor: pointer;
  appearance: none;
  border-radius: 0.75rem;
  border: 1px solid rgba(255, 255, 255, 0.05);
  background-color: var(--bg-input);
  padding: 0.75rem 1rem;
  font-size: 1rem;
  line-height: 1.5rem;
  font-weight: 700;
  color: var(--text-main);
  outline: 2px solid transparent;
  outline-offset: 2px;
  transition: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
}

.accounts-page :deep(.acc-filter-select):focus {
  border-color: var(--gold);
}

.accounts-page :deep(.acc-filter-clear) {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  border-radius: 0.75rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background-color: rgba(255, 255, 255, 0.03);
  padding: 0.75rem 1rem;
  font-size: 1rem;
  line-height: 1.5rem;
  font-weight: 900;
  color: var(--text-muted);
  transition: color 150ms, background-color 150ms, border-color 150ms;
}

.accounts-page :deep(.acc-filter-clear):hover {
  border-color: rgba(212, 168, 67, 0.3);
  color: var(--gold);
}

.accounts-page :deep(.acc-table th) {
  font-size: 0.875rem;
  line-height: 1.25rem;
  font-weight: 900;
  text-transform: uppercase;
  letter-spacing: 0.025em;
  color: var(--text-muted);
}

.accounts-page :deep(.acc-table td) {
  vertical-align: middle;
}
</style>
