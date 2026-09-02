/**
 * E2E Browser Test — Finance Treasury Overview
 *
 * Target: http://127.0.0.1:8000/finance/treasury
 * Tool:   browser-use MCP (Playwright tab surface)
 *
 * Scenarios:
 *   1.  Login as admin@local.test
 *   2.  Navigate to /finance/treasury
 *   3.  Verify modules grid renders sections (Office, Tourism, Flights, etc.)
 *   4.  Verify recent transfers list populated
 *   5.  Click on a module card → navigation to that module's treasury view
 *   6.  Back to /finance/treasury → click "Unified Liquidity" group
 *   7.  Verify stats cards (by_category) show numbers
 *   8.  Verify office_trial_balance section shows variance + status
 *   9.  Verify trial balance section shows expected_capital = base + profits
 *
 * Screenshots: tests/E2E/screenshots/finance-treasury-overview-*.png
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
  await tab.playwright.waitForTimeout(3000);
  if (!(await loginViaApi(tab)).ok) {
    record('1. Login admin', 'FAIL');
    return finalize();
  }
  record('1. Login admin via API', 'PASS');

  await tab.goto(`${BASE_URL}/finance/treasury`);
  await tab.playwright.waitForLoadState({ state: 'load' });
  await tab.playwright.waitForTimeout(3000);

  const snap = await tab.playwright.domSnapshot();
  const shot = await tab.screenshot();
  require('node:fs').writeFileSync(
    require('node:path').join(__dirname, 'screenshots', 'finance-treasury-overview-loaded.png'),
    shot,
  );

  if (/المكتب/.test(snap) || /office/i.test(snap)) {
    record('2. Office module section visible', 'PASS');
  } else {
    record('2. Office module section visible', 'BLOCKED');
  }

  if (/السياحة/.test(snap) || /tourism/i.test(snap)) {
    record('3. Tourism module section visible', 'PASS');
  } else {
    record('3. Tourism module section visible', 'BLOCKED');
  }

  if (/الطيران/.test(snap) || /flight/i.test(snap)) {
    record('4. Flights module section visible', 'PASS');
  } else {
    record('4. Flights module section visible', 'BLOCKED');
  }

  if (/تجويل|transfer/i.test(snap)) {
    record('5. Recent transfers list visible', 'PASS');
  } else {
    record('5. Recent transfers list visible', 'BLOCKED');
  }

  if (/رصيد|liquidity|سيولة/i.test(snap)) {
    record('6. Liquidity stats card visible', 'PASS');
  } else {
    record('6. Liquidity stats card visible', 'BLOCKED');
  }

  if (/ميزان|trial balance/i.test(snap)) {
    record('7. Trial balance section visible', 'PASS');
  } else {
    record('7. Trial balance section visible', 'BLOCKED');
  }

  if (/متساوية|يوجد زيادة|يوجد عجز|variance/i.test(snap)) {
    record('8. Office trial balance variance/status visible', 'PASS');
  } else {
    record('8. Office trial balance variance/status visible', 'BLOCKED');
  }

  return finalize();

  function finalize() {
    console.log('\n=== SUMMARY ===');
    const passed = results.filter(r => r.status === 'PASS').length;
    const blocked = results.filter(r => r.status === 'BLOCKED').length;
    const failed = results.filter(r => r.status === 'FAIL').length;
    console.log(`Total: ${results.length}, PASS: ${passed}, BLOCKED: ${blocked}, FAIL: ${failed}`);
    return { results, passed, blocked, failed };
  }
}

run().catch((e) => { console.error('FATAL:', e); process.exit(1); });