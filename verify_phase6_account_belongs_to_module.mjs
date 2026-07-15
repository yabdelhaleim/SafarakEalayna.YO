// Phase 6 direct-execution verification — Vue accountBelongsToModule tri-rule.
//
// Bypasses Vitest/Jest (not installed in this env) by running plain Node ESM
// assertions via the built-in `node:assert` module. Uses a small loader hook
// (verify_phase6_loader.mjs) to resolve the Vite `@/...` alias to the actual
// ./resources/js/... path.
//
// Mirrors the 37 assertion points of the Phase 5 verify script, but on the
// JS side: 6 modules × 5 acceptance cases + cross-cutting invariants.

import { strict as assert } from 'node:assert';
import {
  accountBelongsToModule,
  isTreasuryAccount,
  SETTLEMENT_ACCOUNT_TYPES,
  filterSettlementAccountsByModule,
  accountBelongsToDivision,
  filterTreasuryAccountsByDivision,
} from '@/composables/useTreasuryAccountGroups.js';
import {
  OFFICE_MODULE_TYPE,
  TOURISM_MODULE_TYPE,
  OFFICE_DIVISION_MODULES,
  TOURISM_DIVISION_MODULES,
  LIQUIDITY_TYPES,
  SUBJECT_TYPES,
  divisionFor,
  isOfficeModule,
  isTourismModule,
  isLiquidityType,
  isSubjectType,
} from '@/constants/accountModuleContract.js';

const results = { pass: 0, fail: 0 };
const failures = [];

function check(label, cond) {
  if (cond) {
    console.log(`  ✓ ${label}`);
    results.pass++;
  } else {
    console.log(`  ✗ ${label}`);
    results.fail++;
    failures.push(label);
  }
}

console.log('=== Phase 6 — accountBelongsToModule tri-rule (direct execution) ===\n');

// ─── 0. Contract mirror invariants ──────────────────────────────────────────

console.log('0. JS contract mirror invariants:');
check('OFFICE_MODULE_TYPE === "office"', OFFICE_MODULE_TYPE === 'office');
check('TOURISM_MODULE_TYPE === "tourism"', TOURISM_MODULE_TYPE === 'tourism');
check('OFFICE_DIVISION_MODULES has exactly 5 entries (no "general")',
  OFFICE_DIVISION_MODULES.length === 5 && !OFFICE_DIVISION_MODULES.includes('general'));
check('TOURISM_DIVISION_MODULES has exactly 4 entries',
  TOURISM_DIVISION_MODULES.length === 4);
check('LIQUIDITY_TYPES is exactly [cashbox, wallet, bank]',
  JSON.stringify(LIQUIDITY_TYPES) === '["cashbox","wallet","bank"]');
check('SUBJECT_TYPES is exactly [customer, supplier]',
  JSON.stringify(SUBJECT_TYPES) === '["customer","supplier"]');
check('divisionFor("bus") === "office"', divisionFor('bus') === 'office');
check('divisionFor("hajj_umra") === "tourism"', divisionFor('hajj_umra') === 'tourism');
check('divisionFor("general") === null', divisionFor('general') === null);
check('isOfficeModule("fawry") === true', isOfficeModule('fawry') === true);
check('isTourismModule("visas") === true', isTourismModule('visas') === true);
check('isLiquidityType("cashbox") === true', isLiquidityType('cashbox') === true);
check('isLiquidityType({value:"wallet"}) === true (BackedEnum handling)', isLiquidityType({ value: 'wallet' }) === true);
check('isSubjectType("customer") === true', isSubjectType('customer') === true);
check('SETTLEMENT_ACCOUNT_TYPES is "cashbox,wallet,bank" (no stale treasury/post)',
  SETTLEMENT_ACCOUNT_TYPES === 'cashbox,wallet,bank');

// ─── Helper to build test account fixtures ─────────────────────────────────

function liquidityAccount(moduleType, moduleCol = null, type = 'cashbox') {
  return { module_type: moduleType, module: moduleCol, type };
}

function subjectAccount(moduleType, moduleCol = null, type = 'customer') {
  return { module_type: moduleType, module: moduleCol, type };
}

// ─── 1. accountBelongsToModule — bus (office division) ─────────────────────

console.log('\n1. accountBelongsToModule("bus") — own_module=bus, division=office:');
check('accepts bus-narrowed office vault (module_type=office, module=bus)',
  accountBelongsToModule(liquidityAccount('office', 'bus'), 'bus') === true);
check('accepts truly-unified office vault (module_type=office, module=null)',
  accountBelongsToModule(liquidityAccount('office', null), 'bus') === true);
check('accepts office vault narrowed to fawry (label is just a label)',
  accountBelongsToModule(liquidityAccount('office', 'fawry'), 'bus') === true);
check('rejects tourism-division vault',
  accountBelongsToModule(liquidityAccount('tourism', null), 'bus') === false);
check('rejects subject (customer) account',
  accountBelongsToModule(subjectAccount('bus', 'bus', 'customer'), 'bus') === false);

// ─── 2. accountBelongsToModule — hajj_umra (tourism division) ──────────────

console.log('\n2. accountBelongsToModule("hajj_umra") — own_module=hajj_umra, division=tourism:');
check('accepts hajj-narrowed tourism vault (module_type=tourism, module=hajj_umra)',
  accountBelongsToModule(liquidityAccount('tourism', 'hajj_umra'), 'hajj_umra') === true);
check('accepts truly-unified tourism vault (module_type=tourism, module=null)',
  accountBelongsToModule(liquidityAccount('tourism', null), 'hajj_umra') === true);
check('accepts tourism vault narrowed to visas (label is just a label)',
  accountBelongsToModule(liquidityAccount('tourism', 'visas'), 'hajj_umra') === true);
check('rejects office-division vault',
  accountBelongsToModule(liquidityAccount('office', null), 'hajj_umra') === false);
check('rejects subject (customer) account',
  accountBelongsToModule(subjectAccount('hajj_umra', 'hajj_umra', 'customer'), 'hajj_umra') === false);

// ─── 3. accountBelongsToModule — fawry (office division) ────────────────────

console.log('\n3. accountBelongsToModule("fawry") — own_module=fawry, division=office:');
check('accepts fawry-narrowed office vault (module_type=office, module=fawry)',
  accountBelongsToModule(liquidityAccount('office', 'fawry'), 'fawry') === true);
check('accepts truly-unified office vault',
  accountBelongsToModule(liquidityAccount('office', null), 'fawry') === true);
check('accepts office vault narrowed to online (label is just a label)',
  accountBelongsToModule(liquidityAccount('office', 'online'), 'fawry') === true);
check('rejects tourism-division vault',
  accountBelongsToModule(liquidityAccount('tourism', null), 'fawry') === false);
check('rejects subject (supplier) account',
  accountBelongsToModule(subjectAccount('fawry', 'fawry', 'supplier'), 'fawry') === false);

// ─── 4. accountBelongsToModule — online (office division) ──────────────────

console.log('\n4. accountBelongsToModule("online") — own_module=online, division=office:');
check('accepts online-narrowed office vault (module_type=office, module=online)',
  accountBelongsToModule(liquidityAccount('office', 'online'), 'online') === true);
check('accepts truly-unified office vault',
  accountBelongsToModule(liquidityAccount('office', null), 'online') === true);
check('accepts office vault narrowed to bus (label is just a label)',
  accountBelongsToModule(liquidityAccount('office', 'bus'), 'online') === true);
check('rejects tourism-division vault',
  accountBelongsToModule(liquidityAccount('tourism', null), 'online') === false);
check('rejects subject (customer) account',
  accountBelongsToModule(subjectAccount('online', 'online', 'customer'), 'online') === false);

// ─── 5. accountBelongsToModule — visa (tourism division) ───────────────────

console.log('\n5. accountBelongsToModule("visa") — own_module=visas, division=tourism:');
check('accepts visas-narrowed tourism vault (module_type=tourism, module=visas)',
  accountBelongsToModule(liquidityAccount('tourism', 'visas'), 'visa') === true);
check('accepts truly-unified tourism vault',
  accountBelongsToModule(liquidityAccount('tourism', null), 'visa') === true);
check('accepts tourism vault narrowed to hajj (label is just a label)',
  accountBelongsToModule(liquidityAccount('tourism', 'hajj_umra'), 'visa') === true);
check('rejects office-division vault',
  accountBelongsToModule(liquidityAccount('office', null), 'visa') === false);
check('rejects subject (customer) account',
  accountBelongsToModule(subjectAccount('visas', 'visas', 'customer'), 'visa') === false);

// ─── 6. accountBelongsToModule — wallet_transfer (office division) ────────

console.log('\n6. accountBelongsToModule("wallet_transfer") — own_module=wallet_transfer, division=office:');
check('accepts wallet_transfer-narrowed office vault (module_type=office, module=wallet_transfer)',
  accountBelongsToModule(liquidityAccount('office', 'wallet_transfer', 'wallet'), 'wallet_transfer') === true);
check('accepts truly-unified office vault',
  accountBelongsToModule(liquidityAccount('office', null, 'bank'), 'wallet_transfer') === true);
check('accepts office vault narrowed to fawry (label is just a label)',
  accountBelongsToModule(liquidityAccount('office', 'fawry', 'cashbox'), 'wallet_transfer') === true);
check('rejects tourism-division vault',
  accountBelongsToModule(liquidityAccount('tourism', null, 'bank'), 'wallet_transfer') === false);
check('rejects subject (customer) account',
  accountBelongsToModule(subjectAccount('wallet_transfer', 'wallet_transfer', 'customer'), 'wallet_transfer') === false);

// ─── 7. Cross-cutting: filterSettlementAccountsByModule integration ────────

console.log('\n7. Cross-cutting integration:');

const mixedList = [
  { id: 1, name: 'bus cashbox',         module_type: 'office',  module: 'bus',         type: 'cashbox', is_active: true },
  { id: 2, name: 'office unified bank',  module_type: 'office',  module: null,          type: 'bank',    is_active: true },
  { id: 3, name: 'office fawry cashbox', module_type: 'office',  module: 'fawry',       type: 'cashbox', is_active: true },
  { id: 4, name: 'tourism unified',      module_type: 'tourism', module: null,          type: 'bank',    is_active: true },
  { id: 5, name: 'tourism hajj',         module_type: 'tourism', module: 'hajj_umra',  type: 'cashbox', is_active: true },
  { id: 6, name: 'bus customer',         module_type: 'bus',     module: 'bus',         type: 'customer', is_active: true },
  { id: 7, name: 'visa narrowed',        module_type: 'tourism', module: 'visas',       type: 'cashbox', is_active: true },
];

const busFiltered = filterSettlementAccountsByModule(mixedList, 'bus').map(a => a.name);
check('filterSettlementAccountsByModule(list, "bus") returns office-division vaults only',
  JSON.stringify(busFiltered) === JSON.stringify(['bus cashbox', 'office unified bank', 'office fawry cashbox']));

const hajjFiltered = filterSettlementAccountsByModule(mixedList, 'hajj_umra').map(a => a.name);
check('filterSettlementAccountsByModule(list, "hajj_umra") returns tourism-division vaults only',
  JSON.stringify(hajjFiltered) === JSON.stringify(['tourism unified', 'tourism hajj', 'visa narrowed']));

const visaFiltered = filterSettlementAccountsByModule(mixedList, 'visa').map(a => a.name);
check('filterSettlementAccountsByModule(list, "visa") accepts all tourism-division vaults',
  JSON.stringify(visaFiltered) === JSON.stringify(['tourism unified', 'tourism hajj', 'visa narrowed']));

// null module → return all (preserves caller contract)
const noFilter = filterSettlementAccountsByModule(mixedList, null);
check('filterSettlementAccountsByModule(list, null) returns full list (no filter)',
  noFilter.length === mixedList.length);

// isTreasuryAccount uses contract's LIQUIDITY_TYPES
check('isTreasuryAccount(cashbox) === true', isTreasuryAccount({ type: 'cashbox' }) === true);
check('isTreasuryAccount(wallet) === true', isTreasuryAccount({ type: 'wallet' }) === true);
check('isTreasuryAccount(bank) === true', isTreasuryAccount({ type: 'bank' }) === true);
check('isTreasuryAccount(customer) === false (subject, not liquidity)',
  isTreasuryAccount({ type: 'customer' }) === false);
check('isTreasuryAccount(treasury) === false (stale type, removed in 3.5b)',
  isTreasuryAccount({ type: 'treasury' }) === false);

// accountBelongsToDivision (existing helper, still works)
check('accountBelongsToDivision(office vault, "office") === true',
  accountBelongsToDivision(liquidityAccount('office', null), 'office') === true);
check('accountBelongsToDivision(tourism vault, "tourism") === true',
  accountBelongsToDivision(liquidityAccount('tourism', null), 'tourism') === true);
check('accountBelongsToDivision(office vault, "tourism") === false',
  accountBelongsToDivision(liquidityAccount('office', null), 'tourism') === false);

// filterTreasuryAccountsByDivision (existing helper)
const officeOnly = filterTreasuryAccountsByDivision(mixedList, 'office').map(a => a.name);
check('filterTreasuryAccountsByDivision(list, "office") returns office vaults only',
  JSON.stringify(officeOnly) === JSON.stringify(['bus cashbox', 'office unified bank', 'office fawry cashbox']));

// ─── 8. Legacy alias handling (singular/plural) ────────────────────────────

console.log('\n8. Legacy aliases (singular/plural):');
check('"flight" alias still maps to canonical "flights"',
  accountBelongsToModule(liquidityAccount('tourism', 'flights'), 'flight') === true);
check('"visa" (singular) still accepted for canonical "visas" module',
  accountBelongsToModule(liquidityAccount('tourism', 'visas'), 'visa') === true);
check('"wallet" alias still maps to canonical "wallet_transfer"',
  accountBelongsToModule(liquidityAccount('office', 'wallet_transfer', 'wallet'), 'wallet') === true);

// ─── Summary ───────────────────────────────────────────────────────────────

console.log('\n═══════════════════════════════════════════');
console.log(`  Phase 6 results: ${results.pass} PASS, ${results.fail} FAIL`);
console.log('═══════════════════════════════════════════');
if (results.fail > 0) {
  console.log('Failures:');
  for (const f of failures) console.log(`  - ${f}`);
}
process.exit(results.fail > 0 ? 1 : 0);