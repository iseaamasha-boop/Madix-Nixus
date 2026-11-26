#!/usr/bin/env node
/*
 * البند 71 في المتصفح: «تراجع» بدل «هل أنت متأكد؟».
 *   node tests/browser/undo.js https://madix.test [--theme=dark]
 *
 * إضافة منطقة ثم حذفها: لا سؤال تأكيد، تختفي فوراً، يظهر إشعار بزر «تراجع»، والضغط عليه يعيدها. لا أخطاء JavaScript.
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
  for (const [w, h] of [[1280, 800], [360, 740]]) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: w, height: h }, colorScheme: THEME === 'light' ? 'light' : 'dark' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message || e)));
    let dialogs = 0;
    page.on('dialog', (d) => { dialogs++; d.dismiss(); });
    await page.goto(BASE + '/' + TENANT + '/login');
    await page.fill('input[name=username]', USER);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    const name = 'منطقة تراجع ' + w + '-' + Date.now().toString(36);
    await page.goto(BASE + '/' + TENANT + '/areas');
    await page.fill('form[action$="/areas"] input[name=name]', name);
    await Promise.all([page.waitForNavigation(), page.click('form[action$="/areas"] button.btn-primary')]);
    console.log(w + 'px');
    const row = page.locator('tr', { hasText: name });
    ok(await row.count() === 1, 'area added');
    let navigated = false;
    page.once('framenavigated', () => { navigated = true; });
    // من لحظة الإرسال حتى اختفاء الصف، مقاساً داخل الصفحة (بلا زمن أداة الاختبار)
    await page.evaluate((n) => {
      window.__gone = null;
      const tr = Array.from(document.querySelectorAll('tr')).find((r) => r.textContent.includes(n));
      document.addEventListener('submit', () => { window.__t0 = performance.now(); }, { capture: true, once: true });
      new MutationObserver((m, o) => { if (tr.hidden) { window.__gone = performance.now() - window.__t0; o.disconnect(); } }).observe(tr, { attributes: true });
    }, name);
    await row.locator('form[action$="/delete"] button').click();
    await page.waitForFunction(() => window.__gone !== null, null, { timeout: 3000 }).catch(() => null);
    const gone = await page.evaluate(() => window.__gone);
    ok(gone !== null && gone < 400, 'row gone at once without waiting for the server (' + Math.round(gone || -1) + 'ms)');
    ok(await page.locator('dialog.modal[open]').count() === 0 && dialogs === 0, 'no "are you sure" question');
    const btn = page.locator('.toast .toast-action');
    await btn.waitFor({ timeout: 5000 }).catch(() => null);
    ok(await btn.count() === 1 && (await btn.textContent()).trim() === 'تراجع', 'message with an undo button');
    ok(!navigated, 'no page reload');
    await btn.click();
    await page.waitForFunction((n) => Array.from(document.querySelectorAll('tr')).some((r) => !r.hidden && r.textContent.includes(n)), name, { timeout: 8000 }).catch(() => null);
    ok(await page.locator('tr:not([hidden])', { hasText: name }).count() === 1, 'undo brings it back on the page');
    await page.reload();
    ok(await page.locator('tr', { hasText: name }).count() === 1, 'and in the database');
    // تنظيف: حذف المنطقة التجريبية
    await page.locator('tr', { hasText: name }).locator('form[action$="/delete"] button').click();
    await page.waitForSelector('.toast .toast-action', { timeout: 5000 }).catch(() => null);
    ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
    await ctx.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
