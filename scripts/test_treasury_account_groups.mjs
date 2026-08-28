// ──────────────────────────────────────────────────────────────────────────
// Smoke test لـ `useTreasuryAccountGroups.js` — يتأكد إن:
//   1. `accountMatchesWalletType` بترجّع true للمطابقة الصارمة (canonical == canonical)
//   2. بترجّع true للـ category match (vodafone_cash تحت cash_wallet)
//   3. بترجّع false لو الـ provider مش في الـ category
//   4. بترجّع false لو الـ walletType مفيش code
//   5. بترجّع true لو الـ account مفيش wallet_provider (defensive — زي الـ legacy rule)
//   6. `walletProviderLabel` بترجّع Arabic label
//   7. normalization + canonicalization بيشتغلوا (legacy aliases)
//
// شغّيله: `node scripts/test_treasury_account_groups.mjs`
// ──────────────────────────────────────────────────────────────────────────

import {
  accountMatchesWalletType,
  walletProviderLabel,
  canonicalizeWalletTypeCode,
  normalizeWalletProviderCode,
  WALLET_TYPE_CATEGORY_MEMBERS,
} from '../resources/js/composables/useTreasuryAccountGroups.js';

let failed = 0;
let passed = 0;

function assert(label, actual, expected) {
  const ok = actual === expected;
  if (ok) {
    passed++;
    console.log(`  ✅ ${label}`);
  } else {
    failed++;
    console.log(`  ❌ ${label}\n     expected: ${JSON.stringify(expected)}\n     actual:   ${JSON.stringify(actual)}`);
  }
}

console.log('\n── 1. exact (canonical === canonical) ────────────────────────');
assert(
  'vodafone_cash account + vodafone_cash type → true',
  accountMatchesWalletType(
    { wallet_provider: 'vodafone_cash' },
    { code: 'vodafone_cash' }
  ),
  true
);
assert(
  'orange_cash account + orange_cash type → true',
  accountMatchesWalletType(
    { wallet_provider: 'orange_cash' },
    { code: 'orange_cash' }
  ),
  true
);
assert(
  'vodafone_cash account + orange_cash type → false',
  accountMatchesWalletType(
    { wallet_provider: 'vodafone_cash' },
    { code: 'orange_cash' }
  ),
  false
);

console.log('\n── 2. category match (the bug fix) ──────────────────────────');
assert(
  'vodafone_cash account + cash_wallet CATEGORY → true',
  accountMatchesWalletType(
    { wallet_provider: 'vodafone_cash' },
    { code: 'cash_wallet' }
  ),
  true
);
assert(
  'instapay account + cash_wallet CATEGORY → true',
  accountMatchesWalletType(
    { wallet_provider: 'instapay' },
    { code: 'cash_wallet' }
  ),
  true
);
assert(
  'we_pay account + cash_wallet CATEGORY → true',
  accountMatchesWalletType(
    { wallet_provider: 'we_pay' },
    { code: 'cash_wallet' }
  ),
  true
);
assert(
  'paymob account + cash_wallet CATEGORY → false (paymob not in category)',
  accountMatchesWalletType(
    { wallet_provider: 'paymob' },
    { code: 'cash_wallet' }
  ),
  false
);
assert(
  'fawry account + cash_wallet CATEGORY → false (fawry not in category)',
  accountMatchesWalletType(
    { wallet_provider: 'fawry' },
    { code: 'cash_wallet' }
  ),
  false
);

console.log('\n── 3. defensive / null-handling ─────────────────────────────');
assert(
  'no walletType.code → true (let everything through)',
  accountMatchesWalletType(
    { wallet_provider: 'vodafone_cash' },
    { code: null }
  ),
  true
);
assert(
  'null walletType → true',
  accountMatchesWalletType({ wallet_provider: 'vodafone_cash' }, null),
  true
);
assert(
  'account without wallet_provider + category type → false (no provider to match)',
  accountMatchesWalletType(
    { wallet_provider: null },
    { code: 'cash_wallet' }
  ),
  false
);

console.log('\n── 4. normalization + canonicalization ──────────────────────');
assert(
  // legacy: الأدمن أضاف type بـ code='v_cash' قبل الـ canonical enum
  // الحساب عنده provider='vodafone_cash' (canonical عبر enum cast)
  // المفروض يتطابقوا عبر الـ alias bridge
  'legacy type code "v_cash" + canonical provider "vodafone_cash" → true',
  accountMatchesWalletType(
    { wallet_provider: 'vodafone_cash' }, // canonical (cast via WalletProvider enum)
    { code: 'v_cash' }                    // legacy alias → canonicalize → vodafone_cash
  ),
  true
);
assert(
  'uppercase + spaces "Vodafone Cash" provider + "vodafone_cash" type → true',
  accountMatchesWalletType(
    { wallet_provider: 'Vodafone Cash' },
    { code: 'vodafone_cash' }
  ),
  true
);
assert(
  'canonicalizeWalletTypeCode("v_cash") → "vodafone_cash"',
  canonicalizeWalletTypeCode('v_cash'),
  'vodafone_cash'
);
assert(
  'normalizeWalletProviderCode("Orange Cash") → "orange_cash"',
  normalizeWalletProviderCode('Orange Cash'),
  'orange_cash'
);

console.log('\n── 5. walletProviderLabel (the UX improvement) ──────────────');
assert(
  'label for vodafone_cash',
  walletProviderLabel('vodafone_cash'),
  'فودافون كاش'
);
assert(
  'label for unknown code → falls back to raw input',
  walletProviderLabel('legacy_unknown_code'),
  'legacy_unknown_code'
);
assert(
  'label for null → "(غير محدد)"',
  walletProviderLabel(null),
  '(غير محدد)'
);

console.log('\n── 6. category map integrity ────────────────────────────────');
assert(
  'cash_wallet category has 5 members',
  WALLET_TYPE_CATEGORY_MEMBERS.cash_wallet.length,
  5
);
assert(
  'cash_wallet members include vodafone_cash',
  WALLET_TYPE_CATEGORY_MEMBERS.cash_wallet.includes('vodafone_cash'),
  true
);
assert(
  'cash_wallet members include instapay',
  WALLET_TYPE_CATEGORY_MEMBERS.cash_wallet.includes('instapay'),
  true
);

console.log(`\n────────────────────────────────────────`);
console.log(`  Passed: ${passed}`);
console.log(`  Failed: ${failed}`);
console.log(`────────────────────────────────────────\n`);

if (failed > 0) {
  process.exit(1);
}
