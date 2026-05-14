const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch();
  const page = await browser.newPage();
  await page.setViewport({width: 1536, height: 776});
  await page.goto('http://127.0.0.1:8000/login');
  await page.type('input[type=email]', 'admin@safarakelayna.com');
  await page.type('input[type=password]', 'password');
  await page.click('button[type=submit]');
  await page.waitForNavigation();
  await page.goto('http://127.0.0.1:8000/flights/dashboard');
  await page.waitForSelector('.sidebar');
  
  const sbBox = await page.$eval('.sidebar', el => el.getBoundingClientRect().toJSON());
  const mzBox = await page.$eval('.main-zone', el => el.getBoundingClientRect().toJSON());
  console.log('Sidebar:', sbBox);
  console.log('MainZone:', mzBox);
  
  const childBox = await page.$eval('.page-body > div', el => el.getBoundingClientRect().toJSON());
  console.log('PageBodyChild:', childBox);
  
  // also get the computed width of .app-shell
  const asBox = await page.$eval('.app-shell', el => el.getBoundingClientRect().toJSON());
  console.log('AppShell:', asBox);
  
  await browser.close();
})();
