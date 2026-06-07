<template>
  <div class="animate-in fade-in duration-500" dir="rtl">

    <!-- Hero Header -->
    <header class="relative overflow-hidden bg-gradient-to-br from-[#0a1628] via-[#0d1f3c] to-[#111827] border-b border-white/5 py-10 px-4 sm:px-6 lg:px-8">
      <div class="absolute inset-0 pointer-events-none opacity-10">
        <div class="absolute -top-20 -right-20 w-96 h-96 rounded-full bg-indigo-600 blur-3xl"></div>
        <div class="absolute -bottom-20 -left-20 w-64 h-64 rounded-full bg-purple-600 blur-3xl"></div>
      </div>
      <div class="relative z-10 mx-auto flex max-w-7xl flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-500/20 border border-indigo-500/30">
              <Users class="h-4 w-4 text-indigo-400" />
            </div>
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-indigo-400/90">وحدة التأشيرات</p>
          </div>
          <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">مديونيات عملاء التأشيرات</h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/50">
            متابعة أرصدة العملاء وما تم سداده وما تبقى من مديونيات في وحدة التأشيرات.
          </p>
        </div>
        <div class="flex gap-3 shrink-0">
          <button @click="exportCsv" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white/70 hover:bg-white/10 transition">
            <Download class="w-4 h-4" /> تصدير CSV
          </button>
          <button @click="loadData" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-500 transition">
            <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" /> تحديث
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <!-- Stats Row -->
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div v-for="s in summaryCards" :key="s.label" class="relative overflow-hidden rounded-2xl border border-white/10 bg-white/[0.02] p-5">
          <div class="absolute -top-4 -right-4 w-16 h-16 rounded-full opacity-10 blur-2xl" :class="s.glow"></div>
          <div class="mb-3 flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg" :class="s.iconBg">
              <component :is="s.icon" class="h-4 w-4" :class="s.iconColor" />
            </div>
            <span class="text-[10px] font-bold uppercase tracking-widest text-white/30">{{ s.label }}</span>
          </div>
          <p class="font-mono text-xl font-black tabular-nums" :class="s.valueColor">{{ s.value }}</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-5">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="md:col-span-1">
            <label class="block text-xs font-bold text-white/40 mb-2">بحث</label>
            <div class="relative">
              <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-white/30" />
              <input v-model="filters.search" @input="onSearch" type="text" placeholder="اسم العميل أو الهاتف..."
                class="w-full pr-9 pl-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:border-indigo-500/50 transition" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-bold text-white/40 mb-2">حالة الرصيد</label>
            <select v-model="filters.status" @change="applyFilter"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:border-indigo-500/50 transition cursor-pointer">
              <option value="all">الكل</option>
              <option value="debtors">المدينون فقط</option>
              <option value="creditors">الدائنون فقط</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-bold text-white/40 mb-2">الترتيب</label>
            <select v-model="filters.sort" @change="applySort"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:border-indigo-500/50 transition cursor-pointer">
              <option value="name_asc">الاسم (أ → ي)</option>
              <option value="debt_desc">الأعلى مديونية</option>
              <option value="debt_asc">الأقل مديونية</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="rounded-2xl border border-white/10 bg-[#0d1525] overflow-hidden shadow-xl">
        <div v-if="loading" class="py-24 text-center">
          <Loader2 class="w-10 h-10 animate-spin text-indigo-400 mx-auto mb-4" />
          <p class="text-white/40">جاري تحميل البيانات...</p>
        </div>

        <div v-else-if="filtered.length === 0" class="py-24 text-center">
          <Users class="w-16 h-16 mx-auto text-white/10 mb-4" />
          <p class="text-white/40 font-bold">لا توجد مديونيات مطابقة.</p>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-right">
            <thead>
              <tr class="border-b border-white/10 bg-black/20 text-[11px] uppercase tracking-widest text-white/30">
                <th class="px-6 py-4 font-bold">العميل</th>
                <th class="px-6 py-4 font-bold text-center">عدد الحجوزات</th>
                <th class="px-6 py-4 font-bold">إجمالي المبيعات</th>
                <th class="px-6 py-4 font-bold text-emerald-400/70">المسدد</th>
                <th class="px-6 py-4 font-bold text-red-400/70">المتبقي</th>
                <th class="px-6 py-4 font-bold">الحالة</th>
                <th class="px-6 py-4 font-bold text-left">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
              <tr v-for="row in paginated" :key="row.client_id" class="group hover:bg-white/[0.02] transition-colors">
                <!-- Customer -->
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-500/10 text-sm font-black text-indigo-400 border border-indigo-500/20">
                      {{ (row.client_name || '?').charAt(0) }}
                    </div>
                    <div>
                      <p class="font-bold text-white text-sm">{{ row.client_name }}</p>
                      <p class="text-xs text-white/30 flex items-center gap-1 mt-0.5">
                        <Phone class="w-3 h-3" />{{ row.phone || '—' }}
                      </p>
                    </div>
                  </div>
                </td>
                <!-- Bookings count -->
                <td class="px-6 py-4 text-center">
                  <span class="inline-flex items-center justify-center h-7 w-7 rounded-full bg-white/5 text-xs font-black text-white border border-white/10">
                    {{ row.booking_count }}
                  </span>
                </td>
                <!-- Total sales -->
                <td class="px-6 py-4 font-mono text-sm text-white/70">{{ fmt(row.total_sales) }}</td>
                <!-- Paid -->
                <td class="px-6 py-4 font-mono text-sm text-emerald-400 font-bold">{{ fmt(row.total_paid) }}</td>
                <!-- Remaining -->
                <td class="px-6 py-4">
                  <div v-if="row.total_debt > 0.009" class="flex items-center gap-2">
                    <span class="font-mono text-sm font-black text-red-400">{{ fmt(row.total_debt) }}</span>
                    <span class="text-[9px] font-black text-red-400 bg-red-500/10 border border-red-500/20 rounded-full px-1.5 py-0.5">آجل</span>
                  </div>
                  <span v-else-if="row.total_debt < -0.009" class="font-mono text-sm font-black text-emerald-400">
                    {{ fmt(Math.abs(row.total_debt)) }} (دائن)
                  </span>
                  <span v-else class="text-sm text-emerald-400/60">مسدّد ✓</span>
                </td>
                <!-- Status badge -->
                <td class="px-6 py-4">
                  <span v-if="row.total_debt > 0.009" class="inline-flex items-center rounded-full bg-red-500/10 px-3 py-1 text-xs font-bold text-red-400 border border-red-500/20">مدين</span>
                  <span v-else-if="row.total_debt < -0.009" class="inline-flex items-center rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-400 border border-emerald-500/20">دائن</span>
                  <span v-else class="inline-flex items-center rounded-full bg-white/5 px-3 py-1 text-xs font-bold text-white/30 border border-white/10">مسوّى</span>
                </td>
                <!-- Actions -->
                <td class="px-6 py-4 text-left">
                  <div class="flex gap-2 justify-end">
                    <button v-if="row.total_debt > 0.009" @click="openPayModal(row)"
                      class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-xs font-bold text-emerald-400 hover:bg-emerald-500 hover:text-black transition cursor-pointer">
                      <Wallet class="w-3.5 h-3.5" /> تسديد
                    </button>
                    <button @click="openStatement(row)"
                      class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/50 hover:border-indigo-500/40 hover:bg-indigo-500/10 hover:text-indigo-300 transition cursor-pointer">
                      <Eye class="w-3.5 h-3.5" /> كشف حساب
                    </button>
                    <button @click="printStatement(row)"
                      class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/50 hover:border-indigo-500/40 hover:bg-indigo-500/10 hover:text-indigo-300 transition cursor-pointer">
                      <Printer class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="filtered.length > perPage" class="px-6 py-4 border-t border-white/10 flex items-center justify-between">
          <span class="text-xs text-white/40">
            عرض {{ (page - 1) * perPage + 1 }}–{{ Math.min(page * perPage, filtered.length) }} من {{ filtered.length }}
          </span>
          <div class="flex gap-2">
            <button @click="page--" :disabled="page === 1"
              class="px-4 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-xs font-bold disabled:opacity-40 hover:bg-white/10 transition">السابق</button>
            <button @click="page++" :disabled="page * perPage >= filtered.length"
              class="px-4 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-xs font-bold disabled:opacity-40 hover:bg-white/10 transition">التالي</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== Statement Modal ===== -->
    <Teleport to="body">
      <div v-if="stmtOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" @click.self="stmtOpen = false">
        <div class="bg-[#0d1525] border border-white/15 w-full max-w-4xl rounded-2xl overflow-hidden shadow-2xl flex flex-col max-h-[85vh] animate-in zoom-in-95 duration-200">
          <div class="p-6 border-b border-white/10 flex items-center justify-between">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-indigo-400">كشف حساب تفصيلي — تأشيرات</p>
              <h3 class="text-lg font-black text-white mt-0.5">{{ stmtCustomer?.client_name }}</h3>
            </div>
            <button @click="stmtOpen = false" class="text-white/30 hover:text-white transition">
              <X class="w-6 h-6" />
            </button>
          </div>

          <div v-if="stmtLoading" class="flex-1 flex items-center justify-center py-16">
            <Loader2 class="w-10 h-10 animate-spin text-indigo-400" />
          </div>

          <div v-else class="flex-1 overflow-y-auto p-6 space-y-5">
            <!-- Summary -->
            <div class="grid grid-cols-3 gap-4 rounded-xl bg-white/[0.02] border border-white/5 p-4 text-center">
              <div>
                <p class="text-xs text-white/30 mb-1">إجمالي المبيعات</p>
                <p class="font-mono text-lg font-black text-white">{{ fmt(stmtData?.summary?.total_sales) }}</p>
              </div>
              <div>
                <p class="text-xs text-white/30 mb-1">إجمالي المسدد</p>
                <p class="font-mono text-lg font-black text-emerald-400">{{ fmt(stmtData?.summary?.total_paid) }}</p>
              </div>
              <div>
                <p class="text-xs text-white/30 mb-1">المتبقي (المديونية)</p>
                <p class="font-mono text-lg font-black" :class="(stmtData?.summary?.total_debt || 0) > 0 ? 'text-red-400' : 'text-emerald-400'">
                  {{ fmt(stmtData?.summary?.total_debt) }}
                </p>
              </div>
            </div>

            <!-- Transactions -->
            <div v-if="stmtData?.transactions?.length" class="border border-white/10 rounded-xl overflow-hidden">
              <table class="w-full text-right text-xs">
                <thead>
                  <tr class="bg-black/20 text-white/30 border-b border-white/10">
                    <th class="px-4 py-3 font-bold">التاريخ</th>
                    <th class="px-4 py-3 font-bold">النوع</th>
                    <th class="px-4 py-3 font-bold">البيان</th>
                    <th class="px-4 py-3 font-bold text-center">الموظف</th>
                    <th class="px-4 py-3 font-bold">مدين</th>
                    <th class="px-4 py-3 font-bold">دائن</th>
                    <th class="px-4 py-3 font-bold">الرصيد</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.04]">
                  <tr v-for="(item, i) in stmtData.transactions" :key="i" class="hover:bg-white/[0.02]">
                    <td class="px-4 py-3 text-white/40 font-mono">{{ item.date }}</td>
                    <td class="px-4 py-3">
                      <span class="rounded px-2 py-0.5 text-[10px] font-black"
                        :class="item.type === 'payment' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'">
                        {{ item.type_label }}
                      </span>
                    </td>
                    <td class="px-4 py-3 text-white/70 max-w-[200px] truncate" :title="item.description">{{ item.description }}</td>
                    <td class="px-4 py-3 text-center text-white/40">{{ item.employee }}</td>
                    <td class="px-4 py-3 font-mono" :class="item.debit > 0 ? 'text-white' : 'text-white/20'">
                      {{ item.debit > 0 ? fmt(item.debit) : '—' }}
                    </td>
                    <td class="px-4 py-3 font-mono" :class="item.credit > 0 ? 'text-emerald-400 font-bold' : 'text-white/20'">
                      {{ item.credit > 0 ? fmt(item.credit) : '—' }}
                    </td>
                    <td class="px-4 py-3 font-mono font-black text-indigo-400">{{ fmt(item.running_balance) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="text-center py-12 text-white/30">لا توجد حركات مالية مسجلة لهذا العميل</div>
          </div>

          <div class="p-5 border-t border-white/10 flex justify-end">
            <button @click="stmtOpen = false" class="px-6 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-white font-bold text-xs transition">إغلاق</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ===== Payment Modal ===== -->
    <Teleport to="body">
      <div v-if="payOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" @click.self="payOpen = false">
        <div class="bg-[#0a111e] border border-white/10 w-full max-w-md rounded-3xl overflow-hidden shadow-2xl animate-in zoom-in-95 duration-200">
          <div class="border-b border-white/5 px-8 py-6 flex items-center justify-between">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-indigo-400">سند قبض — تأشيرات</p>
              <h3 class="text-xl font-black text-white">{{ payRow?.client_name }}</h3>
            </div>
            <button @click="payOpen = false" class="text-white/20 hover:text-white transition">
              <X class="w-5 h-5" />
            </button>
          </div>

          <div class="p-8 space-y-5">
            <!-- Total debt badge -->
            <div class="rounded-xl bg-red-500/10 border border-red-500/20 px-5 py-3 flex justify-between items-center">
              <span class="text-xs font-bold text-red-400">إجمالي المديونية المتبقية:</span>
              <span class="font-mono text-lg font-black text-red-400">{{ fmt(payRow?.total_debt) }}</span>
            </div>

            <!-- Amount -->
            <div class="space-y-2">
              <label class="text-xs font-bold text-white/40">المبلغ المراد تسديده</label>
              <input v-model.number="payForm.amount" type="number" step="0.01" min="0.01"
                class="w-full rounded-2xl border border-white/10 bg-white/5 px-5 py-4 font-mono text-xl font-black text-white outline-none focus:border-indigo-500/50" />
              <div class="flex gap-2">
                <button type="button" @click="payForm.amount = payRow?.total_debt"
                  class="px-3 py-1.5 rounded-lg bg-indigo-500/20 hover:bg-indigo-500/30 border border-indigo-500/30 text-[10px] font-black text-indigo-300 transition">
                  كامل المديونية
                </button>
                <button type="button" @click="payForm.amount = Math.round((payRow?.total_debt || 0) / 2 * 100) / 100"
                  class="px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[10px] font-bold text-white/40 transition">
                  نصف المبلغ
                </button>
              </div>
            </div>

            <!-- Account -->
            <div class="space-y-2">
              <label class="text-xs font-bold text-white/40">من حساب</label>
              <select v-model="payForm.account_id"
                class="w-full rounded-2xl border border-white/10 bg-white/5 px-5 py-3.5 text-sm text-white outline-none focus:border-indigo-500/50 cursor-pointer">
                <option value="">اختر الحساب...</option>
                <option v-for="a in accounts" :key="a.id" :value="a.id">
                  {{ a.name }} — {{ fmt(a.balance, a.currency) }}
                </option>
              </select>
              <p v-if="!accounts.length" class="text-[10px] text-amber-400 font-bold">لا توجد حسابات تسوية نشطة.</p>
            </div>

            <!-- Notes -->
            <div class="space-y-2">
              <label class="text-xs font-bold text-white/40">ملاحظات</label>
              <textarea v-model="payForm.notes" rows="2"
                class="w-full rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-sm text-white outline-none focus:border-indigo-500/50"
                placeholder="تفاصيل اختيارية..."></textarea>
            </div>
          </div>

          <div class="px-8 pb-8 flex gap-3">
            <button type="button" @click="submitPayment" :disabled="submitting || !payForm.account_id || payForm.amount <= 0"
              class="flex-1 rounded-2xl bg-indigo-600 py-4 text-sm font-black text-white shadow-xl shadow-indigo-600/20 hover:bg-indigo-500 disabled:opacity-30 transition flex items-center justify-center gap-2 cursor-pointer">
              <Loader2 v-if="submitting" class="w-4 h-4 animate-spin" />
              تأكيد السداد
            </button>
            <button type="button" @click="payOpen = false"
              class="flex-1 rounded-2xl border border-white/10 bg-white/5 py-4 text-sm font-bold text-white/60 hover:bg-white/10 transition">
              إلغاء
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Print (hidden) -->
    <div id="visa-cust-print" class="hidden">
      <div class="font-sans text-black p-8 bg-white text-right" dir="rtl">
        <div class="flex justify-between items-center border-b-2 border-gray-800 pb-4 mb-6">
          <div>
            <h1 class="text-xl font-bold">سفرك علينا للسياحة والخدمات</h1>
            <p class="text-xs text-gray-500 mt-1">كشف حساب عميل — وحدة التأشيرات</p>
            <p class="text-[10px] text-gray-400">{{ new Date().toLocaleString('ar-EG') }}</p>
          </div>
          <div class="text-lg font-black border-2 border-gray-800 px-3 py-1 rounded">كشف حساب</div>
        </div>
        <div class="grid grid-cols-2 gap-4 bg-gray-100 rounded p-4 mb-6 text-xs border border-gray-300">
          <div>
            <p><strong>العميل:</strong> {{ printRow?.client_name }}</p>
            <p><strong>الهاتف:</strong> {{ printRow?.phone }}</p>
          </div>
          <div class="text-left">
            <p><strong>إجمالي المبيعات:</strong> {{ fmt(printData?.summary?.total_sales) }}</p>
            <p><strong>إجمالي المسدد:</strong> {{ fmt(printData?.summary?.total_paid) }}</p>
            <p class="text-red-700 font-black text-sm mt-1"><strong>المديونية:</strong> {{ fmt(printData?.summary?.total_debt) }}</p>
          </div>
        </div>
        <table class="w-full text-right border-collapse text-[11px] border border-gray-300">
          <thead>
            <tr class="bg-gray-100 text-gray-700">
              <th class="px-3 py-2 border border-gray-300">التاريخ</th>
              <th class="px-3 py-2 border border-gray-300">النوع</th>
              <th class="px-3 py-2 border border-gray-300">البيان</th>
              <th class="px-3 py-2 border border-gray-300 text-left">مدين</th>
              <th class="px-3 py-2 border border-gray-300 text-left">دائن</th>
              <th class="px-3 py-2 border border-gray-300 text-left">الرصيد</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, i) in printData?.transactions" :key="i">
              <td class="px-3 py-2 border border-gray-300">{{ item.date }}</td>
              <td class="px-3 py-2 border border-gray-300 font-bold">{{ item.type_label }}</td>
              <td class="px-3 py-2 border border-gray-300 text-gray-700">{{ item.description }}</td>
              <td class="px-3 py-2 border border-gray-300 text-left font-mono">{{ item.debit > 0 ? fmt(item.debit) : '—' }}</td>
              <td class="px-3 py-2 border border-gray-300 text-left font-mono">{{ item.credit > 0 ? fmt(item.credit) : '—' }}</td>
              <td class="px-3 py-2 border border-gray-300 text-left font-mono font-bold">{{ fmt(item.running_balance) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="grid grid-cols-3 gap-6 pt-12 border-t border-gray-300 text-xs text-center mt-10">
          <div><p class="font-bold text-gray-700">توقيع المحاسب</p><div class="h-16"></div><p>———————</p></div>
          <div><p class="font-bold text-gray-700">توقيع العميل</p><div class="h-16"></div><p>———————</p></div>
          <div><p class="font-bold text-gray-700">الختم والمصادقة</p><div class="h-16"></div><p>———————</p></div>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useVisaStore } from '@/stores/visaStore';
import {
  Users, Search, Download, RefreshCw, Loader2,
  Phone, Eye, Printer, X, Wallet,
  TrendingDown, TrendingUp, CheckCircle2, AlertCircle
} from 'lucide-vue-next';

const store = useVisaStore();

const loading = ref(false);
const records = ref([]);
const accounts = ref([]);

const filters = ref({ search: '', status: 'all', sort: 'name_asc' });
const page    = ref(1);
const perPage = 15;

// Statement modal
const stmtOpen     = ref(false);
const stmtLoading  = ref(false);
const stmtCustomer = ref(null);
const stmtData     = ref(null);

// Payment modal
const payOpen    = ref(false);
const submitting = ref(false);
const payRow     = ref(null);
const payForm    = ref({ amount: 0, account_id: '', notes: '' });

// Print
const printRow  = ref(null);
const printData = ref(null);

// ── Computed ──────────────────────────────────────────────
const filtered = computed(() => {
  let r = [...records.value];
  if (filters.value.search) {
    const q = filters.value.search.toLowerCase();
    r = r.filter(x => (x.client_name || '').toLowerCase().includes(q) || (x.phone || '').includes(q));
  }
  if (filters.value.status === 'debtors')   r = r.filter(x => x.total_debt > 0.009);
  if (filters.value.status === 'creditors') r = r.filter(x => x.total_debt < -0.009);

  if (filters.value.sort === 'debt_desc') r.sort((a, b) => b.total_debt - a.total_debt);
  else if (filters.value.sort === 'debt_asc') r.sort((a, b) => a.total_debt - b.total_debt);
  else r.sort((a, b) => (a.client_name || '').localeCompare(b.client_name || '', 'ar'));
  return r;
});

const paginated = computed(() => {
  const s = (page.value - 1) * perPage;
  return filtered.value.slice(s, s + perPage);
});

const summaryCards = computed(() => {
  const debtors  = records.value.filter(r => r.total_debt > 0.009);
  const totalDebt = debtors.reduce((s, r) => s + r.total_debt, 0);
  const totalSales = records.value.reduce((s, r) => s + r.total_sales, 0);
  return [
    { label: 'إجمالي العملاء',   value: records.value.length + ' عميل', icon: Users,        glow: 'bg-indigo-500', iconBg: 'bg-indigo-500/10', iconColor: 'text-indigo-400',  valueColor: 'text-white' },
    { label: 'المدينون',          value: debtors.length + ' عميل',         icon: AlertCircle,  glow: 'bg-red-500',    iconBg: 'bg-red-500/10',    iconColor: 'text-red-400',     valueColor: 'text-red-400' },
    { label: 'إجمالي المديونيات', value: fmt(totalDebt),                   icon: TrendingDown, glow: 'bg-red-500',    iconBg: 'bg-red-500/10',    iconColor: 'text-red-400',     valueColor: 'text-red-400' },
    { label: 'إجمالي المبيعات',   value: fmt(totalSales),                  icon: TrendingUp,   glow: 'bg-emerald-500', iconBg: 'bg-emerald-500/10', iconColor: 'text-emerald-400', valueColor: 'text-emerald-400' },
  ];
});

// ── Actions ───────────────────────────────────────────────
const loadData = async () => {
  loading.value = true;
  try {
    const [balances] = await Promise.all([
      store.fetchVisaCustomerBalances(),
      store.fetchVisaTreasuryOverview(),
    ]);
    records.value  = Array.isArray(balances) ? balances : [];
    accounts.value = store.treasuryOverview?.settlement_accounts || [];
    page.value = 1;
  } catch (e) {
    store.addToast('فشل تحميل البيانات', 'error');
  } finally {
    loading.value = false;
  }
};

let debTimer = null;
const onSearch = () => {
  clearTimeout(debTimer);
  debTimer = setTimeout(() => { page.value = 1; }, 300);
};
const applyFilter = () => { page.value = 1; };
const applySort   = () => { page.value = 1; };

const openStatement = async (row) => {
  stmtCustomer.value = row;
  stmtOpen.value     = true;
  stmtLoading.value  = true;
  stmtData.value     = null;
  try {
    stmtData.value = await store.fetchVisaCustomerStatement(row.client_id);
  } catch (e) {
    store.addToast('فشل تحميل كشف الحساب', 'error');
  } finally {
    stmtLoading.value = false;
  }
};

const openPayModal = (row) => {
  payRow.value  = row;
  payForm.value = { amount: row.total_debt, account_id: '', notes: '' };
  payOpen.value = true;
};

const submitPayment = async () => {
  if (!payForm.value.account_id || payForm.value.amount <= 0) return;
  submitting.value = true;
  try {
    await store.payVisaCustomerDebt(payRow.value.client_id, {
      amount:     payForm.value.amount,
      account_id: payForm.value.account_id,
      notes:      payForm.value.notes || null,
    });
    payOpen.value = false;
    await loadData();
  } catch {
    // error toast already shown by store
  } finally {
    submitting.value = false;
  }
};

const printStatement = async (row) => {
  try {
    const d = await store.fetchVisaCustomerStatement(row.client_id);
    printRow.value  = row;
    printData.value = d;
    document.body.classList.add('visa-cust-print-active');
    setTimeout(() => {
      const t = document.title;
      document.title = `كشف حساب — ${row.client_name}`;
      window.print();
      document.title = t;
      document.body.classList.remove('visa-cust-print-active');
    }, 250);
  } catch {
    store.addToast('فشل طباعة الكشف', 'error');
  }
};

const exportCsv = () => {
  if (!filtered.value.length) return;
  const headers = ['الاسم', 'الهاتف', 'إجمالي المبيعات', 'المسدد', 'المتبقي', 'الحجوزات'];
  const rows = filtered.value.map(r => [r.client_name, r.phone, r.total_sales, r.total_paid, r.total_debt, r.booking_count]);
  const csv = '\uFEFF' + headers.join(',') + '\n' + rows.map(r => r.join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
  a.download = `مديونيات_عملاء_التأشيرات_${new Date().toISOString().split('T')[0]}.csv`;
  a.click();
};

// ── Helpers ───────────────────────────────────────────────
function fmt(n, curr = 'EGP') {
  return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: curr }).format(Number(n) || 0);
}

onMounted(loadData);
</script>

<style scoped>
@media print {
  body > * { display: none !important; }
  body.visa-cust-print-active #visa-cust-print { display: block !important; }
}
</style>
