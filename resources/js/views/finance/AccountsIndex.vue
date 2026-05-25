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
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-text-muted">
            إدارة حسابات المكتب (نقد، بنك، محفظة، خزينة). لرصيد الحجز لدى مورّدي الطيران استخدم «حسابات شركات الطيران».
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

    <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8 mt-8">
      <!-- Division Tabs -->
      <div class="flex items-center justify-between border-b border-white/5 pb-1">
        <div class="flex gap-8">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            class="group relative pb-4 transition-all"
            @click="setActiveTab(tab.id)"
          >
            <div
              class="flex items-center gap-2.5 px-1"
              :class="activeTab === tab.id ? 'text-gold' : 'text-text-muted hover:text-text-main'"
            >
              <component :is="tab.icon" class="h-4 w-4" />
              <span class="text-sm font-black uppercase tracking-widest">{{ tab.name }}</span>
            </div>
            <div
              v-if="activeTab === tab.id"
              class="absolute bottom-0 left-0 h-0.5 w-full bg-gold shadow-[0_0_12px_rgba(212,175,55,0.4)]"
            />
          </button>
        </div>
        
        <div class="flex items-center gap-4 pb-4">
          <button @click="fetchAccounts" class="p-2 text-text-muted hover:text-gold transition-colors" title="تحديث البيانات">
            <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': isLoading() }" />
          </button>
          <button
            class="btn-airline flex items-center gap-2 px-6 py-2.5 text-[11px] font-black uppercase tracking-widest shadow-xl shadow-gold/10"
            @click="showCreateModal = true"
          >
            <Plus class="h-4 w-4" />
            إضافة حساب / خزينة
          </button>
        </div>
      </div>

      <!-- Advanced Financial Intelligence Ledger -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Ledger & Module Performance (Left Side) -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Summary Intelligence Grid -->
          <div v-if="isLoading()" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <KPICardSkeleton v-for="i in 2" :key="`s-${i}`" />
          </div>
          <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <!-- Main Liquidity Card -->
            <div class="flight-panel relative overflow-hidden !p-6 bg-gradient-to-br from-slate-900 to-slate-800 border-gold/20 shadow-2xl">
              <div class="absolute -right-4 -top-4 w-32 h-32 bg-gold/5 rounded-full blur-3xl"></div>
              <div class="relative z-10">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gold/60 mb-2">إجمالي السيولة المتاحة (Total Liquidity)</p>
                <div class="flex items-baseline gap-2">
                  <h2 class="text-4xl font-black text-text-main font-mono tabular-nums">
                    {{ formatCurrency(dbStats.total_balance, 'EGP') }}
                  </h2>
                </div>
                <div class="mt-6 grid grid-cols-2 gap-4 border-t border-white/5 pt-4">
                  <div class="flex flex-col">
                    <span class="text-[9px] text-text-muted uppercase font-bold">الخزائن النشطة</span>
                    <span class="text-sm font-black text-gold">{{ dbStats.active_count }}</span>
                  </div>
                  <div class="flex flex-col text-left">
                    <span class="text-[9px] text-text-muted uppercase font-bold">التغطية النقدية</span>
                    <span class="text-sm font-black text-success">100%</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="flight-panel !p-6">
              <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-text-muted mb-4">توزيع المحفظة المالية (Portfolio)</h3>
              <div class="space-y-4" v-if="dbStats.total_balance > 0">
                <div v-for="(val, type) in dbStats.liquidity" :key="type" class="space-y-1.5">
                  <div class="flex justify-between text-[10px] font-bold">
                    <span class="text-text-main uppercase">{{ getTypeLabel(type) }}</span>
                    <span class="text-text-muted">{{ getLiquidityPercent(type).toFixed(1) }}%</span>
                  </div>
                  <div class="h-1.5 w-full bg-white/5 rounded-full overflow-hidden">
                    <div 
                      :class="['h-full transition-all duration-1000', type === 'cashbox' ? 'bg-gold' : type === 'bank' ? 'bg-success' : 'bg-sky-400']"
                      :style="{ width: getLiquidityPercent(type) + '%' }"
                    ></div>
                  </div>
                </div>
              </div>
              <div v-else class="flex flex-col items-center justify-center py-10 opacity-30">
                <div class="w-10 h-1 bg-white/5 rounded-full mb-2"></div>
                <span class="text-[9px] uppercase font-bold tracking-widest">No Liquidity Data</span>
              </div>
            </div>
          </div>

          <!-- Section Performance Command Center -->
          <div class="space-y-4">
            <div class="flex items-center justify-between">
              <h3 class="text-sm font-black text-text-main flex items-center gap-2">
                <Activity class="w-4 h-4 text-gold" />
                تحليل ربحية الأقسام التشغيلية (Section Profitability)
              </h3>
            </div>
            <div v-if="isLoading()" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <KPICardSkeleton v-for="i in 2" :key="`p-${i}`" />
            </div>
            <div v-else-if="Object.keys(dbStats.performance).length" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div 
                v-for="(perf, mod) in dbStats.performance" 
                :key="mod"
                v-show="perf.profit !== 0 || perf.income !== 0"
                class="flight-panel !p-4 border-white/5 hover:border-gold/20 transition-all bg-white/[0.02]"
              >
                <div class="flex items-center justify-between mb-4">
                  <div class="flex items-center gap-2">
                    <div class="p-2 bg-white/5 rounded-lg">
                      <component :is="getModuleIcon(mod)" class="w-4 h-4 text-gold" />
                    </div>
                    <div>
                      <span class="text-[10px] font-black uppercase text-text-muted block">قسم {{ getModuleLabel(mod) }}</span>
                      <span class="text-xs font-bold text-text-main">صافي ربح القسم</span>
                    </div>
                  </div>
                  <div class="text-right">
                    <span 
                      class="text-lg font-black font-mono"
                      :class="perf.profit >= 0 ? 'text-success' : 'text-error'"
                    >
                      {{ formatCurrency(perf.profit) }}
                    </span>
                  </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mt-4 pt-4 border-t border-white/5">
                  <div class="bg-success/5 p-2 rounded-lg">
                    <span class="text-[9px] font-bold text-success/60 uppercase block mb-1">إجمالي المبيعات</span>
                    <span class="text-xs font-black text-text-main">{{ formatCurrency(perf.income) }}</span>
                  </div>
                  <div class="bg-error/5 p-2 rounded-lg text-left">
                    <span class="text-[9px] font-bold text-error/60 uppercase block mb-1">تكاليف المشتريات</span>
                    <span class="text-xs font-black text-text-main">{{ formatCurrency(perf.expense) }}</span>
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="flight-panel !p-10 text-center text-text-muted/40 border-dashed">
              <span class="text-[10px] font-black uppercase tracking-widest">Waiting for Transaction Data...</span>
            </div>
          </div>
        </div>

        <!-- System Alerts & Real-time Ledger (Right Side) -->
        <div class="space-y-6">
          <!-- Liquidity Alerts -->
          <div class="flight-panel border-error/20 bg-error/5" v-if="hasDeficits">
            <h3 class="text-xs font-black text-error flex items-center gap-2 mb-4">
              <AlertTriangle class="w-4 h-4" />
              تنبيهات العجز (System Alerts)
            </h3>
            <div class="space-y-3">
              <div 
                v-for="acc in deficitAccounts" 
                :key="acc.id"
                class="flex items-center justify-between p-2 bg-white/5 rounded-lg border border-white/5"
              >
                <span class="text-[10px] font-bold text-text-main">{{ acc.name }}</span>
                <span class="text-[10px] font-mono text-error font-bold">{{ formatCurrency(acc.balance) }}</span>
              </div>
            </div>
          </div>

          <!-- Real-time Activity Ledger -->
          <div class="flight-panel !p-0 overflow-hidden">
            <div class="p-4 border-b border-white/5 flex items-center justify-between bg-white/[0.02]">
              <h3 class="text-xs font-black text-text-main flex items-center gap-2">
                <RefreshCw class="w-3.5 h-3.5 text-sky-400" />
                سجل العمليات الأخير
              </h3>
              <span class="text-[9px] text-sky-400/80 font-bold uppercase tracking-tighter">Live Audit</span>
            </div>
            
            <div v-if="isLoading()" class="p-4 space-y-4">
              <TextLineSkeleton :lines="5" heightClass="h-12" gapClass="gap-2" />
            </div>
            <div v-else class="max-h-[500px] overflow-y-auto">
              <div v-if="!recentTransactions.length" class="p-10 text-center text-[10px] text-text-muted">
                لا توجد حركات مسجلة مؤخراً.
              </div>
              <div 
                v-for="tx in recentTransactions" 
                :key="tx.id"
                class="p-4 border-b border-white/5 last:border-0 hover:bg-white/[0.02] transition-colors"
              >
                <div class="flex items-start justify-between mb-2">
                  <div class="flex flex-col">
                    <span class="text-[10px] font-bold text-text-main leading-tight">{{ tx.notes || 'معاملة مالية' }}</span>
                    <span class="text-[9px] text-text-muted mt-1">{{ formatDate(tx.created_at) }}</span>
                  </div>
                  <span 
                    class="text-[10px] font-black font-mono"
                    :class="tx.type === 'income' ? 'text-success' : 'text-error'"
                  >
                    {{ tx.type === 'income' ? '+' : '-' }}{{ formatCurrency(tx.amount) }}
                  </span>
                </div>
                <div class="flex items-center gap-1.5">
                  <span class="px-1.5 py-0.5 rounded-md bg-white/5 text-[8px] font-bold text-text-muted uppercase tracking-tighter">
                    {{ getModuleLabel(tx.module) }}
                  </span>
                  <span class="text-[8px] text-text-muted/60">بواسطة {{ tx.created_by_name || 'النظام' }}</span>
                </div>
              </div>
            </div>
            <div class="p-3 bg-white/[0.02] text-center border-t border-white/5">
              <router-link to="/finance/transactions" class="text-[10px] font-bold text-gold hover:underline">مشاهدة السجل الكامل</router-link>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Accounts Ledger -->
      <div class="space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-black text-text-main flex items-center gap-2">
            <LayoutGrid class="w-4 h-4 text-gold" />
            بيان أرصدة الخزائن والبنوك (Detailed Ledger)
          </h3>
        </div>

        <!-- Filters Bar -->
        <div class="px-5 py-4 sm:px-6 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:flex lg:flex-wrap items-center gap-3">
            <!-- Search -->
            <div class="flex-1 min-w-[240px] relative">
              <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
              <input
                v-model="filters.search"
                type="text"
                placeholder="البحث باسم الحساب، رقم المحفظة..."
                class="w-full pl-10 pr-4 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm transition-all"
                @input="debouncedSearch"
              />
            </div>

            <!-- Type Filter -->
            <select v-model="filters.type" @change="onFilterChange" class="px-3 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]">
              <option value="">كل أنواع الحسابات</option>
              <option v-for="t in financeStore.meta.accountTypes" :key="t.value" :value="t.value">
                {{ t.label }}
              </option>
            </select>

            <!-- Currency Filter -->
            <select v-model="filters.currency" @change="onFilterChange" class="px-3 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[120px]">
              <option value="">كل العملات</option>
              <option v-for="c in financeStore.meta.currencies" :key="c.code" :value="c.code">
                {{ c.name }} ({{ c.code }})
              </option>
            </select>

            <!-- Module Filter -->
            <select v-model="filters.module" @change="onFilterChange" class="px-3 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[120px]">
              <option value="">كل الموديولات</option>
              <option v-for="m in availableModules" :key="m.value" :value="m.value">
                {{ m.label }}
              </option>
            </select>

            <!-- Payment Status Filter -->
            <select v-model="filters.payment_status" @change="onFilterChange" class="px-3 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]">
              <option value="">حالة السداد</option>
              <option value="paid">مدفوع بالكامل</option>
              <option value="partial">دفع جزئي</option>
              <option value="unpaid">لم يتم الدفع</option>
            </select>

            <!-- Status Filter -->
            <select v-model="filters.is_active" @change="onFilterChange" class="px-3 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[120px]">
              <option value="">الحالة (الكل)</option>
              <option value="1">نشط</option>
              <option value="0">غير نشط</option>
            </select>

            <!-- Clear Filters -->
            <button @click="clearFilters" class="text-sm text-muted hover:text-gold transition-colors px-3 py-2 flex items-center gap-1">
              <RefreshCw class="w-3.5 h-3.5" />
              مسح الفلاتر
            </button>
          </div>
        </div>
      </div>

      <!-- Accounts Table -->
      <div class="flight-panel !overflow-hidden !rounded-2xl !p-0 shadow-2xl border-white/5">
        <div class="overflow-x-auto">
          <table class="w-full border-collapse">
            <thead>
              <tr class="border-b border-white/10 bg-white/5 text-[11px] font-bold uppercase tracking-widest text-text-muted">
                <th class="px-5 py-4 text-right sm:px-6">اسم الحساب</th>
                <th class="px-5 py-4 text-right sm:px-6">النوع</th>
                <th class="px-5 py-4 text-right sm:px-6">العملة</th>
                <th class="px-5 py-4 text-right sm:px-6">الموديول</th>
                <th class="px-5 py-4 text-right sm:px-6">حالة السداد</th>
                <th class="px-5 py-4 text-right sm:px-6">الرصيد الحقيقي</th>
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
                    <span class="text-sm font-black text-text-main group-hover:text-gold transition-colors">{{ acc.name }}</span>
                    <span v-if="acc.owner_type && acc.owner_type !== 'office'" class="inline-flex items-center gap-1 mt-1 text-[9px] font-bold text-sky-400 uppercase tracking-tighter">
                      <div class="w-1 h-1 rounded-full bg-current"></div>
                      {{ acc.owner_type === 'customer' ? 'حساب عميل' : (acc.owner_type === 'supplier' ? 'حساب مورد' : acc.owner_type) }}
                    </span>
                    <span class="text-[10px] text-text-muted font-mono" v-if="acc.wallet_number"># {{ acc.wallet_number }}</span>
                  </div>
                </td>
                <td class="px-5 py-5 text-sm font-medium text-text-muted sm:px-6">
                  <div class="flex items-center gap-2">
                    <div :class="['w-1.5 h-1.5 rounded-full', getTypeColor(acc.type)]"></div>
                    {{ getTypeLabel(acc.type) }}
                  </div>
                </td>
                <td class="px-5 py-5 text-sm font-bold text-text-muted sm:px-6">
                  {{ acc.currency }}
                </td>
                <td class="px-5 py-5 sm:px-6">
                  <span v-if="acc.module" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-white/5 text-[10px] text-gold border border-gold/20">
                    {{ getModuleLabel(acc.module) }}
                  </span>
                  <span v-else class="text-[10px] text-text-muted italic">عام</span>
                </td>
                <td class="px-5 py-5 sm:px-6">
                  <div
                    :class="[
                      'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider',
                      acc.payment_status === 'paid' ? 'bg-success/10 text-success' :
                      acc.payment_status === 'partial' ? 'bg-blue-500/10 text-blue-500' :
                      'bg-error/10 text-error'
                    ]"
                  >
                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                    {{ acc.payment_status === 'paid' ? 'مدفوع' : acc.payment_status === 'partial' ? 'جزئي' : 'غير مدفوع' }}
                  </div>
                </td>
                <td class="px-5 py-5 sm:px-6">
                  <div class="flex flex-col">
                    <span
                      class="font-mono text-base font-black"
                      :class="acc.balance >= 0 ? 'text-success shadow-success/20' : 'text-error shadow-error/20'"
                    >
                      {{ formatCurrency(acc.balance, acc.currency) }}
                    </span>
                  </div>
                </td>
                <td class="px-5 py-5 sm:px-6 text-right">
                  <span
                    :class="
                      acc.module_type === 'tourism'
                        ? 'rounded-lg border border-success/30 bg-success/10 px-2.5 py-1 text-[10px] font-black text-success uppercase'
                        : 'rounded-lg border border-violet-400/25 bg-violet-500/10 px-2.5 py-1 text-[10px] font-black text-violet-200 uppercase'
                    "
                  >
                    {{ acc.module_type === 'tourism' ? 'سياحة' : 'مكتب' }}
                  </span>
                </td>
                <td class="px-5 py-5 sm:px-6">
                  <span
                    :class="
                      acc.is_active
                        ? 'inline-flex items-center gap-1.5 rounded-full border border-success/30 bg-success/10 px-2.5 py-1 text-[10px] font-black text-success'
                        : 'inline-flex items-center gap-1.5 rounded-full border border-error/30 bg-error/10 px-2.5 py-1 text-[10px] font-black text-error'
                    "
                  >
                    <span :class="['w-1.5 h-1.5 rounded-full bg-current', acc.is_active ? 'animate-pulse' : '']"></span>
                    {{ acc.is_active ? 'نشط' : 'غير نشط' }}
                  </span>
                </td>
                <td class="px-5 py-5 text-sm sm:px-6 text-left">
                  <div class="flex items-center justify-end gap-3 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button
                      type="button"
                      class="p-2 hover:bg-sky-500/10 rounded-lg text-sky-400 transition-all flex items-center gap-1 font-bold text-[11px]"
                      @click.stop="viewStatement(acc.id)"
                    >
                      <FileText class="w-4 h-4" />
                      كشف حساب
                    </button>
                    <button
                      type="button"
                      class="p-2 hover:bg-gold/10 rounded-lg text-gold transition-all flex items-center gap-1 font-bold text-[11px]"
                      @click.stop="openEdit(acc)"
                    >
                      <Pen class="w-4 h-4" />
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
                <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">القسم التابع له</label>
                <select v-model="newAccount.module_type" required class="flight-select w-full" @change="onNewModuleTypeChange">
                  <option value="tourism">سياحة (عمليات، طيران، إلخ)</option>
                  <option value="office">مكتب (باص، فوري، مصاريف إدارية)</option>
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
              <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">القسم التابع له</label>
              <select v-model="editAccount.module_type" required class="flight-select w-full" @change="onEditModuleTypeChange">
                <option value="tourism">سياحة (عمليات، طيران، إلخ)</option>
                <option value="office">مكتب (باص، فوري، مصاريف إدارية)</option>
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
import { ref, computed, onMounted } from 'vue'
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
  payment_status: '',
  currency: '',
  is_active: '',
  module_type: '',
})

const availableModules = computed(() => {
  const modules = financeStore.meta.transactionModules || []
  if (activeTab.value === 'all') return modules

  const tourismModules = ['flight', 'hajj_umra', 'visa']
  const officeModules = ['bus', 'wallet', 'online', 'fawry', 'general', 'service']

  if (activeTab.value === 'tourism') {
    return modules.filter((m) => tourismModules.includes(m.value))
  }
  if (activeTab.value === 'office') {
    return modules.filter((m) => officeModules.includes(m.value))
  }
  return modules
})

const newAccountAvailableModules = computed(() => {
  const modules = financeStore.meta.transactionModules || []
  const type = newAccount.value.module_type

  const tourismModules = ['flight', 'hajj_umra', 'visa']
  const officeModules = ['bus', 'wallet', 'online', 'fawry', 'general', 'service']

  if (type === 'tourism') {
    return modules.filter((m) => tourismModules.includes(m.value))
  }
  if (type === 'office') {
    return modules.filter((m) => officeModules.includes(m.value))
  }
  return modules
})

const editAccountAvailableModules = computed(() => {
  const modules = financeStore.meta.transactionModules || []
  if (!editAccount.value) return modules
  const type = editAccount.value.module_type

  const tourismModules = ['flight', 'hajj_umra', 'visa']
  const officeModules = ['bus', 'wallet', 'online', 'fawry', 'general', 'service']

  if (type === 'tourism') {
    return modules.filter((m) => tourismModules.includes(m.value))
  }
  if (type === 'office') {
    return modules.filter((m) => officeModules.includes(m.value))
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
  { id: 'tourism', name: 'السياحة', icon: Plane },
  { id: 'office', name: 'المكتب', icon: Activity },
]

const newAccountDefaults = () => ({
  name: '',
  type: 'cashbox',
  currency: 'EGP',
  module_type: 'tourism',
  module: '',
  owner_type: 'owner',
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

async function fetchAccounts() {
  setLoading()
  try {
    const params = {
      search: filters.value.search,
      account_type: filters.value.type,
      module: filters.value.module,
      payment_status: filters.value.payment_status,
      currency: filters.value.currency,
      module_type: filters.value.module_type || undefined,
      owner_type: ['office', 'owner'], // Force isolation from entity accounts
    }

    if (filters.value.is_active !== '') {
      params.is_active = filters.value.is_active
    }

    await accountStore.fetchAccounts(params)
    setSuccess()
  } catch (err) {
    console.error('Failed to fetch accounts:', err)
    setError(err)
  }
}

function onFilterChange() {
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
    payment_status: '',
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
    const payload = { ...editAccount.value }
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
    const payload = { ...newAccount.value }
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
    module_type: acc.module_type || 'tourism',
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
</script>
