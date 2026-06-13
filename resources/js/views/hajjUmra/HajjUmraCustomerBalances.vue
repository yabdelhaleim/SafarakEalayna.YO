<template>
  <div class="space-y-8 animate-in fade-in duration-700" dir="rtl">
    
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white tracking-tight">
          مديونيات العملاء (الحج والعمرة)
        </h1>
        <p class="text-muted mt-1">
          متابعة وتدقيق مديونيات وأرصدة العملاء في موديول الحج والعمرة
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
          <span class="text-[10px] font-bold uppercase tracking-widest text-muted/65">{{ s.label }}</span>
        </div>
        <p class="font-mono text-xl sm:text-2xl font-black tabular-nums" :class="s.valueColor">{{ s.value }}</p>
      </div>
    </div>

    <!-- Filters Section -->
    <div class="bg-card border border-white/10 rounded-2xl p-6">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Search Input -->
        <div class="md:col-span-2 lg:col-span-1">
          <label class="block text-sm font-medium text-muted mb-2">اسم العميل أو الهاتف</label>
          <div class="relative">
            <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted" />
            <input
              v-model="filters.search"
              type="text"
              placeholder="البحث..."
              @input="debouncedFetch"
              class="w-full pr-10 pl-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-white"
            />
          </div>
        </div>

        <!-- Status Filter -->
        <div>
          <label class="block text-sm font-medium text-muted mb-2">حالة الرصيد</label>
          <select v-model="filters.status" @change="fetchBalances" class="w-full px-4 py-2.5 bg-input border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-sm text-white cursor-pointer">
            <option value="all">الكل</option>
            <option value="debtors">المدينون فقط (أرصدة موجبة)</option>
            <option value="creditors">الدائنون فقط (أرصدة سالبة)</option>
          </select>
        </div>

        <!-- Date From -->
        <div>
          <label class="block text-sm font-medium text-muted mb-2">من تاريخ</label>
          <input
            v-model="filters.from_date"
            type="date"
            @change="fetchBalances"
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-sm text-white"
          />
        </div>

        <!-- Date To -->
        <div>
          <label class="block text-sm font-medium text-muted mb-2">إلى تاريخ</label>
          <input
            v-model="filters.to_date"
            type="date"
            @change="fetchBalances"
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-sm text-white"
          />
        </div>
      </div>
    </div>

    <!-- Data Table -->
    <div class="bg-card border border-white/10 rounded-2xl overflow-hidden shadow-xl">
      <div v-if="loading" class="flex flex-col items-center justify-center py-20">
        <Loader2 class="w-10 h-10 animate-spin text-gold mb-4" />
        <span class="text-muted">جاري تحميل البيانات...</span>
      </div>

      <div v-else-if="records.length === 0" class="text-center py-20">
        <Users class="w-16 h-16 mx-auto text-muted/30 mb-4" />
        <h3 class="text-xl font-bold text-white">لا توجد مديونيات مسجلة</h3>
        <p class="text-muted mt-2">لا تتوفر حركات مالية مطابقة للمعايير المحددة حالياً.</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-right border-collapse">
          <thead>
            <tr class="border-b border-white/10 bg-black/20 text-[11px] uppercase tracking-[0.18em] text-muted">
              <th class="px-6 py-5 font-bold">العميل</th>
              <th class="px-6 py-5 font-bold text-center">عدد الحجوزات</th>
              <th class="px-6 py-5 font-bold">إجمالي الحجوزات</th>
              <th class="px-6 py-5 font-bold text-success/80">المدفوع</th>
              <th class="px-6 py-5 font-bold text-error/80">الآجل المتبقي</th>
              <th class="px-6 py-5 font-bold">آخر حجز</th>
              <th class="px-6 py-5 font-bold text-left">إجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/[0.04]">
            <tr
              v-for="(row, index) in paginatedRecords"
              :key="index"
              class="group transition-colors hover:bg-white/[0.025]"
            >
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gold/10 text-sm font-black text-gold border border-gold/20">
                    {{ (row.client_name || '?').charAt(0) }}
                  </div>
                  <div>
                    <p class="font-bold text-white text-sm">{{ row.client_name || '—' }}</p>
                    <p class="text-xs text-muted flex items-center gap-1 mt-0.5">
                      <Phone class="w-3 h-3"/>{{ row.phone || '—' }}
                    </p>
                  </div>
                </div>
              </td>

              <td class="px-6 py-4 text-center">
                <span class="inline-flex items-center rounded-full bg-sky-500/10 px-3 py-1 text-xs font-bold text-sky-400 border border-sky-500/20">
                  {{ row.booking_count || 0 }} حجز
                </span>
              </td>

              <td class="px-6 py-4">
                <span class="font-mono text-sm font-semibold text-white">
                  {{ formatMoney(row.total_sales) }}
                </span>
              </td>

              <td class="px-6 py-4">
                <span class="font-mono text-sm font-semibold text-success">
                  {{ formatMoney(row.total_paid) }}
                </span>
              </td>

              <td class="px-6 py-4">
                <div v-if="Number(row.total_debt) > 0" class="flex items-center gap-2">
                  <span class="font-mono text-sm font-black text-error">
                    {{ formatMoney(row.total_debt) }}
                  </span>
                  <span class="rounded-full bg-error/10 px-2 py-0.5 text-[9px] font-bold text-error border border-error/20">آجل</span>
                </div>
                <span v-else-if="Number(row.total_debt) < 0" class="font-mono text-sm font-black text-success">
                  {{ formatMoney(Math.abs(row.total_debt)) }} (دائن)
                </span>
                <span v-else class="font-mono text-sm text-success/60">مسدّد ✓</span>
              </td>

              <td class="px-6 py-4 text-sm text-muted">{{ formatDate(row.last_booking) }}</td>

              <td class="px-6 py-4 text-left">
                <div class="flex gap-2 justify-end">
                  <button
                    v-if="row.client_id && Number(row.total_debt) > 0"
                    @click="openPaymentModal(row)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-success/30 bg-success/10 px-3 py-1.5 text-xs font-bold text-success transition hover:bg-success hover:text-black cursor-pointer"
                  >
                    <WalletIcon class="w-3.5 h-3.5" />
                    تسديد
                  </button>
                  <button
                    @click="openDetails(row)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/60 transition hover:border-gold/40 hover:bg-gold/10 hover:text-gold cursor-pointer"
                  >
                    <Eye class="w-3.5 h-3.5" />
                    عرض التفاصيل
                  </button>
                  <button
                    @click="printStatement(row)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/60 transition hover:border-gold/40 hover:bg-gold/10 hover:text-gold cursor-pointer"
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
        <div class="text-sm text-muted">
          عرض {{ (currentPage - 1) * perPage + 1 }} إلى {{ Math.min(currentPage * perPage, records.length) }} من {{ records.length }} عميل
        </div>
        <div class="flex gap-2">
          <button
            @click="currentPage--"
            :disabled="currentPage === 1"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-white text-xs font-bold"
          >
            السابق
          </button>
          <button
            @click="currentPage++"
            :disabled="currentPage * perPage >= records.length"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-white text-xs font-bold"
          >
            التالي
          </button>
        </div>
      </div>
    </div>

    <!-- Details Statement Modal -->
    <div
      v-if="modalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm"
      @click.self="modalOpen = false"
    >
      <div class="bg-[#1A1A1A] border border-white/15 w-full max-w-4xl rounded-2xl overflow-hidden shadow-2xl flex flex-col max-h-[85vh] animate-in zoom-in duration-300">
        <!-- Modal Header -->
        <div class="p-6 border-b border-white/10 flex items-center justify-between bg-white/[0.02]">
          <div>
            <h3 class="text-lg font-bold text-white">كشف حساب العميل التفصيلي</h3>
            <p class="text-xs text-muted mt-1">كشف حركات الحج والعمرة للعميل: {{ selectedRow?.client_name }}</p>
          </div>
          <button @click="modalOpen = false" class="text-muted hover:text-white transition-colors">
            <X class="w-6 h-6" />
          </button>
        </div>

        <!-- Modal Content -->
        <div class="p-6 overflow-y-auto space-y-6 flex-1">
          <!-- Statement Summary Grid -->
          <div v-if="statementSummary" class="grid grid-cols-3 gap-4 bg-white/[0.02] border border-white/5 rounded-xl p-4">
            <div class="text-center border-l border-white/5 last:border-l-0">
              <span class="text-xs text-muted block mb-1">إجمالي الحجوزات</span>
              <span class="font-mono text-lg font-extrabold text-white">{{ formatMoney(statementSummary.total_sales) }}</span>
            </div>
            <div class="text-center border-l border-white/5 last:border-l-0">
              <span class="text-xs text-muted block mb-1">إجمالي المدفوع</span>
              <span class="font-mono text-lg font-extrabold text-success">{{ formatMoney(statementSummary.total_paid) }}</span>
            </div>
            <div class="text-center border-l border-white/5 last:border-l-0">
              <span class="text-xs text-muted block mb-1">المديونية المتبقية</span>
              <span class="font-mono text-lg font-extrabold" :class="statementSummary.total_debt > 0 ? 'text-error' : 'text-success'">
                {{ formatMoney(statementSummary.total_debt) }}
              </span>
            </div>
          </div>

          <!-- Statement Table -->
          <div class="border border-white/10 rounded-xl overflow-hidden">
            <table class="w-full text-right border-collapse text-xs">
              <thead>
                <tr class="bg-black/20 text-muted border-b border-white/10">
                  <th class="px-4 py-3 font-bold">التاريخ</th>
                  <th class="px-4 py-3 font-bold">نوع المعاملة</th>
                  <th class="px-4 py-3 font-bold">البيان والتفاصيل</th>
                  <th class="px-4 py-3 font-bold text-center">الموظف</th>
                  <th class="px-4 py-3 font-bold">مدين (قيمة الحجز)</th>
                  <th class="px-4 py-3 font-bold">دائن (مسدد)</th>
                  <th class="px-4 py-3 font-bold">رصيد الحساب</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/[0.04]">
                <tr v-for="(t, idx) in statementTransactions" :key="idx" class="hover:bg-white/[0.01]">
                  <td class="px-4 py-3 text-muted font-mono">{{ t.date }}</td>
                  <td class="px-4 py-3">
                    <span
                      class="inline-block rounded px-2 py-0.5 text-[10px] font-bold"
                      :class="t.debit > 0 ? 'bg-error/10 text-error border border-error/20' : 'bg-success/10 text-success border border-success/20'"
                    >
                      {{ t.type_label }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-white">{{ t.description }}</td>
                  <td class="px-4 py-3 text-center text-muted">{{ t.employee }}</td>
                  <td class="px-4 py-3 font-mono" :class="t.debit > 0 ? 'text-white' : 'text-muted'">{{ t.debit > 0 ? formatMoney(t.debit) : '—' }}</td>
                  <td class="px-4 py-3 font-mono" :class="t.credit > 0 ? 'text-success' : 'text-muted'">{{ t.credit > 0 ? formatMoney(t.credit) : '—' }}</td>
                  <td class="px-4 py-3 font-mono font-bold text-gold">{{ formatMoney(t.running_balance) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="p-6 border-t border-white/10 flex justify-end bg-white/[0.02]">
          <button @click="modalOpen = false" class="px-6 py-2.5 bg-white/5 hover:bg-white/10 rounded-xl font-bold text-white transition-colors text-xs">
            إغلاق الكشف
          </button>
        </div>
      </div>
    </div>

    <!-- Debt Payment Modal (سند قبض) -->
    <div
      v-if="payModalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm"
      @click.self="payModalOpen = false"
    >
      <div class="bg-[#1A1A1A] border border-white/15 w-full max-w-lg rounded-2xl overflow-hidden shadow-2xl flex flex-col animate-in zoom-in duration-300">
        <!-- Modal Header -->
        <div class="p-5 border-b border-white/10 flex items-center justify-between">
          <div>
            <h3 class="text-lg font-bold text-white">تسديد مديونية عميل (سند قبض)</h3>
            <p class="text-xs text-muted mt-1">العميل: {{ payForm.client_name }}</p>
          </div>
          <button @click="payModalOpen = false" class="text-muted hover:text-white">
            <X class="w-5 h-5" />
          </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 space-y-4">
          <!-- Live Outstanding Balance widget -->
          <div class="p-4 bg-error/10 border border-error/30 rounded-xl flex justify-between items-center">
            <span class="text-xs text-error font-bold">المديونية المتبقية الحالية:</span>
            <span class="font-mono text-lg font-black text-error">{{ formatMoney(payForm.current_debt) }}</span>
          </div>

          <!-- Form Fields -->
          <div class="space-y-4">
            <div>
              <label class="block text-xs text-muted mb-2 font-bold uppercase tracking-widest">نوع حساب التحصيل</label>
              <div class="flex flex-wrap gap-2 mb-3" dir="rtl">
                <button
                  v-for="chip in settlementChips"
                  :key="chip.id"
                  type="button"
                  @click="settlementCategory = chip.id"
                  :class="[
                    'flex items-center gap-2 px-3 py-2 rounded-lg border transition-all text-xs font-bold',
                    settlementCategory === chip.id
                      ? 'bg-white/10 border-gold text-gold'
                      : 'bg-white/[0.02] border-white/10 text-muted hover:border-white/20'
                  ]"
                >
                  <component :is="chip.icon" :class="['h-3.5 w-3.5', chip.iconClass]" />
                  {{ chip.label }}
                </button>
              </div>
            </div>

            <div>
              <label class="block text-xs text-muted mb-2 font-semibold">حساب التحصيل المستهدف</label>
              <select v-model="payForm.account_id" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white cursor-pointer text-xs">
                <option :value="null">اختر الحساب...</option>
                <option v-for="a in filteredSafeAccounts" :key="a.id" :value="a.id">
                  {{ a.name }} ({{ accountTypeLabel(a) }}) — {{ formatMoney(a.balance, a.currency) }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-xs text-muted mb-2 font-semibold">المبلغ المسدد (EGP)</label>
              <input
                v-model.number="payForm.amount"
                type="number"
                min="0.01"
                step="0.01"
                class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white font-mono text-sm"
              />
              <!-- Quick presets -->
              <div class="flex gap-2 mt-2">
                <button
                  type="button"
                  @click="payForm.amount = payForm.current_debt"
                  class="px-3 py-1 rounded-lg bg-gold/10 hover:bg-gold/20 border border-gold/30 text-[10px] text-gold font-bold transition-all"
                >
                  كامل المديونية
                </button>
                <button
                  type="button"
                  @click="payForm.amount = round(payForm.current_debt / 2)"
                  class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[10px] text-white transition-all"
                >
                  نصف المبلغ
                </button>
              </div>
            </div>

            <div>
              <label class="block text-xs text-muted mb-2 font-semibold">ملاحظات السند</label>
              <textarea
                v-model="payForm.notes"
                rows="2"
                placeholder="ملاحظات توضيحية..."
                class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white text-xs"
              ></textarea>
            </div>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="p-5 border-t border-white/10 bg-white/[0.02] flex justify-end gap-3">
          <button
            type="button"
            @click="payModalOpen = false"
            class="px-5 py-2.5 bg-white/5 hover:bg-white/10 rounded-xl text-white font-bold text-xs"
          >
            إلغاء
          </button>
          <button
            type="button"
            @click="submitPayment"
            :disabled="submittingPayment || !payForm.account_id || payForm.amount <= 0"
            class="px-6 py-2.5 bg-success text-white font-bold rounded-xl hover:bg-success/90 disabled:opacity-30 disabled:grayscale flex items-center gap-2 text-xs cursor-pointer"
          >
            <Loader2 v-if="submittingPayment" class="w-4 h-4 animate-spin" />
            حفظ سند القبض
          </button>
        </div>
      </div>
    </div>

    <!-- Hidden Printable Statement Layout (A4 Portrait) -->
    <div id="hajj-print-content" class="hidden font-sans text-black p-8 bg-white border border-gray-300 w-[210mm] min-h-[297mm] mx-auto rounded shadow-lg text-right" dir="rtl">
      <!-- Printable Header -->
      <div class="flex justify-between items-center border-b-2 border-gray-800 pb-4 mb-6">
        <div>
          <h1 class="text-xl font-bold text-gray-900">سفرك علينا للسياحة والخدمات</h1>
          <p class="text-xs text-gray-500 mt-1">قسم الحج والعمرة - كشف مالي تفصيلي للعميل</p>
          <p class="text-[10px] text-gray-400 mt-0.5">تاريخ الطباعة: {{ new Date().toLocaleString('ar-EG') }}</p>
        </div>
        <div class="text-left">
          <div class="text-lg font-black tracking-widest text-gray-800 border-2 border-gray-800 px-3 py-1 rounded">كشف حساب</div>
        </div>
      </div>

      <!-- Customer Summary Profile -->
      <div class="grid grid-cols-2 gap-4 bg-gray-100 rounded-lg p-4 mb-6 border border-gray-300 text-xs">
        <div>
          <p class="mb-1"><span class="font-bold text-gray-700 ml-1">اسم العميل:</span> {{ printData.customer?.name }}</p>
          <p><span class="font-bold text-gray-700 ml-1">رقم الهاتف:</span> {{ printData.customer?.phone }}</p>
        </div>
        <div class="text-left">
          <p class="mb-1"><span class="font-bold text-gray-700 ml-1">إجمالي قيمة المبيعات:</span> {{ formatMoney(printData.summary?.total_sales) }}</p>
          <p class="mb-1"><span class="font-bold text-gray-700 ml-1">إجمالي المبالغ المسددة:</span> {{ formatMoney(printData.summary?.total_paid) }}</p>
          <p class="text-sm font-black mt-2 text-red-700"><span class="ml-1">المديونية المتبقية:</span> {{ formatMoney(printData.summary?.total_debt) }}</p>
        </div>
      </div>

      <!-- Printable Transactions table -->
      <div class="mb-8">
        <h3 class="text-sm font-bold text-gray-800 border-b border-gray-400 pb-1 mb-3">تفاصيل الحركات القييدية والتحصيلات</h3>
        <table class="w-full text-right border-collapse text-[11px] border border-gray-300">
          <thead>
            <tr class="bg-gray-100 border-b border-gray-300 text-gray-700">
              <th class="px-3 py-2 font-bold border border-gray-300">التاريخ</th>
              <th class="px-3 py-2 font-bold border border-gray-300">المعاملة</th>
              <th class="px-3 py-2 font-bold border border-gray-300">التفاصيل والبيان</th>
              <th class="px-3 py-2 font-bold border border-gray-300">الموظف</th>
              <th class="px-3 py-2 font-bold text-left border border-gray-300">مدين (+)</th>
              <th class="px-3 py-2 font-bold text-left border border-gray-300">دائن (-)</th>
              <th class="px-3 py-2 font-bold text-left border border-gray-300">الرصيد المتبقي</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr v-for="(t, idx) in printData.transactions" :key="idx">
              <td class="px-3 py-2 border border-gray-300">{{ t.date }}</td>
              <td class="px-3 py-2 border border-gray-300 font-bold">{{ t.type_label }}</td>
              <td class="px-3 py-2 border border-gray-300 text-gray-700">{{ t.description }}</td>
              <td class="px-3 py-2 border border-gray-300 text-center text-gray-600">{{ t.employee }}</td>
              <td class="px-3 py-2 text-left border border-gray-300 font-mono">{{ t.debit > 0 ? formatMoney(t.debit) : '—' }}</td>
              <td class="px-3 py-2 text-left border border-gray-300 font-mono">{{ t.credit > 0 ? formatMoney(t.credit) : '—' }}</td>
              <td class="px-3 py-2 text-left border border-gray-300 font-mono font-bold">{{ formatMoney(t.running_balance) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Signatures Footer -->
      <div class="grid grid-cols-3 gap-6 pt-12 border-t border-gray-300 text-xs text-center mt-auto">
        <div>
          <p class="font-bold text-gray-700">توقيع محاسب المكتب</p>
          <div class="h-16"></div>
          <p class="text-gray-400">—————————————</p>
        </div>
        <div>
          <p class="font-bold text-gray-700">توقيع العميل المقر بالرصيد</p>
          <div class="h-16"></div>
          <p class="text-gray-400">—————————————</p>
        </div>
        <div>
          <p class="font-bold text-gray-700">الختم والمصادقة الإدارية</p>
          <div class="h-16"></div>
          <p class="text-gray-400">—————————————</p>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';
import { useAuthStore } from '@/stores/authStore';
import {
  Search, Download, Loader2, Users, Phone, Eye, Printer, X, 
  WalletIcon, Banknote, Wallet, Landmark, ChevronUp, ChevronDown, 
  DollarSign
} from 'lucide-vue-next';

const store = useHajjUmraStore();
const authStore = useAuthStore();

const loading = ref(false);
const records = ref([]);

const filters = ref({
  search: '',
  status: 'all',
  from_date: '',
  to_date: ''
});

// Pagination
const currentPage = ref(1);
const perPage = 15;

const paginatedRecords = computed(() => {
  const start = (currentPage.value - 1) * perPage;
  return records.value.slice(start, start + perPage);
});

// Stats Cards Computations
const customerStats = computed(() => {
  const totalSales = records.value.reduce((s, r) => s + (Number(r.total_sales) || 0), 0);
  const totalPaid = records.value.reduce((s, r) => s + (Number(r.total_paid) || 0), 0);
  const totalDebt = records.value.reduce((s, r) => s + (Number(r.total_debt) || 0), 0);
  const activeDebtors = records.value.filter(r => Number(r.total_debt) > 0).length;

  return [
    {
      label: 'إجمالي المبيعات (حجوزات)',
      value: formatMoney(totalSales),
      icon: DollarSign,
      glow: 'bg-indigo-500',
      iconBg: 'bg-indigo-500/10',
      iconColor: 'text-indigo-400',
      valueColor: 'text-white'
    },
    {
      label: 'إجمالي المحصل النقدي',
      value: formatMoney(totalPaid),
      icon: Banknote,
      glow: 'bg-emerald-500',
      iconBg: 'bg-emerald-500/10',
      iconColor: 'text-emerald-400',
      valueColor: 'text-emerald-400'
    },
    {
      label: 'إجمالي المديونيات المستحقة',
      value: formatMoney(totalDebt),
      icon: ChevronDown,
      glow: 'bg-red-500',
      iconBg: 'bg-red-500/10',
      iconColor: 'text-red-400',
      valueColor: 'text-red-400'
    },
    {
      label: 'العملاء المدينون النشطون',
      value: activeDebtors + ' عملاء',
      icon: Users,
      glow: 'bg-amber-500',
      iconBg: 'bg-amber-500/10',
      iconColor: 'text-amber-400',
      valueColor: 'text-amber-400'
    }
  ];
});

// Statement details modal state
const modalOpen = ref(false);
const selectedRow = ref(null);
const statementSummary = ref(null);
const statementTransactions = ref([]);

// Print temporary state
const printData = ref({
  customer: null,
  summary: null,
  transactions: []
});

// Repayment Modal form state
const payModalOpen = ref(false);
const submittingPayment = ref(false);
const settlementCategory = ref('cash');
const settlementChips = [
  { id: 'cash', label: 'نقدي / خزينة', icon: Banknote, iconClass: 'text-gold' },
  { id: 'wallet', label: 'محافظ', icon: Wallet, iconClass: 'text-sky-300' },
  { id: 'bank', label: 'بنك', icon: Landmark, iconClass: 'text-info' },
];

const payForm = ref({
  client_id: null,
  client_name: '',
  current_debt: 0,
  amount: 0,
  account_id: null,
  notes: ''
});

const filteredSafeAccounts = computed(() => {
  const accounts = store.accounts || [];
  if (settlementCategory.value === 'cash') {
    return accounts.filter(a => a.type === 'cashbox' || a.type === 'treasury');
  }
  if (settlementCategory.value === 'wallet') {
    return accounts.filter(a => a.type === 'wallet');
  }
  if (settlementCategory.value === 'bank') {
    return accounts.filter(a => a.type === 'bank');
  }
  return accounts;
});

const isAdminOrOwner = computed(() => {
  return authStore.isAdmin || authStore.user?.role === 'owner';
});

// Methods
const fetchBalances = async () => {
  loading.value = true;
  try {
    const params = {
      search: filters.value.search || undefined,
      status: filters.value.status || undefined,
      from_date: filters.value.from_date || undefined,
      to_date: filters.value.to_date || undefined
    };
    const response = await axios.get('/api/v1/hajj-umra/customer-balances', { params });
    records.value = response.data?.data ?? [];
    currentPage.value = 1;
  } catch (error) {
    console.error('Failed to fetch hajj-umra customer balances', error);
    store.addToast('فشل تحميل مديونيات العملاء', 'error');
  } finally {
    loading.value = false;
  }
};

let debounceTimeout = null;
const debouncedFetch = () => {
  if (debounceTimeout) clearTimeout(debounceTimeout);
  debounceTimeout = setTimeout(fetchBalances, 300);
};

const openDetails = async (row) => {
  selectedRow.value = row;
  modalOpen.value = true;
  statementSummary.value = null;
  statementTransactions.value = [];
  try {
    const response = await axios.get('/api/v1/hajj-umra/customer-statement', {
      params: { client_id: row.client_id }
    });
    const s = response.data?.data;
    if (s) {
      statementSummary.value = s.summary;
      statementTransactions.value = s.transactions;
    }
  } catch (error) {
    console.error('Failed to fetch customer statement', error);
    store.addToast('فشل تحميل كشف الحساب', 'error');
  }
};

const openPaymentModal = (row) => {
  payForm.value = {
    client_id: row.client_id,
    client_name: row.client_name,
    current_debt: Number(row.total_debt),
    amount: Number(row.total_debt),
    account_id: null,
    notes: ''
  };
  payModalOpen.value = true;
};

const submitPayment = async () => {
  if (!payForm.value.account_id || payForm.value.amount <= 0) return;
  submittingPayment.value = true;
  try {
    await axios.post(`/api/v1/customers/${payForm.value.client_id}/pay-debt`, {
      amount: payForm.value.amount,
      account_id: payForm.value.account_id,
      notes: payForm.value.notes?.trim() || null,
      type: 'receipt',
      module: 'hajj_umra'
    });
    store.addToast('تم قيد سند القبض وسداد المديونية بنجاح');
    payModalOpen.value = false;
    await fetchBalances();
  } catch (error) {
    console.error('Failed to submit repayment', error);
    const msg = error.response?.data?.message || 'فشل حفظ السند';
    store.addToast(msg, 'error');
  } finally {
    submittingPayment.value = false;
  }
};

const printStatement = async (row) => {
  try {
    const response = await axios.get('/api/v1/hajj-umra/customer-statement', {
      params: { client_id: row.client_id }
    });
    const s = response.data?.data;
    if (s) {
      printData.value = s;
      document.body.classList.add('hajj-print-active');
      setTimeout(() => {
        const titleBefore = document.title;
        document.title = `كشف حساب حج وعمرة - ${row.client_name}`;
        window.print();
        document.title = titleBefore;
        document.body.classList.remove('hajj-print-active');
      }, 300);
    }
  } catch (error) {
    console.error('Failed to print customer statement', error);
    store.addToast('فشل طباعة الكشف', 'error');
  }
};

const exportCsv = () => {
  if (records.value.length === 0) return;
  const headers = ['العميل', 'الهاتف', 'عدد الحجوزات', 'إجمالي قيمة الحجوزات (EGP)', 'إجمالي المدفوع (EGP)', 'المديونية المتبقية (EGP)', 'تاريخ آخر حجز'];
  const rows = records.value.map(r => [
    r.client_name,
    r.phone,
    r.booking_count,
    r.total_sales,
    r.total_paid,
    r.total_debt,
    r.last_booking
  ]);
  
  let csvContent = '\uFEFF' + headers.join(',') + '\n' + rows.map(e => e.join(',')).join('\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.setAttribute('href', url);
  link.setAttribute('download', `مديونيات_الحج_والعمرة_${new Date().toISOString().split('T')[0]}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
};

function formatMoney(n, curr = 'EGP') {
  const num = Number(n) || 0;
  return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: curr }).format(num);
}

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });
}

function accountTypeLabel(a) {
  const map = { cashbox: 'خزينة نقدية', bank: 'حساب بنكي', wallet: 'محفظة إلكترونية', treasury: 'خزينة', customer: 'عميل', supplier: 'مورّد' };
  return map[a.type] || a.type || '-';
}

function round(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

onMounted(async () => {
  await Promise.all([
    fetchBalances(),
    store.fetchAccounts({ types: 'cashbox,wallet,bank,treasury,post' })
  ]);
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.border-gold { border-color: var(--gold); }
.bg-gold\/10 { background-color: rgba(212, 168, 67, 0.1); }
.border-gold\/30 { border-color: rgba(212, 168, 67, 0.3); }

/* Printable Custom CSS */
@media print {
  body.hajj-print-active #app {
    display: none !important;
  }
  body.hajj-print-active #hajj-print-content {
    display: block !important;
  }
}
</style>

<!-- Unscoped Style Block to apply class hidden print effects -->
<style>
body.hajj-print-active #app {
  display: none !important;
}
body.hajj-print-active #hajj-print-content {
  display: block !important;
  background-color: white !important;
  color: black !important;
}
</style>
