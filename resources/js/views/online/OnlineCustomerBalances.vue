<template>
  <div class="space-y-8 animate-in fade-in duration-700" dir="rtl">
    
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white tracking-tight">
          مديونيات العملاء (الخدمات الإلكترونية)
        </h1>
        <p class="text-text-muted mt-1">
          متابعة وتدقيق مديونيات وأرصدة العملاء في موديول الخدمات الإلكترونية
        </p>
      </div>
      <div class="flex gap-3">
        <button
          @click="exportCsv"
          class="px-6 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold flex items-center justify-center gap-2 transition-all text-sm text-white"
        >
          <Download class="w-4 h-4" />
          تصدير التقرير (CSV)
        </button>
      </div>
    </div>

    <!-- Stats row -->
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
      <div v-for="s in customerStats" :key="s.label"
        class="relative overflow-hidden rounded-2xl border border-white/10 bg-white/[0.02] p-5 shadow-lg">
        <div class="absolute -right-3 -top-3 h-16 w-16 rounded-full opacity-10 blur-2xl" :class="s.glow"/>
        <div class="mb-3 flex items-center gap-2">
          <div class="flex h-8 w-8 items-center justify-center rounded-lg" :class="s.iconBg">
            <component :is="s.icon" class="h-4 w-4" :class="s.iconColor"/>
          </div>
          <span class="text-[10px] font-bold uppercase tracking-widest text-text-muted/65">{{ s.label }}</span>
        </div>
        <p class="font-mono text-xl sm:text-2xl font-black tabular-nums" :class="s.valueColor">{{ s.value }}</p>
      </div>
    </div>

    <!-- Filters Section -->
    <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- Search Input -->
        <div class="md:col-span-2 lg:col-span-1">
          <label class="block text-sm font-medium text-text-muted mb-2">اسم العميل أو الهاتف</label>
          <div class="relative">
            <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-text-muted" />
            <input
              v-model="filters.search"
              type="text"
              placeholder="البحث..."
              @input="debouncedFetch"
              class="w-full pr-10 pl-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-violet-500/50 focus:border-violet-500 transition-all text-white text-sm"
            />
          </div>
        </div>

        <!-- Status Filter -->
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">حالة الرصيد</label>
          <select v-model="filters.status" @change="fetchBalances" class="form-select-dark py-2.5 text-sm">
            <option value="all">الكل</option>
            <option value="debtors">المدينون فقط (أرصدة موجبة)</option>
            <option value="creditors">الدائنون فقط (أرصدة سالبة)</option>
          </select>
        </div>

        <!-- Service Type Filter -->
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">نوع الخدمة</label>
          <select v-model="filters.service_type_id" @change="fetchBalances" class="form-select-dark py-2.5 text-sm">
            <option value="all">الكل</option>
            <option v-for="st in serviceTypes" :key="st.id" :value="st.id">{{ st.label }}</option>
          </select>
        </div>

        <!-- Date From -->
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">من تاريخ</label>
          <input
            v-model="filters.from_date"
            type="date"
            @change="fetchBalances"
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-violet-500/50 focus:border-violet-500 transition-all text-white text-sm"
          />
        </div>

        <!-- Date To -->
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">إلى تاريخ</label>
          <input
            v-model="filters.to_date"
            type="date"
            @change="fetchBalances"
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-violet-500/50 focus:border-violet-500 transition-all text-white text-sm"
          />
        </div>
      </div>
    </div>

    <!-- Data Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden shadow-xl">
      <div v-if="loading" class="flex flex-col items-center justify-center py-20">
        <Loader2 class="w-10 h-10 animate-spin text-violet-500 mb-4" />
        <span class="text-text-muted">جاري تحميل البيانات...</span>
      </div>

      <div v-else-if="records.length === 0" class="text-center py-20">
        <Users class="w-16 h-16 mx-auto text-text-muted/30 mb-4" />
        <h3 class="text-xl font-bold text-white">لا توجد مديونيات مسجلة</h3>
        <p class="text-text-muted mt-2">لا تتوفر حركات مالية مطابقة للمعايير المحددة حالياً.</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-right border-collapse">
          <thead>
            <tr class="border-b border-white/10 bg-black/20 text-[11px] uppercase tracking-[0.18em] text-text-muted">
              <th class="px-6 py-5 font-bold">العميل</th>
              <th class="px-6 py-5 font-bold text-center">المعاملات</th>
              <th class="px-6 py-5 font-bold">إجمالي العمليات</th>
              <th class="px-6 py-5 font-bold text-emerald-400/60">المدفوع</th>
              <th class="px-6 py-5 font-bold text-red-400/60">الآجل المتبقي</th>
              <th class="px-6 py-5 font-bold">آخر معاملة</th>
              <th class="px-6 py-5 font-bold text-left">إجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/[0.04]">
            <tr
              v-for="(row, index) in paginatedRecords"
              :key="index"
              class="group transition-colors hover:bg-white/[0.025]"
            >
              <!-- Customer avatar name & phone -->
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-500/10 text-sm font-black text-violet-400 border border-violet-500/20">
                    {{ (row.client_name || '?').charAt(0) }}
                  </div>
                  <div>
                    <p class="font-bold text-white text-sm">{{ row.client_name || '—' }}</p>
                    <p class="text-xs text-text-muted/65 flex items-center gap-1 mt-0.5">
                      <Phone class="w-3 h-3"/>{{ row.phone || '—' }}
                    </p>
                  </div>
                </div>
              </td>

              <!-- Transactions count -->
              <td class="px-6 py-4 text-center">
                <span class="inline-flex items-center rounded-full bg-sky-500/10 px-3 py-1 text-xs font-bold text-sky-400 border border-sky-500/20">
                  {{ row.transaction_count || 0 }} معاملة
                </span>
              </td>

              <!-- Total sales -->
              <td class="px-6 py-4">
                <span class="font-mono text-sm font-semibold text-white">
                  {{ formatMoney(row.total_sales) }}
                </span>
              </td>

              <!-- Total paid -->
              <td class="px-6 py-4">
                <span class="font-mono text-sm font-semibold text-emerald-400">
                  {{ formatMoney(row.total_paid) }}
                </span>
              </td>

              <!-- Remaining debt -->
              <td class="px-6 py-4">
                <div v-if="Number(row.total_debt) > 0" class="flex items-center gap-2">
                  <span class="font-mono text-sm font-black text-red-400">
                    {{ formatMoney(row.total_debt) }}
                  </span>
                  <span class="rounded-full bg-red-500/10 px-2 py-0.5 text-[9px] font-bold text-red-400 border border-red-500/20">آجل</span>
                </div>
                <span v-else-if="Number(row.total_debt) < 0" class="font-mono text-sm font-black text-emerald-400">
                  {{ formatMoney(Math.abs(row.total_debt)) }} (دائن)
                </span>
                <span v-else class="font-mono text-sm text-emerald-400/60">مسدّد ✓</span>
              </td>

              <!-- Last transaction date -->
              <td class="px-6 py-4 text-sm text-text-muted">{{ formatDate(row.last_transaction) }}</td>

              <!-- Actions -->
              <td class="px-6 py-4 text-left">
                <div class="flex gap-2 justify-end">
                  <button
                    v-if="row.client_id && Number(row.total_debt) > 0"
                    @click="openPaymentModal(row)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-xs font-bold text-emerald-400 transition hover:bg-emerald-500 hover:text-black"
                  >
                    <WalletIcon class="w-3.5 h-3.5" />
                    تسديد
                  </button>
                  <button
                    @click="openDetails(row)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/60 transition hover:border-violet-500/40 hover:bg-violet-500/10 hover:text-violet-400"
                  >
                    <Eye class="w-3.5 h-3.5" />
                    عرض التفاصيل
                  </button>
                  <button
                    @click="printStatement(row)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/60 transition hover:border-violet-500/40 hover:bg-violet-500/10 hover:text-violet-400"
                  >
                    <Printer class="w-3.5 h-3.5" />
                    طباعة الكشف
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="records.length > perPage" class="px-6 py-4 border-t border-white/10 flex items-center justify-between">
        <div class="text-sm text-text-muted">
          عرض {{ (currentPage - 1) * perPage + 1 }} إلى {{ Math.min(currentPage * perPage, records.length) }} من {{ records.length }} عميل
        </div>
        <div class="flex gap-2">
          <button
            @click="currentPage--"
            :disabled="currentPage === 1"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-white text-sm"
          >
            السابق
          </button>
          <button
            @click="currentPage++"
            :disabled="currentPage * perPage >= records.length"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-white text-sm"
          >
            التالي
          </button>
        </div>
      </div>
    </div>

    <!-- Details Modal -->
    <div
      v-if="modalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm"
      @click.self="modalOpen = false"
    >
      <div class="bg-card-bg border border-white/15 w-full max-w-4xl rounded-2xl overflow-hidden shadow-2xl flex flex-col max-h-[85vh] animate-in zoom-in duration-300">
        <!-- Modal Header -->
        <div class="p-6 border-b border-white/10 flex items-center justify-between bg-white/[0.02]">
          <div>
            <h3 class="text-xl font-bold text-white">{{ selectedCustomerName }}</h3>
            <p class="text-xs text-text-muted mt-1 font-mono">رقم الهاتف: {{ selectedCustomerPhone }}</p>
          </div>
          <button @click="modalOpen = false" class="p-2 hover:bg-white/10 rounded-lg transition-colors">
            <X class="w-6 h-6 text-text-muted hover:text-white" />
          </button>
        </div>

        <!-- Modal Content -->
        <div class="p-6 overflow-y-auto space-y-6 flex-1">
          <!-- Total Card -->
          <div class="flex justify-between items-center bg-white/[0.03] border border-white/10 p-5 rounded-xl">
            <div>
              <span class="text-xs text-text-muted block">صافي الرصيد الحالي للعميل</span>
              <span class="text-2xl font-black mt-1 inline-block" :class="selectedCustomerRunningBalance > 0 ? 'text-red-400' : (selectedCustomerRunningBalance < 0 ? 'text-emerald-400' : 'text-text-muted')">
                {{ formatMoney(Math.abs(selectedCustomerRunningBalance)) }}
                <span class="text-xs font-normal text-text-muted">({{ selectedCustomerRunningBalance > 0 ? 'مستحق عليه / مدين' : (selectedCustomerRunningBalance < 0 ? 'له طرفنا / دائن' : 'صفر') }})</span>
              </span>
            </div>
            <button
              v-if="selectedCustomerId && selectedCustomerRunningBalance > 0"
              @click="openPaymentModalFromDetails"
              class="px-5 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-black font-bold rounded-xl text-sm transition-all shadow-lg shadow-emerald-500/20"
            >
              تسديد دفعة
            </button>
          </div>

          <!-- Transaction List -->
          <div class="border border-white/10 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full text-right text-sm">
                <thead class="bg-white/5 border-b border-white/10">
                  <tr>
                    <th class="px-4 py-3 text-text-muted">التاريخ</th>
                    <th class="px-4 py-3 text-text-muted">الجهة / الحساب</th>
                    <th class="px-4 py-3 text-text-muted">النوع</th>
                    <th class="px-4 py-3 text-text-muted">المبلغ</th>
                    <th class="px-4 py-3 text-text-muted">الموظف</th>
                    <th class="px-4 py-3 text-text-muted">البيان</th>
                    <th class="px-4 py-3 text-text-muted">الرصيد التراكمي</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-white">
                  <tr v-for="tx in modalTransactions" :key="tx.id" class="hover:bg-white/[0.02]">
                    <td class="px-4 py-3 font-medium">{{ tx.date }}</td>
                    <td class="px-4 py-3">{{ tx.machine }}</td>
                    <td class="px-4 py-3">
                      <span
                        :class="[
                          'px-2 py-0.5 rounded text-xs font-bold',
                          tx.type === 'عملية' ? 'bg-violet-500/10 text-violet-400' : 'bg-emerald-500/10 text-emerald-400'
                        ]"
                      >
                        {{ tx.type }}
                      </span>
                    </td>
                    <td class="px-4 py-3 font-mono font-bold">{{ formatMoney(tx.amount) }}</td>
                    <td class="px-4 py-3 text-text-muted">{{ tx.employee }}</td>
                    <td class="px-4 py-3 max-w-xs truncate" :title="tx.description">{{ tx.description }}</td>
                    <td class="px-4 py-3 font-mono font-semibold" :class="tx.running_balance > 0 ? 'text-red-400' : (tx.running_balance < 0 ? 'text-emerald-400' : 'text-text-muted')">
                      {{ formatMoney(tx.running_balance) }}
                    </td>
                  </tr>
                  <tr v-if="modalTransactions.length === 0">
                    <td colspan="7" class="px-4 py-4 text-center text-text-muted">لا توجد معاملات مسجلة.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Payment Modal -->
    <Teleport to="body">
      <div v-if="showPaymentModal"
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
        @click.self="showPaymentModal = false">
        <div class="w-full max-w-md overflow-hidden rounded-2xl border border-white/10 bg-[#0d0d0d] shadow-2xl animate-in zoom-in duration-200" dir="rtl">
          <!-- Modal header -->
          <div class="flex items-center justify-between border-b border-white/5 bg-emerald-500/5 px-6 py-5">
            <h3 class="flex items-center gap-3 text-lg font-black text-emerald-400">
              <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/15">
                <WalletIcon class="h-5 w-5"/>
              </div>
              تسديد مديونية العميل (أونلاين)
            </h3>
            <button type="button" @click="showPaymentModal = false"
              class="flex h-8 w-8 items-center justify-center rounded-lg text-white/30 hover:bg-white/10 hover:text-white transition">
              ✕
            </button>
          </div>

          <form @submit.prevent="submitPayment" class="space-y-5 p-6">
            <!-- Customer + debt info -->
            <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.04] px-4 py-4">
              <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-500/15 text-sm font-black text-red-400">
                  {{ selectedCustomer?.client_name?.charAt(0) || '?' }}
                </div>
                <div>
                  <p class="text-[10px] text-white/35 uppercase tracking-wider">العميل</p>
                  <p class="font-bold text-white">{{ selectedCustomer?.client_name }}</p>
                </div>
              </div>
              <div class="text-left">
                <p class="text-[10px] text-white/35 uppercase tracking-wider">المديونية الحالية</p>
                <p class="font-mono text-lg font-black text-red-400">
                  {{ formatMoney(Math.abs(Number(selectedCustomer?.total_debt) || 0)) }}
                </p>
              </div>
            </div>

            <!-- Source account -->
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-white/40">حساب الاستلام / التحصيل <span class="text-red-400">*</span></label>
              <select v-model="paymentForm.account_id" required
                class="form-select-dark py-3">
                <option value="">— اختر حساب التحصيل —</option>
                <option v-for="acc in onlineAccounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} — {{ formatMoney(acc.balance) }}
                </option>
              </select>
            </div>

            <!-- Amount -->
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-white/40">المبلغ المستلم <span class="text-red-400">*</span></label>
              <div class="relative">
                <input
                  v-model.number="paymentForm.amount"
                  type="number" step="0.01" required
                  :max="Math.abs(Number(selectedCustomer?.total_debt) || 0)"
                  class="w-full rounded-xl border border-white/15 bg-white/[0.05] py-3 pr-4 pl-16 font-mono text-white outline-none transition focus:border-emerald-500/50 text-right"
                />
                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xs text-white/30">ج.م</span>
              </div>
              <!-- Quick amounts -->
              <div class="mt-2 flex gap-2">
                <button v-for="pct in [25,50,75,100]" :key="pct" type="button"
                  @click="paymentForm.amount = roundMoney(Math.abs(Number(selectedCustomer?.total_debt)||0) * pct / 100)"
                  class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white/40 hover:border-emerald-400/40 hover:text-emerald-400 transition">
                  {{ pct }}٪
                </button>
              </div>
            </div>

            <!-- Notes -->
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-white/40">ملاحظات</label>
              <input
                v-model="paymentForm.notes"
                type="text"
                placeholder="مثال: تسديد جزء من مديونية الخدمات الإلكترونية"
                class="w-full rounded-xl border border-white/15 bg-white/[0.05] px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-500/50"
              />
            </div>

            <div class="flex gap-3 pt-2">
              <button type="submit" :disabled="submitting || !paymentForm.account_id || !paymentForm.amount"
                class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-500 py-3 text-sm font-black text-black shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40">
                <Loader2 v-if="submitting" class="h-4 w-4 animate-spin"/>
                <CheckCircle v-else class="h-4 w-4"/>
                {{ submitting ? 'جاري التسجيل...' : 'تأكيد السداد' }}
              </button>
              <button type="button" @click="showPaymentModal = false"
                class="rounded-xl border border-white/10 px-6 py-3 text-sm text-white/50 hover:bg-white/5 transition">
                إلغاء
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>

    <!-- Printable Statement Section -->
    <div
      v-if="printCustomer"
      id="online-statement-print-content"
      dir="rtl"
      class="hidden print:block print:w-full print:bg-white print:text-black print:font-sans p-6"
    >
      <!-- Header -->
      <div class="flex items-center justify-between border-b border-gray-300 pb-4 mb-6">
        <div>
          <h2 class="text-xl font-bold text-gray-800">{{ printSettingsStore.settings.company_name_ar || 'سفري علينا للسياحة' }}</h2>
          <p class="text-xs text-gray-500 mt-1">كشف حساب عميل - موديول الخدمات الإلكترونية</p>
        </div>
        <div class="text-left">
          <p class="text-xs text-gray-500">تاريخ الطباعة: {{ printTimestamp }}</p>
        </div>
      </div>

      <!-- Customer Info Card -->
      <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-6 grid grid-cols-2 gap-4 text-sm text-gray-800">
        <div>
          <span class="text-xs text-gray-500 block">اسم العميل:</span>
          <span class="text-base font-bold text-gray-900">{{ printCustomer.client_name }}</span>
        </div>
        <div>
          <span class="text-xs text-gray-500 block">رقم الهاتف:</span>
          <span class="text-base font-mono font-bold text-gray-900">{{ printCustomer.phone || '—' }}</span>
        </div>
        <div>
          <span class="text-xs text-gray-500 block">إجمالي العمليات:</span>
          <span class="text-base font-bold text-gray-900 font-mono">{{ formatMoney(printCustomer.total_sales) }}</span>
        </div>
        <div>
          <span class="text-xs text-gray-500 block">الرصيد المتبقي:</span>
          <span class="text-base font-bold text-red-600 font-mono">{{ formatMoney(printCustomer.total_debt) }}</span>
        </div>
      </div>

      <!-- Transactions Table -->
      <table class="w-full border-collapse border border-gray-300 mb-8 text-sm">
        <thead>
          <tr class="bg-gray-100">
            <th class="border border-gray-300 px-3 py-2 text-right">التاريخ</th>
            <th class="border border-gray-300 px-3 py-2 text-right">الجهة / الحساب</th>
            <th class="border border-gray-300 px-3 py-2 text-right">النوع</th>
            <th class="border border-gray-300 px-3 py-2 text-right">المبلغ</th>
            <th class="border border-gray-300 px-3 py-2 text-right">الموظف</th>
            <th class="border border-gray-300 px-3 py-2 text-right">البيان</th>
            <th class="border border-gray-300 px-3 py-2 text-right">الرصيد التراكمي</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tx in printTransactions" :key="tx.id" class="hover:bg-gray-50">
            <td class="border border-gray-300 px-3 py-2">{{ tx.date }}</td>
            <td class="border border-gray-300 px-3 py-2">{{ tx.machine }}</td>
            <td class="border border-gray-300 px-3 py-2">
              <span :class="tx.type === 'عملية' ? 'text-violet-600 font-bold' : 'text-emerald-600 font-bold'">
                {{ tx.type }}
              </span>
            </td>
            <td class="border border-gray-300 px-3 py-2 font-mono">{{ formatMoney(tx.amount) }}</td>
            <td class="border border-gray-300 px-3 py-2">{{ tx.employee }}</td>
            <td class="border border-gray-300 px-3 py-2 max-w-xs truncate" :title="tx.description">{{ tx.description }}</td>
            <td class="border border-gray-300 px-3 py-2 font-mono font-bold" :class="tx.running_balance > 0 ? 'text-red-600' : (tx.running_balance < 0 ? 'text-emerald-600' : 'text-gray-500')">
              {{ formatMoney(tx.running_balance) }}
            </td>
          </tr>
          <tr v-if="printTransactions.length === 0">
            <td colspan="7" class="border border-gray-300 px-3 py-4 text-center text-gray-500">لا توجد معاملات مسجلة.</td>
          </tr>
        </tbody>
      </table>

      <!-- Summary & Signatures Section -->
      <div class="grid grid-cols-3 gap-8 mt-12 text-center text-sm pt-8 border-t border-dashed border-gray-200">
        <div>
          <p class="font-bold text-gray-700">توقيع العميل</p>
          <div class="h-16"></div>
          <p class="text-xs text-gray-400">________________________</p>
        </div>
        <div>
          <p class="font-bold text-gray-700">توقيع المحاسب</p>
          <div class="h-16"></div>
          <p class="text-xs text-gray-400">________________________</p>
        </div>
        <div>
          <p class="font-bold text-gray-700">ختم الشركة</p>
          <div class="h-16"></div>
          <p class="text-xs text-gray-400">________________________</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, nextTick } from 'vue';
import axios from 'axios';
import { useOnlineStore } from '@/stores/onlineStore';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';

const printSettingsStore = usePrintSettingsStore();
import {
  Download,
  Search,
  Users,
  Eye,
  Printer,
  X,
  Loader2,
  Phone,
  AlertTriangle,
  TrendingUp,
  ListOrdered,
  Wallet as WalletIcon,
  CheckCircle,
} from 'lucide-vue-next';

const store = useOnlineStore();

// Filters
const filters = ref({
  search: '',
  status: 'all',
  service_type_id: 'all',
  from_date: '',
  to_date: '',
});

// Records & Loading state
const records = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const perPage = 25;

// Modal state
const modalOpen = ref(false);
const selectedCustomerId = ref(null);
const selectedCustomerName = ref('');
const selectedCustomerPhone = ref('');
const selectedCustomerRunningBalance = ref(0.0);
const modalTransactions = ref([]);

// Payment modal state
const showPaymentModal = ref(false);
const submitting = ref(false);
const selectedCustomer = ref(null);
const onlineAccounts = ref([]);
const paymentForm = ref({
  amount: 0,
  account_id: '',
  notes: '',
});

// Print statement state
const printCustomer = ref(null);
const printTransactions = ref([]);
const printRunningBalance = ref(0);
const printTimestamp = ref('');

// Service types list
const serviceTypes = computed(() => store.serviceTypes || []);

// Debounce timer
let debounceTimer = null;

const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    fetchBalances();
  }, 350);
};

const formatMoney = (amount) => {
  const n = Number(amount) || 0;
  return `${n.toLocaleString('ar-EG', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} ج.م`;
};

const formatDate = (dateString) => {
  if (!dateString) return '—';
  return new Date(dateString).toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const paginatedRecords = computed(() => {
  const start = (currentPage.value - 1) * perPage;
  return records.value.slice(start, start + perPage);
});

// Stats cards
const customerStats = computed(() => {
  const totalSales = records.value.reduce((s, c) => s + (Number(c.total_sales) || 0), 0);
  const totalPaid = records.value.reduce((s, c) => s + (Number(c.total_paid) || 0), 0);
  const totalDebt = records.value.reduce((s, c) => s + Math.max(0, Number(c.total_debt) || 0), 0);
  const debtorsCount = records.value.filter(c => Number(c.total_debt) > 0).length;

  return [
    {
      label: 'إجمالي العملاء',
      value: records.value.length,
      icon: Users, iconBg: 'bg-sky-500/15', iconColor: 'text-sky-400',
      valueColor: 'text-white', glow: 'bg-sky-400',
    },
    {
      label: 'إجمالي العمليات',
      value: formatMoney(totalSales),
      icon: ListOrdered, iconBg: 'bg-violet-500/15', iconColor: 'text-violet-400',
      valueColor: 'text-violet-400', glow: 'bg-violet-400',
    },
    {
      label: 'عملاء عليهم ديون',
      value: debtorsCount,
      icon: AlertTriangle, iconBg: 'bg-red-500/15', iconColor: 'text-red-400',
      valueColor: 'text-red-400', glow: 'bg-red-400',
    },
    {
      label: 'إجمالي الآجل المتبقي',
      value: formatMoney(totalDebt),
      icon: TrendingUp, iconBg: 'bg-orange-500/15', iconColor: 'text-orange-400',
      valueColor: 'text-orange-400', glow: 'bg-orange-400',
    },
  ];
});

const fetchBalances = async () => {
  loading.value = true;
  currentPage.value = 1;
  try {
    const res = await axios.get('/api/v1/online/customer-balances', {
      params: {
        search: filters.value.search,
        status: filters.value.status,
        service_type_id: filters.value.service_type_id,
        from_date: filters.value.from_date,
        to_date: filters.value.to_date,
        _t: Date.now(),
      },
    });
    records.value = res.data?.data || [];
  } catch (error) {
    console.error('Failed to load customer balances:', error);
  } finally {
    loading.value = false;
  }
};

const openDetails = async (row) => {
  selectedCustomerId.value = row.client_id || row.id;
  selectedCustomerName.value = row.client_name;
  selectedCustomerPhone.value = row.phone;
  modalTransactions.value = [];
  modalOpen.value = true;

  try {
    const res = await axios.get('/api/v1/online/customer-statement', {
      params: {
        client_id: row.client_id,
        client_name: row.client_name,
        _t: Date.now(),
      },
    });
    modalTransactions.value = res.data?.data?.transactions || [];
    selectedCustomerRunningBalance.value = res.data?.data?.running_balance || 0.0;
  } catch (error) {
    console.error('Failed to load customer statement:', error);
  }
};

const roundMoney = (n) => Math.round((Number(n) || 0) * 100) / 100;

const loadAccounts = async () => {
  try {
    const res = await axios.get('/api/v1/online/settings/accounts', {
      params: {
        _t: Date.now(),
      },
    });
    onlineAccounts.value = res.data?.data || [];
  } catch (e) {
    console.error('Failed to load online accounts:', e);
  }
};

const openPaymentModal = (row) => {
  selectedCustomer.value = row;
  paymentForm.value = {
    amount: roundMoney(Number(row.total_debt) || 0),
    account_id: '',
    notes: '',
  };
  showPaymentModal.value = true;
};

const openPaymentModalFromDetails = () => {
  const row = records.value.find(r => r.client_id === selectedCustomerId.value || r.id === selectedCustomerId.value);
  if (row) {
    openPaymentModal(row);
  }
};

const submitPayment = async () => {
  if (!paymentForm.value.account_id) {
    return;
  }
  submitting.value = true;
  try {
    const id = selectedCustomer.value.client_id || selectedCustomer.value.id;
    await axios.post(`/api/v1/customers/${id}/pay-debt`, {
      amount: paymentForm.value.amount,
      account_id: paymentForm.value.account_id,
      notes: paymentForm.value.notes || undefined,
      module: 'online',
    });
    
    showPaymentModal.value = false;
    
    await Promise.all([
      fetchBalances(),
      loadAccounts()
    ]);
    
    if (modalOpen.value) {
      const row = records.value.find(r => r.client_id === selectedCustomerId.value || r.id === selectedCustomerId.value);
      if (row) {
        await openDetails(row);
      }
    }
  } catch (error) {
    console.error('Failed to submit payment:', error);
  } finally {
    submitting.value = false;
  }
};

const printStatement = async (row) => {
  loading.value = true;
  try {
    const res = await axios.get('/api/v1/online/customer-statement', {
      params: {
        client_id: row.client_id || row.id,
        client_name: row.client_name,
        _t: Date.now(),
      },
    });
    printCustomer.value = row;
    printTransactions.value = res.data?.data?.transactions || [];
    printRunningBalance.value = res.data?.data?.running_balance || 0.0;
    printTimestamp.value = new Date().toLocaleString('ar-EG', {
      dateStyle: 'medium',
      timeStyle: 'short',
    });

    await nextTick();

    const prevTitle = document.title;
    document.documentElement.classList.add('online-print-active');
    document.title = `كشف حساب أونلاين — ${row.client_name}`;

    const cleanup = () => {
      document.documentElement.classList.remove('online-print-active');
      document.title = prevTitle;
      window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);
    window.setTimeout(() => {
      window.removeEventListener('afterprint', cleanup);
      document.documentElement.classList.remove('online-print-active');
      document.title = prevTitle;
    }, 120_000);

    window.print();
  } catch (error) {
    console.error('Failed to load customer statement for printing:', error);
  } finally {
    loading.value = false;
  }
};

const exportCsv = () => {
  let csvContent = '\uFEFF'; // UTF-8 BOM
  csvContent += 'اسم العميل,رقم الهاتف,إجمالي العمليات (ج.م),المدفوع (ج.م),الآجل المتبقي (ج.م),عدد المعاملات,آخر معاملة\r\n';

  records.value.forEach((r) => {
    csvContent += `"${r.client_name}","${r.phone}",${r.total_sales},${r.total_paid},${r.total_debt},${r.transaction_count},"${r.last_transaction || '—'}"\r\n`;
  });

  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.setAttribute('href', url);
  link.setAttribute('download', `online_debts_${new Date().toISOString().slice(0, 10)}.csv`);
  link.style.visibility = 'hidden';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
};

onMounted(async () => {
  await store.fetchAllSettings();
  await Promise.all([
    fetchBalances(),
    loadAccounts()
  ]);
  printSettingsStore.fetch().catch(() => {});
});
</script>

<style scoped>
.form-select-dark {
  width: 100%;
  padding: 10px 14px;
  background-color: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  color: #ffffff;
  outline: none;
  transition: all 0.2s;
  cursor: pointer;
}
.form-select-dark:focus {
  border-color: #8b5cf6;
  box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.2);
}
</style>

<style>
@media print {
  @page {
    size: A4 portrait;
    margin: 15mm 10mm;
  }

  html.online-print-active {
    background: #ffffff !important;
    color: #000000 !important;
  }

  /* Hide everything except the print-content block when active */
  html.online-print-active body * {
    visibility: hidden !important;
  }

  html.online-print-active #online-statement-print-content,
  html.online-print-active #online-statement-print-content * {
    visibility: visible !important;
  }

  html.online-print-active #online-statement-print-content {
    display: block !important;
    visibility: visible !important;
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    max-width: 100%;
    margin: 0;
    padding: 20px !important;
    box-shadow: none !important;
    border: none !important;
    border-radius: 0 !important;
    overflow: visible !important;
    background: white !important;
    color: black !important;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
  }

  #online-statement-print-content table {
    width: 100% !important;
    border-collapse: collapse !important;
    color: black !important;
    font-size: 10pt !important;
    direction: rtl !important;
    margin-top: 1.5rem !important;
    margin-bottom: 2rem !important;
  }

  #online-statement-print-content tr {
    page-break-inside: avoid !important;
    break-inside: avoid !important;
  }

  #online-statement-print-content th, 
  #online-statement-print-content td {
    border: 1px solid #c0c0c0 !important;
    padding: 8px 12px !important;
    color: black !important;
    text-align: right !important;
  }

  #online-statement-print-content th {
    background-color: #f3f4f6 !important;
    font-weight: bold !important;
  }

  .no-print {
    display: none !important;
  }
}
</style>
