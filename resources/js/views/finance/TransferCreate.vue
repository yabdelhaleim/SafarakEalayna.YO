<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <div class="flex items-center gap-4">
      <router-link to="/finance/transfers" class="p-2 hover:bg-white/10 rounded-lg transition-all">
        <svg class="w-5 h-5 text-text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
      </router-link>
      <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-text-main tracking-tight">تحويل أموال</h1>
        <p class="text-text-muted mt-1">نقل سيولة بين خزائن وبنوك ومحافظ الشركة — مقسّمة حسب الموديول</p>
      </div>
    </div>

    <div class="max-w-2xl mx-auto">
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <form @submit.prevent="handleTransfer">
          <div class="space-y-6">
            <div>
              <label for="transfer-from-account" class="block text-sm font-semibold text-text-main mb-2">
                من حساب
                <span class="text-rose-500">*</span>
              </label>
              <select
                id="transfer-from-account"
                name="transfer_from_account"
                v-model="transfer.from_account_id"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 text-white rounded-xl focus:border-gold outline-none"
              >
                <option value="" disabled>اختر الحساب المرسل...</option>
                <optgroup
                  v-for="group in treasuryAccountGroups"
                  :key="`from-${group.key}`"
                  :label="group.label"
                >
                  <option
                    v-for="acc in group.accounts"
                    :key="acc.id"
                    :value="acc.id"
                    :disabled="acc.id === transfer.to_account_id"
                  >
                    {{ acc.name }} — {{ formatAccountType(acc.type) }} ({{ formatCurrency(acc.balance, acc.currency) }})
                  </option>
                </optgroup>
              </select>
            </div>

            <div>
              <label for="transfer-to-account" class="block text-sm font-semibold text-text-main mb-2">
                إلى حساب
                <span class="text-rose-500">*</span>
              </label>
              <select
                id="transfer-to-account"
                name="transfer_to_account"
                v-model="transfer.to_account_id"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 text-white rounded-xl focus:border-gold outline-none"
              >
                <option value="" disabled>اختر الحساب المستلم...</option>
                <optgroup
                  v-for="group in treasuryAccountGroups"
                  :key="`to-${group.key}`"
                  :label="group.label"
                >
                  <option
                    v-for="acc in group.accounts"
                    :key="acc.id"
                    :value="acc.id"
                    :disabled="acc.id === transfer.from_account_id"
                  >
                    {{ acc.name }} — {{ formatAccountType(acc.type) }} ({{ formatCurrency(acc.balance, acc.currency) }})
                  </option>
                </optgroup>
              </select>
              <p class="text-xs text-text-muted mt-2">
                الحسابات مجمّعة حسب الموديول: طيران، باص، فوري، محافظ، إدارة عامة...
              </p>
            </div>

            <div>
              <label for="transfer-amount" class="block text-sm font-semibold text-text-main mb-2">
                المبلغ
                <span class="text-rose-500">*</span>
              </label>
              <div class="relative">
                <input
                  id="transfer-amount"
                  name="transfer_amount"
                  v-model.number="transfer.amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  required
                  class="w-full px-4 py-3 bg-input-bg border border-white/10 text-white rounded-xl focus:border-gold outline-none"
                  placeholder="0.00"
                />
                <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                  <span class="text-text-muted text-sm">{{ fromAccount?.currency || 'EGP' }}</span>
                </div>
              </div>
            </div>

            <div v-if="fromAccount && toAccount && fromAccount.currency !== toAccount.currency">
              <label for="transfer-exchange-rate" class="block text-sm font-semibold text-text-main mb-2">
                سعر الصرف
                <span class="text-rose-500">*</span>
              </label>
              <div class="flex items-center gap-2">
                <span class="text-sm text-text-muted">1 {{ fromAccount.currency }} =</span>
                <input
                  id="transfer-exchange-rate"
                  name="transfer_exchange_rate"
                  v-model.number="transfer.exchange_rate"
                  type="number"
                  step="0.000001"
                  min="0.000001"
                  required
                  class="flex-1 px-4 py-3 bg-input-bg border border-white/10 text-white rounded-xl focus:border-gold outline-none"
                />
                <span class="text-sm text-text-muted">{{ toAccount.currency }}</span>
              </div>
              <p class="mt-1 text-sm text-text-muted">
                المبلغ المحول: {{ formatCurrency(convertedAmount, toAccount?.currency) }}
              </p>
            </div>

            <div>
              <label for="transfer-notes" class="block text-sm font-semibold text-text-main mb-2">ملاحظات</label>
              <textarea
                id="transfer-notes"
                name="transfer_notes"
                v-model="transfer.notes"
                rows="3"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 text-white rounded-xl focus:border-gold outline-none resize-none"
                placeholder="سبب التحويل — مثال: تمويل خزينة الطيران"
              ></textarea>
            </div>

            <div v-if="fromAccount && toAccount" class="bg-white/5 border border-white/10 rounded-xl p-4">
              <h3 class="font-bold text-text-main mb-3">ملخص التحويل</h3>
              <div class="space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                  <span class="text-text-muted">من:</span>
                  <span class="font-medium text-white text-left">{{ fromAccount.name }} ({{ moduleLabel(fromAccount.module_type) }})</span>
                </div>
                <div class="flex justify-between gap-4">
                  <span class="text-text-muted">إلى:</span>
                  <span class="font-medium text-white text-left">{{ toAccount.name }} ({{ moduleLabel(toAccount.module_type) }})</span>
                </div>
                <div class="flex justify-between gap-4">
                  <span class="text-text-muted">المبلغ:</span>
                  <span class="font-medium text-gold">{{ formatCurrency(transfer.amount, fromAccount.currency) }}</span>
                </div>
                <div v-if="fromAccount.currency !== toAccount.currency" class="flex justify-between gap-4">
                  <span class="text-text-muted">المبلغ المحول:</span>
                  <span class="font-medium text-gold">{{ formatCurrency(convertedAmount, toAccount.currency) }}</span>
                </div>
              </div>
            </div>

            <p v-if="transferError" class="text-sm text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-xl px-4 py-3">
              {{ transferError }}
            </p>

            <div class="flex gap-3">
              <button
                type="submit"
                :disabled="loading || !canTransfer"
                class="flex-1 bg-gold hover:bg-gold/90 text-black px-4 py-3 rounded-xl font-bold transition-all disabled:opacity-50"
              >
                {{ loading ? 'جاري التحويل...' : 'تأكيد التحويل' }}
              </button>
              <button
                type="button"
                @click="router.back()"
                class="flex-1 bg-white/5 border border-white/10 text-text-muted px-4 py-3 rounded-xl font-bold hover:bg-white/10 transition-all"
              >
                إلغاء
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated } from 'vue';
import { useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useAccountStore } from '@/stores/accountStore';
import {
  formatAccountType,
  MODULE_GROUP_LABELS,
  useTreasuryAccountGroups,
} from '@/composables/useTreasuryAccountGroups';

const router = useRouter();
const accountStore = useAccountStore();
const transferError = ref('');

function createDefaultTransfer() {
  return {
    from_account_id: '',
    to_account_id: '',
    amount: 0,
    exchange_rate: 1.0,
    notes: '',
  };
}

const transfer = ref(createDefaultTransfer());

function resetForm() {
  transfer.value = createDefaultTransfer();
  transferError.value = '';
}

const { loading, accounts } = storeToRefs(accountStore);
const treasuryAccountGroups = useTreasuryAccountGroups(accounts);

function findAccount(accountId) {
  if (!accountId) return null;
  const id = Number(accountId);
  return accounts.value.find((acc) => Number(acc.id) === id) || null;
}

const fromAccount = computed(() => findAccount(transfer.value.from_account_id));

const toAccount = computed(() => findAccount(transfer.value.to_account_id));

const convertedAmount = computed(() => {
  if (!fromAccount.value || !toAccount.value) return 0;
  if (fromAccount.value.currency === toAccount.value.currency) {
    return transfer.value.amount;
  }
  return transfer.value.amount * transfer.value.exchange_rate;
});

const canTransfer = computed(() => {
  if (
    !transfer.value.from_account_id ||
    !transfer.value.to_account_id ||
    Number(transfer.value.from_account_id) === Number(transfer.value.to_account_id) ||
    transfer.value.amount <= 0 ||
    !fromAccount.value
  ) {
    return false;
  }

  if (fromAccount.value.balance < transfer.value.amount) {
    return false;
  }

  if (
    toAccount.value &&
    fromAccount.value.currency !== toAccount.value.currency &&
    (!transfer.value.exchange_rate || transfer.value.exchange_rate <= 0)
  ) {
    return false;
  }

  return true;
});

function moduleLabel(moduleType) {
  return MODULE_GROUP_LABELS[moduleType || 'general'] || 'غير مصنف';
}

async function handleTransfer() {
  transferError.value = '';
  try {
    await accountStore.transferFunds({
      from_account_id: transfer.value.from_account_id,
      to_account_id: transfer.value.to_account_id,
      amount: transfer.value.amount,
      converted_amount: convertedAmount.value,
      exchange_rate: transfer.value.exchange_rate,
      notes: transfer.value.notes,
    });

    router.push({ name: 'finance.transfers' });
  } catch (err) {
    transferError.value = err.response?.data?.message || 'تعذّر تنفيذ التحويل';
    console.error('Failed to transfer:', err);
  }
}

function formatCurrency(amount, currency = 'EGP') {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency || 'EGP',
  }).format(amount || 0);
}

onMounted(() => {
  resetForm();
  accountStore.fetchAccounts({
    types: 'cashbox,bank,treasury,wallet,post',
    is_active: true,
    per_page: 100,
  });
});

onActivated(() => {
  resetForm();
});
</script>
