<template>
  <DashboardLayout>
    <div class="container mx-auto px-4 py-6">
      <div class="flex justify-between items-center mb-6">
        <div class="flex items-center gap-4">
          <button
            @click="router.back()"
            class="text-gray-600 hover:text-gray-900"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <h1 class="text-2xl font-bold text-gray-900">تحويل أموال</h1>
        </div>
      </div>

      <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-lg shadow p-6">
          <form @submit.prevent="handleTransfer">
            <div class="space-y-6">
              <!-- From Account -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  من حساب
                  <span class="text-red-500">*</span>
                </label>
                <select
                  v-model="transfer.from_account_id"
                  required
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">اختر الحساب</option>
                  <optgroup v-for="type in accountTypes" :key="type.key" :label="type.label">
                    <option
                      v-for="acc in type.accounts"
                      :key="acc.id"
                      :value="acc.id"
                      :disabled="acc.id === transfer.to_account_id"
                    >
                      {{ acc.name }} ({{ formatCurrency(acc.balance, acc.currency) }})
                    </option>
                  </optgroup>
                </select>
              </div>

              <!-- To Account -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  إلى حساب
                  <span class="text-red-500">*</span>
                </label>
                <select
                  v-model="transfer.to_account_id"
                  required
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">اختر الحساب</option>
                  <optgroup v-for="type in accountTypes" :key="type.key" :label="type.label">
                    <option
                      v-for="acc in type.accounts"
                      :key="acc.id"
                      :value="acc.id"
                      :disabled="acc.id === transfer.from_account_id"
                    >
                      {{ acc.name }} ({{ formatCurrency(acc.balance, acc.currency) }})
                    </option>
                  </optgroup>
                </select>
              </div>

              <!-- Amount -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  المبلغ
                  <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                  <input
                    v-model.number="transfer.amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    placeholder="0.00"
                  />
                  <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                    <span class="text-gray-500">{{ fromAccount?.currency || 'EGP' }}</span>
                  </div>
                </div>
              </div>

              <!-- Exchange Rate (if different currencies) -->
              <div v-if="fromAccount && toAccount && fromAccount.currency !== toAccount.currency">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  سعر الصرف
                  <span class="text-red-500">*</span>
                </label>
                <div class="flex items-center gap-2">
                  <span class="text-sm text-gray-600">1 {{ fromAccount.currency }} =</span>
                  <input
                    v-model.number="transfer.exchange_rate"
                    type="number"
                    step="0.000001"
                    min="0.000001"
                    required
                    class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                  />
                  <span class="text-sm text-gray-600">{{ toAccount.currency }}</span>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                  المبلغ المحول: {{ formatCurrency(convertedAmount, toAccount?.currency) }}
                </p>
              </div>

              <!-- Notes -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  ملاحظات
                </label>
                <textarea
                  v-model="transfer.notes"
                  rows="3"
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                  placeholder="سبب التحويل..."
                ></textarea>
              </div>

              <!-- Summary -->
              <div v-if="fromAccount && toAccount" class="bg-gray-50 rounded-lg p-4">
                <h3 class="font-medium text-gray-900 mb-2">ملخص التحويل</h3>
                <div class="space-y-1 text-sm">
                  <div class="flex justify-between">
                    <span class="text-gray-600">من:</span>
                    <span class="font-medium">{{ fromAccount.name }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-gray-600">إلى:</span>
                    <span class="font-medium">{{ toAccount.name }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-gray-600">المبلغ:</span>
                    <span class="font-medium">{{ formatCurrency(transfer.amount, fromAccount.currency) }}</span>
                  </div>
                  <div v-if="fromAccount.currency !== toAccount.currency" class="flex justify-between">
                    <span class="text-gray-600">المبلغ المحول:</span>
                    <span class="font-medium">{{ formatCurrency(convertedAmount, toAccount.currency) }}</span>
                  </div>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex gap-3">
                <button
                  type="submit"
                  :disabled="loading || !canTransfer"
                  class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {{ loading ? 'جاري التحويل...' : 'تأكيد التحويل' }}
                </button>
                <button
                  type="button"
                  @click="router.back()"
                  class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400"
                >
                  إلغاء
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAccountStore } from '@/stores/accountStore'
import DashboardLayout from '@/layouts/DashboardLayout.vue'

const router = useRouter()
const accountStore = useAccountStore()

const transfer = ref({
  from_account_id: '',
  to_account_id: '',
  amount: 0,
  exchange_rate: 1.0,
  notes: '',
})

const { loading, accounts } = storeToRefs(accountStore)

const accountTypes = computed(() => {
  const types = {
    tourism: {
      key: 'tourism',
      label: 'حسابات السياحة',
      accounts: accounts.value.filter(acc => acc.module_type === 'tourism' && acc.is_active),
    },
    office: {
      key: 'office',
      label: 'حسابات المكتب',
      accounts: accounts.value.filter(acc => acc.module_type === 'office' && acc.is_active),
    },
  }
  return types
})

const fromAccount = computed(() => {
  return accounts.value.find(acc => acc.id === transfer.value.from_account_id)
})

const toAccount = computed(() => {
  return accounts.value.find(acc => acc.id === transfer.value.to_account_id)
})

const convertedAmount = computed(() => {
  if (!fromAccount.value || !toAccount.value) return 0
  if (fromAccount.value.currency === toAccount.value.currency) {
    return transfer.value.amount
  }
  return transfer.value.amount * transfer.value.exchange_rate
})

const canTransfer = computed(() => {
  return (
    transfer.value.from_account_id &&
    transfer.value.to_account_id &&
    transfer.value.from_account_id !== transfer.value.to_account_id &&
    transfer.value.amount > 0 &&
    fromAccount.value &&
    fromAccount.value.balance >= transfer.value.amount
  )
})

async function handleTransfer() {
  try {
    await accountStore.transferFunds({
      from_account_id: transfer.value.from_account_id,
      to_account_id: transfer.value.to_account_id,
      amount: transfer.value.amount,
      from_currency: fromAccount.value.currency,
      to_currency: toAccount.value.currency,
      exchange_rate: transfer.value.exchange_rate,
      converted_amount: convertedAmount.value,
      notes: transfer.value.notes,
    })

    router.push({ name: 'finance.transfers.list' })
  } catch (err) {
    console.error('Failed to transfer:', err)
  }
}

function formatCurrency(amount, currency = 'EGP') {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency,
  }).format(amount)
}

// Fetch accounts on mount
accountStore.fetchAccounts()
</script>
