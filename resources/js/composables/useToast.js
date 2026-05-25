import { ref } from 'vue'

const toasts = ref([])

export function useToast() {
  const addToast = (message, type = 'success') => {
    const id = Date.now()
    toasts.value.push({ id, message, type })

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

  const success = (message) => addToast(message, 'success')

  const error = (message) => addToast(message, 'error')

  const warning = (message) => addToast(message, 'warning')

  const info = (message) => addToast(message, 'info')

  return {
    toasts,
    success,
    error,
    warning,
    info,
    removeToast
  }
}
