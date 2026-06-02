<template>
  <div class="space-y-8 pb-20" dir="rtl">

    <!-- ══════════ HEADER ══════════ -->
    <header class="relative overflow-hidden rounded-3xl border border-amber-500/20
                   bg-gradient-to-br from-[#1a1200] via-[#160f00] to-[#0d0d0d] p-8 shadow-2xl">
      <div class="pointer-events-none absolute inset-0
                  bg-[radial-gradient(ellipse_at_top_right,_rgba(245,158,11,0.10),_transparent_60%)]"/>
      <div class="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-widest text-amber-400/70">قسم الباص</p>
          <h1 class="mt-1 text-3xl font-black text-white">العملاء والشركات</h1>
          <p class="mt-1 text-sm text-white/40">
            عملاء الحجوزات · شركات النقل وحسابات الآجل
          </p>
        </div>

        <!-- Tab switcher -->
        <div class="flex gap-2 rounded-2xl border border-white/10 bg-black/30 p-1.5">
          <button
            v-for="tab in tabs" :key="tab.id"
            type="button"
            @click="activeTab = tab.id"
            class="flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold transition-all duration-200"
            :class="activeTab === tab.id
              ? 'bg-amber-500 text-black shadow-lg shadow-amber-500/30'
              : 'text-white/50 hover:text-white'"
          >
            <component :is="tab.icon" class="h-4 w-4"/>
            {{ tab.label }}
            <span v-if="tab.badge" class="rounded-full px-2 py-0.5 text-[10px] font-black"
              :class="activeTab === tab.id ? 'bg-black/20 text-black' : 'bg-white/10 text-white/60'">
              {{ tab.badge }}
            </span>
          </button>
        </div>
      </div>
    </header>

    <!-- ══════════════════════════════════════════
         TAB 1 — CUSTOMERS (عملاء الحجوزات)
    ══════════════════════════════════════════ -->
    <transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 translate-y-3" enter-to-class="opacity-100 translate-y-0">
      <section v-show="activeTab === 'customers'" class="space-y-6">

        <!-- Stats row -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div v-for="s in customerStats" :key="s.label"
            class="relative overflow-hidden rounded-2xl border border-white/10 bg-white/[0.02] p-5">
            <div class="absolute -right-3 -top-3 h-16 w-16 rounded-full opacity-10 blur-2xl" :class="s.glow"/>
            <div class="mb-3 flex items-center gap-2">
              <div class="flex h-8 w-8 items-center justify-center rounded-lg" :class="s.iconBg">
                <component :is="s.icon" class="h-4 w-4" :class="s.iconColor"/>
              </div>
              <span class="text-[10px] font-bold uppercase tracking-widest text-white/30">{{ s.label }}</span>
            </div>
            <p class="font-mono text-2xl font-black tabular-nums" :class="s.valueColor">{{ s.value }}</p>
          </div>
        </div>

        <!-- Search bar -->
        <div class="flex gap-3">
          <div class="relative flex-1">
            <Search class="pointer-events-none absolute right-4 top-1/2 h-4 w-4 -translate-y-1/2 text-white/30"/>
            <input
              v-model="customerSearch"
              type="text"
              placeholder="ابحث بالاسم أو رقم الهاتف..."
              class="w-full rounded-xl border border-white/10 bg-white/[0.04] py-3 pr-11 pl-4 text-sm text-white placeholder-white/25 outline-none transition focus:border-amber-400/40 focus:bg-white/[0.06]"
              @input="debouncedCustomerSearch"
            />
          </div>
          <!-- Debt filter -->
          <button
            type="button"
            @click="customerDebtOnly = !customerDebtOnly; fetchCustomers(1)"
            class="flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold transition"
            :class="customerDebtOnly
              ? 'border-red-500/50 bg-red-500/15 text-red-400'
              : 'border-white/10 bg-white/[0.04] text-white/50 hover:border-white/20'"
          >
            <AlertTriangle class="h-4 w-4"/>
            عليهم ديون فقط
          </button>
        </div>

        <!-- Customers table -->
        <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.02] shadow-xl">
          <div class="overflow-x-auto">
            <table class="w-full border-collapse text-right">
              <thead>
                <tr class="border-b border-white/5 bg-black/20 text-[11px] uppercase tracking-[0.18em] text-white/35">
                  <th class="px-6 py-5 font-bold">العميل</th>
                  <th class="px-6 py-5 font-bold text-center">الحجوزات</th>
                  <th class="px-6 py-5 font-bold">إجمالي المبيعات</th>
                  <th class="px-6 py-5 font-bold text-emerald-400/60">المدفوع</th>
                  <th class="px-6 py-5 font-bold text-red-400/60">الآجل المتبقي</th>
                  <th class="px-6 py-5 font-bold text-left">إجراءات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/[0.04]">
                <!-- Loading skeleton -->
                <template v-if="customersLoading">
                  <tr v-for="i in 6" :key="i">
                    <td v-for="j in 6" :key="j" class="px-6 py-5">
                      <div class="h-4 animate-pulse rounded-lg bg-white/5" :class="j===1?'w-36':'w-24'"/>
                    </td>
                  </tr>
                </template>

                <!-- Empty -->
                <tr v-else-if="customers.length === 0">
                  <td colspan="6" class="px-6 py-20 text-center">
                    <div class="flex flex-col items-center gap-4">
                      <div class="flex h-20 w-20 items-center justify-center rounded-full border border-white/5 bg-white/[0.03]">
                        <Users class="h-10 w-10 text-white/10"/>
                      </div>
                      <div>
                        <p class="text-lg font-bold text-white">لا يوجد عملاء</p>
                        <p class="mt-1 text-sm text-white/30">لم يتم العثور على عملاء بالمعايير المحددة</p>
                      </div>
                    </div>
                  </td>
                </tr>

                <!-- Data rows -->
                <tr v-else v-for="c in customers" :key="c.id"
                  class="group transition-colors hover:bg-white/[0.025]">
                  <!-- Customer name + phone -->
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 text-sm font-black text-amber-400 border border-amber-500/20">
                        {{ (c.full_name || '?').charAt(0) }}
                      </div>
                      <div>
                        <p class="font-bold text-white text-sm">{{ c.full_name || '—' }}</p>
                        <p class="text-xs text-white/40 flex items-center gap-1 mt-0.5">
                          <Phone class="h-3 w-3"/>{{ c.phone || '—' }}
                        </p>
                      </div>
                    </div>
                  </td>

                  <!-- Bookings count -->
                  <td class="px-6 py-4 text-center">
                    <span class="inline-flex items-center rounded-full bg-sky-500/10 px-3 py-1 text-xs font-bold text-sky-400 border border-sky-500/20">
                      {{ c.total_bus_bookings || 0 }} حجز
                    </span>
                  </td>

                  <!-- Total amount -->
                  <td class="px-6 py-4">
                    <span class="font-mono text-sm font-semibold text-white">
                      {{ formatMoney(c.total_bus_amount) }}
                    </span>
                  </td>

                  <!-- Paid -->
                  <td class="px-6 py-4">
                    <span class="font-mono text-sm font-semibold text-emerald-400">
                      {{ formatMoney(c.total_bus_paid) }}
                    </span>
                  </td>

                  <!-- Remaining debt -->
                  <td class="px-6 py-4">
                    <div v-if="Number(c.bus_remaining_debt) > 0" class="flex items-center gap-2">
                      <span class="font-mono text-sm font-black text-red-400">
                        {{ formatMoney(c.bus_remaining_debt) }}
                      </span>
                      <span class="rounded-full bg-red-500/10 px-2 py-0.5 text-[9px] font-bold text-red-400 border border-red-500/20">آجل</span>
                    </div>
                    <span v-else class="font-mono text-sm text-emerald-400/60">مسدّد ✓</span>
                  </td>

                  <!-- Actions -->
                  <td class="px-6 py-4 text-left">
                    <router-link
                      :to="{ name: 'bus.list', query: { search: c.phone || c.full_name } }"
                      class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/60 transition hover:border-amber-500/40 hover:bg-amber-500/10 hover:text-amber-400"
                    >
                      <ListOrdered class="h-3.5 w-3.5"/>
                      الحجوزات
                    </router-link>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div v-if="!customersLoading && customerTotalPages > 1"
            class="flex flex-col gap-3 border-t border-white/5 bg-white/[0.01] px-6 py-4 text-sm sm:flex-row sm:items-center sm:justify-between">
            <span class="text-white/40">
              عرض {{ customerPageFrom }}–{{ customerPageTo }} من {{ customerTotalItems }} عميل
            </span>
            <div class="flex items-center gap-2">
              <button type="button"
                class="rounded-lg border border-white/10 px-4 py-1.5 text-xs font-semibold text-white/60 transition hover:border-amber-400/40 disabled:opacity-30"
                :disabled="customerCurrentPage <= 1 || customersLoading"
                @click="prevCustomerPage">
                السابق
              </button>
              <span class="px-2 font-mono text-xs text-white/40">{{ customerCurrentPage }} / {{ customerTotalPages }}</span>
              <button type="button"
                class="rounded-lg border border-white/10 px-4 py-1.5 text-xs font-semibold text-white/60 transition hover:border-amber-400/40 disabled:opacity-30"
                :disabled="customerCurrentPage >= customerTotalPages || customersLoading"
                @click="nextCustomerPage">
                التالي
              </button>
            </div>
          </div>
        </div>
      </section>
    </transition>

    <!-- ══════════════════════════════════════════
         TAB 2 — COMPANIES (شركات الآجل)
    ══════════════════════════════════════════ -->
    <transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 translate-y-3" enter-to-class="opacity-100 translate-y-0">
      <section v-show="activeTab === 'companies'" class="space-y-6">

        <!-- Company stats -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div v-for="s in companyStatsCards" :key="s.label"
            class="relative overflow-hidden rounded-2xl border border-white/10 bg-white/[0.02] p-5">
            <div class="absolute -right-3 -top-3 h-16 w-16 rounded-full opacity-10 blur-2xl" :class="s.glow"/>
            <div class="mb-3 flex items-center gap-2">
              <div class="flex h-8 w-8 items-center justify-center rounded-lg" :class="s.iconBg">
                <component :is="s.icon" class="h-4 w-4" :class="s.iconColor"/>
              </div>
              <span class="text-[10px] font-bold uppercase tracking-widest text-white/30">{{ s.label }}</span>
            </div>
            <p class="font-mono text-2xl font-black tabular-nums" :class="s.valueColor">{{ s.value }}</p>
          </div>
        </div>

        <!-- Company list as cards -->
        <div v-if="store.loading.companies" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="i in 6" :key="i" class="h-44 animate-pulse rounded-2xl bg-white/[0.03] border border-white/5"/>
        </div>

        <div v-else-if="!store.companies.length"
          class="flex flex-col items-center gap-4 rounded-2xl border border-white/10 bg-white/[0.02] py-24 text-center">
          <div class="flex h-20 w-20 items-center justify-center rounded-full border border-white/5 bg-white/[0.03]">
            <Building2 class="h-10 w-10 text-white/10"/>
          </div>
          <div>
            <p class="text-lg font-bold text-white">لا توجد شركات مسجلة</p>
            <p class="mt-1 text-sm text-white/30">أضف شركات من لوحة الإدارة</p>
          </div>
        </div>

        <div v-else class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="company in store.companies" :key="company.id"
            class="group relative overflow-hidden rounded-2xl border bg-white/[0.02] p-6 transition-all duration-300 hover:shadow-xl"
            :class="Number(company.balance) < 0
              ? 'border-red-500/20 hover:border-red-500/40 hover:bg-red-950/20'
              : 'border-white/10 hover:border-white/20 hover:bg-white/[0.04]'"
          >
            <!-- Glow -->
            <div class="pointer-events-none absolute -left-6 -top-6 h-24 w-24 rounded-full blur-3xl transition"
              :class="Number(company.balance) < 0 ? 'bg-red-500/8 group-hover:bg-red-500/15' : 'bg-amber-500/5 group-hover:bg-amber-500/10'"/>

            <!-- Header row -->
            <div class="mb-5 flex items-start justify-between gap-3">
              <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border text-sm font-black"
                  :class="Number(company.balance) < 0
                    ? 'border-red-500/30 bg-red-500/10 text-red-400'
                    : 'border-amber-500/30 bg-amber-500/10 text-amber-400'">
                  {{ company.name.charAt(0) }}
                </div>
                <div class="min-w-0">
                  <p class="truncate font-bold text-white">{{ company.name }}</p>
                  <p v-if="company.phone" class="flex items-center gap-1 text-[11px] text-white/40 mt-0.5">
                    <Phone class="h-3 w-3"/> {{ company.phone }}
                  </p>
                </div>
              </div>
              <!-- Status badge -->
              <span class="shrink-0 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold"
                :class="company.is_active ? 'bg-emerald-500/10 text-emerald-400' : 'bg-white/5 text-white/30'">
                <span class="h-1.5 w-1.5 rounded-full bg-current"/>
                {{ company.is_active ? 'نشط' : 'متوقف' }}
              </span>
            </div>

            <!-- Financial block -->
            <div class="mb-5 rounded-xl border p-4 space-y-1"
              :class="Number(company.balance) < 0 ? 'border-red-500/15 bg-red-500/5' : 'border-emerald-500/15 bg-emerald-500/5'">
              <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider"
                  :class="Number(company.balance) < 0 ? 'text-red-400/70' : 'text-emerald-400/70'">
                  {{ Number(company.balance) < 0 ? 'مديونية عليك (آجل)' : 'رصيد دائن' }}
                </span>
                <TrendingDown v-if="Number(company.balance) < 0" class="h-4 w-4 text-red-400"/>
                <CheckCircle v-else class="h-4 w-4 text-emerald-400"/>
              </div>
              <p class="font-mono text-2xl font-black tabular-nums"
                :class="Number(company.balance) < 0 ? 'text-red-400' : 'text-emerald-400'">
                {{ formatMoney(Math.abs(Number(company.balance) || 0)) }}
              </p>
            </div>

            <!-- Action buttons -->
            <div class="flex gap-2">
              <router-link
                :to="{ name: 'bus.companies.statement', params: { id: company.id } }"
                class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 py-2.5 text-xs font-semibold text-white/60 transition hover:border-sky-500/40 hover:bg-sky-500/10 hover:text-sky-400"
              >
                <FileText class="h-3.5 w-3.5"/> كشف الحساب
              </router-link>
              <button
                v-if="Number(company.balance) < 0"
                type="button"
                @click="openPaymentModal(company)"
                class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 py-2.5 text-xs font-bold text-emerald-400 transition hover:bg-emerald-500 hover:text-black"
              >
                <Wallet class="h-3.5 w-3.5"/> تسديد
              </button>
              <router-link
                :to="{ name: 'bus.list', query: { company_id: company.id } }"
                class="flex items-center justify-center rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-xs text-white/40 transition hover:border-white/20 hover:text-white"
                title="حجوزات الشركة"
              >
                <ListOrdered class="h-3.5 w-3.5"/>
              </router-link>
            </div>
          </div>
        </div>

        <!-- Total debt summary bar -->
        <div v-if="totalCompanyDebt > 0"
          class="flex flex-col gap-4 rounded-2xl border border-red-500/20 bg-red-950/20 p-5 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-red-500/15 text-red-400">
              <AlertTriangle class="h-6 w-6"/>
            </div>
            <div>
              <p class="font-bold text-red-400">إجمالي المديونيات على شركات النقل</p>
              <p class="text-xs text-white/40">مجموع ما يجب تسديده لجميع الشركات</p>
            </div>
          </div>
          <div class="text-left">
            <p class="font-mono text-3xl font-black text-red-400 tabular-nums">
              {{ formatMoney(totalCompanyDebt) }}
            </p>
          </div>
        </div>

      </section>
    </transition>

    <!-- ══════════ PAYMENT MODAL ══════════ -->
    <Teleport to="body">
      <div v-if="showPaymentModal"
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
        @click.self="closePaymentModal">
        <div class="w-full max-w-md overflow-hidden rounded-2xl border border-white/10 bg-[#0d0d0d] shadow-2xl">
          <!-- Modal header -->
          <div class="flex items-center justify-between border-b border-white/5 bg-emerald-500/5 px-6 py-5">
            <h3 class="flex items-center gap-3 text-lg font-black text-emerald-400">
              <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/15">
                <Wallet class="h-5 w-5"/>
              </div>
              تسديد دين شركة النقل
            </h3>
            <button type="button" @click="closePaymentModal"
              class="flex h-8 w-8 items-center justify-center rounded-lg text-white/30 hover:bg-white/10 hover:text-white transition">
              ✕
            </button>
          </div>

          <form @submit.prevent="submitPayment" class="space-y-5 p-6">
            <!-- Company + debt info -->
            <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.04] px-4 py-4">
              <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-500/15 text-sm font-black text-red-400">
                  {{ selectedCompany?.name?.charAt(0) || '?' }}
                </div>
                <div>
                  <p class="text-[10px] text-white/30 uppercase tracking-wider">الشركة</p>
                  <p class="font-bold text-white">{{ selectedCompany?.name }}</p>
                </div>
              </div>
              <div class="text-left">
                <p class="text-[10px] text-white/30 uppercase tracking-wider">الدين الحالي</p>
                <p class="font-mono text-lg font-black text-red-400">
                  {{ formatMoney(Math.abs(Number(selectedCompany?.balance) || 0)) }}
                </p>
              </div>
            </div>

            <!-- Source account -->
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-white/40">حساب الدفع <span class="text-red-400">*</span></label>
              <select v-model="paymentForm.from_account_id" required
                class="w-full rounded-xl border border-white/15 bg-white/[0.05] px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-500/50">
                <option value="">— اختر حساب مصدر الدفع —</option>
                <option v-for="acc in treasuryAccounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} — {{ formatMoney(acc.balance) }}
                </option>
              </select>
            </div>

            <!-- Amount -->
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-white/40">المبلغ المراد تسديده <span class="text-red-400">*</span></label>
              <div class="relative">
                <input
                  v-model.number="paymentForm.amount"
                  type="number" step="0.01" required
                  :max="Math.abs(Number(selectedCompany?.balance) || 0)"
                  class="w-full rounded-xl border border-white/15 bg-white/[0.05] py-3 pr-4 pl-16 font-mono text-white outline-none transition focus:border-emerald-500/50"
                />
                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xs text-white/30">ج.م</span>
              </div>
              <!-- Quick amounts -->
              <div class="mt-2 flex gap-2">
                <button v-for="pct in [25,50,75,100]" :key="pct" type="button"
                  @click="paymentForm.amount = roundMoney(Math.abs(Number(selectedCompany?.balance)||0) * pct / 100)"
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
                placeholder="مثال: تسديد دفعة تذاكر مايو 2025"
                class="w-full rounded-xl border border-white/15 bg-white/[0.05] px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-500/50"
              />
            </div>

            <div class="flex gap-3 pt-2">
              <button type="submit" :disabled="submitting || !paymentForm.from_account_id || !paymentForm.amount"
                class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-500 py-3 text-sm font-black text-black shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40">
                <Loader2 v-if="submitting" class="h-4 w-4 animate-spin"/>
                <CheckCircle v-else class="h-4 w-4"/>
                {{ submitting ? 'جاري التسديد...' : 'تأكيد التسديد' }}
              </button>
              <button type="button" @click="closePaymentModal"
                class="rounded-xl border border-white/10 px-6 py-3 text-sm text-white/50 hover:bg-white/5 transition">
                إلغاء
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useBusStore } from '@/stores/busStore';
import axios from 'axios';
import {
  Users, Building2, Search, Phone, AlertTriangle, CheckCircle,
  ListOrdered, FileText, Wallet, TrendingDown, Loader2,
} from 'lucide-vue-next';

const store = useBusStore();

// ─── Tabs ──────────────────────────────────────────────────────────────────
const activeTab = ref('customers');

const tabs = computed(() => [
  {
    id: 'customers',
    label: 'العملاء',
    icon: Users,
    badge: customerTotalItems.value ? customerTotalItems.value : null,
  },
  {
    id: 'companies',
    label: 'شركات الآجل',
    icon: Building2,
    badge: store.companies.length || null,
  },
]);

// ─── Customers state ───────────────────────────────────────────────────────
const customers        = ref([]);
const customersLoading = ref(false);
const customerSearch   = ref('');
const customerDebtOnly = ref(false);
const customerCurrentPage = ref(1);
const customerTotalPages  = ref(1);
const customerTotalItems  = ref(0);
const customerPerPage     = ref(30);
let   customerSearchTimeout;

const customerPageFrom = computed(() =>
  customerTotalItems.value ? (customerCurrentPage.value - 1) * customerPerPage.value + 1 : 0
);
const customerPageTo   = computed(() =>
  Math.min(customerCurrentPage.value * customerPerPage.value, customerTotalItems.value)
);

const fetchCustomers = async (page = 1) => {
  customersLoading.value = true;
  try {
    const res = await axios.get('/api/v1/bus/customers', {
      params: {
        page,
        per_page: customerPerPage.value,
        search:    customerSearch.value || undefined,
        debt_only: customerDebtOnly.value || undefined,
      },
    });
    const d = res.data?.data?.customers || res.data?.data || {};
    const items = d.data || d.items || (Array.isArray(d) ? d : []);
    customers.value           = items;
    customerCurrentPage.value = d.current_page || page;
    customerTotalPages.value  = d.last_page     || 1;
    customerTotalItems.value  = d.total          || items.length;
  } catch (e) {
    console.error('fetchCustomers', e);
    customers.value = [];
  } finally {
    customersLoading.value = false;
  }
};

const debouncedCustomerSearch = () => {
  clearTimeout(customerSearchTimeout);
  customerSearchTimeout = setTimeout(() => { customerCurrentPage.value = 1; fetchCustomers(1); }, 450);
};
const prevCustomerPage = () => { if (customerCurrentPage.value > 1) fetchCustomers(customerCurrentPage.value - 1); };
const nextCustomerPage = () => { if (customerCurrentPage.value < customerTotalPages.value) fetchCustomers(customerCurrentPage.value + 1); };

// ─── Customer stats cards ──────────────────────────────────────────────────
const totalCustomerDebt = computed(() =>
  customers.value.reduce((s, c) => s + Math.max(0, Number(c.bus_remaining_debt) || 0), 0)
);
const totalCustomerSales = computed(() =>
  customers.value.reduce((s, c) => s + (Number(c.total_bus_amount) || 0), 0)
);
const customersWithDebt = computed(() =>
  customers.value.filter(c => Number(c.bus_remaining_debt) > 0).length
);

const customerStats = computed(() => [
  {
    label: 'إجمالي العملاء',
    value: customerTotalItems.value,
    icon: Users, iconBg: 'bg-sky-500/15', iconColor: 'text-sky-400',
    valueColor: 'text-white', glow: 'bg-sky-400',
  },
  {
    label: 'إجمالي المبيعات',
    value: formatMoney(totalCustomerSales.value),
    icon: ListOrdered, iconBg: 'bg-amber-500/15', iconColor: 'text-amber-400',
    valueColor: 'text-amber-400', glow: 'bg-amber-400',
  },
  {
    label: 'عملاء عليهم ديون',
    value: customersWithDebt.value,
    icon: AlertTriangle, iconBg: 'bg-red-500/15', iconColor: 'text-red-400',
    valueColor: 'text-red-400', glow: 'bg-red-400',
  },
  {
    label: 'إجمالي الآجل',
    value: formatMoney(totalCustomerDebt.value),
    icon: TrendingDown, iconBg: 'bg-orange-500/15', iconColor: 'text-orange-400',
    valueColor: 'text-orange-400', glow: 'bg-orange-400',
  },
]);

// ─── Company state ─────────────────────────────────────────────────────────
const totalCompanyDebt = computed(() =>
  store.companies.reduce((s, c) => {
    const b = Number(c.balance || 0);
    return b < 0 ? s + Math.abs(b) : s;
  }, 0)
);
const activeCompaniesCount = computed(() =>
  store.companies.filter(c => c.is_active).length
);
const totalCompanies = computed(() => store.companies.length);

const companyStatsCards = computed(() => [
  {
    label: 'إجمالي الشركات', value: totalCompanies.value,
    icon: Building2, iconBg: 'bg-sky-500/15', iconColor: 'text-sky-400',
    valueColor: 'text-white', glow: 'bg-sky-400',
  },
  {
    label: 'شركات نشطة', value: activeCompaniesCount.value,
    icon: CheckCircle, iconBg: 'bg-emerald-500/15', iconColor: 'text-emerald-400',
    valueColor: 'text-emerald-400', glow: 'bg-emerald-400',
  },
  {
    label: 'شركات عليها ديون',
    value: store.companies.filter(c => Number(c.balance) < 0).length,
    icon: AlertTriangle, iconBg: 'bg-red-500/15', iconColor: 'text-red-400',
    valueColor: 'text-red-400', glow: 'bg-red-400',
  },
  {
    label: 'إجمالي المديونيات',
    value: formatMoney(totalCompanyDebt.value),
    icon: TrendingDown, iconBg: 'bg-orange-500/15', iconColor: 'text-orange-400',
    valueColor: 'text-orange-400', glow: 'bg-orange-400',
  },
]);

// ─── Payment modal ─────────────────────────────────────────────────────────
const showPaymentModal  = ref(false);
const submitting        = ref(false);
const selectedCompany   = ref(null);
const treasuryAccounts  = ref([]);
const paymentForm = ref({ amount: 0, from_account_id: '', notes: '' });

const loadAccounts = async () => {
  try {
    const res = await axios.get('/api/v1/finance/accounts', { params: { per_page: 200 } });
    let raw = res.data?.data;
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
      if (Array.isArray(raw.items)) {
        raw = raw.items;
      } else if (raw.items && Array.isArray(raw.items.data)) {
        raw = raw.items.data;
      } else if (Array.isArray(raw.data)) {
        raw = raw.data;
      }
    }
    const all = Array.isArray(raw) ? raw : [];
    treasuryAccounts.value = all.filter(a => {
      const t = String(a.type?.value || a.type || '').toLowerCase();
      return ['cashbox', 'bank', 'wallet', 'treasury'].includes(t);
    });
  } catch (e) { console.error('loadAccounts', e); }
};

const openPaymentModal = (company) => {
  selectedCompany.value = company;
  paymentForm.value = {
    amount: roundMoney(Math.abs(Number(company.balance) || 0)),
    from_account_id: '',
    notes: '',
  };
  showPaymentModal.value = true;
};

const closePaymentModal = () => {
  showPaymentModal.value = false;
  selectedCompany.value  = null;
};

const submitPayment = async () => {
  if (!paymentForm.value.from_account_id) {
    store.addToast('يرجى اختيار حساب مصدر الدفع', 'error');
    return;
  }
  submitting.value = true;
  try {
    await store.payCompanyDebt(selectedCompany.value.id, paymentForm.value);
    store.addToast('تم تسديد الدين بنجاح ✓', 'success');
    closePaymentModal();
    await store.fetchCompanies();
  } catch {
    store.addToast('فشل التسديد. تحقق من الرصيد المتاح.', 'error');
  } finally {
    submitting.value = false;
  }
};

// ─── Helpers ───────────────────────────────────────────────────────────────
const formatMoney = (n) => {
  try {
    return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP', minimumFractionDigits: 0 }).format(Number(n) || 0);
  } catch { return `${Number(n || 0).toFixed(0)} ج.م`; }
};
const roundMoney = (n) => Math.round((Number(n) || 0) * 100) / 100;

// ─── Lifecycle ─────────────────────────────────────────────────────────────
onMounted(async () => {
  await Promise.all([
    fetchCustomers(1),
    store.fetchCompanies(),
    loadAccounts(),
  ]);
});
</script>
