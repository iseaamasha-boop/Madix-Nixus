#!/usr/bin/env node
/*
 * البند 111 في المتصفح: انقطاع النت بأناقة.
 *   node tests/browser/offline.js https://madix.test [--theme=dark]
 *
 * - انقطاع الاتصال يُظهر شريط «انقطع الاتصال»، وعودته تُظهر «عاد الاتصال» ثم يختفي.
 * - نموذج أُرسل بلا اتصال لا يضيع: يُرسل تلقائياً مرة واحدة عند عودة الاتصال، ومعه مفتاح منع التكرار.
 * - طلب SN.api فشل بالانقطاع يُعاد تلقائياً بالمفتاح نفسه وينجح، دون رسالة خطأ.
 * - لا أخطاء JavaScript.
 */
'use strict';

const { chromium } = require('playwright');

const argv = process.argv.slice(2);
const BASE = (argv.find((a) => /^https?:/.test(a)) || 'http://127.0.0.1:8090').replace(/\/$/, '');
const opt = Object.fromEntries(argv.filter((a) => a.startsWith('--')).map((a) => { const [k, v] = a.slice(2).split('='); return [k, v === undefined ? '1' : v]; }));
const TENANT = process.env.MNX_TENANT || 'demo';
const USER = process.env.MNX_USER || 'owner';
const PASS = process.env.MNX_PASS || 'OwnerPass123';
const THEME = opt.theme || 'light';

let failed = 0;
function ok(cond, msg) {
  console.log((cond ? '  ✔ ' : '  ✘ ') + msg);
  if (!cond) { failed++; }
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 360, height: 740 }, colorScheme: THEME === 'light' ? 'light' : 'dark' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message || e)));
  await page.goto(BASE + '/' + TENANT + '/login');
  await page.fill('input[name=username]', USER);
  await page.fill('input[name=password]', PASS);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

  await page.goto(BASE + '/' + TENANT + '/subscribers?per=5');
  const subUrl = await page.$$eval('a[href*="/subscribers/"]', (as) => as.map((a) => a.getAttribute('href').replace(/[?#].*$/, '')).find((h) => /\/subscribers\/\d+$/.test(h)));
  await page.goto(BASE + subUrl);
  await page.waitForLoadState('load');

  console.log('connection bar');
  await ctx.setOffline(true);
  await page.waitForSelector('.net-bar.is-visible', { timeout: 5000 }).catch(() => null);
  ok(await page.$eval('.net-bar', (b) => b.classList.contains('is-visible') && !b.classList.contains('is-back') && /انقطع الاتصال/.test(b.textContent)).catch(() => false), 'shows "انقطع الاتصال" when the connection drops');

  console.log('form sent while offline');
  const posts = [];
  page.on('request', (r) => { if (r.method() === 'POST' && /\/transaction$/.test(r.url())) { posts.push(r.postData() || ''); } });
  const hasForm = await page.evaluate(() => {
    const d = document.getElementById('tx-modal');
    if (!d) { return false; }
    d.showModal();
    d.querySelector('[name=amount]').value = '1';
    d.querySelector('[name=type]').value = 'payment';
    return true;
  });
  ok(hasForm, 'payment form available');
  await page.click('#tx-modal .modal-foot .btn-primary');
  await page.waitForTimeout(800);
  ok(posts.length === 0, 'nothing sent while offline');
  await ctx.setOffline(false);
  await page.waitForURL(/\/subscribers\/\d+/, { timeout: 15000 }).catch(() => null);
  await page.waitForLoadState('load');
  await page.waitForTimeout(500);
  ok(posts.length === 1, 'sent exactly once when the connection returned (' + posts.length + ')');
  ok(/(^|&)_idem=[0-9a-f]{32}(&|$)/.test(posts[0] || ''), 'carries the duplicate-protection key');
  ok(await page.evaluate(() => /تم تسجيل القيد/.test(document.body.innerText)), 'success message shown');

  console.log('automatic retry of a request');
  await ctx.setOffline(true);
  const sent = [];
  page.on('requestfinished', (r) => { if (/\/account\/theme$/.test(r.url())) { sent.push(r.headers()['idempotency-key'] || ''); } });
  await page.evaluate(() => {
    window.__res = null;
    SN.api(document.querySelector('meta[name="base-url"]').content + '/account/theme', { method: 'POST', data: { theme: document.documentElement.getAttribute('data-theme') || 'light' } })
      .then((d) => { window.__res = d.ok ? 'ok' : 'bad'; }, (e) => { window.__res = 'error: ' + e.message; });
  });
  await page.waitForTimeout(1200);
  ok(await page.evaluate(() => window.__res === null), 'request waits while offline');
  await ctx.setOffline(false);
  await page.waitForFunction(() => window.__res !== null, null, { timeout: 15000 }).catch(() => null);
  ok(await page.evaluate(() => window.__res) === 'ok', 'completed after the connection returned');
  ok(sent.length === 1 && /^[0-9a-f]{32}$/.test(sent[0]), 'one request with its key reached the server');
  await page.waitForSelector('.net-bar.is-back', { timeout: 6000 }).catch(() => null);
  ok(await page.$eval('.net-bar', (b) => /عاد الاتصال/.test(b.textContent)).catch(() => false), 'shows "عاد الاتصال"');
  await page.waitForTimeout(2800);
  ok(await page.$eval('.net-bar', (b) => !b.classList.contains('is-visible')).catch(() => true), 'bar hides afterwards');
  ok(await page.evaluate(() => !document.querySelector('.toast-error')), 'no error message');

  ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
