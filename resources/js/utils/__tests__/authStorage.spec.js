import { describe, it, expect, beforeEach } from 'vitest';
import {
  isValidToken,
  getStoredToken,
  setStoredToken,
  clearStoredToken,
  getStoredExpiryMinutes,
} from '../authStorage.js';

class MockStorage {
  constructor() {
    this.store = {};
  }
  getItem(key) {
    return this.store[key] ?? null;
  }
  setItem(key, value) {
    this.store[key] = String(value);
  }
  removeItem(key) {
    delete this.store[key];
  }
  clear() {
    this.store = {};
  }
}

describe('authStorage Utility', () => {
  beforeEach(() => {
    globalThis.sessionStorage = new MockStorage();
    globalThis.localStorage = new MockStorage();
    clearStoredToken();
  });

  describe('isValidToken', () => {
    it('rejects non-strings and falsy values', () => {
      expect(isValidToken(null)).toBe(false);
      expect(isValidToken(undefined)).toBe(false);
      expect(isValidToken('')).toBe(false);
      expect(isValidToken(12345)).toBe(false);
      expect(isValidToken({})).toBe(false);
    });

    it('rejects "undefined" and "null" string literals', () => {
      expect(isValidToken('undefined')).toBe(false);
      expect(isValidToken('null')).toBe(false);
      expect(isValidToken('   undefined   ')).toBe(false);
    });

    it('rejects strings that are too short to be Sanctum tokens', () => {
      expect(isValidToken('12345')).toBe(false);
      expect(isValidToken('token')).toBe(false);
    });

    it('accepts valid token shapes', () => {
      expect(isValidToken('1|abcdefghijklmnopqrstuvwxyz1234567890')).toBe(true);
      expect(isValidToken('auth-token-valid-token-string-here')).toBe(true);
    });
  });

  describe('setStoredToken & getStoredToken', () => {
    it('persists and retrieves a valid token', () => {
      const valid = '1|abcdefghijklmnopqrstuvwxyz1234567890';
      const success = setStoredToken(valid, 120);

      expect(success).toBe(true);
      expect(getStoredToken()).toBe(valid);
      expect(sessionStorage.getItem('auth_token')).toBe(valid);
      expect(localStorage.getItem('auth_token')).toBe(valid);
      expect(getStoredExpiryMinutes()).toBe(120);
    });

    it('rejects invalid token from being stored', () => {
      expect(setStoredToken('short')).toBe(false);
      expect(setStoredToken('undefined')).toBe(false);
      expect(getStoredToken()).toBeNull();
    });

    it('purges storage and returns null if stored token is corrupted with literal "undefined"', () => {
      sessionStorage.setItem('auth_token', 'undefined');
      expect(getStoredToken()).toBeNull();
      expect(sessionStorage.getItem('auth_token')).toBeNull();
    });

    it('retrieves from localStorage if sessionStorage is empty (e.g. cross-tab or test setup)', () => {
      const valid = '2|cross_tab_token_abcdefghijklmnop12345';
      localStorage.setItem('auth_token', valid);

      expect(getStoredToken()).toBe(valid);
    });
  });

  describe('clearStoredToken', () => {
    it('completely removes tokens and expiry metadata from all layers', () => {
      const valid = '3|token_to_be_cleared_1234567890abcdef';
      setStoredToken(valid, 60);

      expect(getStoredToken()).toBe(valid);

      clearStoredToken();

      expect(getStoredToken()).toBeNull();
      expect(sessionStorage.getItem('auth_token')).toBeNull();
      expect(localStorage.getItem('auth_token')).toBeNull();
      expect(sessionStorage.getItem('auth_token_expires_at')).toBeNull();
      expect(localStorage.getItem('auth_token_expires_at')).toBeNull();
    });
  });

  describe('TTL and Expiry', () => {
    it('returns null and purges storage when the token has expired', () => {
      const valid = '4|token_about_to_expire_1234567890';
      setStoredToken(valid, 60);

      // Force expired timestamp in the past
      const pastTime = String(Date.now() - 10000);
      sessionStorage.setItem('auth_token_expires_at', pastTime);
      localStorage.setItem('auth_token_expires_at', pastTime);

      expect(getStoredToken()).toBeNull();
      expect(sessionStorage.getItem('auth_token')).toBeNull();
      expect(localStorage.getItem('auth_token')).toBeNull();
    });
  });
});
