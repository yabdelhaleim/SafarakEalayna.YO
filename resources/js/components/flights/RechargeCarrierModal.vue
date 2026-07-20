<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-labelledby="recharge-modal-title"
      @click.self="$emit('close')"
      @keydown.esc="$emit('close')"
    >
      <div class="bg-card border border-white/10 rounded-2xl max-w-md w-full p-6 shadow-2xl animate-in fade-in zoom-in duration-200">
        <div class="flex items-center justify-between mb-4">
          <h2 id="recharge-modal-title" class="text-lg font-bold flex items-center gap-2">
            <Zap class="w-5 h-5 text-gold" />
            شحن رصيد الناقل
          </h2>
          <button
            @click="$emit('close')"
            class="text-text-muted hover:text-text-main transition-colors p-1 rounded-lg hover:bg-white/5"
            aria-label="إغلاق"
          >
            <X class="w-5 h-5" />
          </button>
        </div>

        <!-- Carrier summary -->
        <div v-if="carrier" class="bg-white/5 rounded-xl p-3 mb-4 flex items-center justify-between text-sm">
          <div>
            <div class="font-bold">{{ carrier.name }}</div>
            <div class="text-xs text-text-muted font-mono">{{ carrier.code }}</div>
          </div>
          <div class="text-left">
            <div class="text-[10px] text-text-muted">الرصيد الحالي</div>
            <div class="font-mono font-bold text-sky-300">
              {{ formatMoney(carrier.balance) }} {{ carrier.currency }}
            </div>
          </div>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
          <!-- Source account -->
          <div>
            <label class="block text-xs text-text-muted mb-2">الحساب المصدر (يجب أن يكون {{ carrier?.currency }})</label>
            <select
              v-model="form.from_account_id"
              required
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              :disabled="loadingAccounts"
            >
              <option :value="null">— اختر حساب —</option>
              <option
                v-for="a in matchingAccounts"
                :key="a.id"
                :value="a.id"
              >
                {{ a.name }} ({{ a.currency }}) — رصيد: {{ formatMoney(a.balance) }}
              </option>
            </select>
            <p v-if="!loadingAccounts && matchingAccounts.length === 0" class="mt-1 text-xs text-error">
              لا توجد حسابات بنفس عملة الناقل ({{ carrier?.currency }}) — أنشئ حساباً أولاً.
            </p>
          </div>

          <!-- Amount -->
          <div>
            <label class="block text-xs text-text-muted mb-2">المبلغ</label>
            <input
              v-model.number="form.amount"
              type="number"
              min="0.01"
              step="0.01"
              required
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono text-lg"
              :placeholder="`0.00 ${carrier?.currency}`"
            />
          </div>

          <!-- Notes -->
          <div>
            <label class="block text-xs text-text-muted mb-2">ملاحظات (اختياري)</label>
            <textarea
              v-model="form.notes"
              rows="2"
              maxlength="500"
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              placeholder="سبب الشحن..."
            />
          </div>

          <!-- Error -->
          <p v-if="errorMsg" class="text-sm text-error bg-error/10 border border-error/30 rounded-lg p-3">
            {{ errorMsg }}
          </p>

          <!-- Actions -->
          <div class="flex gap-2">
            <button
              type="submit"
              :disabled="submitting || !form.from_account_id || !form.amount"
              class="flex-1 py-3 bg-gold text-black rounded-xl font-bold hover:bg-gold/90 transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
              <Loader2 v-if="submitting" class="w-4 h-4 animate-spin" />
              <Zap v-else class="w-4 h-4" />
              {{ submitting ? 'جاري الشحن...' : 'تأكيد الشحن' }}
            </button>
            <button
              type="button"
              @click="$emit('close')"
              class="px-5 py-3 bg-white/10 hover:bg-white/20 rounded-xl font-bold transition-all"
            >
              إلغاء
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Loader2, X, Zap } from 'lucide-vue-next';
import api from '@/utils/api';

const props = defineProps({
  carrier: { type: Object, required: true },
});

const emit = defineEmits(['close', 'done']);

const form = ref({
  from_account_id: null,
  amount: null,
  notes: '',
});

const accounts = ref([]);
const loadingAccounts = ref(true);
const submitting = ref(false);
const errorMsg = ref(null);

const matchingAccounts = computed(() => {
  if (!props.carrier?.currency) return [];
  const cur = String(props.carrier.currency).toUpperCase();
  return accounts.value.filter((a) => String(a.currency).toUpperCase() === cur && a.is_active);
});

function formatMoney(n) {
  return Number(n || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

async function loadAccounts() {
  loadingAccounts.value = true;
  try {
    // Pull all accounts (filter client-side)
    const res = await api.get('/api/v1/finance/accounts', { params: { per_page: 200 } });
    accounts.value = res.data?.data?.items ?? res.data?.data ?? [];
  } catch (e) {
    console.error('Failed to load accounts', e);
    accounts.value = [];
  } finally {
    loadingAccounts.value = false;
  }
}

async function submit() {
  if (submitting.value) return;
  submitting.value = true;
  errorMsg.value = null;
  try {
    const res = await api.post(`/api/v1/flight/carriers/${props.carrier.id}/recharge`, {
      from_account_id: form.value.from_account_id,
      amount: form.value.amount,
      notes: form.value.notes || null,
    });
    emit('done', res.data?.data);
  } catch (e) {
    const status = e?.response?.status;
    const apiMessage = e?.response?.data?.message;
    errorMsg.value = apiMessage || (status ? `فشل الشحن (HTTP ${status})` : (e?.message || 'حدث خطأ غير متوقع'));
  } finally {
    submitting.value = false;
  }
}

onMounted(loadAccounts);
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-error { color: var(--error); }
.bg-error { background-color: var(--error); }
.animate-in { animation: fadeIn 0.2s ease; }
@keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: none; } }
</style>
