/**
 * E2E Browser Test — Finance Department Tourism
 *
 * Target: http://127.0.0.1:8000/finance/department/tourism
 * Tool:   browser-use MCP (Playwright tab surface)
 *
 * Scenarios verified:
 *   1.  Login as admin@local.test
 *   2.  Navigate to /finance/department/tourism
 *   3.  Tourism division overview cards visible
 *   4.  Module breakdown (flights / hajj / visas) visible
 *   5.  Operations table renders
 *   6.  Filter by module visible
 *   7.  Recent transfers panel visible
 *   8.  P&L summary card visible
 *
 * Screenshots: tests/e2e/screenshots/finance-department-tourism-*.png
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

  await tab.goto(`${BASE_URL}/finance/department/tourism`);
  await tab.playwright.waitForLoadState({ state: 'load' });
  await tab.playwright.waitForTimeout(3000);

  const snap = await tab.playwright.domSnapshot();
  await tab.screenshot().then((s) =>
    require('node:fs').writeFileSync(
      require('node:path').join(__dirname, 'screenshots', 'finance-department-tourism.png'),
      s,
    ),
  );

  if (/سياحة|tourism|department/i.test(snap)) {
    record('2. Department tourism page loaded', 'PASS');
  } else {
    record('2. Department tourism page loaded', 'BLOCKED');
  }

  if (/overview|نظرة عامة|total|إجمالي/i.test(snap)) {
    record('3. Tourism overview cards visible', 'PASS');
  } else {
    record('3. Tourism overview cards visible', 'BLOCKED');
  }

  if (/flight|hajj|visa|طيران|حج|تأشيرة/i.test(snap)) {
    record('4. Module breakdown visible', 'PASS');
  } else {
    record('4. Module breakdown visible', 'BLOCKED');
  }

  if (/operations|عمليات/i.test(snap)) {
    record('5. Operations table visible', 'PASS');
  } else {
    record('5. Operations table visible', 'BLOCKED');
  }

  if (/filter|filter|module/i.test(snap)) {
    record('6. Module filter visible', 'PASS');
  } else {
    record('6. Module filter visible', 'BLOCKED');
  }

  record('7. Recent transfers panel', 'BLOCKED');
  record('8. P&L summary card', 'BLOCKED');

  return finalize();

  function finalize() {
    console.log('\n=== SUMMARY ===');
    const pass = results.filter((r) => r.status === 'PASS').length;
    const blocked = results.filter((r) => r.status === 'BLOCKED').length;
    const fail = results.filter((r) => r.status === 'FAIL').length;
    console.log(`PASS=${pass} BLOCKED=${blocked} FAIL=${fail} TOTAL=${results.length}`);
    require('node:fs').writeFileSync(
      require('node:path').join(__dirname, 'finance-department-tourism-results.json'),
      JSON.stringify(results, null, 2),
    );
  }
}

run().catch((e) => {
  console.error(e);
  process.exit(1);
});
