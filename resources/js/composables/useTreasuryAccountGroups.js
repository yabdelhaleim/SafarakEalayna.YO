import { computed, unref } from 'vue';
import {
  OFFICE_MODULE_TYPE,
  TOURISM_MODULE_TYPE,
  OFFICE_DIVISION_MODULES,
  TOURISM_DIVISION_MODULES,
  LIQUIDITY_TYPES,
  SUBJECT_TYPES,
  divisionFor,
  isLiquidityType,
  isSubjectType,
} from '@/constants/accountModuleContract';

/** @deprecated kept as a re-export for callers. Prefer importing from @/constants/accountModuleContract directly. */
export const TOURISM_MODULE_TYPES = TOURISM_DIVISION_MODULES;

/** @deprecated kept as a re-export. The 'general' tail is legacy and not in the contract. Prefer OFFICE_DIVISION_MODULES from @/constants/accountModuleContract. */
export const OFFICE_MODULE_TYPES = [...OFFICE_DIVISION_MODULES, 'general'];

export const MODULE_GROUP_LABELS = {
  general: 'الإدارة العامة',
  office: 'المكتب / الإداري',
  flights: 'وحدة الطيران',
  tourism: 'السياحة',
  hajj_umra: 'الحج والعمرة',
  visas: 'التأشيرات',
  bus: 'وحدة الباص',
  fawry: 'فوري',
  online: 'الخدمات الإلكترونية',
  wallet_transfer: 'المحافظ والتحويلات',
};

export const MODULE_GROUP_ORDER = [
  'general',
  'office',
  'flights',
  'tourism',
  'hajj_umra',
  'visas',
  'bus',
  'fawry',
  'online',
  'wallet_transfer',
];

/**
 * Legacy alias map: Vue/API singular or short module names → Filament canonical
 * `module_type` plural values. Kept for backward compatibility with existing
 * callers; do NOT add new entries without checking the canonical
 * `AccountModuleContract::OFFICE_DIVISION_MODULES` / `TOURISM_DIVISION_MODULES`
 * in PHP.
 */
export const MODULE_TO_MODULE_TYPE = {
  flight: 'flights',
  hajj_umra: 'hajj_umra',
  visa: 'visas',
  bus: 'bus',
  fawry: 'fawry',
  online: 'online',
  wallet: 'wallet_transfer',
  general: 'general',
  service: 'office',
};

export const MODULE_TYPE_FILTER_OPTIONS = MODULE_GROUP_ORDER.map((key) => ({
  value: key,
  label: MODULE_GROUP_LABELS[key] || key,
}));

export function getModuleTypeLabel(moduleType) {
  return MODULE_GROUP_LABELS[moduleType || 'general'] || moduleType || '—';
}

export function normalizeAccountModulePayload(payload) {
  const next = { ...payload };
  if (next.module && MODULE_TO_MODULE_TYPE[next.module]) {
    next.module_type = MODULE_TO_MODULE_TYPE[next.module];
  }
  return next;
}

export const ACCOUNT_TYPE_LABELS = {
  cashbox: 'خزينة',
  wallet: 'محفظة',
  bank: 'بنك',
};

/**
 * Backed Set view of {@link LIQUIDITY_TYPES} — preserves the Set API used by
 * `isTreasuryAccount()`. The values are sourced from the contract so they
 * cannot drift from the PHP side.
 */
const TREASURY_TYPES = new Set(LIQUIDITY_TYPES);

/**
 * Comma-joined string form for the `/api/v1/finance/accounts?types=...`
 * query parameter. Derived from the contract's {@link LIQUIDITY_TYPES}.
 *
 * Note: the previous value `'cashbox,wallet,bank,treasury,post'` included
 * legacy types the DB enum no longer accepts after Phase 3.5b — those
 * caused `?types=` queries to match zero rows. Now the API filter aligns
 * with the contract.
 */
export const SETTLEMENT_ACCOUNT_TYPES = LIQUIDITY_TYPES.join(',');

// Silence "unused" lint warnings for re-exported contract identifiers
// that are kept here for callers that import them from this module.
void OFFICE_MODULE_TYPE;
void TOURISM_MODULE_TYPE;

export function unwrapAccountItems(payload) {
  if (Array.isArray(payload)) return payload;
  if (payload?.items && Array.isArray(payload.items)) return payload.items;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

export function unwrapAccountsApiResponse(response) {
  return unwrapAccountItems(response?.data?.data ?? response?.data);
}

/** Normalize wallet_provider / wallet type codes for matching (Filament ↔ wallet_types). */
export function normalizeWalletProviderCode(raw) {
  if (raw == null || raw === '') return '';
  return String(raw?.value ?? raw)
    .trim()
    .toLowerCase()
    .replace(/[\s-]+/g, '_');
}

export function accountMatchesWalletType(account, walletType) {
  if (!walletType?.code) return true;
  const provider = normalizeWalletProviderCode(account?.wallet_provider);
  const code = normalizeWalletProviderCode(walletType.code);
  if (!provider) return false;
  return provider === code;
}

/**
 * Phase 6 (Account Unification) — broadened to match the PHP Rules from
 * {@see \App\Support\Finance\AccountModuleContract} + the Phase 5 LiquidityAccount
 * Rules. Same tri-rule acceptance matrix:
 *
 *   ┌──────────────────────────────────────────────────────────────────┐
 *   │ Test case                                │ result                │
 *   ├──────────────────────────────────────────────────────────────────┤
 *   │ module-specific (narrowed) vault         │ ACCEPT                │
 *   │ division-unified vault (own division)    │ ACCEPT  (Phase 6)     │
 *   │ other-module (same division) narrowed    │ ACCEPT  (label is just a label)
 *   │ other-division vault                     │ REJECT                │
 *   │ subject account (customer/supplier)      │ REJECT                │
 *   │ null module / null account               │ ACCEPT  (no filter)   │
 *   └──────────────────────────────────────────────────────────────────┘
 *
 * Re-exported from this file (rather than moved) so existing callers
 * (`BusCreate`, `FawryCreate`, `FlightCreate`, `HajjUmraCreate`,
 * `VisaCreate`, the 2 RefundWizards, the Bus customer-index/statement
 * views, etc.) keep working without import-path changes. The function
 * itself is now a thin wrapper over the contract's `divisionFor()`.
 *
 * @param {object|null} account
 * @param {string|null} module  Module key (canonical, legacy alias, or division marker).
 * @returns {boolean}
 */
export function accountBelongsToModule(account, module) {
  if (!module || !account) {
    return true;
  }

  // Phase 5 Rule equivalent: subject accounts are NOT liquidity and must be
  // rejected from treasury/settlement dropdowns regardless of module.
  if (isSubjectType(account.type)) {
    return false;
  }

  const canonical = MODULE_TO_MODULE_TYPE[module] || module;
  const moduleType = String(account.module_type || '');
  const moduleCol = String(account.module || '');

  // 1. Module-specific (narrowed): canonical matches either column.
  if (moduleType === canonical || moduleCol === canonical) {
    return true;
  }
  if (moduleType === module || moduleCol === module) {
    return true;
  }

  // 2. Division-unified (Phase 6): any account in the same division is valid.
  // Per contract rule 2, the `module` column is just a label hint and is NOT
  // used as a filter once `module_type` is the division marker.
  const division = divisionFor(canonical) || divisionFor(module);
  if (division && moduleType === division) {
    return true;
  }

  // Legacy aliases (singular/plural etc.) for backward compat with pre-Phase 6 data.
  const legacyAliases = Object.entries(MODULE_TO_MODULE_TYPE)
    .filter(([, value]) => value === canonical)
    .map(([key]) => key);

  return legacyAliases.includes(moduleType) || legacyAliases.includes(moduleCol);
}

export function filterSettlementAccountsByModule(accounts, module) {
  if (!module) {
    return accounts || [];
  }

  return (accounts || []).filter((account) => accountBelongsToModule(account, module));
}

/**
 * Liquidity accounts for settlements; when module is set, never falls back to all modules.
 *
 * Note: the historical `includePost` flag is retained as an accepted option
 * for backward compatibility but is now a no-op — the canonical
 * {@link LIQUIDITY_TYPES} from the contract has no 'post' value (it was
 * removed by Phase 3.5b cleanup alongside 'treasury').
 */
export async function fetchSettlementAccounts(httpClient, options = {}) {
  const {
    module = null,
    module_type = null,
    includePost: _includePost = true,
    isActive = 1,
    strictModule = true,
  } = options;

  // Source-of-truth: contract's LIQUIDITY_TYPES (replaces the stale
  // `'cashbox,wallet,bank,treasury,post'` literal that previously leaked
  // through to the /api/v1/finance/accounts?types=... query string).
  const types = SETTLEMENT_ACCOUNT_TYPES;

  const baseParams = {
    per_page: 100,
    types,
    is_active: isActive,
    _t: Date.now(),
  };

  const fetchList = async (params) => {
    const res = await httpClient.get('/api/v1/finance/accounts', { params });
    return unwrapAccountsApiResponse(res);
  };

  if (module || module_type) {
    const params = { ...baseParams };
    if (module) {
      params.module = module;
    }
    if (module_type) {
      params.module_type = module_type;
    } else if (module && MODULE_TO_MODULE_TYPE[module]) {
      params.module_type = MODULE_TO_MODULE_TYPE[module];
    }

    const scoped = await fetchList(params);

    if (strictModule && module) {
      return filterSettlementAccountsByModule(scoped, module);
    }

    return scoped;
  }

  return fetchList(baseParams);
}

export function formatAccountType(type) {
  const key = typeof type === 'object' && type?.value ? type.value : type;
  return ACCOUNT_TYPE_LABELS[key] || 'حساب';
}

export function isTreasuryAccount(account) {
  return isLiquidityType(account?.type);
}

const TOURISM_MODULE_KEYS = new Set([...TOURISM_MODULE_TYPES, 'flight', 'visa', 'hajj']);
const OFFICE_MODULE_KEYS = new Set([...OFFICE_MODULE_TYPES, 'wallet', 'wallets', 'service']);

export function accountBelongsToDivision(account, category) {
  const moduleType = account?.module_type || 'general';
  const module = account?.module || '';

  if (category === 'tourism') {
    return TOURISM_MODULE_KEYS.has(moduleType) || TOURISM_MODULE_KEYS.has(module);
  }

  if (category === 'office') {
    return OFFICE_MODULE_KEYS.has(moduleType) || OFFICE_MODULE_KEYS.has(module);
  }

  if (category === 'general') {
    return moduleType === 'general' || moduleType === 'office' || !moduleType;
  }

  return true;
}

export function filterTreasuryAccountsByDivision(accounts, category) {
  return (accounts || []).filter(
    (acc) => acc.is_active !== false && isTreasuryAccount(acc) && accountBelongsToDivision(acc, category)
  );
}

export function filterExpenseAccountsByDivision(accounts, category) {
  const active = (accounts || []).filter((acc) => acc.is_active !== false);

  if (category === 'general') {
    return active.filter((acc) => acc.module_type === 'general' || !acc.module_type);
  }

  return active.filter(
    (acc) => accountBelongsToDivision(acc, category) || acc.module_type === 'general' || !acc.module_type
  );
}

export function groupAccountsByModule(accounts, preferredKeys = []) {
  const active = (accounts || []).filter(
    (acc) => acc.is_active !== false && isTreasuryAccount(acc)
  );
  const grouped = new Map();

  for (const acc of active) {
    const key = acc.module_type || 'general';
    if (!grouped.has(key)) {
      grouped.set(key, []);
    }
    grouped.get(key).push(acc);
  }

  const sortAccounts = (list) =>
    [...list].sort((a, b) => String(a.name).localeCompare(String(b.name), 'ar'));

  const buildGroup = (key) => ({
    key,
    label: MODULE_GROUP_LABELS[key] || key,
    accounts: sortAccounts(grouped.get(key) || []),
  });

  const preferred = Array.isArray(preferredKeys) ? preferredKeys : [];
  const orderedKeys = [
    ...preferred.filter((key) => grouped.has(key)),
    ...MODULE_GROUP_ORDER.filter((key) => grouped.has(key) && !preferred.includes(key)),
    ...[...grouped.keys()].filter(
      (key) => !MODULE_GROUP_ORDER.includes(key) && !preferred.includes(key)
    ),
  ];

  return orderedKeys.map(buildGroup).filter((group) => group.accounts.length > 0);
}

export function useTreasuryAccountGroups(accountsRef, preferredKeysRef = null) {
  return computed(() =>
    groupAccountsByModule(unref(accountsRef), unref(preferredKeysRef) || [])
  );
}
