<template>
  <div class="space-y-8 animate-in fade-in duration-700 pb-10">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <!-- Header -->
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-gold">موديول فوري</p>
          <h1 class="mt-1 text-3xl font-black tracking-tight text-text-main sm:text-4xl">إدارة ماكينات الشحن</h1>
          <p class="mt-2 max-w-2xl text-sm text-text-muted">
            متابعة أرصدة ماكينات شحن الرصيد والدفع الإلكتروني (فوري، أمان، ممتاز...). يمكنك شحن رصيد الماكينات بالخصم من حسابات التحصيل المتاحة.
          </p>
        </div>
      </div>

      <!-- Stats -->
      <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="flex flex-col justify-between rounded-2xl border border-white/10 bg-card-bg p-6 transition-all hover:border-gold/30">
          <div class="mb-2 text-xs font-bold uppercase tracking-widest text-text-muted">عدد الماكينات</div>
          <div class="font-mono text-3xl font-bold text-text-main">{{ store.machines.length }}</div>
        </div>
        <div class="flex flex-col justify-between rounded-2xl border border-white/10 bg-card-bg p-6 transition-all hover:border-gold/30">
          <div class="mb-2 text-xs font-bold uppercase tracking-widest text-text-muted">الماكينات النشطة</div>
          <div class="font-mono text-3xl font-bold text-success">{{ activeCount }}</div>
        </div>
        <div class="flex flex-col justify-between rounded-2xl border border-white/10 bg-card-bg p-6 transition-all hover:border-gold/30">
          <div class="mb-2 text-xs font-bold uppercase tracking-widest text-text-muted">إجمالي الأرصدة المتاحة بالماكينات</div>
          <div class="font-mono text-3xl font-bold text-gold">{{ formatMoney(totalBalance) }}</div>
        </div>
      </div>

      <!-- Filter Panel -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-5 mt-8">
        <div class="flex flex-col gap-4 md:flex-row md:flex-wrap">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="بحث باسم الماكينة أو النوع..."
            class="w-full md:w-80 px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-sm"
            @input="debouncedFetch"
          />
          <select
            v-model="activeFilter"
            class="px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-sm text-text-muted"
            @change="fetchList"
          >
            <option value="">كل الماكينات</option>
            <option value="1">نشط فقط</option>
            <option value="0">غير نشط</option>
          </select>
        </div>
        <p v-if="store.errors.machines" class="mt-3 text-sm text-error">{{ store.errors.machines }}</p>
      </div>

      <!-- List -->
      <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden mt-6">
        <div class="overflow-x-auto">
          <table class="w-full border-collapse">
            <thead>
              <tr class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-text-muted">
                <th class="px-6 py-4 text-right font-semibold">اسم الماكينة</th>
                <th class="px-6 py-4 text-right font-semibold">النوع / الشبكة</th>
                <th class="px-6 py-4 text-right font-semibold">الرصيد الحالي</th>
                <th class="px-6 py-4 text-right font-semibold">ملاحظات</th>
                <th class="px-6 py-4 text-right font-semibold">الحالة</th>
                <th class="px-6 py-4 text-right font-semibold text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="store.loading.machines">
                <td colspan="6" class="py-14 text-center text-text-muted">
                  <span class="inline-block h-8 w-8 animate-spin rounded-full border-2 border-gold border-t-transparent" />
                  <span class="mt-3 block text-sm">جاري التحميل...</span>
                </td>
              </tr>
              <tr v-else-if="!store.machines.length">
                <td colspan="6" class="px-6 py-12 text-center text-text-muted">لا توجد ماكينات مدخلة. يمكنك إضافة ماكينات جديدة من لوحة التحكم الإدارية (Filament).</td>
              </tr>
              <tr
                v-for="mach in store.machines"
                v-else
                :key="mach.id"
                class="border-b border-white/5 transition-colors hover:bg-white/5"
              >
                <td class="px-6 py-4 text-sm font-semibold text-text-main">{{ mach.name }}</td>
                <td class="px-6 py-4 text-sm text-text-muted">
                  <span class="px-2.5 py-1 rounded-full text-xs font-bold border border-white/10 bg-white/5">
                    {{ formatMachineType(mach.type) }}
                  </span>
                </td>
                <td class="px-6 py-4 font-mono text-sm font-bold text-gold">
                  {{ formatMoney(mach.balance) }}
                </td>
                <td class="px-6 py-4 text-sm text-text-muted max-w-xs truncate" :title="mach.notes">
                  {{ mach.notes || '—' }}
                </td>
                <td class="px-6 py-4">
                  <span
                    :class="
                      mach.is_active
                        ? 'rounded-full border border-success/30 bg-success/10 px-2 py-0.5 text-[11px] font-bold text-success'
                        : 'rounded-full border border-error/30 bg-error/10 px-2 py-0.5 text-[11px] font-bold text-error'
                    "
                  >
                    {{ mach.is_active ? 'نشط' : 'معطّل' }}
                  </span>
                </td>
                <td class="px-6 py-4 text-sm text-center">
                  <div class="flex justify-center gap-3">
                    <button
                      v-if="mach.is_active"
                      type="button"
                      class="px-3 py-1 bg-gold text-black rounded-lg text-xs font-bold hover:bg-gold/90 transition"
                      @click="openRecharge(mach)"
                    >
                      شحن الرصيد
                    </button>
                    <button
                      type="button"
                      class="px-3 py-1 bg-white/5 border border-white/10 text-text-main rounded-lg text-xs font-bold hover:bg-white/10 transition"
                      @click="openTransactions(mach)"
                    >
                      سجل الحركات
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Recharge Modal -->
    <teleport to="body">
      <div
        v-if="rechargeOpen"
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/65 p-4 backdrop-blur-sm"
        @click.self="rechargeOpen = false"
      >
        <div class="bg-card-bg border border-white/10 rounded-2xl w-full max-w-md overflow-hidden p-6 shadow-2xl animate-in zoom-in duration-150" role="dialog" @click.stop>
          <div class="flex items-center gap-3 mb-6">
            <div class="p-2 bg-gold/10 rounded-lg">
              <Banknote class="w-5 h-5 text-gold" />
            </div>
            <h3 class="text-lg font-black text-text-main">شحن ماكينة — {{ rechargeMachineData?.name }}</h3>
          </div>

          <p v-if="store.errors.recharge" class="mb-4 p-3 bg-error/10 border border-error/25 text-sm text-error rounded-xl">{{ store.errors.recharge }}</p>

          <form class="space-y-4" @submit.prevent="submitRecharge">
            <div>
              <label class="mb-2 block text-xs font-bold text-text-muted">الماكينة المستهدفة</label>
              <input :value="`${rechargeMachineData?.name} (${formatMachineType(rechargeMachineData?.type)})`" type="text" disabled class="form-select-dark opacity-60 pointer-events-none" />
            </div>
            <div>
              <label class="mb-2 block text-xs font-bold text-text-muted">خصم من حساب (بنوك / محافظ فوري فقط) <span class="text-error">*</span></label>
              <select v-model="rechargeForm.from_account_id" required class="form-select-dark">
                <option value="">اختر حساب الخزينة/التحصيل</option>
                <option v-for="acc in store.fawryAccounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} (الرصيد: {{ formatMoney(acc.balance) }})
                </option>
              </select>
            </div>
            <div>
              <label class="mb-2 block text-xs font-bold text-text-muted">مبلغ الشحن <span class="text-error">*</span></label>
              <div class="relative">
                <input v-model.number="rechargeForm.amount" type="number" step="0.01" min="0.01" required class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-sm font-mono tabular-nums" placeholder="0.00" />
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted text-xs">ج.م</span>
              </div>
            </div>
            <div>
              <label class="mb-2 block text-xs font-bold text-text-muted">البيان / ملاحظات</label>
              <textarea v-model="rechargeForm.notes" rows="2" maxlength="500" class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-sm resize-none" placeholder="البيان أو رقم التحويل..."></textarea>
            </div>
            <div class="mt-6 flex gap-3">
              <button type="submit" :disabled="store.loading.recharge" class="flex-1 py-3 px-4 bg-gold text-black rounded-xl text-sm font-bold disabled:opacity-45 hover:bg-gold/90 transition flex items-center justify-center gap-2">
                <Loader2 v-if="store.loading.recharge" class="w-4 h-4 animate-spin" />
                <span>{{ store.loading.recharge ? 'جاري الشحن...' : 'تأكيد الشحن' }}</span>
              </button>
              <button type="button" class="flex-1 py-3 px-4 bg-white/5 border border-white/10 text-text-main rounded-xl text-sm font-bold hover:bg-white/10 transition" @click="rechargeOpen = false">
                إلغاء
              </button>
            </div>
          </form>
        </div>
      </div>
    </teleport>

    <!-- Balance History Modal (Slide-over style) -->
    <teleport to="body">
      <div
        v-if="historyOpen"
        class="fixed inset-0 z-[200] flex justify-end bg-black/65 backdrop-blur-sm"
        @click.self="historyOpen = false"
      >
        <div class="bg-card-bg border-r border-white/10 h-full w-full max-w-xl p-6 shadow-2xl flex flex-col animate-in slide-in-from-left duration-200">
          <div class="flex items-center justify-between mb-6 pb-4 border-b border-white/10">
            <h3 class="text-lg font-black text-gold">سجل حركات الرصيد — {{ historyMachineData?.name }}</h3>
            <button @click="historyOpen = false" class="p-2 hover:bg-white/5 rounded-lg text-text-muted hover:text-text-main">
              ✕
            </button>
          </div>

          <div class="flex-1 overflow-y-auto space-y-4 pr-1">
            <div v-if="store.loading.machineTransactions" class="py-12 text-center text-text-muted">
              <span class="inline-block h-8 w-8 animate-spin rounded-full border-2 border-gold border-t-transparent" />
              <span class="mt-3 block text-sm">جاري تحميل سجل الحركات...</span>
            </div>
            <div v-else-if="!store.machineTransactions.length" class="text-center py-12 text-text-muted text-sm">
              لا توجد عمليات مسجلة لهذه الماكينة بعد.
            </div>
            <div
              v-for="tx in store.machineTransactions"
              v-else
              :key="tx.id"
              class="border border-white/5 rounded-xl bg-white/[0.01] p-4 space-y-2 hover:border-white/10 transition-colors"
            >
              <div class="flex items-center justify-between">
                <span class="text-xs text-text-muted font-mono">{{ formatDate(tx.created_at) }}</span>
                <span :class="[
                  'px-2 py-0.5 rounded-md text-[10px] font-bold',
                  tx.type === 'credit' ? 'bg-success/15 border border-success/30 text-success' : 'bg-error/15 border border-error/30 text-error'
                ]">
                  {{ tx.type === 'credit' ? 'شحن رصيد (+)' : 'سحب / عملية (-)' }}
                </span>
              </div>
              <div class="flex justify-between items-baseline">
                <span class="text-text-main text-sm font-semibold">{{ tx.description || 'عملية فوري' }}</span>
                <span :class="[
                  'font-mono text-base font-black',
                  tx.type === 'credit' ? 'text-success' : 'text-error'
                ]">
                  {{ tx.type === 'credit' ? '+' : '-' }}{{ formatMoney(tx.amount) }}
                </span>
              </div>
              <div class="flex justify-between text-xs text-text-muted font-mono border-t border-white/5 pt-2 mt-2">
                <span>قبل: {{ formatMoney(tx.balance_before) }}</span>
                <span>بعد: {{ formatMoney(tx.balance_after) }}</span>
              </div>
              <div class="text-[11px] text-text-muted/80 flex items-center justify-between pt-1">
                <span>بواسطة: {{ tx.created_by?.name || 'النظام' }}</span>
                <span v-if="tx.fawry_transaction_id" class="text-gold font-bold">معاملة رقم #{{ tx.fawry_transaction_id }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useFawryStore } from '@/stores/fawryStore';
import { debounce } from 'lodash-es';
import { Banknote, Loader2 } from 'lucide-vue-next';

const store = useFawryStore();

const searchQuery = ref('');
const activeFilter = ref('');

const rechargeOpen = ref(false);
const rechargeMachineData = ref(null);
const rechargeForm = ref({ from_account_id: '', amount: '', notes: '' });

const historyOpen = ref(false);
const historyMachineData = ref(null);

const activeCount = computed(() => store.machines.filter(m => m.is_active).length);
const totalBalance = computed(() => store.machines.reduce((sum, m) => sum + (parseFloat(m.balance) || 0), 0));

function buildFetchParams() {
  const p = {};
  if (searchQuery.value.trim()) p.search = searchQuery.value.trim();
  if (activeFilter.value === '1') p.is_active = true;
  if (activeFilter.value === '0') p.is_active = false;
  return p;
}

async function fetchList() {
  await store.fetchMachines(buildFetchParams());
}

const debouncedFetch = debounce(fetchList, 320);

function formatMoney(amount) {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(amount) || 0);
}

function formatMachineType(type) {
  const types = {
    fawry: 'فوري',
    aman: 'أمان',
    momtaz: 'ممتاز',
    masary: 'مصاري',
    other: 'أخرى',
  };
  return types[type] || type;
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function openRecharge(machine) {
  rechargeMachineData.value = machine;
  rechargeForm.value = { from_account_id: '', amount: '', notes: '' };
  if (store.errors.recharge) delete store.errors.recharge;
  rechargeOpen.value = true;
}

async function submitRecharge() {
  if (!rechargeMachineData.value) return;
  try {
    await store.rechargeMachine(rechargeMachineData.value.id, rechargeForm.value);
    rechargeOpen.value = false;
    await fetchList();
  } catch (e) {
    // handled by store error
  }
}

async function openTransactions(machine) {
  historyMachineData.value = machine;
  historyOpen.value = true;
  await store.fetchMachineTransactions(machine.id);
}

onMounted(async () => {
  await Promise.all([fetchList(), store.fetchFawryAccounts()]);
});
</script>
