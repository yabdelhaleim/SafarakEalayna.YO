import { computed, unref } from 'vue';

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

export const TOURISM_MODULE_TYPES = ['tourism', 'flights', 'hajj_umra', 'visas'];
export const OFFICE_MODULE_TYPES = ['office', 'bus', 'fawry', 'online', 'wallet_transfer', 'general'];

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
  treasury: 'خزينة عامة',
  post: 'بريد',
};

const TREASURY_TYPES = new Set(['cashbox', 'wallet', 'bank', 'treasury', 'post']);

export const SETTLEMENT_ACCOUNT_TYPES = 'cashbox,wallet,bank,treasury,post';

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

/** Liquidity accounts for settlements; retries without module when module filter returns none. */
export async function fetchSettlementAccounts(httpClient, options = {}) {
  const {
    module = null,
    includePost = true,
    isActive = 1,
  } = options;

  const types = includePost
    ? SETTLEMENT_ACCOUNT_TYPES
    : 'cashbox,wallet,bank,treasury';

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

  if (module) {
    const scoped = await fetchList({ ...baseParams, module });
    if (scoped.length > 0) {
      return scoped;
    }
  }

  return fetchList(baseParams);
}

export function formatAccountType(type) {
  const key = typeof type === 'object' && type?.value ? type.value : type;
  return ACCOUNT_TYPE_LABELS[key] || 'حساب';
}

export function isTreasuryAccount(account) {
  const type = typeof account?.type === 'object' && account?.type?.value
    ? account.type.value
    : account?.type;
  return TREASURY_TYPES.has(type);
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
