#!/usr/bin/env node
/*
 * البند 70 في المتصفح: الاستجابة الفورية.
 *   node tests/browser/instant.js https://madix.test [--theme=dark]
 *
 * - تعليم تنبيه كمقروء: يتغير فوراً بلا إعادة تحميل، وتنقص شارة القائمة، ويبقى مقروءاً بعد تحديث الصفحة.
 * - إن رفض الخادم الطلب: تعود الواجهة كما كانت مع رسالة خطأ.
 * - تشغيل/إيقاف إعلان الهوتسبوت (إن وُجد إعلان): الزر والشارة يتبدلان فوراً.
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

  console.log('mark an alert as read');
  await page.goto(BASE + '/' + TENANT + '/alerts?state=unread');
  const badge = () => page.evaluate(() => { const b = document.querySelector('.nav-link[href$="/alerts"] .nav-badge'); return b && !b.hidden ? parseInt(b.textContent, 10) || 0 : 0; });
  const unread = await page.locator('.alert-list li.is-unread').count();
  if (!unread) {
    console.log('  - no unread alerts in this data, skipped');
  } else {
    const before = await badge();
    let navigated = false;
    page.once('framenavigated', () => { navigated = true; });
    const first = page.locator('.alert-list li.is-unread').first();
    const title = (await first.locator('.list-item-title').textContent()).trim();
    await page.evaluate(() => { window.__t0 = performance.now(); });
    await first.locator('form[data-instant="read"] button').click();
    const ms = await page.evaluate(() => performance.now() - window.__t0);
    ok(await page.locator('.alert-list li.is-unread').count() === unread - 1, 'marked read at once (' + Math.round(ms) + 'ms incl. click)');
    ok(before === 0 || (await badge()) === before - 1, 'menu badge decreased');
    await page.waitForTimeout(800);
    ok(!navigated, 'no page reload');
    await page.goto(BASE + '/' + TENANT + '/alerts');
    const row = page.locator('.alert-list li', { hasText: title }).first();
    ok(!(await row.evaluate((li) => li.classList.contains('is-unread'))), 'still read after reload');

    console.log('server refuses: the page goes back');
    await page.goto(BASE + '/' + TENANT + '/alerts?state=unread');
    await page.route('**/alerts/*/read', (r) => r.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, message: 'غير مسموح.' }) }));
    const n = await page.locator('.alert-list li.is-unread').count();
    if (n) {
      await page.locator('.alert-list li.is-unread').first().locator('form[data-instant="read"] button').click();
      await page.waitForSelector('.toast-error', { timeout: 5000 }).catch(() => null);
      ok(await page.locator('.alert-list li.is-unread').count() === n, 'unread again after the error');
      ok(await page.locator('.toast-error').count() >= 1, 'error message shown');
    }
    await page.unroute('**/alerts/*/read');
  }

  console.log('hotspot ad on/off');
  await page.goto(BASE + '/' + TENANT + '/hotspot/ads');
  const ad = page.locator('li.ad-item').first();
  if (!(await ad.count())) {
    console.log('  - no ads in this data, skipped');
  } else {
    const form = ad.locator('form[data-instant="toggle"]');
    const st = await form.getAttribute('data-state');
    await form.locator('button').click();
    ok((await form.getAttribute('data-state')) !== st, 'switched at once');
    await page.waitForTimeout(800);
    await form.locator('button').click();
    await page.waitForTimeout(800);
    await page.reload();
    ok((await page.locator('li.ad-item').first().locator('form[data-instant="toggle"]').getAttribute('data-state')) === st, 'two switches leave it as it was');
  }

  ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
