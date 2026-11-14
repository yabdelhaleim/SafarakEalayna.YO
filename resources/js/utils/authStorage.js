/**
 * Unified Authentication Storage Utility (Single Source of Truth)
 * ─────────────────────────────────────────────────────────────────────────────
 * Provides bulletproof token management across the Vue SPA:
 *  - Memory-first caching for performance & protection against disk snooping
 *  - Dual persistence (sessionStorage primary + localStorage with TTL check)
 *  - Strict token validation to prevent ghost/undefined sessions
 *  - Environment-resilient: gracefully handles SSR / Node / private mode
 * ─────────────────────────────────────────────────────────────────────────────
 */

let _memToken = null;

const TOKEN_KEY = 'auth_token';
const EXPIRY_MINUTES_KEY = 'auth_token_expires_minutes';
const EXPIRES_AT_KEY = 'auth_token_expires_at';

function getStorage(type) {
  try {
    if (typeof window !== 'undefined' && window[type]) {
      return window[type];
    }
    if (typeof globalThis !== 'undefined' && globalThis[type]) {
      return globalThis[type];
    }
  } catch {
    // Access denied (e.g. strict iframe or private browsing sandbox)
  }
  return null;
}

/**
 * Validates token shape to prevent 'undefined', 'null', or corrupted values
 */
export function isValidToken(token) {
  if (typeof token !== 'string') return false;
  const clean = token.trim();
  if (!clean || clean === 'undefined' || clean === 'null') return false;
  // Personal access tokens in Sanctum are formatted as "{id}|{hash}" (length >= 15)
  return clean.length >= 15;
}

/**
 * Checks if the stored token has exceeded its TTL timestamp
 */
function isExpired() {
  try {
    const sessionStore = getStorage('sessionStorage');
    const localStore = getStorage('localStorage');

    const rawExpiresAt = sessionStore?.getItem(EXPIRES_AT_KEY) || localStore?.getItem(EXPIRES_AT_KEY);
    if (!rawExpiresAt) return false;
    const expiresAt = parseInt(rawExpiresAt, 10);
    if (Number.isFinite(expiresAt) && Date.now() > expiresAt) {
      return true;
    }
  } catch {
    // If parsing fails, fall through safely
  }
  return false;
}

/**
 * Retrieves the active auth token from memory or storage.
 * Automatically purges expired or corrupted tokens.
 */
export function getStoredToken() {
  // Check expiry first across all layers (including memory)
  if (isExpired()) {
    clearStoredToken();
    return null;
  }

  if (isValidToken(_memToken)) {
    return _memToken;
  }

  let token = null;
  try {
    const sessionStore = getStorage('sessionStorage');
    const localStore = getStorage('localStorage');
    token = sessionStore?.getItem(TOKEN_KEY) || localStore?.getItem(TOKEN_KEY) || null;
  } catch (e) {
    console.warn('[authStorage] Storage access restricted:', e);
  }

  if (isValidToken(token)) {
    _memToken = token.trim();
    return _memToken;
  }

  // If a corrupted token exists in storage (e.g. "undefined"), purge it immediately
  if (token != null) {
    clearStoredToken();
  }

  return null;
}

/**
 * Persists token securely across storage layers with expiry metadata
 */
export function setStoredToken(token, expiresInMinutes = null) {
  if (!isValidToken(token)) {
    console.warn('[authStorage] Attempted to store invalid token, rejecting');
    return false;
  }

  const cleanToken = token.trim();
  _memToken = cleanToken;

  try {
    const sessionStore = getStorage('sessionStorage');
    const localStore = getStorage('localStorage');

    sessionStore?.setItem(TOKEN_KEY, cleanToken);
    localStore?.setItem(TOKEN_KEY, cleanToken);

    const minutes = Number(expiresInMinutes);
    if (Number.isFinite(minutes) && minutes > 0) {
      const expiresAt = Date.now() + minutes * 60 * 1000;
      sessionStore?.setItem(EXPIRY_MINUTES_KEY, String(minutes));
      sessionStore?.setItem(EXPIRES_AT_KEY, String(expiresAt));
      localStore?.setItem(EXPIRY_MINUTES_KEY, String(minutes));
      localStore?.setItem(EXPIRES_AT_KEY, String(expiresAt));
    }
  } catch (e) {
    console.warn('[authStorage] Failed to write storage:', e);
  }

  return true;
}

/**
 * Completely purges all auth tokens and expiry metadata
 */
export function clearStoredToken() {
  _memToken = null;

  try {
    const sessionStore = getStorage('sessionStorage');
    const localStore = getStorage('localStorage');

    sessionStore?.removeItem(TOKEN_KEY);
    sessionStore?.removeItem(EXPIRY_MINUTES_KEY);
    sessionStore?.removeItem(EXPIRES_AT_KEY);

    localStore?.removeItem(TOKEN_KEY);
    localStore?.removeItem(EXPIRY_MINUTES_KEY);
    localStore?.removeItem(EXPIRES_AT_KEY);
  } catch (e) {
    console.warn('[authStorage] Failed to clear storage:', e);
  }
}

/**
 * Reads the configured expiry duration in minutes
 */
export function getStoredExpiryMinutes() {
  try {
    const sessionStore = getStorage('sessionStorage');
    const localStore = getStorage('localStorage');
    const raw = sessionStore?.getItem(EXPIRY_MINUTES_KEY) || localStore?.getItem(EXPIRY_MINUTES_KEY);
    const parsed = parseInt(raw || '', 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  } catch {
    return null;
  }
}
