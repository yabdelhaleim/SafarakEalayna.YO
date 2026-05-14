import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

function unwrapAccountsList(payload) {
  if (!payload) return []
  if (Array.isArray(payload)) return payload
  if (Array.isArray(payload.data)) return payload.data
  return []
}

export const useAccountStore = defineStore('account', () => {
  const accounts = ref([])
  const account = ref(null)
  const statement = ref([])
  const loading = ref(false)
  const error = ref(null)
  const dbStats = ref({
    tourism_count: 0,
    office_count: 0,
    total_balance: 0,
    active_count: 0,
    performance: {},
    liquidity: {
      cash: 0,
      bank: 0,
      wallet: 0,
      treasury: 0,
    },
  })
  const pagination = ref({
    total: 0,
    per_page: 15,
    current_page: 1,
    last_page: 1,
    has_more: false,
  })

  const totalBalance = computed(() => dbStats.value.total_balance)
  const tourismCount = computed(() => dbStats.value.tourism_count)
  const officeCount = computed(() => dbStats.value.office_count)
  const activeAccountsCount = computed(() => dbStats.value.active_count)

  async function fetchAccounts(params = {}) {
    loading.value = true
    error.value = null
    try {
      const query = {
        per_page: 100,
        ...params,
      }
      const response = await axios.get('/api/v1/finance/accounts', { params: query })
      const body = response.data
      accounts.value = unwrapAccountsList(body?.data)
      
      const stats = body?.stats || body?.data?.stats
      if (stats) {
        dbStats.value = {
          ...dbStats.value,
          ...stats,
          liquidity: {
            ...dbStats.value.liquidity,
            ...(stats.liquidity || {})
          },
          performance: stats.performance || {},
          recent_transactions: stats.recent_transactions || [],
          deficit_accounts: stats.deficit_accounts || []
        }
      }
      const inner = body?.data
      const pg =
        inner && typeof inner === 'object' && !Array.isArray(inner)
          ? inner.meta || inner.pagination || body.pagination
          : body.pagination || {}
      if (pg && (pg.total != null || pg.current_page != null)) {
        pagination.value = {
          total: pg.total ?? accounts.value.length,
          per_page: pg.per_page ?? query.per_page,
          current_page: pg.current_page ?? 1,
          last_page: pg.last_page ?? 1,
          has_more:
            pg.has_more ??
            (pg.current_page && pg.last_page ? pg.current_page < pg.last_page : false),
        }
      } else {
        pagination.value = {
          total: accounts.value.length,
          per_page: query.per_page,
          current_page: 1,
          last_page: 1,
          has_more: false,
        }
      }
      return body
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch accounts'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchAccount(id) {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get(`/api/v1/finance/accounts/${id}`)
      account.value = response.data.data
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch account'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchAccountStatement(id, params = {}) {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get(`/api/v1/finance/accounts/${id}/statement`, { params })
      statement.value = response.data.data.items || []
      pagination.value = response.data.data.pagination || {}
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch statement'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createAccount(data) {
    loading.value = true
    error.value = null
    try {
      const response = await axios.post('/api/v1/finance/accounts', data)
      accounts.value.unshift(response.data.data)
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create account'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateAccount(id, data) {
    loading.value = true
    error.value = null
    try {
      const response = await axios.put(`/api/v1/finance/accounts/${id}`, data)
      const index = accounts.value.findIndex(acc => acc.id === id)
      if (index !== -1) {
        accounts.value[index] = response.data.data
      }
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update account'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deactivateAccount(id) {
    loading.value = true
    error.value = null
    try {
      const response = await axios.post(`/api/v1/finance/accounts/${id}/deactivate`)
      const index = accounts.value.findIndex(acc => acc.id === id)
      if (index !== -1) {
        accounts.value[index].is_active = false
      }
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to deactivate account'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function transferFunds(data) {
    loading.value = true
    error.value = null
    try {
      const response = await axios.post('/api/v1/finance/transfers', data)
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to transfer funds'
      throw err
    } finally {
      loading.value = false
    }
  }

  function reset() {
    accounts.value = []
    account.value = null
    statement.value = []
    loading.value = false
    error.value = null
    pagination.value = {
      total: 0,
      per_page: 15,
      current_page: 1,
      last_page: 1,
      has_more: false,
    }
  }

  return {
    accounts,
    account,
    statement,
    loading,
    error,
    pagination,
    totalBalance,
    tourismCount,
    officeCount,
    activeAccountsCount,
    dbStats,
    fetchAccounts,
    fetchAccount,
    fetchAccountStatement,
    createAccount,
    updateAccount,
    deactivateAccount,
    transferFunds,
    reset,
  }
})
