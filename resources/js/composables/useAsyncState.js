import { ref } from 'vue';

export function useAsyncState(initialState = 'idle') {
  // states: 'idle' | 'loading' | 'success' | 'empty' | 'error'
  const state = ref(initialState);
  const error = ref(null);

  const setIdle = () => {
    state.value = 'idle';
    error.value = null;
  };

  const setLoading = () => {
    state.value = 'loading';
    error.value = null;
  };

  const setSuccess = (isEmpty = false) => {
    state.value = isEmpty ? 'empty' : 'success';
    error.value = null;
  };

  const setEmpty = () => {
    state.value = 'empty';
    error.value = null;
  };

  const setError = (err) => {
    state.value = 'error';
    error.value = err?.message || err || 'An error occurred';
  };

  const isIdle = () => state.value === 'idle';
  const isLoading = () => state.value === 'loading';
  const isSuccess = () => state.value === 'success';
  const isEmpty = () => state.value === 'empty';
  const isError = () => state.value === 'error';

  return {
    state,
    error,
    setIdle,
    setLoading,
    setSuccess,
    setEmpty,
    setError,
    isIdle,
    isLoading,
    isSuccess,
    isEmpty,
    isError,
  };
}
