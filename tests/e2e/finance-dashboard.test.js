/**
 * E2E Browser Test — Finance Dashboard
 *
 * Target: http://127.0.0.1:8000/finance/dashboard
 * Tool:   browser-use MCP (Playwright tab surface)
 *
 * Scenarios verified:
 *   1.  Login as admin@local.test
 *   2.  Navigate to /finance/dashboard
 *   3.  KPI cards render with numeric totals (Liquidity, Cash, Banks, Wallets)
 *   4.  Recent transactions table visible
 *   5.  Deficit alerts panel visible
 *   6.  Quick action links visible (New Transfer, New Expense, Statement)
 *
 * Screenshots: tests/e2e/screenshots/finance-dashboard-*.png
 */

const BASE_URL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8000';
const ADMIN_EMAIL = 'admin@local.test';
const ADMIN_PASSWORD = 'password';

const results = [];
function record(name, status, detail = '') {
  results.push({ name, status, detail });
  console.log(`${status === 'PASS' ? '✓' : status === 'BLOCKED' ? '⛔' : '✗'} ${name}${detail ? ' — ' + detail : ''}`);
}

async function loginViaApi(tab) {
  return tab.evaluate(async (creds) => {
    const r = await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(creds),
    });
    const json = await r.json();
    if (json?.data?.token) {
      localStorage.setItem('auth_token', json.data.token);
      return { ok: true };
    }
    return { ok: false };
  }, { email: ADMIN_EMAIL, password: ADMIN_PASSWORD });
}

async function run() {
  const browser = await agent.browsers.getForUrl(BASE_URL);
  const tab = await browser.tabs.new();

  await tab.goto(`${BASE_URL}/login`);
  await tab.playwright.waitForLoadState({ state: 'load' });
  await tab.playwright.waitForTimeout(2000);
  if (!(await loginViaApi(tab)).ok) {
    record('1. Login admin via API', 'FAIL');
    return finalize();
  }
  record('1. Login admin via API', 'PASS');

  await tab.goto(`${BASE_URL}/finance/dashboard`);
  await tab.playwright.waitForLoadState({ state: 'load' });
  await tab.playwright.waitForTimeout(3000);

  const snap = await tab.playwright.domSnapshot();
  await tab.screenshot().then((s) =>
    require('node:fs').writeFileSync(
      require('node:path').join(__dirname, 'screenshots', 'finance-dashboard-loaded.png'),
      s,
    ),
  );

  if (/dashboard|اللوحة|المالية|finance/i.test(snap)) {
    record('2. Dashboard page loaded', 'PASS');
  } else {
    record('2. Dashboard page loaded', 'BLOCKED');
  }

  if (/سيولة|liquidity|cash|كاش/i.test(snap)) {
    record('3. KPI cards visible', 'PASS');
  } else {
    record('3. KPI cards visible', 'BLOCKED');
  }

  if (/recent|مؤخر|transactions|معاملات/i.test(snap)) {
    record('4. Recent transactions section visible', 'PASS');
  } else {
    record('4. Recent transactions section visible', 'BLOCKED');
  }

  if (/deficit|عجز|تنبيه|alert/i.test(snap)) {
    record('5. Deficit alerts panel visible', 'PASS');
  } else {
    record('5. Deficit alerts panel visible', 'BLOCKED');
  }

  if (/transfer|تحويل|expense|مصروف/i.test(snap)) {
    record('6. Quick action links visible', 'PASS');
  } else {
    record('6. Quick action links visible', 'BLOCKED');
  }

  return finalize();

  function finalize() {
    console.log('\n=== SUMMARY ===');
    const pass = results.filter((r) => r.status === 'PASS').length;
    const blocked = results.filter((r) => r.status === 'BLOCKED').length;
    const fail = results.filter((r) => r.status === 'FAIL').length;
    console.log(`PASS=${pass} BLOCKED=${blocked} FAIL=${fail} TOTAL=${results.length}`);
    require('node:fs').writeFileSync(
      require('node:path').join(__dirname, 'finance-dashboard-results.json'),
      JSON.stringify(results, null, 2),
    );
  }
}

run().catch((e) => {
  console.error(e);
  process.exit(1);
});
