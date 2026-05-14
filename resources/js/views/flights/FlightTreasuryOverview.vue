<template>
  <div class="finance-dashboard flight-booking animate-in pb-10 fade-in duration-700">
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
        <h2 class="text-xl font-bold text-white">أرصدة شركات الطيران</h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <div
            v-for="c in ov.carriers"
            :key="c.id"
            class="rounded-xl border border-white/10 bg-white/[0.04] p-4 text-sm"
          >
            <p class="font-bold text-white">{{ c.name }}</p>
            <p class="text-xs text-text-muted">{{ c.system?.name || '—' }} · {{ c.code }}</p>
            <p class="mt-2 font-mono text-gold tabular-nums">
              {{ Number(c.available_balance ?? c.balance ?? 0).toLocaleString('ar-EG') }} {{ c.currency }}
            </p>
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

    <!-- Modal: عمليات نظام -->
    <Teleport to="body">
      <div
        v-if="modal.type === 'system' && modal.system"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        @click.self="closeModal"
      >
        <div class="max-h-[85vh] w-full max-w-3xl overflow-hidden rounded-2xl border border-white/15 bg-[#0b1220] shadow-2xl">
          <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
            <div>
              <p class="text-xs text-text-muted">عمليات نظام الحجز</p>
              <h3 class="text-lg font-bold text-white">{{ modal.system.name }}</h3>
            </div>
            <button type="button" class="rounded-lg p-2 text-text-muted hover:bg-white/10" @click="closeModal">
              ✕
            </button>
          </div>
          <div class="max-h-[60vh] overflow-y-auto p-4">
            <table class="min-w-full text-right text-xs">
              <thead class="sticky top-0 bg-[#0b1220] text-text-muted">
                <tr>
                  <th class="px-2 py-2">التاريخ</th>
                  <th class="px-2 py-2">النوع</th>
                  <th class="px-2 py-2">المبلغ</th>
                  <th class="px-2 py-2">الرصيد بعد</th>
                  <th class="px-2 py-2">الحجز</th>
                  <th class="px-2 py-2">وصف</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in systemTxRows" :key="row.id" class="border-t border-white/5">
                  <td class="px-2 py-2 font-mono">{{ formatDt(row.created_at) }}</td>
                  <td class="px-2 py-2">
                    <span :class="row.type === 'credit' ? 'text-success' : 'text-warning'">{{ row.type }}</span>
                  </td>
                  <td class="px-2 py-2 font-mono tabular-nums">{{ Number(row.amount).toLocaleString('ar-EG') }}</td>
                  <td class="px-2 py-2 font-mono tabular-nums">{{ Number(row.balance_after).toLocaleString('ar-EG') }}</td>
                  <td class="px-2 py-2">{{ row.flight_booking?.booking_number || row.flight_booking_id || '—' }}</td>
                  <td class="max-w-[180px] truncate px-2 py-2 text-text-muted">{{ row.description || '—' }}</td>
                </tr>
              </tbody>
            </table>
            <p v-if="!systemTxLoading && !systemTxRows.length" class="py-8 text-center text-text-muted">لا توجد عمليات.</p>
            <p v-if="systemTxLoading" class="py-6 text-center text-text-muted">جاري التحميل…</p>
          </div>
          <div v-if="systemTxMeta && systemTxMeta.last_page > 1" class="flex justify-center gap-2 border-t border-white/10 px-4 py-3">
            <button
              type="button"
              class="rounded-lg border border-white/10 px-3 py-1 text-xs font-bold disabled:opacity-40"
              :disabled="systemTxMeta.current_page <= 1"
              @click="loadSystemPage(systemTxMeta.current_page - 1)"
            >
              السابق
            </button>
            <span class="self-center text-xs text-text-muted">
              {{ systemTxMeta.current_page }} / {{ systemTxMeta.last_page }}
            </span>
            <button
              type="button"
              class="rounded-lg border border-white/10 px-3 py-1 text-xs font-bold disabled:opacity-40"
              :disabled="systemTxMeta.current_page >= systemTxMeta.last_page"
              @click="loadSystemPage(systemTxMeta.current_page + 1)"
            >
              التالي
            </button>
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

    <!-- Modal: حركات حساب (طيران) -->
    <Teleport to="body">
      <div
        v-if="modal.type === 'account' && modal.account"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        @click.self="closeModal"
      >
        <div class="max-h-[85vh] w-full max-w-3xl overflow-hidden rounded-2xl border border-white/15 bg-[#0b1220] shadow-2xl">
          <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
            <div>
              <p class="text-xs text-text-muted">حركات الطيران على الحساب</p>
              <h3 class="text-lg font-bold text-white">{{ modal.account.name }}</h3>
            </div>
            <button type="button" class="rounded-lg p-2 text-text-muted hover:bg-white/10" @click="closeModal">
              ✕
            </button>
          </div>
          <div class="max-h-[60vh] overflow-y-auto p-4">
            <table class="min-w-full text-right text-xs">
              <thead class="sticky top-0 bg-[#0b1220] text-text-muted">
                <tr>
                  <th class="px-2 py-2">التاريخ</th>
                  <th class="px-2 py-2">المبلغ</th>
                  <th class="px-2 py-2">من</th>
                  <th class="px-2 py-2">إلى</th>
                  <th class="px-2 py-2">ملاحظات</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="tx in accountTxRows" :key="tx.id" class="border-t border-white/5">
                  <td class="px-2 py-2 font-mono">{{ formatDt(tx.created_at) }}</td>
                  <td class="px-2 py-2 font-mono tabular-nums">{{ Number(tx.amount).toLocaleString('ar-EG') }}</td>
                  <td class="px-2 py-2">{{ tx.from_account?.name || '—' }}</td>
                  <td class="px-2 py-2">{{ tx.to_account?.name || '—' }}</td>
                  <td class="max-w-[200px] truncate px-2 py-2 text-text-muted">{{ tx.notes || '—' }}</td>
                </tr>
              </tbody>
            </table>
            <p v-if="!accountTxLoading && !accountTxRows.length" class="py-8 text-center text-text-muted">لا توجد حركات.</p>
            <p v-if="accountTxLoading" class="py-6 text-center text-text-muted">جاري التحميل…</p>
          </div>
          <div v-if="accountTxMeta && accountTxMeta.last_page > 1" class="flex justify-center gap-2 border-t border-white/10 px-4 py-3">
            <button
              type="button"
              class="rounded-lg border border-white/10 px-3 py-1 text-xs font-bold disabled:opacity-40"
              :disabled="accountTxMeta.current_page <= 1"
              @click="loadAccountPage(accountTxMeta.current_page - 1)"
            >
              السابق
            </button>
            <span class="self-center text-xs text-text-muted">
              {{ accountTxMeta.current_page }} / {{ accountTxMeta.last_page }}
            </span>
            <button
              type="button"
              class="rounded-lg border border-white/10 px-3 py-1 text-xs font-bold disabled:opacity-40"
              :disabled="accountTxMeta.current_page >= accountTxMeta.last_page"
              @click="loadAccountPage(accountTxMeta.current_page + 1)"
            >
              التالي
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useFlightStore } from '@/stores/flightStore';
import { ArrowRight, Loader2, RefreshCw, Wallet } from 'lucide-vue-next';

const store = useFlightStore();

const ov = computed(() => store.treasuryOverview);

const settlementTypeLabel = (type) => {
  const t = String(type || '');
  if (t === 'cashbox') return 'نقدي / درج';
  if (t === 'bank') return 'بنك';
  if (t === 'wallet') return 'محفظة';
  if (t === 'treasury') return 'خزينة عامة';
  return t || 'حساب';
};

const settlementCardClass = (type) => {
  const t = String(type || '');
  const base = 'rounded-2xl border p-4';
  if (t === 'cashbox') return `${base} border-amber-500/25 bg-amber-500/10`;
  if (t === 'bank') return `${base} border-sky-500/25 bg-sky-500/10`;
  if (t === 'wallet') return `${base} border-emerald-500/25 bg-emerald-500/10`;
  if (t === 'treasury') return `${base} border-white/15 bg-white/[0.04]`;
  return `${base} border-white/10 bg-white/[0.03]`;
};

const fmt = (n, cur) => `${Number(n ?? 0).toLocaleString('ar-EG')} ${cur || ''}`.trim();

const cashboxAccounts = computed(() => (ov.value?.settlement_accounts || []).filter(a => a.type === 'cashbox' || a.type === 'treasury'));
const bankAccounts = computed(() => (ov.value?.settlement_accounts || []).filter(a => a.type === 'bank'));
const walletAccounts = computed(() => (ov.value?.settlement_accounts || []).filter(a => a.type === 'wallet'));

const availableForSystem = (sys) => {
  if (sys.available_balance != null && sys.available_balance !== '') {
    return Number(sys.available_balance);
  }
  return Number(sys.balance ?? 0) + Number(sys.credit_limit ?? 0);
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

const openAccountTx = async (acc) => {
  modal.value = { type: 'account', account: acc };
  await loadAccountPage(1);
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
