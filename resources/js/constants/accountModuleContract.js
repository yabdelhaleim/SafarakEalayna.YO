/**
 * MIRROR of App\Support\Finance\AccountModuleContract (PHP).
 *
 * This file is the JS-side single source of truth for:
 *  - Which `module_type` values identify the Office division vs. the Tourism division.
 *  - Which modules belong to each division.
 *  - Which AccountType values are operational (liquidity), subject (AR/AP mirroring),
 *    or internal (clearing / GL line items).
 *
 * Rules of engagement (mirroring the PHP contract):
 *
 *  1. An Office-division account NEVER appears in any Tourism-division
 *     dropdown or filter, and vice versa.
 *
 *  2. `module_type` is the strict classification column. `module` is
 *     an OPTIONAL preferred-label / alias column. A division-unified
 *     account has `module_type='office'` (or `'tourism'`) and any
 *     `module` label (or `null`). The `module` column on a division-unified
 *     account is just a label hint and is NOT used as a filter.
 *
 *  3. `module_type='general'` is intentionally NOT in either division's
 *     `_DIVISION_MODULES` list. It is legacy code that the contract
 *     rejects as a division marker.
 *
 * ⚠️ KEEP IN SYNC with `app/Support/Finance/AccountModuleContract.php`.
 *    When the PHP class changes, update this file in the same commit.
 *    Future improvement: replace this static mirror with an API endpoint
 *    (e.g. `/api/v1/account-module-contract`) and have the composable
 *    prefer the live response — then this file becomes a fallback only.
 */

export const OFFICE_MODULE_TYPE = 'office';

export const TOURISM_MODULE_TYPE = 'tourism';

/** Office division members — matches PHP `AccountModuleContract::OFFICE_DIVISION_MODULES`. */
export const OFFICE_DIVISION_MODULES = [
  'office',
  'bus',
  'fawry',
  'online',
  'wallet_transfer',
];

/** Tourism division members — matches PHP `AccountModuleContract::TOURISM_DIVISION_MODULES`. */
export const TOURISM_DIVISION_MODULES = [
  'tourism',
  'flights',
  'hajj_umra',
  'visas',
];

/**
 * Operational (liquidity) AccountType values — matches PHP
 * `AccountModuleContract::LIQUIDITY_TYPES`. The 'treasury' and 'post'
 * cases were removed in Phase 3.5b cleanup; do not add them here.
 */
export const LIQUIDITY_TYPES = ['cashbox', 'wallet', 'bank'];

/** Subject (AR / AP mirroring) AccountType values. */
export const SUBJECT_TYPES = ['customer', 'supplier'];

/** Internal GL line accounts (clearing and equalisation). */
export const INTERNAL_TYPES = ['expense', 'revenue', 'liability', 'owner'];

/**
 * Determine the division a module belongs to.
 *
 * @param  {string|null|undefined} module
 * @returns {'office'|'tourism'|null}
 */
export function divisionFor(module) {
  if (module == null || module === '') return null;
  const m = String(module).toLowerCase();
  if (OFFICE_DIVISION_MODULES.includes(m)) return 'office';
  if (TOURISM_DIVISION_MODULES.includes(m)) return 'tourism';
  return null;
}

/** True iff the module belongs to the Office division. */
export function isOfficeModule(module) {
  return divisionFor(module) === 'office';
}

/** True iff the module belongs to the Tourism division. */
export function isTourismModule(module) {
  return divisionFor(module) === 'tourism';
}

/** True iff the given AccountType value is a liquidity type. */
export function isLiquidityType(type) {
  if (type == null) return false;
  const v = typeof type === 'object' && type?.value != null ? type.value : type;
  return LIQUIDITY_TYPES.includes(String(v));
}

/** True iff the given AccountType value is a subject (customer/supplier). */
export function isSubjectType(type) {
  if (type == null) return false;
  const v = typeof type === 'object' && type?.value != null ? type.value : type;
  return SUBJECT_TYPES.includes(String(v));
}