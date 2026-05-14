import { ref } from 'vue'
import { useFlightStore } from '@/stores/flightStore'

const toasts = ref([])

export function useToast() {
  const store = useFlightStore()

  const addToast = (message, type = 'success') => {
    const id = Date.now()
    toasts.value.push({ id, message, type })

    // Auto-dismiss after 4 seconds
    setTimeout(() => {
      removeToast(id)
    }, 4000)

    return id
  }

  const removeToast = (id) => {
    const index = toasts.value.findIndex(t => t.id === id)
    if (index !== -1) {
      toasts.value.splice(index, 1)
    }
  }

  const success = (message) => {
    store.addToast(message, 'success')
    return addToast(message, 'success')
  }

  const error = (message) => {
    store.addToast(message, 'error')
    return addToast(message, 'error')
  }

  const warning = (message) => {
    return addToast(message, 'warning')
  }

  const info = (message) => {
    return addToast(message, 'info')
  }

  return {
    toasts,
    success,
    error,
    warning,
    info,
    removeToast
  }
}
