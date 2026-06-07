import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

function unwrapAccountsList(payload) {
  if (!payload) return []
  if (Array.isArray(payload)) return payload
  if (payload.items && Array.isArray(payload.items)) return payload.items
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

  let fetchAccountsController = null
  let activeAccountsRequests = 0

  async function fetchAccounts(params = {}) {
    if (fetchAccountsController) {
      fetchAccountsController.abort()
    }
    fetchAccountsController = new AbortController()

    activeAccountsRequests++
    loading.value = true
    error.value = null
    accounts.value = [] // Reset data variables before calling API

    try {
      const query = {
        per_page: 100,
        _t: Date.now(),
        ...params,
      }
      const response = await axios.get('/api/v1/finance/accounts', {
        params: query,
        signal: fetchAccountsController.signal
      })
      const body = response.data
      accounts.value = unwrapAccountsList(body?.data)
      
      const stats = body?.data?.stats ?? body?.stats
      if (stats) {
        const liquidity = stats.liquidity || {}
        dbStats.value = {
          ...dbStats.value,
          ...stats,
          liquidity: {
            cashbox: liquidity.cashbox ?? liquidity.cash ?? 0,
            bank: liquidity.bank ?? 0,
            wallet: liquidity.wallet ?? 0,
            treasury: liquidity.treasury ?? 0,
            post: liquidity.post ?? 0,
          },
          performance: stats.performance || {},
          recent_transactions: stats.recent_transactions || [],
          deficit_accounts: stats.deficit_accounts || [],
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
      if (axios.isCancel(err)) {
        return
      }
      error.value = err.response?.data?.message || 'Failed to fetch accounts'
      throw err
    } finally {
      activeAccountsRequests--
      if (activeAccountsRequests === 0) {
        loading.value = false
      }
    }
  }

  let fetchAccountController = null
  let activeAccountRequests = 0

  async function fetchAccount(id) {
    if (fetchAccountController) {
      fetchAccountController.abort()
    }
    fetchAccountController = new AbortController()

    activeAccountRequests++
    loading.value = true
    error.value = null
    account.value = null // reset before calling API

    try {
      const response = await axios.get(`/api/v1/finance/accounts/${id}`, {
        signal: fetchAccountController.signal
      })
      account.value = response.data.data
      return response.data
    } catch (err) {
      if (axios.isCancel(err)) {
        return
      }
      error.value = err.response?.data?.message || 'Failed to fetch account'
      throw err
    } finally {
      activeAccountRequests--
      if (activeAccountRequests === 0) {
        loading.value = false
      }
    }
  }

  let fetchStatementController = null
  let activeStatementRequests = 0

  async function fetchAccountStatement(id, params = {}) {
    if (fetchStatementController) {
      fetchStatementController.abort()
    }
    fetchStatementController = new AbortController()

    activeStatementRequests++
    loading.value = true
    error.value = null
    statement.value = [] // reset before calling API

    try {
      const response = await axios.get(`/api/v1/finance/accounts/${id}/statement`, {
        params,
        signal: fetchStatementController.signal
      })
      statement.value = response.data.data.items || []
      pagination.value = response.data.data.pagination || {}
      return response.data
    } catch (err) {
      if (axios.isCancel(err)) {
        return
      }
      error.value = err.response?.data?.message || 'Failed to fetch statement'
      throw err
    } finally {
      activeStatementRequests--
      if (activeStatementRequests === 0) {
        loading.value = false
      }
    }
  }

  async function createAccount(data) {
    if (loading.value) return;
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
    if (loading.value) return;
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
    if (loading.value) return;
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
    if (loading.value) return;
    loading.value = true
    error.value = null
    try {
      const payload = {
        ...data,
        from_account_id: Number(data.from_account_id),
        to_account_id: Number(data.to_account_id),
        amount: Number(data.amount),
      };
      if (data.converted_amount != null) {
        payload.converted_amount = Number(data.converted_amount);
      }
      if (data.exchange_rate != null) {
        payload.exchange_rate = Number(data.exchange_rate);
      }
      const response = await axios.post('/api/v1/finance/transfers', payload)
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

  function $reset() {
    reset()
  }

  function abortPendingRequests() {
    if (fetchAccountsController) {
      fetchAccountsController.abort()
    }
    if (fetchAccountController) {
      fetchAccountController.abort()
    }
    if (fetchStatementController) {
      fetchStatementController.abort()
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
    abortPendingRequests,
    reset,
    $reset,
  }
})
