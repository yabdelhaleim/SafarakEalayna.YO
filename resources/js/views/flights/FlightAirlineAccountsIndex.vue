<template>
  <div class="space-y-8 animate-in fade-in duration-700 pb-10">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-sky-400/90">وحدة الطيران</p>
          <h1 class="mt-1 text-3xl font-black tracking-tight text-text-main sm:text-4xl">حسابات شركات الطيران</h1>
          <p class="mt-2 max-w-2xl text-sm text-text-muted">
            أرصدة التسوية مع مورّدي الطيران (أماديوس، NDC، يدوي…). يُخصم الرصيد عند تأكيد الحجوزات ويُسجّل الشحن
            والحركات من هنا — منفصل عن خزائن المكتب المحاسبية.
          </p>
        </div>
        <button
          type="button"
          class="btn-airline inline-flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold shadow-xl"
          @click="openCreate"
        >
          <Plus class="h-4 w-4" />
          حساب جديد
        </button>
      </div>

      <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="عدد الحسابات" :value="accounts.length" icon="Plane" trend-color="blue" />
        <StatCard label="حسابات نشطة" :value="activeCount" icon="Activity" trend-color="success" />
        <div
          class="flex flex-col justify-between rounded-2xl border border-white/10 bg-card-bg p-6 transition-all hover:border-gold/30"
        >
          <div class="mb-2 text-xs font-bold uppercase tracking-widest text-text-muted">إجمالي الأرصدة</div>
          <div class="font-mono text-xl font-bold text-gold">{{ balanceSummary.display }}</div>
          <div v-if="balanceSummary.currencyCount > 1" class="mt-1 text-xs text-text-muted">
            {{ balanceSummary.currencyCount }} عملات — العرض نصي فقط
          </div>
        </div>
        <div
          class="flex flex-col justify-between rounded-2xl border border-white/10 bg-card-bg p-6 transition-all hover:border-gold/30"
        >
          <div class="mb-2 text-xs font-bold uppercase tracking-widest text-text-muted">رصيد + سقف (ملخص)</div>
          <div class="font-mono text-lg font-bold text-text-main">{{ totalsSummaryLine }}</div>
        </div>
      </div>

      <div class="flight-panel mt-8 !py-5 sm:!py-6">
        <div class="flex flex-col gap-4 md:flex-row md:flex-wrap">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="بحث بالاسم أو الكود…"
            class="flight-input min-w-[200px] flex-1"
            @input="debouncedFetch"
          />
          <input
            v-model="systemTypeFilter"
            type="text"
            placeholder="نوع النظام"
            class="flight-input md:w-48"
            @input="debouncedFetch"
          />
          <select v-model="activeFilter" class="flight-select md:w-44" @change="fetchList">
            <option value="">الكل</option>
            <option value="1">نشط فقط</option>
            <option value="0">غير نشط</option>
          </select>
        </div>
        <p v-if="listError" class="mt-3 text-sm text-error">{{ listError }}</p>
      </div>

      <div class="flight-panel mt-6 !overflow-hidden !p-0">
        <div class="overflow-x-auto">
          <table class="w-full border-collapse">
            <thead>
              <tr class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-text-muted">
                <th class="px-5 py-3 text-right font-semibold sm:px-6">الشركة / الكود</th>
                <th class="px-5 py-3 text-right font-semibold sm:px-6">النظام</th>
                <th class="px-5 py-3 text-right font-semibold sm:px-6">العملة</th>
                <th class="px-5 py-3 text-right font-semibold sm:px-6">الرصيد</th>
                <th class="px-5 py-3 text-right font-semibold sm:px-6">سقف الائتمان</th>
                <th class="px-5 py-3 text-right font-semibold sm:px-6">المتاح</th>
                <th class="px-5 py-3 text-right font-semibold sm:px-6">المعاملات</th>
                <th class="px-5 py-3 text-right font-semibold sm:px-6">الحالة</th>
                <th class="px-5 py-3 text-right font-semibold sm:px-6">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td colspan="9" class="py-14 text-center text-text-muted">
                  <span
                    class="inline-block h-8 w-8 animate-spin rounded-full border-2 border-gold border-t-transparent"
                  />
                  <span class="mt-3 block text-sm">جاري التحميل…</span>
                </td>
              </tr>
              <tr v-else-if="!accounts.length">
                <td colspan="9" class="px-6 py-12 text-center text-text-muted">لا توجد حسابات مطابقة.</td>
              </tr>
              <tr
                v-for="acc in accounts"
                v-else
                :key="acc.id"
                class="border-b border-white/5 transition-colors hover:bg-white/5"
              >
                <td class="px-5 py-4 text-sm sm:px-6">
                  <div class="font-semibold text-text-main">{{ acc.name }}</div>
                  <div class="font-mono text-xs text-text-muted">{{ acc.code }}</div>
                </td>
                <td class="px-5 py-4 text-sm text-text-muted sm:px-6">{{ acc.systemType }}</td>
                <td class="px-5 py-4 text-sm text-text-muted sm:px-6">{{ acc.currency }}</td>
                <td
                  class="px-5 py-4 font-mono text-sm font-semibold sm:px-6"
                  :class="Number(acc.balance) >= 0 ? 'text-success' : 'text-error'"
                >
                  {{ formatMoney(acc.balance, acc.currency) }}
                </td>
                <td class="px-5 py-4 font-mono text-sm text-gold sm:px-6">
                  {{ formatMoney(acc.creditLimit, acc.currency) }}
                </td>
                <td class="px-5 py-4 font-mono text-sm sm:px-6" :class="Number(acc.availableBalance) >= 0 ? 'text-success' : 'text-error'">
                  {{ formatMoney(acc.availableBalance, acc.currency) }}
                </td>
                <td class="px-5 py-4 text-sm text-text-muted sm:px-6">{{ acc.transactionsCount }}</td>
                <td class="px-5 py-4 sm:px-6">
                  <span
                    :class="
                      acc.isActive
                        ? 'rounded-full border border-success/30 bg-success/10 px-2 py-1 text-[11px] font-bold text-success'
                        : 'rounded-full border border-error/30 bg-error/10 px-2 py-1 text-[11px] font-bold text-error'
                    "
                  >
                    {{ acc.isActive ? 'نشط' : 'موقوف' }}
                  </span>
                </td>
                <td class="px-5 py-4 text-sm sm:px-6">
                  <button type="button" class="mr-2 font-bold text-gold hover:underline" @click="openCredit(acc)">شحن</button>
                  <button type="button" class="mr-2 font-bold text-sky-400 hover:underline" @click="goTransactions(acc.id)">معاملات</button>
                  <button type="button" class="mr-2 font-bold text-text-main hover:underline" @click="openEdit(acc)">تعديل</button>
                  <button type="button" class="font-bold text-error/90 hover:underline" @click="confirmDelete(acc)">حذف</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- شحن رصيد -->
    <teleport to="body">
      <div
        v-if="creditOpen"
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/65 p-4 backdrop-blur-sm"
        @click.self="creditOpen = false"
      >
        <div class="flight-panel max-h-[90vh] w-full max-w-md overflow-y-auto !p-6 shadow-2xl" role="dialog" @click.stop>
          <h3 class="mb-4 text-lg font-black text-text-main">شحن رصيد — {{ creditAccount?.name }}</h3>
          <p v-if="creditError" class="mb-3 text-sm text-error">{{ creditError }}</p>
          <form class="space-y-4" @submit.prevent="submitCredit">
            <div>
              <label class="mb-2 block text-xs font-bold text-text-muted">المبلغ</label>
              <input v-model.number="creditForm.amount" type="number" step="0.01" min="0.01" required class="flight-input w-full" />
            </div>
            <div>
              <label class="mb-2 block text-xs font-bold text-text-muted">البيان</label>
              <input v-model="creditForm.description" type="text" required maxlength="500" class="flight-input w-full" />
            </div>
            <div class="mt-6 flex gap-3">
              <button type="submit" :disabled="loading" class="btn-airline flex-1 px-4 py-3 text-sm font-bold disabled:opacity-45">
                {{ loading ? 'جاري الحفظ…' : 'تأكيد الشحن' }}
              </button>
              <button type="button" class="btn-airline-ghost flex-1 rounded-xl px-4 py-3 text-sm font-bold" @click="creditOpen = false">
                إلغاء
              </button>
            </div>
          </form>
        </div>
      </div>
    </teleport>

    <!-- إنشاء / تعديل -->
    <teleport to="body">
      <div
        v-if="formOpen"
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/65 p-4 backdrop-blur-sm"
        @click.self="formOpen = false"
      >
        <div class="flight-panel max-h-[90vh] w-full max-w-lg overflow-y-auto !p-6 shadow-2xl" role="dialog" @click.stop>
          <h3 class="mb-4 text-lg font-black text-text-main">{{ editingId ? 'تعديل حساب شركة طيران' : 'حساب شركة طيران جديد' }}</h3>
          <p v-if="formError" class="mb-3 text-sm text-error">{{ formError }}</p>
          <form class="space-y-3" @submit.prevent="submitForm">
            <div>
              <label class="mb-1 block text-xs font-bold text-text-muted">الاسم</label>
              <input v-model="accountForm.name" type="text" required class="flight-input w-full" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-text-muted">الكود</label>
              <input v-model="accountForm.code" type="text" required class="flight-input w-full" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-text-muted">نوع النظام (Amadeus, NDC, manual…)</label>
              <input v-model="accountForm.system_type" type="text" required class="flight-input w-full" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-text-muted">العملة</label>
              <select v-model="accountForm.currency" required class="flight-select w-full">
                <option value="EGP">EGP</option>
                <option value="SAR">SAR</option>
                <option value="USD">USD</option>
                <option value="AED">AED</option>
                <option value="QAR">QAR</option>
                <option value="KWD">KWD</option>
              </select>
            </div>
            <div v-if="!editingId">
              <label class="mb-1 block text-xs font-bold text-text-muted">الرصيد الافتتاحي</label>
              <input v-model.number="accountForm.balance" type="number" step="0.01" min="0" class="flight-input w-full" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-text-muted">سقف الائتمان</label>
              <input v-model.number="accountForm.credit_limit" type="number" step="0.01" min="0" class="flight-input w-full" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-text-muted">ملاحظات</label>
              <textarea v-model="accountForm.notes" rows="2" class="flight-input w-full" />
            </div>
            <div v-if="editingId" class="flex items-center gap-2">
              <input id="acc-active" v-model="accountForm.is_active" type="checkbox" class="rounded border-white/20" />
              <label for="acc-active" class="text-sm text-text-main">حساب نشط</label>
            </div>
            <div class="mt-6 flex gap-3">
              <button type="submit" :disabled="loading" class="btn-airline flex-1 px-4 py-3 text-sm font-bold disabled:opacity-45">
                {{ loading ? 'جاري الحفظ…' : 'حفظ' }}
              </button>
              <button type="button" class="btn-airline-ghost flex-1 rounded-xl px-4 py-3 text-sm font-bold" @click="formOpen = false">
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
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { debounce } from 'lodash-es'
import { Plus } from 'lucide-vue-next'
import { useFlightStore } from '@/stores/flightStore'
import StatCard from '@/components/dashboard/StatCard.vue'

const router = useRouter()
const flightStore = useFlightStore()
const { airlineAccounts, loading: storeLoading, errors: storeErrors } = storeToRefs(flightStore)

const searchQuery = ref('')
const systemTypeFilter = ref('')
const activeFilter = ref('')

const creditOpen = ref(false)
const creditAccount = ref(null)
const creditForm = ref({ amount: '', description: '' })

const formOpen = ref(false)
const editingId = ref(null)
const accountForm = ref({
  name: '',
  code: '',
  system_type: 'Amadeus',
  currency: 'SAR',
  balance: 0,
  credit_limit: 0,
  notes: '',
  is_active: true,
})

const loading = computed(() => storeLoading.value.airlineAccounts)

const accounts = computed(() => (Array.isArray(airlineAccounts.value) ? airlineAccounts.value : []))

const listError = computed(() => storeErrors.value.airlineAccounts || '')
const creditError = computed(() => storeErrors.value.addCredit || '')
const formError = computed(() => storeErrors.value.airlineAccountForm || '')

const activeCount = computed(() => accounts.value.filter((a) => a.isActive).length)

const balanceSummary = computed(() => {
  const list = accounts.value
  const curSet = [...new Set(list.map((a) => a.currency).filter(Boolean))]
  return {
    currencyCount: curSet.length,
    display:
      list.length === 0
        ? '—'
        : curSet.length > 1
          ? 'عدة عملات'
          : formatMoney(list.reduce((s, a) => s + Number(a.balance || 0), 0), curSet[0] || 'SAR'),
  }
})

/** ملخص نصي سريع عند تعدد العملات */
const totalsSummaryLine = computed(() => {
  const list = accounts.value
  if (!list.length) return '—'
  const byCur = {}
  for (const a of list) {
    const c = a.currency || '—'
    if (!byCur[c]) byCur[c] = { bal: 0, lim: 0 }
    byCur[c].bal += Number(a.balance || 0)
    byCur[c].lim += Number(a.creditLimit || 0)
  }
  return Object.entries(byCur)
    .map(([c, v]) => `${c}: رصيد ${formatPlain(v.bal)} / سقف ${formatPlain(v.lim)}`)
    .join(' · ')
})

function formatPlain(n) {
  return Number(n || 0).toLocaleString('ar-EG', { maximumFractionDigits: 2 })
}

function buildFetchParams() {
  const p = {}
  if (searchQuery.value.trim()) p.search = searchQuery.value.trim()
  if (systemTypeFilter.value.trim()) p.system_type = systemTypeFilter.value.trim()
  if (activeFilter.value === '1') p.is_active = true
  if (activeFilter.value === '0') p.is_active = false
  return p
}

async function fetchList() {
  await flightStore.fetchAirlineAccounts(buildFetchParams())
}

const debouncedFetch = debounce(fetchList, 320)

function formatMoney(amount, currency) {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency || 'SAR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(Number(amount) || 0)
}

function goTransactions(id) {
  router.push({ name: 'flights.airline-transactions', params: { id } })
}

function openCredit(acc) {
  creditAccount.value = acc
  creditForm.value = { amount: '', description: '' }
  if (flightStore.errors.addCredit) delete flightStore.errors.addCredit
  creditOpen.value = true
}

async function submitCredit() {
  if (!creditAccount.value) return
  try {
    await flightStore.addCreditToAccount(creditAccount.value.id, creditForm.value, buildFetchParams())
    creditOpen.value = false
  } catch {
    /* toast / errors من الستور */
  }
}

function openCreate() {
  editingId.value = null
  accountForm.value = {
    name: '',
    code: '',
    system_type: 'Amadeus',
    currency: 'SAR',
    balance: 0,
    credit_limit: 0,
    notes: '',
    is_active: true,
  }
  if (flightStore.errors.airlineAccountForm) delete flightStore.errors.airlineAccountForm
  formOpen.value = true
}

function openEdit(acc) {
  editingId.value = acc.id
  accountForm.value = {
    name: acc.name,
    code: acc.code,
    system_type: acc.systemType,
    currency: acc.currency,
    balance: acc.balance,
    credit_limit: acc.creditLimit,
    notes: acc.notes || '',
    is_active: acc.isActive,
  }
  if (flightStore.errors.airlineAccountForm) delete flightStore.errors.airlineAccountForm
  formOpen.value = true
}

async function submitForm() {
  try {
    if (editingId.value) {
      await flightStore.updateAirlineAccount(editingId.value, accountForm.value)
    } else {
      await flightStore.createAirlineAccount(accountForm.value)
    }
    formOpen.value = false
    await fetchList()
  } catch {
    /* رسالة في formError */
  }
}

async function confirmDelete(acc) {
  const ok = window.confirm(`حذف حساب «${acc.name}»؟ يُسمح فقط إن لم تكن له حجوزات أو معاملات.`)
  if (!ok) return
  try {
    await flightStore.deleteAirlineAccount(acc.id)
    await fetchList()
  } catch {
    window.alert(flightStore.errors.airlineAccountDelete || 'تعذر الحذف')
  }
}

onMounted(() => {
  fetchList()
})
</script>
